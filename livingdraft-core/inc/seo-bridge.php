<?php
/**
 * The SEO bridge.
 *
 * Rank Math handles sitemaps, redirects and pagination properly, and this site
 * needs those. But Rank Math does not know about the update log, so on its own
 * it tells Google the story was "modified" whenever anyone touched the post —
 * a typo fix and a factual correction look identical.
 *
 * This file hands Rank Math the real information instead of fighting it:
 * the time of the newest logged change, and the corrections themselves.
 *
 * Nothing here runs unless Rank Math (or Yoast) is actually active.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Is there a logged update on this post?
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function livingdraft_core_has_log( $post_id ) {
	return ! empty( livingdraft_get_updates( $post_id ) );
}

/* ------------------------------------------------------------------
 * Rank Math
 * ------------------------------------------------------------------ */

/**
 * Correct the dateModified in Rank Math's structured data, and attach the
 * corrections themselves.
 *
 * @param array $data  The schema pieces Rank Math is about to print.
 * @param mixed $jsonld Rank Math's JSON-LD object.
 * @return array
 */
function livingdraft_core_rankmath_schema( $data, $jsonld ) {
	if ( ! is_singular( 'post' ) || ! is_array( $data ) ) {
		return $data;
	}

	$post_id = get_the_ID();
	$updates = livingdraft_get_updates( $post_id );

	if ( empty( $updates ) ) {
		return $data;
	}

	$modified = gmdate( DATE_W3C, (int) strtotime( livingdraft_last_changed_gmt( $post_id ) ) );

	$notes = array();
	foreach ( $updates as $row ) {
		if ( 'correction' === $row['kind'] ) {
			$notes[] = $row['note'];
		}
	}

	foreach ( $data as $key => $piece ) {
		if ( ! is_array( $piece ) || empty( $piece['@type'] ) ) {
			continue;
		}

		$type = (array) $piece['@type'];

		// Rank Math calls it Article, BlogPosting or NewsArticle depending on
		// how it is configured. Any of them is the piece we want.
		if ( ! array_intersect( $type, array( 'Article', 'NewsArticle', 'BlogPosting' ) ) ) {
			continue;
		}

		$data[ $key ]['dateModified'] = $modified;

		if ( ! empty( $notes ) ) {
			$data[ $key ]['correction'] = $notes;
		}
	}

	return $data;
}
add_filter( 'rank_math/json_ld', 'livingdraft_core_rankmath_schema', 20, 2 );

/**
 * The same correction for the Open Graph modified time.
 *
 * @param string $time Modified time.
 * @return string
 */
function livingdraft_core_rankmath_og_modified( $time ) {
	if ( ! is_singular( 'post' ) ) {
		return $time;
	}

	$post_id = get_the_ID();
	if ( ! livingdraft_core_has_log( $post_id ) ) {
		return $time;
	}

	return gmdate( DATE_W3C, (int) strtotime( livingdraft_last_changed_gmt( $post_id ) ) );
}
add_filter( 'rank_math/opengraph/facebook/article_modified_time', 'livingdraft_core_rankmath_og_modified' );

/* ------------------------------------------------------------------
 * Yoast — same job, different hook, in case the site ever moves over
 * ------------------------------------------------------------------ */

/**
 * @param array $piece The Article schema piece.
 * @return array
 */
function livingdraft_core_yoast_article( $piece ) {
	if ( ! is_singular( 'post' ) || ! is_array( $piece ) ) {
		return $piece;
	}

	$post_id = get_the_ID();
	$updates = livingdraft_get_updates( $post_id );

	if ( empty( $updates ) ) {
		return $piece;
	}

	$piece['dateModified'] = gmdate( DATE_W3C, (int) strtotime( livingdraft_last_changed_gmt( $post_id ) ) );

	$notes = array();
	foreach ( $updates as $row ) {
		if ( 'correction' === $row['kind'] ) {
			$notes[] = $row['note'];
		}
	}

	if ( ! empty( $notes ) ) {
		$piece['correction'] = $notes;
	}

	return $piece;
}
add_filter( 'wpseo_schema_article', 'livingdraft_core_yoast_article', 20 );

/* ------------------------------------------------------------------
 * A sponsored story must be labelled to a crawler, not only to a reader
 * ------------------------------------------------------------------ */

/**
 * Add rel="sponsored" handling and a robots hint for paid content.
 *
 * @param array $robots Robots directives.
 * @return array
 */
function livingdraft_core_sponsored_robots( $robots ) {
	if ( ! is_singular( 'post' ) ) {
		return $robots;
	}

	$label = get_post_meta( get_the_ID(), '_livingdraft_label', true );

	if ( 'sponsored' === $label ) {
		$robots['max-snippet'] = 'max-snippet:-1';
	}

	return $robots;
}
add_filter( 'rank_math/frontend/robots', 'livingdraft_core_sponsored_robots' );
