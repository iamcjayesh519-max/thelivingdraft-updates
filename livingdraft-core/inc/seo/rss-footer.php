<?php
/**
 * RSS footer content.
 *
 * Appends a small footer to every RSS item in every feed the site
 * publishes. Two purposes:
 *
 *   1. When a scraper republishes the feed verbatim (and most do),
 *      the footer includes a canonical link back to the original.
 *      Google is smart enough to treat that link as a strong
 *      canonical signal, so the original post keeps the authority
 *      rather than the scraper's copy.
 *
 *   2. Attribution: a copyright line or "originally appeared on X"
 *      byline shows even in aggregators like Feedly.
 *
 * The template is user-configurable and supports these variables:
 *   %%link%%       — permalink of the original post
 *   %%sitename%%   — the site's name
 *   %%siteurl%%    — the site's URL
 *   %%author%%     — post author's display name
 *   %%title%%      — post title
 *   %%year%%       — current year
 *
 * Yoast has this feature too — it's one of their oldest, and one of
 * the most consistently praised as a small but real win.
 *
 * @package LivingDraftCore
 * @since   3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default template used when nothing has been configured. Written
 * as plain HTML because feed readers accept it.
 */
function livingdraft_seo_rss_footer_default_template() {
	return '<hr><p><em>' . esc_html__(
		'The post %%title%% originally appeared on %%sitename%%. Read the canonical version: %%link%%',
		'livingdraft-core'
	) . '</em></p>';
}

/**
 * Read the configured template. Empty string means "disabled" and
 * nothing gets appended.
 */
function livingdraft_seo_rss_footer_template() {
	$saved = get_option( 'livingdraft_seo_rss_footer', null );
	if ( null === $saved ) {
		// First time — return default so it's on out of the box. A user
		// who saves the settings tab with the field cleared gets the
		// empty string stored, which is respected below as "off".
		return livingdraft_seo_rss_footer_default_template();
	}
	return (string) $saved;
}

/**
 * Substitute variables in the template, using $post as the source.
 */
function livingdraft_seo_rss_footer_render( $template, $post ) {
	if ( '' === trim( $template ) ) {
		return '';
	}
	$link = esc_url( get_permalink( $post ) );
	// The %%link%% variable is intentionally emitted as an <a> when
	// the surrounding template just references it plainly (a raw URL
	// isn't clickable in every feed reader). Users who want just the
	// URL can use %%link_raw%%.
	$link_html = sprintf( '<a href="%1$s">%1$s</a>', $link );

	$vars = array(
		'%%link%%'     => $link_html,
		'%%link_raw%%' => $link,
		'%%sitename%%' => esc_html( get_bloginfo( 'name' ) ),
		'%%siteurl%%'  => esc_url( home_url( '/' ) ),
		'%%author%%'   => esc_html( get_the_author_meta( 'display_name', (int) $post->post_author ) ),
		'%%title%%'    => esc_html( get_the_title( $post ) ),
		'%%year%%'     => (string) gmdate( 'Y' ),
	);

	return strtr( $template, $vars );
}

/**
 * Append to the full-content feed body.
 */
function livingdraft_seo_rss_footer_the_content_feed( $content, $feed_type = null ) {
	global $post;
	if ( ! is_feed() || ! $post ) {
		return $content;
	}
	$footer = livingdraft_seo_rss_footer_render( livingdraft_seo_rss_footer_template(), $post );
	if ( '' === $footer ) {
		return $content;
	}
	return $content . "\n" . $footer;
}
add_filter( 'the_content_feed', 'livingdraft_seo_rss_footer_the_content_feed', 20, 2 );

/**
 * Append to excerpt feeds too — some sites configure feeds as excerpts
 * to reduce scrape value, but even those should carry attribution.
 */
function livingdraft_seo_rss_footer_the_excerpt_rss( $excerpt ) {
	global $post;
	if ( ! is_feed() || ! $post ) {
		return $excerpt;
	}
	$footer = livingdraft_seo_rss_footer_render( livingdraft_seo_rss_footer_template(), $post );
	if ( '' === $footer ) {
		return $excerpt;
	}
	return $excerpt . "\n" . $footer;
}
add_filter( 'the_excerpt_rss', 'livingdraft_seo_rss_footer_the_excerpt_rss', 20 );

/**
 * Save handler for the Webmasters settings tab.
 */
function livingdraft_seo_rss_footer_handle_save() {
	if ( ! isset( $_POST['ld_seo_rss_footer_save'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'ld_seo_rss_footer' );

	$val = isset( $_POST['rss_footer'] ) ? wp_kses_post( wp_unslash( $_POST['rss_footer'] ) ) : '';
	update_option( 'livingdraft_seo_rss_footer', $val, false );

	wp_safe_redirect( add_query_arg( array( 'page' => 'livingdraft-seo', 'tab' => 'webmasters', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
	exit;
}
add_action( 'admin_init', 'livingdraft_seo_rss_footer_handle_save' );
