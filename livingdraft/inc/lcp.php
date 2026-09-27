<?php
/**
 * Protecting the largest image on the page.
 *
 * The lead image is almost always the Largest Contentful Paint element — the
 * one Google times your page by. The theme already marks it eager and high
 * priority, but a caching or image plugin can rewrite the markup afterwards
 * and lazy-load it anyway. That turns a 20ms download into a 1.4 second wait,
 * because the browser is not allowed to start until JavaScript has run.
 *
 * So this does two things: tells every lazy-loader by name to leave that one
 * image alone, and announces it in the head so the download can begin before
 * the markup is even reached.
 *
 * @package LivingDraft
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attributes that keep an image out of every lazy-loader in common use.
 *
 * Each plugin looks for a different marker, and none of them agree, so the
 * only reliable answer is to carry all of them.
 *
 * @return array
 */
function livingdraft_no_lazy_attrs() {
	return array(
		'loading'       => 'eager',
		'decoding'      => 'sync',
		'fetchpriority' => 'high',
		'data-no-lazy'  => '1',        // LiteSpeed Cache.
		'data-skip-lazy' => '1',       // WP Rocket.
		'class'         => 'skip-lazy no-lazyload lcp-image', // Jetpack, Smush, Autoptimize.
	);
}

/**
 * Which image is the LCP element on this page?
 *
 * @return int Attachment ID, or 0.
 */
function livingdraft_lcp_image_id() {
	if ( is_singular() && has_post_thumbnail() ) {
		return (int) get_post_thumbnail_id();
	}

	if ( ( is_home() || is_front_page() ) && ! is_paged() ) {
		global $wp_query;

		if ( ! empty( $wp_query->posts[0] ) ) {
			$first = $wp_query->posts[0];

			if ( has_post_thumbnail( $first ) ) {
				return (int) get_post_thumbnail_id( $first );
			}
		}
	}

	return 0;
}

/**
 * Announce the lead image in the head.
 *
 * Without this the browser cannot know the image exists until it has parsed
 * down to it. The preload uses the same srcset and sizes as the tag itself,
 * so the browser picks one file and downloads it once.
 *
 * === WHY THE SIZES HINT HERE MUST MATCH THE IMG TAG EXACTLY ===
 *
 * Modern browsers use `imagesrcset` + `imagesizes` on <link rel=preload> to
 * pick which variant to fetch — the same way they'd pick from an <img srcset>.
 * If the preload's sizes value differs from the actual <img>'s sizes even
 * slightly, the browser picks two different variants: one for the preload,
 * one for the img tag when it's finally parsed. Result: the image gets
 * downloaded TWICE, doubling network cost and often making LCP worse.
 *
 * The values below must match single.php's the_post_thumbnail() sizes arg.
 * A LIVINGDRAFT_LCP_SIZES filter is provided for future template overrides
 * so this doesn't need to be edited every time the img sizes hint changes.
 */
function livingdraft_preload_lcp() {
	$id = livingdraft_lcp_image_id();

	if ( ! $id ) {
		return;
	}

	$src = wp_get_attachment_image_src( $id, 'livingdraft-lede' );

	if ( ! $src ) {
		return;
	}

	$srcset = wp_get_attachment_image_srcset( $id, 'livingdraft-lede' );

	/**
	 * The sizes hint for the LCP image preload. MUST match the img tag's
	 * `sizes` attribute exactly, or the browser downloads two variants.
	 *
	 * Default matches single.php:48 (Phase 2 update).
	 *
	 * @param string $sizes Default sizes hint.
	 * @param int    $id    Attachment ID being preloaded.
	 */
	$sizes = apply_filters(
		'livingdraft_lcp_sizes',
		'(min-width: 900px) 750px, 92vw',
		$id
	);

	printf(
		'<link rel="preload" as="image" href="%s"%s%s fetchpriority="high">' . "\n",
		esc_url( $src[0] ),
		$srcset ? ' imagesrcset="' . esc_attr( $srcset ) . '"' : '',
		$srcset ? ' imagesizes="' . esc_attr( $sizes ) . '"' : ''
	);
}
add_action( 'wp_head', 'livingdraft_preload_lcp', 2 );

/* ------------------------------------------------------------------
 * Telling the plugins directly
 * ------------------------------------------------------------------ */

/**
 * LiteSpeed Cache: never lazy-load the lead image.
 *
 * @param array $excludes Existing exclusions.
 * @return array
 */
function livingdraft_litespeed_lazy_excludes( $excludes ) {
	if ( ! is_array( $excludes ) ) {
		$excludes = array();
	}

	$id = livingdraft_lcp_image_id();

	if ( $id ) {
		$src = wp_get_attachment_image_src( $id, 'livingdraft-lede' );
		if ( $src ) {
			$excludes[] = basename( $src[0] );
		}
	}

	// Belt and braces: anything the theme has marked as the LCP element.
	$excludes[] = 'lcp-image';

	return array_unique( $excludes );
}
add_filter( 'litespeed_media_lazy_img_excludes', 'livingdraft_litespeed_lazy_excludes' );

/**
 * Smush and WP Rocket both read a class list.
 *
 * @param array $classes Excluded classes.
 * @return array
 */
function livingdraft_lazy_class_excludes( $classes ) {
	if ( ! is_array( $classes ) ) {
		$classes = array();
	}

	$classes[] = 'lcp-image';
	$classes[] = 'skip-lazy';

	return array_unique( $classes );
}
add_filter( 'wp_smush_skip_image_classes', 'livingdraft_lazy_class_excludes' );
add_filter( 'rocket_lazyload_excluded_attributes', 'livingdraft_lazy_class_excludes' );

/**
 * WordPress core decides lazy loading itself from 6.3. Make sure it agrees
 * with us about the lead image.
 *
 * @param array  $attrs   Loading attributes.
 * @param string $tag     Tag name.
 * @param string $context Context.
 * @return array
 */
function livingdraft_core_loading_attrs( $attrs, $tag = '', $context = '' ) {
	if ( 'img' !== $tag || ! is_array( $attrs ) ) {
		return $attrs;
	}

	if ( isset( $attrs['class'] ) && false !== strpos( (string) $attrs['class'], 'lcp-image' ) ) {
		$attrs['loading']       = 'eager';
		$attrs['fetchpriority'] = 'high';
	}

	return $attrs;
}
add_filter( 'wp_get_loading_optimization_attributes', 'livingdraft_core_loading_attrs', 10, 3 );
