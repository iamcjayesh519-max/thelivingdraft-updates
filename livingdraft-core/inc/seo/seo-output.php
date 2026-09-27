<?php
/**
 * The front-end SEO output.
 *
 * Reads what the meta box stored (or the Rank Math fallback) and emits
 * the tags Google + social platforms consume: <title>, description,
 * canonical, Open Graph, Twitter Card, robots.
 *
 * === WHY THIS IS OFF BY DEFAULT ===
 *
 * If Rank Math or Yoast is still active, letting this run too would
 * produce duplicate tags in the source, which look like a bug even
 * when everything technically still works. So the master switch is
 * off until the user explicitly turns it on from the SEO settings
 * screen — which they should do only after disabling Rank Math.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The master switch. Filterable so a staging environment can force it
 * on/off without touching the database.
 */
function livingdraft_seo_output_enabled() {
	$enabled = (bool) get_option( 'livingdraft_seo_output_enabled', false );
	return (bool) apply_filters( 'livingdraft_seo_output_enabled', $enabled );
}

/**
 * Detect a conflicting SEO plugin — surface a notice in admin, and
 * suppress our output so the user is not chasing duplicates.
 */
function livingdraft_seo_conflicts() {
	$conflicts = array();
	if ( defined( 'RANK_MATH_VERSION' ) || function_exists( 'rank_math' ) ) {
		$conflicts[] = 'Rank Math';
	}
	if ( defined( 'WPSEO_VERSION' ) ) {
		$conflicts[] = 'Yoast SEO';
	}
	if ( defined( 'AIOSEO_VERSION' ) ) {
		$conflicts[] = 'All in One SEO';
	}
	return $conflicts;
}

/* ------------------------------------------------------------------
 * <title> tag
 * ------------------------------------------------------------------ */

function livingdraft_seo_filter_document_title( $title ) {
	if ( ! livingdraft_seo_output_enabled() || is_admin() ) {
		return $title;
	}

	$custom    = '';
	$sep       = apply_filters( 'livingdraft_seo_title_separator', '|' );
	$site_name = get_bloginfo( 'name' );

	if ( is_singular() ) {
		$post_id = (int) get_queried_object_id();
		if ( $post_id ) {
			$custom = livingdraft_seo_get( 'title', $post_id );
		}
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();
		if ( $term && ! empty( $term->term_id ) && function_exists( 'livingdraft_seo_term_get' ) ) {
			$custom = livingdraft_seo_term_get( 'title', $term->term_id );
		}
	}

	if ( '' === $custom ) {
		return $title;
	}
	return trim( $custom ) . ' ' . $sep . ' ' . $site_name;
}
add_filter( 'pre_get_document_title', 'livingdraft_seo_filter_document_title', 20 );

/* ------------------------------------------------------------------
 * Meta / OG / Twitter / canonical / robots
 * ------------------------------------------------------------------ */

function livingdraft_seo_print_head() {
	if ( ! livingdraft_seo_output_enabled() || is_admin() ) {
		return;
	}

	// Feeds and search pages don't get overridden — WP's own headers
	// handle those correctly, and we don't want to pollute a search
	// results page with a specific post's meta.
	if ( is_feed() || is_search() || is_404() ) {
		return;
	}

	if ( is_singular() ) {
		livingdraft_seo_print_head_singular();
		return;
	}

	if ( is_category() || is_tag() || is_tax() ) {
		livingdraft_seo_print_head_term();
		return;
	}

	// Home and other archive types (author, date) are not yet owned by
	// this module — they fall through to WordPress defaults + whatever
	// the theme emits.
}
add_action( 'wp_head', 'livingdraft_seo_print_head', 1 );

/**
 * Emit the meta bundle for a single post/page. Kept as its own
 * function so the term-archive path can mirror the same shape
 * without duplicating the emitter.
 */
function livingdraft_seo_print_head_singular() {
	$post_id = (int) get_queried_object_id();
	if ( ! $post_id ) {
		return;
	}

	$desc      = livingdraft_seo_get( 'description', $post_id );
	$canonical = livingdraft_seo_get( 'canonical', $post_id );
	$og_title  = livingdraft_seo_get( 'og_title', $post_id );
	$og_desc   = livingdraft_seo_get( 'og_description', $post_id );
	$og_image  = livingdraft_seo_get( 'og_image', $post_id );
	// v3.3.0: separate Twitter fields (fall back to OG if unset —
	// the fallback logic lives in livingdraft_seo_emit_bundle()).
	$tw_title  = livingdraft_seo_get( 'twitter_title', $post_id );
	$tw_desc   = livingdraft_seo_get( 'twitter_description', $post_id );
	$tw_image  = livingdraft_seo_get( 'twitter_image', $post_id );
	$robots    = livingdraft_seo_get( 'robots', $post_id );

	// Fallbacks so we always emit sensible defaults.
	if ( '' === $desc ) {
		$post = get_post( $post_id );
		$desc = wp_trim_words( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ), 30 );
	}
	if ( '' === $canonical ) {
		$canonical = get_permalink( $post_id );
	}

	$page_title = livingdraft_seo_get( 'title', $post_id ) ?: get_the_title( $post_id );

	if ( '' === $og_title ) { $og_title = $page_title; }
	if ( '' === $og_desc )  { $og_desc  = $desc; }
	if ( '' === $og_image && has_post_thumbnail( $post_id ) ) {
		$src = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'large' );
		if ( is_array( $src ) ) {
			$og_image = $src[0];
		}
	}

	livingdraft_seo_emit_bundle( array(
		'og_type'    => is_single() ? 'article' : 'website',
		'title'      => $og_title,
		'description'=> $desc,
		'canonical'  => $canonical,
		'og_title'   => $og_title,
		'og_desc'    => $og_desc,
		'og_image'   => $og_image,
		'twitter_title'       => $tw_title,
		'twitter_description' => $tw_desc,
		'twitter_image'       => $tw_image,
		'robots'     => $robots,
	) );

	// Article-specific fields.
	if ( is_singular( 'post' ) ) {
		$published = get_the_date( 'c', $post_id );

		// Prefer the update-log-derived modified time if available and
		// parseable. Falls back to WP's own post_modified when the log
		// returns empty or something that strtotime can't parse — without
		// this guard, a stale/empty log value would produce a 1970-01-01
		// article:modified_time tag on the front-end.
		$modified = get_the_modified_date( 'c', $post_id );
		if ( function_exists( 'livingdraft_last_changed_gmt' ) ) {
			$raw = livingdraft_last_changed_gmt( $post_id );
			if ( $raw ) {
				$ts = is_numeric( $raw ) ? (int) $raw : (int) strtotime( (string) $raw );
				if ( $ts > 0 ) {
					$modified = gmdate( 'c', $ts );
				}
			}
		}

		printf( '<meta property="article:published_time" content="%s">' . "\n", esc_attr( $published ) );
		printf( '<meta property="article:modified_time" content="%s">' . "\n", esc_attr( $modified ) );

		$author = get_the_author_meta( 'display_name', get_post_field( 'post_author', $post_id ) );
		if ( $author ) {
			printf( '<meta property="article:author" content="%s">' . "\n", esc_attr( $author ) );
		}
	}

	echo "<!-- /The Living Draft SEO -->\n\n";
}

/**
 * Emit the meta bundle for a category / tag / custom taxonomy
 * archive page.
 *
 * Fallback rules (each field, in order):
 *   title       → term_meta seo_title → term name
 *   description → term_meta seo_description → term description → auto-summary from most-recent posts
 *   canonical   → term_meta seo_canonical → get_term_link() (paginated-aware)
 *   og_image    → term_meta seo_og_image → featured image of newest post in the term (best-effort)
 */
function livingdraft_seo_print_head_term() {
	if ( ! function_exists( 'livingdraft_seo_term_get' ) ) {
		return;
	}
	$term = get_queried_object();
	if ( ! $term || empty( $term->term_id ) ) {
		return;
	}
	$term_id = (int) $term->term_id;

	$title     = livingdraft_seo_term_get( 'title',          $term_id );
	$desc      = livingdraft_seo_term_get( 'description',    $term_id );
	$canonical = livingdraft_seo_term_get( 'canonical',      $term_id );
	$og_title  = livingdraft_seo_term_get( 'og_title',       $term_id );
	$og_desc   = livingdraft_seo_term_get( 'og_description', $term_id );
	$og_image  = livingdraft_seo_term_get( 'og_image',       $term_id );
	$robots    = livingdraft_seo_term_get( 'robots',         $term_id );

	if ( '' === $title )     { $title     = $term->name; }
	if ( '' === $desc ) {
		$term_desc = wp_strip_all_tags( (string) $term->description );
		if ( '' !== trim( $term_desc ) ) {
			$desc = wp_trim_words( $term_desc, 30 );
		} else {
			// Last resort — a fresh 2-post excerpt so the page isn't blank.
			$desc = livingdraft_seo_term_autodescription( $term );
		}
	}
	if ( '' === $canonical ) {
		$canonical = livingdraft_seo_term_paginated_link( $term );
	}

	if ( '' === $og_title ) { $og_title = $title; }
	if ( '' === $og_desc )  { $og_desc  = $desc; }
	if ( '' === $og_image ) {
		$og_image = livingdraft_seo_term_fallback_image( $term );
	}

	livingdraft_seo_emit_bundle( array(
		'og_type'     => 'website',
		'title'       => $title,
		'description' => $desc,
		'canonical'   => $canonical,
		'og_title'    => $og_title,
		'og_desc'     => $og_desc,
		'og_image'    => $og_image,
		'robots'      => $robots,
	) );

	echo "<!-- /The Living Draft SEO -->\n\n";
}

/**
 * Shared emitter — takes an associative array with all resolved
 * values and prints the meta / OG / Twitter / canonical / robots
 * block. Callers are responsible for the trailing "</The Living
 * Draft SEO>" comment.
 */
function livingdraft_seo_emit_bundle( $b ) {
	echo "\n<!-- The Living Draft SEO -->\n";

	if ( '' !== $b['description'] ) {
		printf( '<meta name="description" content="%s">' . "\n", esc_attr( $b['description'] ) );
	}
	if ( '' !== $b['canonical'] ) {
		printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $b['canonical'] ) );
	}
	if ( is_array( $b['robots'] ) && ! empty( $b['robots'] ) ) {
		printf( '<meta name="robots" content="%s">' . "\n", esc_attr( implode( ', ', $b['robots'] ) ) );
	}

	// Open Graph.
	printf( '<meta property="og:type" content="%s">' . "\n", esc_attr( $b['og_type'] ) );
	printf( '<meta property="og:site_name" content="%s">' . "\n", esc_attr( get_bloginfo( 'name' ) ) );
	printf( '<meta property="og:title" content="%s">' . "\n", esc_attr( $b['og_title'] ) );
	if ( '' !== $b['og_desc'] ) {
		printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( $b['og_desc'] ) );
	}
	if ( '' !== $b['canonical'] ) {
		printf( '<meta property="og:url" content="%s">' . "\n", esc_url( $b['canonical'] ) );
	}
	if ( '' !== $b['og_image'] ) {
		printf( '<meta property="og:image" content="%s">' . "\n", esc_url( $b['og_image'] ) );
	}

	// Twitter Card — v3.3.0: use twitter-specific fields when set, fall
	// back to OG. This lets a writer punch up a headline for Twitter
	// (shorter, sharper) without breaking the Facebook / LinkedIn share
	// where the more measured OG copy is what they want.
	$tw_title = ( isset( $b['twitter_title'] ) && '' !== $b['twitter_title'] )
		? $b['twitter_title']
		: $b['og_title'];
	$tw_desc  = ( isset( $b['twitter_description'] ) && '' !== $b['twitter_description'] )
		? $b['twitter_description']
		: $b['og_desc'];
	$tw_image = ( isset( $b['twitter_image'] ) && '' !== $b['twitter_image'] )
		? $b['twitter_image']
		: $b['og_image'];

	printf( '<meta name="twitter:card" content="%s">' . "\n", '' !== $tw_image ? 'summary_large_image' : 'summary' );
	printf( '<meta name="twitter:title" content="%s">' . "\n", esc_attr( $tw_title ) );
	if ( '' !== $tw_desc ) {
		printf( '<meta name="twitter:description" content="%s">' . "\n", esc_attr( $tw_desc ) );
	}
	if ( '' !== $tw_image ) {
		printf( '<meta name="twitter:image" content="%s">' . "\n", esc_url( $tw_image ) );
	}
	// Optional site handle from Settings → General → Social — kept
	// filterable so a theme or must-use plugin can supply it without
	// forcing site owners into yet another settings page.
	$tw_handle = (string) apply_filters( 'livingdraft_seo_twitter_site', get_option( 'livingdraft_seo_twitter_site', '' ) );
	if ( '' !== $tw_handle ) {
		$tw_handle = '@' . ltrim( $tw_handle, '@' );
		printf( '<meta name="twitter:site" content="%s">' . "\n", esc_attr( $tw_handle ) );
	}
}

/**
 * Build a canonical link that respects pagination — /category/foo/page/2/
 * should point to itself, not /category/foo/.
 */
function livingdraft_seo_term_paginated_link( $term ) {
	$base = get_term_link( $term );
	if ( is_wp_error( $base ) ) {
		return '';
	}
	$paged = (int) get_query_var( 'paged' );
	if ( $paged > 1 ) {
		return trailingslashit( $base ) . 'page/' . $paged . '/';
	}
	return $base;
}

/**
 * Auto-summary fallback when the term has no description of its own
 * and no override. Composed from the titles of the two newest posts
 * in the term — makes the archive look intentional, not empty.
 */
function livingdraft_seo_term_autodescription( $term ) {
	$posts = get_posts( array(
		'posts_per_page' => 2,
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'tax_query'      => array(
			array(
				'taxonomy' => $term->taxonomy,
				'field'    => 'term_id',
				'terms'    => (int) $term->term_id,
			),
		),
		'no_found_rows'      => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	) );
	if ( empty( $posts ) ) {
		return sprintf(
			/* translators: %s: term name. */
			__( 'Coverage and archive of stories filed under %s.', 'livingdraft-core' ),
			$term->name
		);
	}
	$titles = array();
	foreach ( $posts as $p ) {
		$titles[] = get_the_title( $p );
	}
	return sprintf(
		/* translators: 1: term name, 2: comma-joined post titles. */
		__( 'Stories filed under %1$s. Recent: %2$s.', 'livingdraft-core' ),
		$term->name,
		implode( ', ', $titles )
	);
}

/**
 * Best-effort OG image for a term archive — the featured image of the
 * most recent post in the term. Cheap: one small query.
 */
function livingdraft_seo_term_fallback_image( $term ) {
	$posts = get_posts( array(
		'posts_per_page'         => 1,
		'post_type'              => 'post',
		'post_status'            => 'publish',
		'tax_query'              => array(
			array(
				'taxonomy' => $term->taxonomy,
				'field'    => 'term_id',
				'terms'    => (int) $term->term_id,
			),
		),
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
		'meta_query'             => array(
			array(
				'key'     => '_thumbnail_id',
				'compare' => 'EXISTS',
			),
		),
	) );
	if ( empty( $posts ) ) {
		return '';
	}
	$src = wp_get_attachment_image_src( get_post_thumbnail_id( $posts[0]->ID ), 'large' );
	return is_array( $src ) ? $src[0] : '';
}

/**
 * When our output is on, silence WordPress's default core canonical to
 * avoid a duplicate <link rel="canonical">. Our own emission covers it.
 */
function livingdraft_seo_maybe_remove_core_canonical() {
	if ( livingdraft_seo_output_enabled() && ! is_admin() ) {
		remove_action( 'wp_head', 'rel_canonical' );
	}
}
add_action( 'template_redirect', 'livingdraft_seo_maybe_remove_core_canonical' );
