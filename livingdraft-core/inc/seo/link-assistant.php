<?php
/**
 * Link Assistant: AI-suggested internal links, anchored on focus keywords.
 *
 * === WHAT IT DOES ===
 *
 * On the post edit screen, one button. The assistant:
 *
 *   1. Works out how many more links this article may take:
 *      one link per N words (default 175), minus the links already in it.
 *      An article that is already full gets no suggestions at all.
 *   2. Finds other published articles whose FOCUS KEYWORD appears in this
 *      one (or, using the embeddings from internal-links.php, articles on
 *      the same topic whose keyword may appear in a slightly different form).
 *   3. Scores each one for reliability — topic match, freshness, how well
 *      Google already ranks it, how many people read it, whether it needs
 *      links — and says why, in words.
 *   4. Sends the article and the best candidates to the AI, which reads the
 *      article, states what it is about, and proposes small sentence
 *      rewrites that carry a link on the target's keyword.
 *   5. Checks every proposal before showing it: the sentence must exist
 *      word for word, the rewrite must stay close to the original, the
 *      anchor must be the target's keyword or a close form of it, one link
 *      per target, one per paragraph, and never more than the limit.
 *
 * The editor accepts or skips each suggestion. Nothing is saved until they
 * press Update, and nothing is written to the update log: adding a link is
 * not a change to the story.
 *
 * Only ordinary body paragraphs are touched — never headings, quotes,
 * captions, tables, lists, or the plugin's own boxes (FAQ, key points…).
 *
 * @package LivingDraftCore
 * @since 4.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LD_LA_SETTINGS', 'livingdraft_link_assistant' );

/* ==================================================================
 * 1. SETTINGS
 * ================================================================== */

/**
 * Words per link. One link is allowed for every this-many words.
 *
 * @return int
 */
function livingdraft_la_words_per_link() {
	$saved = get_option( LD_LA_SETTINGS, array() );
	$value = isset( $saved['words_per_link'] ) ? (int) $saved['words_per_link'] : 175;

	return max( 100, min( 400, $value ) );
}

/* ==================================================================
 * 2. MEASURING AN ARTICLE
 * ================================================================== */

/**
 * Words in a piece of HTML.
 *
 * @param string $html Content.
 * @return int
 */
function livingdraft_la_count_words( $html ) {
	$text = wp_strip_all_tags( strip_shortcodes( (string) $html ) );
	$text = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) );

	return '' === $text ? 0 : count( preg_split( '/\s+/u', $text ) );
}

/**
 * Every real link in the content (internal or external).
 *
 * Jump links (#section) and mailto/tel are not counted: they do not send
 * the reader to another page.
 *
 * @param string $html Content.
 * @return string[] hrefs.
 */
function livingdraft_la_links( $html ) {
	preg_match_all( '#<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1#is', (string) $html, $m );

	$out = array();
	foreach ( $m[2] as $href ) {
		$href = trim( html_entity_decode( $href, ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $href || '#' === $href[0] || preg_match( '#^(mailto|tel|javascript):#i', $href ) ) {
			continue;
		}
		$out[] = $href;
	}

	return $out;
}

/**
 * The link budget for a piece of content.
 *
 * @param string $html Content.
 * @return array{words:int,links:int,allowed:int,remaining:int,per:int}
 */
function livingdraft_la_budget( $html ) {
	$per     = livingdraft_la_words_per_link();
	$words   = livingdraft_la_count_words( $html );
	$links   = count( livingdraft_la_links( $html ) );
	$allowed = (int) floor( $words / $per );

	return array(
		'words'     => $words,
		'links'     => $links,
		'allowed'   => $allowed,
		'remaining' => max( 0, $allowed - $links ),
		'per'       => $per,
	);
}

/**
 * Post IDs this content already links to.
 *
 * @param string $html Content.
 * @return int[]
 */
function livingdraft_la_linked_ids( $html ) {
	$home = wp_parse_url( home_url(), PHP_URL_HOST );
	$ids  = array();

	foreach ( livingdraft_la_links( $html ) as $href ) {
		$host = wp_parse_url( $href, PHP_URL_HOST );
		if ( $host && strtolower( $host ) !== strtolower( (string) $home ) ) {
			continue;
		}
		$id = url_to_postid( $href );
		if ( $id ) {
			$ids[] = (int) $id;
		}
	}

	return array_values( array_unique( $ids ) );
}

/**
 * The first focus keyword of a post. Rank Math allows several, comma
 * separated; the first is the primary one.
 *
 * @param int $post_id Post.
 * @return string
 */
function livingdraft_la_keyword( $post_id ) {
	$raw = function_exists( 'livingdraft_seo_get' )
		? (string) livingdraft_seo_get( 'focus_keyword', $post_id )
		: (string) get_post_meta( $post_id, '_ld_seo_focus_keyword', true );

	$first = trim( (string) strtok( $raw, ',' ) );

	return $first;
}

/**
 * May this post be linked to at all?
 *
 * @param WP_Post $post Post.
 * @return bool
 */
function livingdraft_la_target_ok( $post ) {
	if ( ! $post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
		return false;
	}

	$robots = function_exists( 'livingdraft_seo_get' ) ? livingdraft_seo_get( 'robots', $post->ID ) : array();

	return ! ( is_array( $robots ) && in_array( 'noindex', $robots, true ) );
}

/* ==================================================================
 * 3. FINDING AND SCORING CANDIDATES
 * ================================================================== */

/**
 * Every published post that has a focus keyword, as id => keyword.
 *
 * One query. Our own key wins over Rank Math's when both exist.
 *
 * @return array<int,string>
 */
function livingdraft_la_all_keywords() {
	global $wpdb;

	$types = livingdraft_links_post_types();
	$in    = "'" . implode( "','", array_map( 'esc_sql', $types ) ) . "'";

	$rows = $wpdb->get_results(
		"SELECT pm.post_id, pm.meta_key, pm.meta_value
		 FROM {$wpdb->postmeta} pm
		 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		 WHERE pm.meta_key IN ('_ld_seo_focus_keyword','rank_math_focus_keyword')
		   AND pm.meta_value <> ''
		   AND p.post_status = 'publish'
		   AND p.post_type IN ({$in})"
	); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$out = array();
	foreach ( $rows as $row ) {
		$id = (int) $row->post_id;
		$kw = trim( (string) strtok( (string) $row->meta_value, ',' ) );
		if ( '' === $kw ) {
			continue;
		}
		if ( '_ld_seo_focus_keyword' === $row->meta_key || ! isset( $out[ $id ] ) ) {
			$out[ $id ] = $kw;
		}
	}

	return $out;
}

/**
 * Other articles using exactly the same focus keyword.
 *
 * They compete with each other in Google — worth knowing about.
 *
 * @param int    $post_id Post.
 * @param string $keyword Its keyword.
 * @return array<int,array{id:int,title:string,url:string}>
 */
function livingdraft_la_same_keyword( $post_id, $keyword ) {
	if ( '' === $keyword ) {
		return array();
	}

	$out = array();
	foreach ( livingdraft_la_all_keywords() as $id => $kw ) {
		if ( $id !== (int) $post_id && 0 === strcasecmp( $kw, $keyword ) ) {
			$out[] = array(
				'id'    => $id,
				'title' => get_the_title( $id ),
				'url'   => get_edit_post_link( $id, 'raw' ),
			);
		}
	}

	return array_slice( $out, 0, 5 );
}

/**
 * How many other published posts link to this one. Approximate: looks for
 * the post's path in their content.
 *
 * @param int $post_id Post.
 * @return int
 */
function livingdraft_la_incoming( $post_id ) {
	global $wpdb;

	$path = wp_parse_url( get_permalink( $post_id ), PHP_URL_PATH );
	if ( ! $path || '/' === $path ) {
		return 0;
	}

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_status = 'publish' AND ID <> %d AND post_content LIKE %s",
			$post_id,
			'%' . $wpdb->esc_like( 'href="' ) . '%' . $wpdb->esc_like( untrailingslashit( $path ) ) . '%'
		)
	);
}

/**
 * Lifetime views recorded by inc/views.php (0 if that module is absent).
 *
 * @param int $post_id Post.
 * @return int
 */
function livingdraft_la_views( $post_id ) {
	return defined( 'LIVINGDRAFT_VIEWS_TOTAL' ) ? (int) get_post_meta( $post_id, LIVINGDRAFT_VIEWS_TOTAL, true ) : 0;
}

/**
 * Case-insensitive, whole-phrase test.
 *
 * @param string $needle   Keyword.
 * @param string $haystack Text.
 * @return bool
 */
function livingdraft_la_contains( $needle, $haystack ) {
	return (bool) preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $needle, '/' ) . '(?![\p{L}\p{N}])/iu', $haystack );
}

/**
 * Candidate link targets for an article, scored and explained.
 *
 * @param int    $post_id Post being edited.
 * @param string $text    Its plain text.
 * @param int[]  $already IDs it already links to.
 * @return array[]
 */
function livingdraft_la_candidates( $post_id, $text, $already ) {
	$keywords = livingdraft_la_all_keywords();

	// Topic match from the embeddings, when the site has them.
	$similar = array();
	if ( function_exists( 'livingdraft_links_related' ) && defined( 'LD_LINKS_META_KEY' ) && get_post_meta( $post_id, LD_LINKS_META_KEY, true ) ) {
		foreach ( livingdraft_links_related( $post_id, 40 ) as $row ) {
			$similar[ (int) $row['id'] ] = (float) $row['score'];
		}
	}

	$pool = array();
	foreach ( $keywords as $id => $kw ) {
		if ( $id === (int) $post_id || in_array( $id, $already, true ) ) {
			continue;
		}

		$exact = livingdraft_la_contains( $kw, $text );

		// Keep a post if its keyword is in the text, or if it is on the
		// same topic (the AI may find the keyword in another form).
		if ( ! $exact && ( ! isset( $similar[ $id ] ) || $similar[ $id ] < 0.55 ) ) {
			continue;
		}

		$pool[ $id ] = array(
			'kw'    => $kw,
			'exact' => $exact,
			'sim'   => $similar[ $id ] ?? null,
		);
	}

	if ( empty( $pool ) ) {
		return array();
	}

	// Readers, for a relative score.
	$max_views = 1;
	foreach ( array_keys( $pool ) as $id ) {
		$max_views = max( $max_views, livingdraft_la_views( $id ) );
	}

	$scored = array();
	foreach ( $pool as $id => $c ) {
		$post = get_post( $id );
		if ( ! livingdraft_la_target_ok( $post ) ) {
			continue;
		}

		$score   = 0;
		$reasons = array();

		// Topic match — up to 40.
		if ( null !== $c['sim'] ) {
			$score += (int) round( 40 * max( 0, min( 1, ( $c['sim'] - 0.4 ) / 0.45 ) ) );
			if ( $c['sim'] >= 0.75 ) {
				$reasons[] = __( 'very close topic', 'livingdraft-core' );
			} elseif ( $c['sim'] >= 0.6 ) {
				$reasons[] = __( 'related topic', 'livingdraft-core' );
			}
		} elseif ( $c['exact'] ) {
			$score += 22;
		}

		// Keyword appears word for word — 15.
		if ( $c['exact'] ) {
			$score    += 15;
			$reasons[] = __( 'its keyword appears in this article', 'livingdraft-core' );
		}

		// Freshness — up to 15, fading over a year.
		$changed = function_exists( 'livingdraft_last_changed_gmt' ) ? livingdraft_last_changed_gmt( $id ) : $post->post_modified_gmt;
		$stamp   = $changed ? strtotime( $changed . ' UTC' ) : false;
		$days    = $stamp ? max( 0, (int) floor( ( time() - $stamp ) / DAY_IN_SECONDS ) ) : 9999;
		$score  += (int) round( 15 * max( 0, 1 - $days / 365 ) );
		if ( $days <= 30 ) {
			$reasons[] = sprintf( /* translators: %d: days. */ _n( 'updated %d day ago', 'updated %d days ago', max( 1, $days ), 'livingdraft-core' ), max( 1, $days ) );
		}

		// Google — up to 15.
		$pos    = (float) get_post_meta( $id, '_ld_gsc_position', true );
		$clicks = (int) get_post_meta( $id, '_ld_gsc_clicks', true );
		if ( $pos > 0 && $pos <= 10 ) {
			$score    += 15;
			$reasons[] = __( 'on Google page 1', 'livingdraft-core' );
		} elseif ( $pos > 0 && $pos <= 20 ) {
			$score    += 8;
			$reasons[] = __( 'on Google page 2', 'livingdraft-core' );
		} elseif ( $clicks > 0 ) {
			$score += 4;
		}

		// Readers — up to 10.
		$views  = livingdraft_la_views( $id );
		$score += $views > 0 ? (int) round( 10 * log( 1 + $views ) / log( 1 + $max_views ) ) : 0;
		if ( $views > 0 && $views >= 0.5 * $max_views ) {
			$reasons[] = __( 'widely read', 'livingdraft-core' );
		}

		// Trust — a published correction is shown, and weighs a little.
		$corrected = false;
		if ( function_exists( 'livingdraft_get_updates' ) ) {
			foreach ( livingdraft_get_updates( $id ) as $u ) {
				if ( 'correction' === $u['kind'] ) {
					$corrected = true;
					break;
				}
			}
		}
		if ( $corrected ) {
			$score    -= 5;
			$reasons[] = __( 'carries a correction', 'livingdraft-core' );
		}

		$scored[ $id ] = array(
			'id'        => $id,
			'title'     => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'url'       => get_permalink( $post ),
			'keyword'   => $c['kw'],
			'summary'   => wp_trim_words( (string) ( livingdraft_seo_get( 'description', $id ) ?: get_the_excerpt( $post ) ), 30, '…' ),
			'score'     => $score,
			'reasons'   => $reasons,
		);
	}

	uasort(
		$scored,
		static function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		}
	);

	$scored = array_slice( $scored, 0, 12, true );

	// Needs help — up to 5, only computed for the shortlist (one query each).
	foreach ( $scored as $id => &$row ) {
		$incoming = livingdraft_la_incoming( $id );
		if ( $incoming <= 1 ) {
			$row['score']    += 5;
			$row['reasons'][] = 0 === $incoming ? __( 'no other article links to it yet', 'livingdraft-core' ) : __( 'only one article links to it', 'livingdraft-core' );
		}
		$row['score'] = max( 0, min( 100, $row['score'] ) );
	}
	unset( $row );

	uasort(
		$scored,
		static function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		}
	);

	return $scored;
}

/* ==================================================================
 * 4. READING THE ARTICLE
 * ================================================================== */

/**
 * The body paragraphs that may receive a link.
 *
 * Skips anything inside a heading, quote, figure, table, list, details
 * box, or any element whose class starts with "ld-" (the plugin's FAQ,
 * key points, rating and similar boxes).
 *
 * @param string $html Content.
 * @return array<int,string> Plain text of each usable paragraph, by index.
 */
function livingdraft_la_paragraphs( $html ) {
	if ( ! class_exists( 'DOMDocument' ) ) {
		return array();
	}

	$doc  = new DOMDocument();
	$prev = libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8"?><div id="ld-root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING );
	libxml_clear_errors();
	libxml_use_internal_errors( $prev );

	$xpath = new DOMXPath( $doc );
	$out   = array();
	$index = 0;

	foreach ( $xpath->query( '//p' ) as $p ) {
		$skip = false;
		for ( $node = $p; $node && 'ld-root' !== ( $node instanceof DOMElement ? $node->getAttribute( 'id' ) : '' ); $node = $node->parentNode ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}
			if ( in_array( strtolower( $node->nodeName ), array( 'blockquote', 'figure', 'figcaption', 'table', 'ul', 'ol', 'details', 'aside', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
				$skip = true;
				break;
			}
			if ( preg_match( '/(^|\s)ld-/', $node->getAttribute( 'class' ) ) ) {
				$skip = true;
				break;
			}
		}

		$text = trim( preg_replace( '/\s+/u', ' ', $p->textContent ) );

		if ( ! $skip && mb_strlen( $text ) >= 40 ) {
			$out[ $index ] = $text;
		}
		++$index;
	}

	return $out;
}

/* ==================================================================
 * 5. ASKING THE AI, AND CHECKING WHAT IT SAYS
 * ================================================================== */

/**
 * Build the prompt.
 *
 * @param string  $title      Article title.
 * @param array   $paragraphs Index => text.
 * @param array[] $candidates Scored candidates.
 * @param int     $max        Maximum suggestions.
 * @return string
 */
function livingdraft_la_prompt( $title, $paragraphs, $candidates, $max ) {
	$body  = '';
	$chars = 0;
	foreach ( $paragraphs as $i => $text ) {
		$chars += mb_strlen( $text );
		if ( $chars > 14000 ) {
			break;
		}
		$body .= "[P{$i}] {$text}\n\n";
	}

	$list = '';
	foreach ( $candidates as $c ) {
		$list .= sprintf( "- id %d | keyword: \"%s\" | title: %s | about: %s | reliability %d/100\n", $c['id'], $c['keyword'], $c['title'], $c['summary'], $c['score'] );
	}

	return <<<PROMPT
You are an experienced news sub-editor adding internal links to an article.

First, read the whole article and understand what it is about and what a reader of it wants to know next.

Then propose AT MOST {$max} internal links, choosing only from the candidate articles listed. Prefer higher reliability, but only when the link genuinely helps this article's reader go deeper. Fewer, better links beat more links. Proposing zero is fine.

Rules for every suggestion:
- The link text (anchor) must be the candidate's keyword, or a close natural form of it (plural, different word order, a minor extra word). Nothing else.
- Copy ONE sentence from one paragraph exactly, character for character, as "original".
- Give "rewritten": the same sentence with the smallest change needed to fit the keyword naturally. Usually no change at all beyond marking the anchor. Keep the author's voice, facts and meaning. Never add claims.
- Mark the anchor inside "rewritten" with double square brackets, exactly once: [[like this]].
- Never link inside a direct quotation.
- At most one suggestion per paragraph, and each candidate at most once.

Reply with JSON only, no markdown, in this shape:
{"intent": "one sentence: what this article is about and what its reader wants next",
 "suggestions": [{"p": 3, "original": "…", "rewritten": "… [[anchor]] …", "target": 123, "why": "short reason a reader would click"}]}

ARTICLE TITLE: {$title}

ARTICLE:
{$body}
CANDIDATE ARTICLES:
{$list}
PROMPT;
}

/**
 * Pull JSON out of a model reply that may carry fences or chatter.
 *
 * @param string $raw Reply.
 * @return array|null
 */
function livingdraft_la_parse_json( $raw ) {
	$text = trim( (string) $raw );
	$text = preg_replace( '/^```(?:json)?\s*|```\s*$/i', '', $text );

	$first = strpos( $text, '{' );
	$last  = strrpos( $text, '}' );
	if ( false === $first || false === $last || $last <= $first ) {
		return null;
	}

	$data = json_decode( substr( $text, $first, $last - $first + 1 ), true );

	return is_array( $data ) ? $data : null;
}

/**
 * Normalise text for comparison: whitespace, curly quotes, dashes.
 *
 * @param string $s Text.
 * @return string
 */
function livingdraft_la_norm( $s ) {
	$s = html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' );
	$s = str_replace( array( "\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{00A0}" ), array( "'", "'", '"', '"', ' ' ), $s );

	return trim( preg_replace( '/\s+/u', ' ', $s ) );
}

/**
 * Is the anchor the keyword, or a close form of it?
 *
 * The significant words of the keyword (3+ letters) must appear in the
 * anchor, matched on their first four letters so plurals and small
 * inflections pass. A keyword of three or more words may lose one of them.
 * The anchor may not be much longer than the keyword.
 *
 * @param string $anchor  Anchor.
 * @param string $keyword Keyword.
 * @return bool
 */
function livingdraft_la_anchor_ok( $anchor, $keyword ) {
	$a = mb_strtolower( livingdraft_la_norm( $anchor ) );
	$k = mb_strtolower( livingdraft_la_norm( $keyword ) );

	if ( '' === $a || '' === $k ) {
		return false;
	}
	if ( false !== mb_strpos( $a, $k ) ) {
		return mb_strlen( $a ) <= mb_strlen( $k ) + 20;
	}

	$words = array_filter(
		preg_split( '/[^\p{L}\p{N}]+/u', $k ),
		static function ( $w ) {
			return mb_strlen( $w ) >= 3;
		}
	);

	if ( empty( $words ) ) {
		return false;
	}

	$found = 0;
	foreach ( $words as $w ) {
		if ( false !== mb_strpos( $a, mb_substr( $w, 0, 4 ) ) ) {
			++$found;
		}
	}

	// Short keywords: every word. Three words or more: one may be left
	// out ("coastal road" for "Mumbai coastal road"), never more.
	$needed = count( $words ) >= 3 ? count( $words ) - 1 : count( $words );

	return $found >= $needed && mb_strlen( $a ) <= mb_strlen( $k ) + 20;
}

/**
 * Find the exact stretch of raw HTML that renders as a given sentence,
 * provided the sentence has no inline markup inside it.
 *
 * @param string $sentence Plain sentence.
 * @param string $html     Raw content.
 * @return string|null The raw substring, or null.
 */
function livingdraft_la_locate( $sentence, $html ) {
	$variants = array_unique(
		array(
			$sentence,
			htmlspecialchars( $sentence, ENT_NOQUOTES, 'UTF-8', false ),
			str_replace( "'", "\u{2019}", $sentence ),
			htmlspecialchars( str_replace( "'", "\u{2019}", $sentence ), ENT_NOQUOTES, 'UTF-8', false ),
			str_replace( "'", '&rsquo;', htmlspecialchars( $sentence, ENT_NOQUOTES, 'UTF-8', false ) ),
			str_replace( "'", '&#8217;', htmlspecialchars( $sentence, ENT_NOQUOTES, 'UTF-8', false ) ),
		)
	);

	foreach ( $variants as $v ) {
		if ( '' !== $v && 1 === substr_count( $html, $v ) ) {
			return $v;
		}
	}

	return null;
}

/**
 * Turn the AI's reply into checked, ready-to-apply suggestions.
 *
 * @param array   $data       Parsed reply.
 * @param array   $paragraphs Index => text.
 * @param array[] $candidates Scored candidates by id.
 * @param string  $html       Raw content.
 * @param int     $max        Limit.
 * @return array[]
 */
function livingdraft_la_validate( $data, $paragraphs, $candidates, $html, $max ) {
	$out        = array();
	$used_p     = array();
	$used_t     = array();
	$html_lower = strtolower( $html );

	foreach ( (array) ( $data['suggestions'] ?? array() ) as $s ) {
		if ( count( $out ) >= $max ) {
			break;
		}

		$p      = isset( $s['p'] ) ? (int) $s['p'] : -1;
		$target = isset( $s['target'] ) ? (int) $s['target'] : 0;
		$orig   = livingdraft_la_norm( $s['original'] ?? '' );
		$rew    = livingdraft_la_norm( $s['rewritten'] ?? '' );

		if ( ! isset( $paragraphs[ $p ], $candidates[ $target ] ) || isset( $used_p[ $p ] ) || isset( $used_t[ $target ] ) ) {
			continue;
		}

		// The sentence must really be in that paragraph.
		if ( '' === $orig || false === mb_strpos( livingdraft_la_norm( $paragraphs[ $p ] ), $orig ) ) {
			continue;
		}

		// Exactly one [[anchor]].
		if ( 1 !== preg_match_all( '/\[\[(.+?)\]\]/u', $rew, $m ) ) {
			continue;
		}
		$anchor = trim( $m[1][0] );
		$plain  = str_replace( $m[0][0], $anchor, $rew );

		$cand = $candidates[ $target ];
		if ( ! livingdraft_la_anchor_ok( $anchor, $cand['keyword'] ) ) {
			continue;
		}

		// The rewrite must stay close to the original.
		similar_text( mb_strtolower( $orig ), mb_strtolower( $plain ), $pct );
		if ( $pct < 70 || mb_strlen( $plain ) > mb_strlen( $orig ) * 1.4 + 30 ) {
			continue;
		}

		// Already linked to this target somewhere? Skip.
		$path = wp_parse_url( $cand['url'], PHP_URL_PATH );
		if ( $path && '/' !== $path && false !== strpos( $html_lower, strtolower( untrailingslashit( $path ) ) ) ) {
			continue;
		}

		// Where exactly does it sit in the raw HTML?
		$needle = livingdraft_la_locate( $orig, $html );
		if ( null === $needle ) {
			continue;
		}

		$link        = '<a href="' . esc_url( $cand['url'] ) . '">' . esc_html( $anchor ) . '</a>';
		$parts       = explode( $m[0][0], $rew, 2 );
		$replacement = esc_html( $parts[0] ) . $link . esc_html( $parts[1] ?? '' );

		// Write apostrophes the same way the original did.
		$apos = "'";
		foreach ( array( "\u{2019}", '&rsquo;', '&#8217;' ) as $form ) {
			if ( false !== strpos( $needle, $form ) ) {
				$apos = $form;
				break;
			}
		}
		$replacement = str_replace( '&#039;', $apos, $replacement );
		$replacement = str_replace( '&quot;', '"', $replacement );

		$used_p[ $p ]      = true;
		$used_t[ $target ] = true;

		$out[] = array(
			'p'           => $p,
			'original'    => $orig,
			'rewritten'   => $plain,
			'anchor'      => $anchor,
			'changed'     => mb_strtolower( $orig ) !== mb_strtolower( $plain ),
			'needle'      => $needle,
			'replacement' => $replacement,
			'why'         => sanitize_text_field( (string) ( $s['why'] ?? '' ) ),
			'target'      => array(
				'id'      => $cand['id'],
				'title'   => $cand['title'],
				'url'     => $cand['url'],
				'keyword' => $cand['keyword'],
				'score'   => $cand['score'],
				'reasons' => $cand['reasons'],
			),
		);
	}

	return $out;
}

/* ==================================================================
 * 6. THE AJAX ENDPOINT
 * ================================================================== */

/**
 * Run the assistant on the content currently in the editor.
 */
function livingdraft_la_ajax_run() {
	$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

	check_ajax_referer( 'ld_la_' . $post_id, 'nonce' );

	if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( array( 'message' => __( 'You cannot edit this article.', 'livingdraft-core' ) ), 403 );
	}

	// The unsaved editor content. Only read, never saved from here.
	$html = isset( $_POST['content'] ) ? (string) wp_unslash( $_POST['content'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	if ( '' === trim( $html ) ) {
		$html = (string) get_post_field( 'post_content', $post_id );
	}

	$budget  = livingdraft_la_budget( $html );
	$keyword = livingdraft_la_keyword( $post_id );

	$response = array(
		'budget'       => $budget,
		'keyword'      => $keyword,
		'same_keyword' => livingdraft_la_same_keyword( $post_id, $keyword ),
		'intent'       => '',
		'suggestions'  => array(),
		'candidates'   => 0,
		'message'      => '',
	);

	if ( $budget['remaining'] <= 0 ) {
		$response['message'] = $budget['allowed'] <= 0
			? sprintf( /* translators: %d: words per link. */ __( 'This article is too short for a link. One link is allowed for every %d words.', 'livingdraft-core' ), $budget['per'] )
			: __( 'This article already has as many links as its length allows. No more will be suggested.', 'livingdraft-core' );
		wp_send_json_success( $response );
	}

	$paragraphs = livingdraft_la_paragraphs( $html );
	$text       = implode( "\n", $paragraphs );
	$candidates = livingdraft_la_candidates( $post_id, $text, livingdraft_la_linked_ids( $html ) );

	$response['candidates'] = count( $candidates );

	if ( empty( $candidates ) ) {
		$response['message'] = __( 'No suitable article found. Link suggestions need other published articles whose focus keyword appears in this one, or which cover the same topic.', 'livingdraft-core' );
		wp_send_json_success( $response );
	}

	$reply = livingdraft_ai_complete(
		livingdraft_la_prompt( get_the_title( $post_id ), $paragraphs, $candidates, $budget['remaining'] ),
		array(
			'task'        => 'link_assistant',
			'system'      => 'You are a careful news sub-editor. You reply with JSON only.',
			'max_tokens'  => 2000,
			'temperature' => 0.3,
		)
	);

	if ( is_wp_error( $reply ) ) {
		wp_send_json_error( array( 'message' => $reply->get_error_message() ) );
	}

	$data = livingdraft_la_parse_json( $reply );
	if ( null === $data ) {
		wp_send_json_error( array( 'message' => __( 'The AI reply could not be read. Please try again.', 'livingdraft-core' ) ) );
	}

	$response['intent']      = sanitize_text_field( (string) ( $data['intent'] ?? '' ) );
	$response['suggestions'] = livingdraft_la_validate( $data, $paragraphs, $candidates, $html, $budget['remaining'] );

	if ( empty( $response['suggestions'] ) ) {
		$response['message'] = __( 'The AI found no link that would genuinely help this article\'s reader. That is a fine result.', 'livingdraft-core' );
	}

	wp_send_json_success( $response );
}
add_action( 'wp_ajax_ld_link_assistant', 'livingdraft_la_ajax_run' );

/* ==================================================================
 * 7. THE PANEL ON THE EDIT SCREEN
 * ================================================================== */

/**
 * Register the panel.
 */
function livingdraft_la_add_meta_box() {
	foreach ( livingdraft_links_post_types() as $type ) {
		add_meta_box( 'ld-link-assistant', __( 'Link assistant', 'livingdraft-core' ), 'livingdraft_la_render_box', $type, 'normal', 'high' );
	}
}
add_action( 'add_meta_boxes', 'livingdraft_la_add_meta_box' );

/**
 * Render the panel.
 *
 * @param WP_Post $post Post.
 */
function livingdraft_la_render_box( $post ) {
	$budget  = livingdraft_la_budget( $post->post_content );
	$keyword = livingdraft_la_keyword( $post->ID );
	$ready   = function_exists( 'livingdraft_seo_ai_ready' ) && livingdraft_seo_ai_ready();
	?>
	<div class="ld-la" data-ld-la
		data-post-id="<?php echo (int) $post->ID; ?>"
		data-nonce="<?php echo esc_attr( wp_create_nonce( 'ld_la_' . $post->ID ) ); ?>">

		<p class="ld-la-status" data-ld-la-status>
			<?php
			printf(
				/* translators: 1: words, 2: links, 3: allowed, 4: words per link. */
				esc_html__( '%1$d words · %2$d links · up to %3$d allowed (1 per %4$d words)', 'livingdraft-core' ),
				(int) $budget['words'],
				(int) $budget['links'],
				(int) $budget['allowed'],
				(int) $budget['per']
			);
			?>
		</p>

		<?php if ( '' === $keyword ) : ?>
			<p class="ld-la-note"><?php esc_html_e( 'This article has no focus keyword yet. Other articles can only link to it once it has one.', 'livingdraft-core' ); ?></p>
		<?php endif; ?>

		<?php if ( ! $ready ) : ?>
			<p class="ld-la-note"><?php esc_html_e( 'Add an AI key under The Living Draft → Settings → AI to use the assistant.', 'livingdraft-core' ); ?></p>
		<?php else : ?>
			<button type="button" class="button button-primary" data-ld-la-run>
				<?php esc_html_e( 'Find link opportunities', 'livingdraft-core' ); ?>
			</button>
			<span class="ld-la-hint"><?php esc_html_e( 'Reads the article as it is now in the editor, including unsaved changes.', 'livingdraft-core' ); ?></span>
		<?php endif; ?>

		<div class="ld-la-results" data-ld-la-results aria-live="polite"></div>
	</div>
	<?php
}

/**
 * Load the panel's script and styles on the edit screen.
 *
 * @param string $hook Admin page.
 */
function livingdraft_la_enqueue( $hook ) {
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->post_type, livingdraft_links_post_types(), true ) ) {
		return;
	}

	$css = LIVINGDRAFT_CORE_DIR . 'assets/css/link-assistant.css';
	$js  = LIVINGDRAFT_CORE_DIR . 'assets/js/link-assistant.js';

	wp_enqueue_style( 'livingdraft-link-assistant', LIVINGDRAFT_CORE_URL . 'assets/css/link-assistant.css', array(), filemtime( $css ) );
	wp_enqueue_script( 'livingdraft-link-assistant', LIVINGDRAFT_CORE_URL . 'assets/js/link-assistant.js', array(), filemtime( $js ), true );

	wp_localize_script(
		'livingdraft-link-assistant',
		'ldLinkAssistant',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'i18n'    => array(
				'working'     => __( 'Reading the article and checking candidates… this can take up to a minute.', 'livingdraft-core' ),
				'intent'      => __( 'What the AI understood', 'livingdraft-core' ),
				'before'      => __( 'Before', 'livingdraft-core' ),
				'after'       => __( 'After', 'livingdraft-core' ),
				'linksTo'     => __( 'Links to', 'livingdraft-core' ),
				'reliability' => __( 'Reliability', 'livingdraft-core' ),
				'accept'      => __( 'Accept', 'livingdraft-core' ),
				'skip'        => __( 'Skip', 'livingdraft-core' ),
				'accepted'    => __( 'Added. Press Update to save.', 'livingdraft-core' ),
				'skipped'     => __( 'Skipped.', 'livingdraft-core' ),
				'notFound'    => __( 'That sentence has changed in the editor since the check. Run the assistant again.', 'livingdraft-core' ),
				'sameKeyword' => __( 'Other articles use the same focus keyword. They compete with this one in Google; consider giving each a different keyword:', 'livingdraft-core' ),
				'error'       => __( 'Something went wrong. Please try again.', 'livingdraft-core' ),
				'onlyLink'    => __( 'Only the link is added; the sentence is unchanged.', 'livingdraft-core' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'livingdraft_la_enqueue' );

/* ==================================================================
 * 8. SETTINGS
 * ================================================================== */

/**
 * Save.
 */
function livingdraft_la_handle_save() {
	if ( ! isset( $_POST['ld_la_save'] ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to change these settings.', 'livingdraft-core' ) );
	}

	check_admin_referer( 'ld_la_save' );

	$per = isset( $_POST['ld_la_words_per_link'] ) ? absint( $_POST['ld_la_words_per_link'] ) : 175;

	update_option( LD_LA_SETTINGS, array( 'words_per_link' => max( 100, min( 400, $per ) ) ) );

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'    => 'livingdraft-settings',
				'section' => 'link-assistant',
				'ld-la'   => 'saved',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}
add_action( 'admin_init', 'livingdraft_la_handle_save' );

/**
 * Render.
 */
function livingdraft_la_render_settings() {
	if ( isset( $_GET['ld-la'] ) && 'saved' === $_GET['ld-la'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="tld-notice"><p>' . esc_html__( 'Link assistant settings saved.', 'livingdraft-core' ) . '</p></div>';
	}
	?>
	<div class="tld-card">
		<div class="tld-section-rule"><h2><?php esc_html_e( 'Link assistant', 'livingdraft-core' ); ?></h2></div>
		<p class="tld-help"><?php esc_html_e( 'Suggests internal links on each article\'s focus keyword, with small AI rewrites you accept or skip one at a time. Links already in an article, internal or external, count towards its limit.', 'livingdraft-core' ); ?></p>

		<form method="post" action="">
			<?php wp_nonce_field( 'ld_la_save' ); ?>
			<div class="tld-field">
				<label class="tld-label" for="ld-la-per"><?php esc_html_e( 'One link for every', 'livingdraft-core' ); ?></label>
				<div class="tld-field-row">
					<input type="number" id="ld-la-per" name="ld_la_words_per_link" min="100" max="400" step="5" class="tld-input is-mono" style="max-width:110px" value="<?php echo esc_attr( (string) livingdraft_la_words_per_link() ); ?>" />
					<span class="tld-unit"><?php esc_html_e( 'words', 'livingdraft-core' ); ?></span>
				</div>
				<p class="tld-help"><?php esc_html_e( 'Between 150 and 200 suits most news writing. At 175: a 350-word brief may carry 2 links, a 1,750-word explainer 10.', 'livingdraft-core' ); ?></p>
			</div>
			<div class="tld-btn-row">
				<button type="submit" name="ld_la_save" value="1" class="tld-btn is-primary"><?php esc_html_e( 'Save', 'livingdraft-core' ); ?></button>
			</div>
		</form>
	</div>
	<?php
}

/**
 * Register in the Settings rail.
 *
 * @param array $sections Sections.
 * @return array
 */
function livingdraft_la_register_settings_section( $sections ) {
	$sections['link-assistant'] = array(
		'label'  => __( 'Link assistant', 'livingdraft-core' ),
		'desc'   => __( 'How many internal links an article may carry.', 'livingdraft-core' ),
		'render' => 'livingdraft_la_render_settings',
		'cap'    => 'manage_options',
	);

	return $sections;
}
add_filter( 'livingdraft_settings_panels', 'livingdraft_la_register_settings_section', 35 );
