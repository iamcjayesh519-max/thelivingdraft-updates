<?php
/**
 * Story timelines.
 *
 * === WHAT THIS DOES ===
 *
 * A running news story is not one article. A reader who lands on the fourth
 * piece of a six-week story from a search result has no way to find the other
 * five, and no way to see the shape of what they walked into halfway. This
 * finds those groups and hands them to the theme to print as a dated
 * timeline at the foot of the article.
 *
 * === HOW THE GROUPING IS DECIDED ===
 *
 * By scoring every plausible pair of articles on four independent signals,
 * then joining up whatever scores highly enough.
 *
 * The previous version grouped on one signal alone: a shared word in the URL.
 * That works while slugs happen to line up and fails silently the moment they
 * do not, which on a long story is most of the time. Three real articles from
 * one Starlink story:
 *
 *   centre-freezes-starlink-state-deals-pending-licence-gen-2-network
 *   in-space-clears-gen-2-constellation-40000-satellites-conditions
 *   trai-finalises-direct-to-device-spectrum-rules-satcom-operators
 *
 * Not one word in common. A six-week story renames itself as it goes, which
 * is exactly what six-week stories do. And a missing timeline looks identical
 * to a page that never had one, so nobody ever finds out.
 *
 * The four signals:
 *
 *   1. A LINK BETWEEN THEM (the strongest, and it was free all along).
 *      When a follow-up says "as reported on 25 August" and links back, that
 *      is a human being stating outright that these two pieces belong
 *      together. It is the highest-precision signal on any news site and it
 *      needs no cleverness to read. On thelivingdraft.com every single
 *      internal link sampled pointed at another article in the same story.
 *
 *   2. SHARED RARE NAMES. A slug is eight words; the article is hundreds,
 *      including the proper nouns that actually pin a story down. IN-SPACe,
 *      GMPCS, PPPAC, Jantar Mantar. Two articles sharing a name that appears
 *      almost nowhere else are almost certainly the same story. Rarity is
 *      what carries the weight here, so a name common across the site earns
 *      nothing.
 *
 *   3. SHARED SLUG WORDS. The old signal, kept, now as one voice among four.
 *
 *   4. TIME AND SECTION. Weak alone, useful as a tiebreaker. Two articles a
 *      year apart are rarely one story however much else they share.
 *
 * They fail independently, which is the point. Links fail when a writer
 * forgets to link. Slug words fail on renamed stories. Names fail on stories
 * with no proper nouns. All four failing at once is rare.
 *
 * === WHY THIS IS ALSO MORE CAUTIOUS, NOT JUST GREEDIER ===
 *
 * Under the old rule, two articles sharing the word "reservation" were
 * grouped automatically, with nothing asked. A protest story and an OBC list
 * revision are both about reservation policy and are not the same running
 * story. Scored, that pair reaches 20 out of a threshold of 60 and is left
 * alone. More signals means a higher bar, not a lower one.
 *
 * === THE THREE THINGS THAT KEEP IT HONEST ===
 *
 * THE SERIES GUARD. A recurring column produces near-identical slugs by
 * definition. Digits are dropped before anything is compared, month names are
 * on the stop list, and the guard then reads the HEADLINES and rejects any
 * group that collapses to a single template.
 *
 * THE RARITY CEILING. A word or a name appearing across more than a quarter
 * of the site is discarded whatever any hand-written list says.
 *
 * THE QUEUE. Pairs that score in the middle are not guessed at. They wait for
 * one click. A decision made there is stored against the two article ids and
 * survives every future rebuild, whatever the scores do.
 *
 * === WHY THIS IS IN THE PLUGIN AND NOT THE THEME ===
 *
 * Timeline membership is a fact about the journalism, not the design. The
 * same rule that puts the correction log here puts this here. The theme owns
 * only the box that draws it.
 *
 * @package LivingDraftCore
 * @since 4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Option: the computed stories. */
const LD_TIMELINE_INDEX = 'livingdraft_timeline_index';

/** Option: per-story decisions, keyed by story key. */
const LD_TIMELINE_OVERRIDES = 'livingdraft_timeline_overrides';

/** Option: per-pair decisions, keyed "smallerId-largerId". */
const LD_TIMELINE_PAIRS = 'livingdraft_timeline_pairs';

/* ==================================================================
 * 1. SETTINGS
 * ================================================================== */

/**
 * Is the feature on?
 *
 * @since 4.0.0
 * @return bool
 */
function livingdraft_timeline_enabled() {
	return (bool) apply_filters(
		'livingdraft_timeline_enabled',
		(bool) get_option( 'livingdraft_timeline_enabled', true )
	);
}

/**
 * Weights, thresholds and limits.
 *
 * @since 4.0.0
 * @return array
 */
function livingdraft_timeline_rules() {
	return (array) apply_filters(
		'livingdraft_timeline_rules',
		array(
			// --- Signal weights ---
			'w_link'         => 50,  // One article links to the other.
			'w_entity'       => 15,  // Per shared rare name.
			'w_entity_cap'   => 30,  // Most a name overlap can contribute.
			'w_slug'         => 20,  // Per shared slug word.
			'w_slug_cap'     => 40,
			'w_section'      => 10,  // Same category.
			'w_recent'       => 10,  // Within 30 days.
			'w_near'         => 5,   // Within 90 days.

			// --- Thresholds ---
			'join_at'        => 60,  // At or above: joined automatically.
			'ask_at'         => 30,  // At or above: waits for one click.

			// --- Shape of a story ---
			'min_posts'      => 3,
			'max_posts'      => 40,
			'max_span_days'  => 400,

			// --- Noise control ---
			'max_frequency'  => 0.25, // Word or name in more than this share
			                          // of all articles is site noise.
			'min_word_len'   => 4,
			'series_ratio'   => 0.6,

			// --- Bounds ---
			'max_stories'    => 200,
			'max_queue'      => 100,
			'max_corpus'     => 5000, // Articles read in one rebuild.
		)
	);
}

/**
 * Words that cannot distinguish one story from another.
 *
 * Three groups: ordinary joining words; the verbs every headline uses; and
 * the words specific to Indian national reporting that appear in a third of
 * this newsroom's writing and mean nothing alone. That last group is why the
 * list is filterable — "centre" and "crore" are noise here and would be
 * signal on a different site.
 *
 * @since 4.0.0
 * @return string[]
 */
function livingdraft_timeline_stopwords() {
	$words = array(
		'the', 'and', 'for', 'with', 'from', 'that', 'this', 'these', 'those',
		'are', 'was', 'were', 'has', 'have', 'had', 'its', 'his', 'her', 'their',
		'not', 'but', 'all', 'any', 'out', 'off', 'into', 'onto', 'than', 'then',
		'about', 'across', 'among', 'between', 'under', 'within', 'without',

		'says', 'said', 'tells', 'told', 'asks', 'asked', 'gets', 'sets', 'puts',
		'calls', 'called', 'seeks', 'urges', 'plans', 'moves', 'holds', 'faces',
		'sees', 'pushes', 'allows', 'approves', 'announces', 'launches', 'begins',
		'takes', 'gives', 'makes', 'shows', 'adds', 'cuts', 'raises', 'clears',
		'will', 'may', 'can', 'must', 'after', 'before', 'amid', 'over', 'ahead',
		'despite', 'pending', 'why', 'what', 'how', 'when', 'where', 'which',
		'who', 'whose',

		'news', 'update', 'updates', 'report', 'reports', 'latest', 'today',
		'live', 'full', 'list', 'details', 'explained', 'explainer', 'know',
		'more', 'best', 'guide', 'here', 'read', 'story', 'stories',
		'important', 'events', 'history', 'day', 'days', 'week', 'month', 'year',
		'years', 'time', 'times', 'first', 'last', 'next', 'new',

		'india', 'indian', 'centre', 'center', 'central', 'government', 'govt',
		'state', 'states', 'national', 'union', 'minister', 'ministry', 'delhi',
		'crore', 'lakh', 'percent', 'cent', 'rupees', 'scheme', 'schemes',
		'policy', 'plan', 'bill', 'act', 'rule', 'rules', 'order', 'meeting',
		'committee', 'council', 'board', 'authority', 'department', 'office',
		'official', 'officials', 'public', 'people', 'country', 'nation',
	);

	/*
	 * Month and weekday names. A daily column's slug is often nothing BUT a
	 * date once filler is stripped, so this is removed where the rule is
	 * certain rather than left to a threshold that happens to hold.
	 */
	for ( $m = 1; $m <= 12; $m++ ) {
		$stamp   = mktime( 0, 0, 0, $m, 1, 2000 );
		$words[] = strtolower( gmdate( 'F', $stamp ) );
		$words[] = strtolower( date_i18n( 'F', $stamp ) );
	}

	for ( $d = 0; $d < 7; $d++ ) {
		$stamp   = strtotime( "next sunday +{$d} days" );
		$words[] = strtolower( gmdate( 'l', $stamp ) );
		$words[] = strtolower( date_i18n( 'l', $stamp ) );
	}

	return array_unique( array_filter( (array) apply_filters( 'livingdraft_timeline_stopwords', $words ) ) );
}

/* ==================================================================
 * 2. READING AN ARTICLE
 * ================================================================== */

/**
 * Reduce a plural to its singular, crudely.
 *
 * Deliberately not a real stemmer: an aggressive one merges words that should
 * stay apart, and a miss here costs one story that fails to group, which is
 * far cheaper than two unrelated stories fused together.
 *
 * @since 4.0.0
 * @param string $word Word.
 * @return string
 */
function livingdraft_timeline_singular( $word ) {
	if ( ! is_scalar( $word ) ) {
		return '';
	}

	$word = (string) $word;

	if ( strlen( $word ) < 5 ) {
		return $word;
	}

	if ( substr( $word, -3 ) === 'ies' ) {
		return substr( $word, 0, -3 ) . 'y';
	}

	if ( substr( $word, -4 ) === 'sses' || substr( $word, -3 ) === 'hes' || substr( $word, -3 ) === 'xes' ) {
		return substr( $word, 0, -2 );
	}

	// Leave double-s and -us words alone: "press" must not become "pres".
	if ( substr( $word, -1 ) === 's' && substr( $word, -2 ) !== 'ss' && substr( $word, -2 ) !== 'us' ) {
		return substr( $word, 0, -1 );
	}

	return $word;
}

/**
 * Split one slug into the words that could name a story.
 *
 * @since 4.0.0
 * @param string $slug Post slug.
 * @return string[]
 */
function livingdraft_timeline_slug_words( $slug ) {
	if ( ! is_scalar( $slug ) ) {
		return array();
	}

	$rules = livingdraft_timeline_rules();

	static $stop = null;
	if ( null === $stop ) {
		$stop = array_flip( livingdraft_timeline_stopwords() );
	}

	$parts = preg_split( '/[^a-z0-9]+/', strtolower( (string) $slug ), -1, PREG_SPLIT_NO_EMPTY );

	if ( ! $parts ) {
		return array();
	}

	$words = array();

	foreach ( $parts as $part ) {
		// Anything with a digit is a date, a figure or an ordinal. None of
		// those name a story, and all of them are what makes a daily column's
		// slugs look distinct when they are not.
		if ( preg_match( '/\d/', $part ) ) {
			continue;
		}

		if ( strlen( $part ) < (int) $rules['min_word_len'] ) {
			continue;
		}

		$part = livingdraft_timeline_singular( $part );

		if ( isset( $stop[ $part ] ) || strlen( $part ) < (int) $rules['min_word_len'] ) {
			continue;
		}

		$words[ $part ] = true;
	}

	return array_keys( $words );
}

/**
 * Pull the proper nouns out of an article.
 *
 * Two shapes are taken: runs of capitalised words (Jantar Mantar, Rural
 * Development) and bare acronyms (IN-SPACe, GMPCS, PPPAC, TRAI). Both are
 * far more discriminating than an ordinary word, because they are rare.
 *
 * Sentence-opening words are a known false positive here — every sentence
 * starts with a capital. They are not filtered out individually, because the
 * rarity ceiling applied later removes them anyway: a word that only ever
 * appears capitalised at the start of sentences appears in most articles, and
 * anything that common is discarded.
 *
 * @since 4.0.0
 * @param string $title   Headline.
 * @param string $content Post body.
 * @return string[] Lower-cased keys.
 */
function livingdraft_timeline_entities( $title, $content ) {
	// Both arrive from the database on the timeline path and from a parsed
	// feed on the desk path. SimplePie hands back strings, but a malformed
	// feed can produce an object, and concatenating one raises a notice and
	// yields the literal word "Array" as an entity — which would then match
	// against every other malformed item and fuse unrelated stories.
	if ( ! is_scalar( $title ) ) {
		$title = '';
	}

	if ( ! is_scalar( $content ) ) {
		$content = '';
	}

	$text = (string) $title . ' . ' . wp_strip_all_tags( strip_shortcodes( (string) $content ) );

	// Keep the body bounded: the names that identify a story appear early,
	// and reading 20,000 words to find them is waste.
	$text = substr( $text, 0, (int) apply_filters( 'livingdraft_timeline_entity_chars', 6000 ) );

	static $stop = null;
	if ( null === $stop ) {
		$stop = array_flip( livingdraft_timeline_stopwords() );
	}

	$found = array();

	// Acronyms and mixed-case codes: GMPCS, TRAI, IN-SPACe, D2D.
	if ( preg_match_all( '/\b[A-Z][A-Za-z0-9]*(?:-[A-Za-z0-9]+)*\b/', $text, $m ) ) {
		foreach ( $m[0] as $token ) {
			$upper = preg_match_all( '/[A-Z]/', $token );

			// At least two capitals means it is a code, not a capitalised
			// ordinary word: GMPCS and IN-SPACe qualify, "Centre" does not.
			if ( $upper < 2 || strlen( $token ) < 3 || strlen( $token ) > 24 ) {
				continue;
			}

			$key = strtolower( $token );

			if ( isset( $stop[ $key ] ) ) {
				continue;
			}

			$found[ $key ] = true;
		}
	}

	// Runs of two or three capitalised words: Jantar Mantar, Rural Development.
	if ( preg_match_all( '/\b[A-Z][a-z]{2,}(?:\s+[A-Z][a-z]{2,}){1,2}\b/', $text, $m ) ) {
		foreach ( $m[0] as $phrase ) {
			$key = strtolower( preg_replace( '/\s+/', ' ', $phrase ) );

			if ( strlen( $key ) < 6 || strlen( $key ) > 40 ) {
				continue;
			}

			$found[ $key ] = true;
		}
	}

	return array_keys( $found );
}

/**
 * Which other articles does this one link to?
 *
 * Links are matched by SLUG, not by running the URL back through the rewrite
 * rules with url_to_postid(). That function returns 0 for permalinks built on
 * a nested category, for URLs without a trailing slash and for percent-encoded
 * paths — the exact failure that made the Search Console columns show a dash
 * on every row before 4.0. The slug is the last path segment and it is
 * already known, so no guessing is needed.
 *
 * @since 4.0.0
 * @param string $content  Post body.
 * @param array  $slug_map slug => post id.
 * @param string $home     Home URL host and path.
 * @return int[] Post ids linked to.
 */
function livingdraft_timeline_links( $content, $slug_map, $home ) {
	if ( ! is_scalar( $content ) || ! is_array( $slug_map ) ) {
		return array();
	}

	if ( false === strpos( (string) $content, 'href' ) ) {
		return array();
	}

	if ( ! preg_match_all( '/href=["\']([^"\']+)["\']/i', (string) $content, $m ) ) {
		return array();
	}

	$ids = array();

	foreach ( $m[1] as $url ) {
		// Internal only. A relative link is internal by definition.
		if ( 0 === strpos( $url, 'http' ) && false === strpos( $url, $home ) ) {
			continue;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( '' === $path ) {
			continue;
		}

		$segments = array_filter( explode( '/', trim( $path, '/' ) ) );

		if ( empty( $segments ) ) {
			continue;
		}

		$slug = urldecode( (string) end( $segments ) );

		if ( isset( $slug_map[ $slug ] ) ) {
			$ids[ $slug_map[ $slug ] ] = true;
		}
	}

	return array_keys( $ids );
}

/* ==================================================================
 * 3. THE SERIES GUARD
 * ================================================================== */

/**
 * Reduce a headline to its template.
 *
 * Run over a recurring column, every entry collapses to the same string. Run
 * over real reporting, each headline stays distinct. Headlines are used
 * rather than slugs because a slug is already stripped of filler, which makes
 * two unrelated slugs look more alike than their headlines do.
 *
 * @since 4.0.0
 * @param string $title Headline.
 * @return string
 */
function livingdraft_timeline_title_template( $title ) {
	if ( ! is_scalar( $title ) ) {
		return '';
	}

	$text = strtolower( wp_strip_all_tags( (string) $title ) );

	$months = array();
	for ( $m = 1; $m <= 12; $m++ ) {
		$stamp    = mktime( 0, 0, 0, $m, 1, 2000 );
		$months[] = strtolower( gmdate( 'F', $stamp ) );
		$months[] = strtolower( gmdate( 'M', $stamp ) );
		$months[] = strtolower( date_i18n( 'F', $stamp ) );
	}

	foreach ( array_unique( array_filter( $months ) ) as $month ) {
		$text = str_replace( $month, ' ', $text );
	}

	for ( $d = 0; $d < 7; $d++ ) {
		$stamp = strtotime( "next sunday +{$d} days" );
		$text  = str_replace( strtolower( gmdate( 'l', $stamp ) ), ' ', $text );
	}

	$text = preg_replace( '/\d+(st|nd|rd|th)?/u', ' ', $text );
	$text = preg_replace( '/[^\p{L}\s]+/u', ' ', (string) $text );
	$text = preg_replace( '/\s+/u', ' ', (string) $text );

	return trim( (string) $text );
}

/**
 * Do these headlines share one template?
 *
 * @since 4.0.0
 * @param string[] $titles Headlines.
 * @return bool
 */
function livingdraft_timeline_is_series( $titles ) {
	$titles = array_values( array_filter( (array) $titles ) );

	if ( count( $titles ) < 3 ) {
		return false;
	}

	$templates = array();

	foreach ( $titles as $title ) {
		$key = livingdraft_timeline_title_template( $title );

		if ( '' === $key ) {
			continue;
		}

		$templates[ $key ] = ( isset( $templates[ $key ] ) ? $templates[ $key ] : 0 ) + 1;
	}

	if ( empty( $templates ) ) {
		return false;
	}

	$rules = livingdraft_timeline_rules();

	return ( max( $templates ) / count( $titles ) ) >= (float) $rules['series_ratio'];
}

/* ==================================================================
 * 4. DECISIONS AN EDITOR HAS MADE
 * ================================================================== */

/**
 * A stable key for one pair of articles.
 *
 * Ordered smallest first so the same two articles always produce the same key
 * whichever way round they are compared.
 *
 * @since 4.0.0
 * @param int $a First id.
 * @param int $b Second id.
 * @return string
 */
function livingdraft_timeline_pair_key( $a, $b ) {
	$a = (int) $a;
	$b = (int) $b;

	return $a < $b ? "{$a}-{$b}" : "{$b}-{$a}";
}

/**
 * Every pair decision.
 *
 * Stored against the two article ids rather than against a story, so a
 * decision survives every future rebuild whatever the scores do.
 *
 * @since 4.0.0
 * @return array
 */
function livingdraft_timeline_pairs() {
	$stored = get_option( LD_TIMELINE_PAIRS, array() );

	return is_array( $stored ) ? $stored : array();
}

/**
 * Record a decision about one pair.
 *
 * @since 4.0.0
 * @param int    $a       First id.
 * @param int    $b       Second id.
 * @param string $verdict 'yes', 'no' or '' to forget it.
 * @return void
 */
function livingdraft_timeline_set_pair( $a, $b, $verdict ) {
	$pairs = livingdraft_timeline_pairs();
	$key   = livingdraft_timeline_pair_key( $a, $b );

	if ( in_array( $verdict, array( 'yes', 'no' ), true ) ) {
		$pairs[ $key ] = $verdict;
	} else {
		unset( $pairs[ $key ] );
	}

	update_option( LD_TIMELINE_PAIRS, $pairs, false );
}

/**
 * Per-story decisions, keyed by story key.
 *
 * @since 4.0.0
 * @return array
 */
function livingdraft_timeline_overrides() {
	$stored = get_option( LD_TIMELINE_OVERRIDES, array() );

	return is_array( $stored ) ? $stored : array();
}

/**
 * Record a decision about one story.
 *
 * @since 4.0.0
 * @param string      $key   Story key.
 * @param string      $mode  'topic' to suppress, 'auto' to forget.
 * @param string|null $label Optional display name.
 * @return void
 */
function livingdraft_timeline_set_override( $key, $mode, $label = null ) {
	$key = sanitize_key( $key );

	if ( '' === $key ) {
		return;
	}

	$overrides = livingdraft_timeline_overrides();
	$entry     = isset( $overrides[ $key ] ) ? (array) $overrides[ $key ] : array();

	if ( 'auto' === $mode ) {
		unset( $entry['mode'] );
	} elseif ( 'topic' === $mode ) {
		$entry['mode'] = 'topic';
	}

	if ( null !== $label ) {
		$label = sanitize_text_field( $label );

		if ( '' === $label ) {
			unset( $entry['label'] );
		} else {
			$entry['label'] = $label;
		}
	}

	if ( empty( $entry ) ) {
		unset( $overrides[ $key ] );
	} else {
		$overrides[ $key ] = $entry;
	}

	update_option( LD_TIMELINE_OVERRIDES, $overrides, false );
}

/* ==================================================================
 * 5. THE CORPUS
 * ================================================================== */

/**
 * Read every published article once, and derive everything from it.
 *
 * One query rather than get_posts(), because the whole corpus is needed and
 * only four columns of it. The bodies are read, mined and thrown away — only
 * the derived word, name and link lists are kept.
 *
 * @since 4.0.0
 * @return array
 */
function livingdraft_timeline_corpus() {
	global $wpdb;

	$rules = livingdraft_timeline_rules();
	$limit = (int) $rules['max_corpus'];

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ID, post_name, post_title, post_content, post_date_gmt
			   FROM {$wpdb->posts}
			  WHERE post_type = 'post'
			    AND post_status = 'publish'
			    AND post_name != ''
			  ORDER BY post_date_gmt ASC
			  LIMIT %d",
			$limit
		)
	);

	if ( empty( $rows ) ) {
		return array();
	}

	// Slug to id, so internal links resolve without url_to_postid().
	$slug_map = array();
	foreach ( $rows as $row ) {
		$slug_map[ $row->post_name ] = (int) $row->ID;
	}

	$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );

	$corpus = array();

	foreach ( $rows as $row ) {
		$id = (int) $row->ID;

		$corpus[ $id ] = array(
			'title'    => (string) $row->post_title,
			'slug'     => (string) $row->post_name,
			'date'     => (string) $row->post_date_gmt,
			'stamp'    => (int) strtotime( $row->post_date_gmt ),
			'words'    => livingdraft_timeline_slug_words( $row->post_name ),
			'entities' => livingdraft_timeline_entities( $row->post_title, $row->post_content ),
			'links'    => livingdraft_timeline_links( $row->post_content, $slug_map, $home ),
			'cats'     => wp_get_post_categories( $id ),
		);
	}

	// --- The rarity ceiling ---
	// A word or a name appearing across more than a quarter of the site
	// carries no information. This is what removes site-specific noise
	// nobody thought to write into the stop list, and what removes the
	// sentence-opening false positives from name extraction.
	$total   = count( $corpus );
	$ceiling = max( 2, (int) ceil( $total * (float) $rules['max_frequency'] ) );

	foreach ( array( 'words', 'entities' ) as $field ) {
		$freq = array();

		foreach ( $corpus as $entry ) {
			foreach ( $entry[ $field ] as $token ) {
				$freq[ $token ] = ( isset( $freq[ $token ] ) ? $freq[ $token ] : 0 ) + 1;
			}
		}

		foreach ( $corpus as $id => $entry ) {
			$corpus[ $id ][ $field ] = array_values(
				array_filter(
					$entry[ $field ],
					static function ( $token ) use ( $freq, $ceiling ) {
						return isset( $freq[ $token ] ) && $freq[ $token ] <= $ceiling && $freq[ $token ] > 1;
					}
				)
			);
		}
	}

	return $corpus;
}

/* ==================================================================
 * 6. SCORING A PAIR
 * ================================================================== */

/**
 * How strongly do these two articles belong to the same story?
 *
 * @since 4.0.0
 * @param array $a     First article's corpus entry.
 * @param array $b     Second article's entry.
 * @param int   $a_id  First id.
 * @param int   $b_id  Second id.
 * @return array {score:int, why:string[]}
 */
function livingdraft_timeline_score( $a, $b, $a_id, $b_id ) {
	$rules = livingdraft_timeline_rules();
	$score = 0;
	$why   = array();

	/*
	 * === THE SERIES GUARD, APPLIED PAIRWISE ===
	 *
	 * Two entries of a recurring column score respectably: same section, days
	 * apart, and often a shared name or two. Not enough to join, but enough
	 * to land in the queue — and a site running a daily column has hundreds
	 * of such pairs, which would bury every real candidate under them.
	 *
	 * The cluster-level guard catches this eventually, but only after the
	 * queue has already been filled. So the same test runs here: if two
	 * headlines reduce to the identical template once dates, numbers and
	 * month names are stripped, they are two issues of one column and not
	 * two articles in one story. Rejected outright, never queued.
	 */
	$template_a = livingdraft_timeline_title_template( $a['title'] );
	$template_b = livingdraft_timeline_title_template( $b['title'] );

	if ( '' !== $template_a && $template_a === $template_b ) {
		return array(
			'score' => 0,
			'why'   => array( __( 'two issues of one recurring column', 'livingdraft-core' ) ),
		);
	}

	// --- 1. A link between them, in either direction ---
	if ( in_array( $b_id, $a['links'], true ) || in_array( $a_id, $b['links'], true ) ) {
		$score += (int) $rules['w_link'];
		$why[]  = __( 'one links to the other', 'livingdraft-core' );
	}

	// --- 2. Shared rare names ---
	$shared_entities = array_intersect( $a['entities'], $b['entities'] );

	if ( $shared_entities ) {
		$points = min( (int) $rules['w_entity_cap'], count( $shared_entities ) * (int) $rules['w_entity'] );
		$score += $points;
		$why[]  = sprintf(
			/* translators: %s: comma-separated list of names. */
			__( 'both mention %s', 'livingdraft-core' ),
			implode( ', ', array_slice( $shared_entities, 0, 3 ) )
		);
	}

	// --- 3. Shared slug words ---
	$shared_words = array_intersect( $a['words'], $b['words'] );

	if ( $shared_words ) {
		$points = min( (int) $rules['w_slug_cap'], count( $shared_words ) * (int) $rules['w_slug'] );
		$score += $points;
		$why[]  = sprintf(
			/* translators: %s: comma-separated list of words. */
			__( 'URLs share %s', 'livingdraft-core' ),
			implode( ', ', array_slice( $shared_words, 0, 3 ) )
		);
	}

	// --- 4. Section and time ---
	if ( array_intersect( $a['cats'], $b['cats'] ) ) {
		$score += (int) $rules['w_section'];
	}

	$gap = abs( $a['stamp'] - $b['stamp'] );

	if ( $gap <= 30 * DAY_IN_SECONDS ) {
		$score += (int) $rules['w_recent'];
	} elseif ( $gap <= 90 * DAY_IN_SECONDS ) {
		$score += (int) $rules['w_near'];
	}

	// Two articles a year apart are rarely one story, however much they
	// share. Applied as a hard cut rather than a penalty so a very high
	// signal score cannot override it.
	if ( $gap > (int) $rules['max_span_days'] * DAY_IN_SECONDS ) {
		return array(
			'score' => 0,
			'why'   => array( __( 'too far apart in time', 'livingdraft-core' ) ),
		);
	}

	return array(
		'score' => $score,
		'why'   => $why,
	);
}

/* ==================================================================
 * 7. BUILDING THE STORIES
 * ================================================================== */

/**
 * Score, join and store.
 *
 * === WHY PAIRS ARE NOT ALL COMPARED ===
 *
 * Every pair on a 5,000-article site is 12.5 million comparisons, which is
 * not something to run on a cron. But almost all of those pairs share
 * nothing at all and would score zero.
 *
 * So candidates are generated from inverted indexes first: only articles that
 * share at least one slug word, one rare name, or a link are ever scored. On
 * a real newsroom that reduces the work by three or four orders of magnitude
 * while producing exactly the same result, because a pair with no shared
 * signal cannot reach the threshold anyway.
 *
 * @since 4.0.0
 * @return array
 */
function livingdraft_timeline_rebuild_index() {
	$rules     = livingdraft_timeline_rules();
	$corpus    = livingdraft_timeline_corpus();
	$overrides = livingdraft_timeline_overrides();
	$decisions = livingdraft_timeline_pairs();

	if ( count( $corpus ) < (int) $rules['min_posts'] ) {
		update_option( LD_TIMELINE_INDEX, array( 'built' => time(), 'stories' => array(), 'queue' => array() ), false );
		return array();
	}

	// --- Candidate generation ---
	$buckets = array();

	foreach ( $corpus as $id => $entry ) {
		foreach ( $entry['words'] as $token ) {
			$buckets[ 'w:' . $token ][] = $id;
		}
		foreach ( $entry['entities'] as $token ) {
			$buckets[ 'e:' . $token ][] = $id;
		}
	}

	$candidates = array();

	foreach ( $buckets as $ids ) {
		$n = count( $ids );

		// A bucket holding half the site produces nothing but noise and a
		// great deal of work. The rarity ceiling should already have removed
		// these; this is the backstop.
		if ( $n < 2 || $n > (int) $rules['max_posts'] ) {
			continue;
		}

		for ( $i = 0; $i < $n; $i++ ) {
			for ( $j = $i + 1; $j < $n; $j++ ) {
				$candidates[ livingdraft_timeline_pair_key( $ids[ $i ], $ids[ $j ] ) ] = array( $ids[ $i ], $ids[ $j ] );
			}
		}
	}

	// Links always produce a candidate, even with nothing else in common.
	// This is the whole point of the signal: the Starlink follow-up shares no
	// word with the piece it links back to.
	foreach ( $corpus as $id => $entry ) {
		foreach ( $entry['links'] as $target ) {
			if ( isset( $corpus[ $target ] ) && $target !== $id ) {
				$candidates[ livingdraft_timeline_pair_key( $id, $target ) ] = array( $id, $target );
			}
		}
	}

	// --- Score every candidate ---
	$edges = array();
	$queue = array();

	foreach ( $candidates as $key => $pair ) {
		list( $a_id, $b_id ) = $pair;

		// A decision already made is never revisited.
		if ( isset( $decisions[ $key ] ) ) {
			if ( 'yes' === $decisions[ $key ] ) {
				$edges[] = array( $a_id, $b_id );
			}
			continue;
		}

		$result = livingdraft_timeline_score( $corpus[ $a_id ], $corpus[ $b_id ], $a_id, $b_id );

		if ( $result['score'] >= (int) $rules['join_at'] ) {
			$edges[] = array( $a_id, $b_id );
		} elseif ( $result['score'] >= (int) $rules['ask_at'] ) {
			$queue[ $key ] = array(
				'a'     => $a_id,
				'b'     => $b_id,
				'score' => $result['score'],
				'why'   => $result['why'],
			);
		}
	}

	// --- Join up: connected components ---
	$parent = array();

	$find = static function ( $x ) use ( &$parent, &$find ) {
		while ( isset( $parent[ $x ] ) && $parent[ $x ] !== $x ) {
			$parent[ $x ] = isset( $parent[ $parent[ $x ] ] ) ? $parent[ $parent[ $x ] ] : $parent[ $x ];
			$x            = $parent[ $x ];
		}
		return $x;
	};

	foreach ( $edges as $edge ) {
		foreach ( $edge as $node ) {
			if ( ! isset( $parent[ $node ] ) ) {
				$parent[ $node ] = $node;
			}
		}

		$ra = $find( $edge[0] );
		$rb = $find( $edge[1] );

		if ( $ra !== $rb ) {
			$parent[ $rb ] = $ra;
		}
	}

	$groups = array();

	foreach ( array_keys( $parent ) as $node ) {
		$groups[ $find( $node ) ][] = $node;
	}

	// --- Turn groups into stories ---
	$stories = array();

	foreach ( $groups as $members ) {
		$members = array_values( array_unique( $members ) );
		$count   = count( $members );

		if ( $count < (int) $rules['min_posts'] || $count > (int) $rules['max_posts'] ) {
			continue;
		}

		usort(
			$members,
			static function ( $x, $y ) use ( $corpus ) {
				return $corpus[ $x ]['stamp'] <=> $corpus[ $y ]['stamp'];
			}
		);

		$titles = array();
		foreach ( $members as $id ) {
			$titles[] = $corpus[ $id ]['title'];
		}

		if ( livingdraft_timeline_is_series( $titles ) ) {
			continue;
		}

		$first = $corpus[ $members[0] ]['stamp'];
		$last  = $corpus[ $members[ $count - 1 ] ]['stamp'];
		$span  = (int) max( 1, round( ( $last - $first ) / DAY_IN_SECONDS ) );

		if ( $span > (int) $rules['max_span_days'] ) {
			continue;
		}

		$label = livingdraft_timeline_label( $members, $corpus );
		$key   = sanitize_key( $label );

		if ( '' === $key ) {
			continue;
		}

		if ( isset( $overrides[ $key ]['mode'] ) && 'topic' === $overrides[ $key ]['mode'] ) {
			continue;
		}

		if ( ! empty( $overrides[ $key ]['label'] ) ) {
			$label = $overrides[ $key ]['label'];
		}

		$stories[ $key ] = array(
			'key'       => $key,
			'label'     => $label,
			'posts'     => $members,
			'count'     => $count,
			'span_days' => $span,
			'first'     => gmdate( 'Y-m-d', $first ),
			'last'      => gmdate( 'Y-m-d', $last ),
		);
	}

	uasort(
		$stories,
		static function ( $a, $b ) {
			return $b['count'] <=> $a['count'];
		}
	);

	uasort(
		$queue,
		static function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		}
	);

	$stories = array_slice( $stories, 0, (int) $rules['max_stories'], true );
	$queue   = array_slice( $queue, 0, (int) $rules['max_queue'], true );

	update_option(
		LD_TIMELINE_INDEX,
		array(
			'built'   => time(),
			'total'   => count( $corpus ),
			'stories' => $stories,
			'queue'   => $queue,
		),
		false
	);

	return $stories;
}

/**
 * Name a story.
 *
 * The commonest rare name across its articles, falling back to the commonest
 * slug word. A name beats a word: "Starlink" reads better than "licence", and
 * it is what the story is actually about.
 *
 * @since 4.0.0
 * @param int[] $members Article ids.
 * @param array $corpus  The corpus.
 * @return string
 */
function livingdraft_timeline_label( $members, $corpus ) {
	foreach ( array( 'entities', 'words' ) as $field ) {
		$counts = array();

		foreach ( $members as $id ) {
			foreach ( $corpus[ $id ][ $field ] as $token ) {
				$counts[ $token ] = ( isset( $counts[ $token ] ) ? $counts[ $token ] : 0 ) + 1;
			}
		}

		if ( empty( $counts ) ) {
			continue;
		}

		arsort( $counts );
		$best  = (string) key( $counts );
		$share = reset( $counts ) / max( 1, count( $members ) );

		// Must appear in at least half the story to name it.
		if ( $share >= 0.5 ) {
			return ucwords( $best );
		}
	}

	return __( 'Running story', 'livingdraft-core' );
}

/**
 * The stored index.
 *
 * @since 4.0.0
 * @return array
 */
function livingdraft_timeline_index() {
	$stored = get_option( LD_TIMELINE_INDEX, array() );

	if ( ! is_array( $stored ) || ! isset( $stored['stories'] ) ) {
		return is_admin() ? livingdraft_timeline_rebuild_index() : array();
	}

	return (array) $stored['stories'];
}

/**
 * Pairs waiting for a decision.
 *
 * @since 4.0.0
 * @return array
 */
function livingdraft_timeline_queue() {
	$stored = get_option( LD_TIMELINE_INDEX, array() );

	return is_array( $stored ) && isset( $stored['queue'] ) ? (array) $stored['queue'] : array();
}

/**
 * When the index was last built.
 *
 * @since 4.0.0
 * @return int
 */
function livingdraft_timeline_index_age() {
	$stored = get_option( LD_TIMELINE_INDEX, array() );

	return is_array( $stored ) && ! empty( $stored['built'] ) ? (int) $stored['built'] : 0;
}

/* ==================================================================
 * 8. WHAT THE THEME ASKS FOR
 * ================================================================== */

/**
 * The timeline this article belongs to.
 *
 * @since 4.0.0
 * @param int|null $post_id Post, or the current one.
 * @return array|null {key, label, posts, position, total}
 */
function livingdraft_timeline_for_post( $post_id = null ) {
	if ( ! livingdraft_timeline_enabled() ) {
		return null;
	}

	$post_id = $post_id ? (int) $post_id : (int) get_the_ID();

	if ( ! $post_id ) {
		return null;
	}

	$chosen = null;

	foreach ( livingdraft_timeline_index() as $story ) {
		if ( empty( $story['posts'] ) || ! in_array( $post_id, array_map( 'intval', $story['posts'] ), true ) ) {
			continue;
		}

		// The narrowest story wins: a group of four describes this piece more
		// precisely than a group of thirty.
		if ( null === $chosen || $story['count'] < $chosen['count'] ) {
			$chosen = $story;
		}
	}

	if ( ! $chosen ) {
		return null;
	}

	// Re-read the members rather than trusting a snapshot that may be a day
	// old, so anything since unpublished drops out.
	$posts = array();

	foreach ( $chosen['posts'] as $id ) {
		$entry = get_post( (int) $id );

		if ( $entry && 'publish' === $entry->post_status ) {
			$posts[] = $entry;
		}
	}

	if ( count( $posts ) < 2 ) {
		return null;
	}

	$position = 0;

	foreach ( $posts as $i => $entry ) {
		if ( (int) $entry->ID === $post_id ) {
			$position = $i + 1;
			break;
		}
	}

	return array(
		'key'      => $chosen['key'],
		'label'    => $chosen['label'],
		'posts'    => $posts,
		'position' => $position,
		'total'    => count( $posts ),
	);
}

/* ==================================================================
 * 9. KEEPING IT FRESH
 * ================================================================== */

/**
 * Rebuild after a post is saved — at most once an hour, because a newsroom
 * publishing ten pieces in a morning does not need ten full rebuilds.
 *
 * @since 4.0.0
 * @param int $post_id Post.
 * @return void
 */
function livingdraft_timeline_maybe_rebuild( $post_id ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	if ( 'post' !== get_post_type( $post_id ) ) {
		return;
	}

	if ( get_transient( 'livingdraft_timeline_debounce' ) ) {
		return;
	}

	set_transient( 'livingdraft_timeline_debounce', 1, HOUR_IN_SECONDS );

	livingdraft_timeline_rebuild_index();
}
add_action( 'save_post', 'livingdraft_timeline_maybe_rebuild', 20 );

/**
 * Nightly rebuild.
 *
 * @since 4.0.0
 * @return void
 */
function livingdraft_timeline_schedule() {
	if ( ! wp_next_scheduled( 'livingdraft_timeline_daily' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'livingdraft_timeline_daily' );
	}
}
add_action( 'init', 'livingdraft_timeline_schedule' );
add_action( 'livingdraft_timeline_daily', 'livingdraft_timeline_rebuild_index' );
