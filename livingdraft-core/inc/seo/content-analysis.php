<?php
/**
 * The SEO analysis engine.
 *
 * A list of check functions, each returning { status, label, hint }.
 * The engine runs them all against a post plus its unsaved-in-form
 * overrides, aggregates the results into a 0–100 score, and returns
 * both so the metabox can show a live checklist AND a headline number.
 *
 * === WHY THE OVERRIDES ===
 *
 * The metabox JavaScript sends the CURRENT values from the form on
 * every debounced update, not just what is in the database. Otherwise
 * the score would only refresh after Save and the whole "watch your
 * score go up as you write" experience would break.
 *
 * === ADDING A CHECK ===
 *
 * Register a callable on the `livingdraft_seo_checks` filter, taking
 * ( $checks_array, $ctx ) and returning the same shape:
 *   [ 'id' => 'unique', 'label' => 'Human sentence', 'status' => 'pass|warn|fail', 'weight' => 5 ]
 *
 * Weight is in points — the total for all registered checks is what
 * the final score is normalised against, so adding a 5-point check
 * doesn't skew existing scores.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run analysis against a post and return { score, checks[] }.
 *
 * @param int   $post_id
 * @param array $overrides Form-time overrides: title, description, focus_keyword, canonical.
 * @return array
 */
function livingdraft_seo_analyze( $post_id, $overrides = array() ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return array( 'score' => 0, 'checks' => array(), 'grade' => 'na' );
	}

	// Build the context every rule sees.
	$ctx = array(
		'post'        => $post,
		'title'       => isset( $overrides['title'] ) && '' !== $overrides['title']
			? (string) $overrides['title']
			: ( livingdraft_seo_get( 'title', $post_id ) ?: get_the_title( $post ) ),
		'description' => isset( $overrides['description'] ) && '' !== $overrides['description']
			? (string) $overrides['description']
			: livingdraft_seo_get( 'description', $post_id ),
		'keyword'     => isset( $overrides['keyword'] )
			? trim( (string) $overrides['keyword'] )
			: livingdraft_seo_get( 'focus_keyword', $post_id ),
		'slug'        => $post->post_name,
		'content'     => (string) $post->post_content,
		'text'        => trim( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) ),
	);

	$ctx['keyword_lc'] = strtolower( $ctx['keyword'] );
	$ctx['title_lc']   = strtolower( $ctx['title'] );
	$ctx['desc_lc']    = strtolower( $ctx['description'] );
	$ctx['text_lc']    = strtolower( $ctx['text'] );

	$checks = array();

	// Skip keyword-dependent checks if no focus keyword has been set —
	// they'd all fail with the same message which reads as noise.
	$has_keyword = '' !== $ctx['keyword_lc'];

	$checks[] = livingdraft_seo_check_title_length( $ctx );
	$checks[] = livingdraft_seo_check_desc_length( $ctx );
	$checks[] = livingdraft_seo_check_content_length( $ctx );
	$checks[] = livingdraft_seo_check_slug_length( $ctx );
	$checks[] = livingdraft_seo_check_images_alt( $ctx );
	$checks[] = livingdraft_seo_check_internal_links( $ctx );
	$checks[] = livingdraft_seo_check_external_links( $ctx );
	$checks[] = livingdraft_seo_check_headings( $ctx );

	if ( $has_keyword ) {
		$checks[] = livingdraft_seo_check_keyword_in_title( $ctx );
		$checks[] = livingdraft_seo_check_keyword_in_desc( $ctx );
		$checks[] = livingdraft_seo_check_keyword_in_slug( $ctx );
		$checks[] = livingdraft_seo_check_keyword_in_first_para( $ctx );
		$checks[] = livingdraft_seo_check_keyword_density( $ctx );
	}

	/**
	 * Filter the checks. Add or replace rules from other plugins/themes.
	 */
	$checks = (array) apply_filters( 'livingdraft_seo_checks', $checks, $ctx );

	// Decorate failing/warning checks with their "Fix with AI" strategy.
	// Kept as a post-processing pass so the rules themselves stay pure
	// and the fix routing lives in one predictable place.
	foreach ( $checks as &$c ) {
		if ( 'pass' === $c['status'] ) {
			continue; // Nothing to fix.
		}
		$fix = livingdraft_seo_fix_strategy( $c['id'] );
		if ( $fix ) {
			$c['fix'] = $fix;
		}
	}
	unset( $c );

	// Score: sum of (status→multiplier × weight), normalised to /100.
	$total_weight = 0;
	$earned       = 0;
	foreach ( $checks as $c ) {
		$w = isset( $c['weight'] ) ? (int) $c['weight'] : 5;
		$total_weight += $w;
		switch ( $c['status'] ) {
			case 'pass': $earned += $w; break;
			case 'warn': $earned += $w * 0.5; break;
			// fail: 0
		}
	}

	$score = $total_weight > 0 ? (int) round( ( $earned / $total_weight ) * 100 ) : 0;

	$grade = 'bad';
	if ( $score >= 80 ) {
		$grade = 'good';
	} elseif ( $score >= 55 ) {
		$grade = 'warn';
	}

	return array(
		'score'       => $score,
		'grade'       => $grade,
		'has_keyword' => $has_keyword,
		'checks'      => array_values( $checks ),
	);
}

/* ------------------------------------------------------------------
 * INDIVIDUAL RULES
 *
 * Each rule is pure: takes context, returns a check row. Kept separate
 * so tests could target each one in isolation later.
 * ------------------------------------------------------------------ */

function livingdraft_seo_check_title_length( $ctx ) {
	$len = mb_strlen( $ctx['title'] );
	if ( $len >= 50 && $len <= 60 ) {
		$status = 'pass';
		$hint   = __( 'Perfect length for Google.', 'livingdraft-core' );
	} elseif ( $len >= 30 && $len < 50 ) {
		$status = 'warn';
		$hint   = __( 'A bit short — you have room for more descriptive words.', 'livingdraft-core' );
	} elseif ( $len > 60 && $len <= 70 ) {
		$status = 'warn';
		$hint   = __( 'A touch long — Google may truncate the tail.', 'livingdraft-core' );
	} elseif ( 0 === $len ) {
		$status = 'fail';
		$hint   = __( 'No SEO title set — using the post title as fallback.', 'livingdraft-core' );
	} else {
		$status = 'fail';
		$hint   = sprintf( __( 'Length is %d characters. Aim for 50–60.', 'livingdraft-core' ), $len );
	}
	return array(
		'id'     => 'title_length',
		'label'  => __( 'SEO title length', 'livingdraft-core' ),
		'status' => $status,
		'hint'   => $hint,
		'weight' => 8,
	);
}

function livingdraft_seo_check_desc_length( $ctx ) {
	$len = mb_strlen( $ctx['description'] );
	if ( $len >= 140 && $len <= 160 ) {
		$status = 'pass';
		$hint   = __( 'Perfect length.', 'livingdraft-core' );
	} elseif ( $len >= 120 && $len < 140 ) {
		$status = 'warn';
		$hint   = __( 'Slightly short — a few more words would fit.', 'livingdraft-core' );
	} elseif ( $len > 160 && $len <= 180 ) {
		$status = 'warn';
		$hint   = __( 'Slightly long — Google may cut it off.', 'livingdraft-core' );
	} elseif ( 0 === $len ) {
		$status = 'fail';
		$hint   = __( 'No meta description set. Google will improvise one.', 'livingdraft-core' );
	} else {
		$status = 'fail';
		$hint   = sprintf( __( 'Length is %d characters. Aim for 140–160.', 'livingdraft-core' ), $len );
	}
	return array(
		'id'     => 'desc_length',
		'label'  => __( 'Meta description length', 'livingdraft-core' ),
		'status' => $status,
		'hint'   => $hint,
		'weight' => 8,
	);
}

function livingdraft_seo_check_content_length( $ctx ) {
	$words = str_word_count( $ctx['text'] );
	if ( $words >= 600 ) {
		$status = 'pass';
		$hint   = sprintf( __( '%d words — good depth.', 'livingdraft-core' ), $words );
	} elseif ( $words >= 300 ) {
		$status = 'warn';
		$hint   = sprintf( __( '%d words. Consider expanding to 600+ for competitive keywords.', 'livingdraft-core' ), $words );
	} else {
		$status = 'fail';
		$hint   = sprintf( __( '%d words. Thin content rarely ranks.', 'livingdraft-core' ), $words );
	}
	return array(
		'id'     => 'content_length',
		'label'  => __( 'Content length', 'livingdraft-core' ),
		'status' => $status,
		'hint'   => $hint,
		'weight' => 6,
	);
}

function livingdraft_seo_check_slug_length( $ctx ) {
	$len = strlen( $ctx['slug'] );
	if ( $len > 0 && $len <= 75 ) {
		return array(
			'id'     => 'slug_length',
			'label'  => __( 'URL slug length', 'livingdraft-core' ),
			'status' => 'pass',
			'hint'   => __( 'Short and readable.', 'livingdraft-core' ),
			'weight' => 3,
		);
	}
	return array(
		'id'     => 'slug_length',
		'label'  => __( 'URL slug length', 'livingdraft-core' ),
		'status' => $len > 75 ? 'warn' : 'fail',
		'hint'   => $len > 75
			? __( 'Slug is quite long. Shorter URLs get shared more.', 'livingdraft-core' )
			: __( 'No slug yet.', 'livingdraft-core' ),
		'weight' => 3,
	);
}

function livingdraft_seo_check_images_alt( $ctx ) {
	if ( ! preg_match_all( '/<img\b[^>]*>/i', $ctx['content'], $imgs ) ) {
		return array(
			'id'     => 'images_alt',
			'label'  => __( 'Image alt text', 'livingdraft-core' ),
			'status' => 'warn',
			'hint'   => __( 'No images in this piece. An article usually benefits from at least one.', 'livingdraft-core' ),
			'weight' => 4,
		);
	}

	$total   = count( $imgs[0] );
	$missing = 0;
	foreach ( $imgs[0] as $tag ) {
		if ( ! preg_match( '/\balt\s*=\s*(["\'])(.+?)\1/i', $tag ) ) {
			$missing++;
		}
	}

	if ( 0 === $missing ) {
		$status = 'pass';
		$hint   = sprintf( __( 'All %d images have alt text.', 'livingdraft-core' ), $total );
	} else {
		$status = 'fail';
		$hint   = sprintf(
			/* translators: 1: images missing alt, 2: total images. */
			__( '%1$d of %2$d images have no alt text.', 'livingdraft-core' ),
			$missing,
			$total
		);
	}

	return array(
		'id'     => 'images_alt',
		'label'  => __( 'Image alt text', 'livingdraft-core' ),
		'status' => $status,
		'hint'   => $hint,
		'weight' => 5,
	);
}

function livingdraft_seo_check_internal_links( $ctx ) {
	$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
	$count     = 0;
	if ( preg_match_all( '/<a\b[^>]*href=(["\'])(.*?)\1/i', $ctx['content'], $m ) ) {
		foreach ( $m[2] as $href ) {
			$host = wp_parse_url( $href, PHP_URL_HOST );
			if ( ! $host || $host === $home_host ) {
				$count++;
			}
		}
	}
	return array(
		'id'     => 'internal_links',
		'label'  => __( 'Internal links', 'livingdraft-core' ),
		'status' => $count >= 1 ? 'pass' : 'warn',
		'hint'   => $count >= 1
			? sprintf( _n( '%d internal link.', '%d internal links.', $count, 'livingdraft-core' ), $count )
			: __( 'No internal links. Linking to your own related pieces helps readers and crawlers.', 'livingdraft-core' ),
		'weight' => 4,
	);
}

function livingdraft_seo_check_external_links( $ctx ) {
	$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
	$count     = 0;
	if ( preg_match_all( '/<a\b[^>]*href=(["\'])(.*?)\1/i', $ctx['content'], $m ) ) {
		foreach ( $m[2] as $href ) {
			$host = wp_parse_url( $href, PHP_URL_HOST );
			if ( $host && $host !== $home_host ) {
				$count++;
			}
		}
	}
	return array(
		'id'     => 'external_links',
		'label'  => __( 'External links', 'livingdraft-core' ),
		'status' => $count >= 1 ? 'pass' : 'warn',
		'hint'   => $count >= 1
			? sprintf( _n( '%d external link.', '%d external links.', $count, 'livingdraft-core' ), $count )
			: __( 'No external links. Linking to sources signals research to search engines.', 'livingdraft-core' ),
		'weight' => 3,
	);
}

function livingdraft_seo_check_headings( $ctx ) {
	preg_match_all( '/<h([1-6])\b/i', $ctx['content'], $m );
	$counts = array_count_values( $m[1] );
	$h2     = isset( $counts['2'] ) ? (int) $counts['2'] : 0;

	if ( $h2 >= 2 ) {
		return array(
			'id'     => 'headings',
			'label'  => __( 'Heading structure', 'livingdraft-core' ),
			'status' => 'pass',
			'hint'   => sprintf( __( '%d H2 subheadings — good scanning structure.', 'livingdraft-core' ), $h2 ),
			'weight' => 4,
		);
	}
	if ( 1 === $h2 ) {
		return array(
			'id'     => 'headings',
			'label'  => __( 'Heading structure', 'livingdraft-core' ),
			'status' => 'warn',
			'hint'   => __( 'One H2. Long pieces read better with several sub-sections.', 'livingdraft-core' ),
			'weight' => 4,
		);
	}
	return array(
		'id'     => 'headings',
		'label'  => __( 'Heading structure', 'livingdraft-core' ),
		'status' => 'fail',
		'hint'   => __( 'No H2 subheadings. Add a few to break the piece up.', 'livingdraft-core' ),
		'weight' => 4,
	);
}

function livingdraft_seo_check_keyword_in_title( $ctx ) {
	$pass = '' !== $ctx['title_lc'] && false !== strpos( $ctx['title_lc'], $ctx['keyword_lc'] );
	return array(
		'id'     => 'kw_in_title',
		'label'  => __( 'Focus keyword in SEO title', 'livingdraft-core' ),
		'status' => $pass ? 'pass' : 'fail',
		'hint'   => $pass
			? __( 'The keyword is in the title.', 'livingdraft-core' )
			: __( 'The focus keyword is missing from the SEO title.', 'livingdraft-core' ),
		'weight' => 8,
	);
}

function livingdraft_seo_check_keyword_in_desc( $ctx ) {
	if ( '' === $ctx['desc_lc'] ) {
		return array(
			'id'     => 'kw_in_desc',
			'label'  => __( 'Focus keyword in meta description', 'livingdraft-core' ),
			'status' => 'fail',
			'hint'   => __( 'No meta description to check.', 'livingdraft-core' ),
			'weight' => 6,
		);
	}
	$pass = false !== strpos( $ctx['desc_lc'], $ctx['keyword_lc'] );
	return array(
		'id'     => 'kw_in_desc',
		'label'  => __( 'Focus keyword in meta description', 'livingdraft-core' ),
		'status' => $pass ? 'pass' : 'fail',
		'hint'   => $pass ? __( 'Present.', 'livingdraft-core' ) : __( 'Add the focus keyword to the description.', 'livingdraft-core' ),
		'weight' => 6,
	);
}

function livingdraft_seo_check_keyword_in_slug( $ctx ) {
	$slug_lc     = strtolower( str_replace( '-', ' ', $ctx['slug'] ) );
	$keyword_lc2 = str_replace( '-', ' ', $ctx['keyword_lc'] );
	$pass        = '' !== $slug_lc && false !== strpos( $slug_lc, $keyword_lc2 );
	return array(
		'id'     => 'kw_in_slug',
		'label'  => __( 'Focus keyword in URL slug', 'livingdraft-core' ),
		'status' => $pass ? 'pass' : 'warn',
		'hint'   => $pass ? __( 'Present.', 'livingdraft-core' ) : __( 'The slug does not contain the focus keyword.', 'livingdraft-core' ),
		'weight' => 5,
	);
}

function livingdraft_seo_check_keyword_in_first_para( $ctx ) {
	// First 200 characters of stripped text is a reasonable proxy for
	// "first paragraph" without parsing paragraph boundaries.
	$intro = mb_strtolower( mb_substr( $ctx['text_lc'], 0, 200 ) );
	$pass  = '' !== $intro && false !== strpos( $intro, $ctx['keyword_lc'] );
	return array(
		'id'     => 'kw_in_intro',
		'label'  => __( 'Focus keyword in the opening', 'livingdraft-core' ),
		'status' => $pass ? 'pass' : 'warn',
		'hint'   => $pass
			? __( 'Present in the first paragraph.', 'livingdraft-core' )
			: __( 'The focus keyword doesn\'t appear in the first paragraph.', 'livingdraft-core' ),
		'weight' => 5,
	);
}

function livingdraft_seo_check_keyword_density( $ctx ) {
	$word_count = str_word_count( $ctx['text_lc'] );
	if ( $word_count < 50 || '' === $ctx['keyword_lc'] ) {
		return array(
			'id'     => 'kw_density',
			'label'  => __( 'Focus keyword density', 'livingdraft-core' ),
			'status' => 'warn',
			'hint'   => __( 'Not enough text to measure density.', 'livingdraft-core' ),
			'weight' => 3,
		);
	}

	$hits    = substr_count( $ctx['text_lc'], $ctx['keyword_lc'] );
	$density = ( $hits * str_word_count( $ctx['keyword_lc'] ) / max( 1, $word_count ) ) * 100;

	if ( $density >= 0.5 && $density <= 2.5 ) {
		$status = 'pass';
		$hint   = sprintf( __( 'Density %.1f%% (%d mentions).', 'livingdraft-core' ), $density, $hits );
	} elseif ( $density > 2.5 ) {
		$status = 'warn';
		$hint   = sprintf( __( 'Density %.1f%% (%d mentions) — may look like stuffing.', 'livingdraft-core' ), $density, $hits );
	} elseif ( $hits === 0 ) {
		$status = 'fail';
		$hint   = __( 'The focus keyword doesn\'t appear in the body at all.', 'livingdraft-core' );
	} else {
		$status = 'warn';
		$hint   = sprintf( __( 'Density %.2f%% (%d mentions) — a little sparse.', 'livingdraft-core' ), $density, $hits );
	}

	return array(
		'id'     => 'kw_density',
		'label'  => __( 'Focus keyword density', 'livingdraft-core' ),
		'status' => $status,
		'hint'   => $hint,
		'weight' => 4,
	);
}

/* ------------------------------------------------------------------
 * FIX STRATEGIES
 *
 * Maps a check ID to what "Fix with AI" should do about it.
 *
 *   mode: 'autofix'   → run the task, paste the result into `target`.
 *                       The metabox field then fires an input event and
 *                       the checklist rebuilds itself.
 *   mode: 'suggest'   → run the task, show the result in an inline
 *                       panel with a Copy button. The writer applies it
 *                       to the post body manually.
 *
 * Anything not listed here has no AI fix path (e.g. changing the slug
 * would break existing links, so we don't offer that; image alt needs
 * vision-model handling that comes in its own module later).
 *
 * Extend by filtering `livingdraft_seo_fix_strategies`.
 * ------------------------------------------------------------------ */

function livingdraft_seo_fix_strategies() {
	$strategies = array(
		'title_length' => array(
			'mode'   => 'autofix',
			'task'   => 'seo_title',
			'target' => 'ld_seo_title',
			'label'  => __( 'Rewrite with AI', 'livingdraft-core' ),
		),
		'kw_in_title' => array(
			'mode'   => 'autofix',
			'task'   => 'seo_title',
			'target' => 'ld_seo_title',
			'label'  => __( 'Rewrite with AI', 'livingdraft-core' ),
		),
		'desc_length' => array(
			'mode'   => 'autofix',
			'task'   => 'meta_description',
			'target' => 'ld_seo_description',
			'label'  => __( 'Rewrite with AI', 'livingdraft-core' ),
		),
		'kw_in_desc' => array(
			'mode'   => 'autofix',
			'task'   => 'meta_description',
			'target' => 'ld_seo_description',
			'label'  => __( 'Rewrite with AI', 'livingdraft-core' ),
		),
		'kw_in_intro' => array(
			'mode'  => 'suggest',
			'task'  => 'suggest_intro',
			'label' => __( 'Suggest an opening', 'livingdraft-core' ),
		),
		'kw_density' => array(
			'mode'  => 'suggest',
			'task'  => 'suggest_density',
			'label' => __( 'Suggest additions', 'livingdraft-core' ),
		),
		'headings' => array(
			'mode'  => 'suggest',
			'task'  => 'suggest_headings',
			'label' => __( 'Suggest H2s', 'livingdraft-core' ),
		),
		'content_length' => array(
			'mode'  => 'suggest',
			'task'  => 'suggest_expansion',
			'label' => __( 'Suggest what to expand', 'livingdraft-core' ),
		),
	);

	return (array) apply_filters( 'livingdraft_seo_fix_strategies', $strategies );
}

/**
 * Look up the fix strategy for a check ID, or null if none is defined.
 */
function livingdraft_seo_fix_strategy( $check_id ) {
	$s = livingdraft_seo_fix_strategies();
	return isset( $s[ $check_id ] ) ? $s[ $check_id ] : null;
}

/**
 * Pull the first paragraph of a post's rendered content, for suggest_intro.
 * Falls back to first ~300 chars of stripped body when no <p> boundary
 * is present (block editor sometimes wraps content differently).
 */
function livingdraft_seo_first_paragraph( $post ) {
	$content = (string) $post->post_content;
	if ( preg_match( '/<p\b[^>]*>(.+?)<\/p>/is', $content, $m ) ) {
		return trim( wp_strip_all_tags( $m[1] ) );
	}
	$stripped = trim( wp_strip_all_tags( strip_shortcodes( $content ) ) );
	return mb_substr( $stripped, 0, 300 );
}
