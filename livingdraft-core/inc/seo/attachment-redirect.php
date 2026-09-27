<?php
/**
 * Attachment page redirect.
 *
 * WordPress gives every uploaded file its own attachment page URL —
 * `/?attachment_id=123` or `/2026/03/photo-slug/`. That page competes
 * with the actual post the image lives on for the same query, gets
 * indexed by Google, and shows up as thin duplicate content in Search
 * Console coverage reports.
 *
 * Yoast and Rank Math both fix this by default. Now we do too.
 *
 * Behaviour (all default-on, can be turned off per site):
 *   - Attachment page requested → 301 to the parent post URL, when
 *     the attachment has a parent.
 *   - Orphan attachment (no parent) → 301 to the actual file URL
 *     (better than a bare attachment page with WP's default template).
 *
 * The redirect is 301, so search engines pass link equity to the
 * parent post. That's the whole point.
 *
 * @package LivingDraftCore
 * @since   3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether attachment redirect is enabled. Default: on.
 * Stored under `livingdraft_seo_attachment_redirect` (bool option).
 */
function livingdraft_seo_attachment_redirect_enabled() {
	$val = get_option( 'livingdraft_seo_attachment_redirect', 'on' );
	return 'on' === $val || '1' === (string) $val || true === $val;
}

/**
 * The redirect. Runs on template_redirect so we intercept before
 * WordPress renders the attachment template.
 *
 * Bail conditions:
 *   - Not an attachment page → nothing to do.
 *   - Feeds and REST → let them through as normal.
 *   - Admin / cron → not our concern.
 *   - Feature toggled off in Settings.
 *   - Explicitly allowed via `livingdraft_seo_attachment_redirect_allow`
 *     filter — themes with a legitimate attachment gallery use case
 *     can opt individual attachments out.
 */
function livingdraft_seo_attachment_redirect() {
	if ( is_admin() || wp_doing_cron() || wp_doing_ajax() ) {
		return;
	}
	if ( ! is_attachment() ) {
		return;
	}
	if ( is_feed() ) {
		return;
	}
	if ( ! livingdraft_seo_attachment_redirect_enabled() ) {
		return;
	}

	$attachment_id = (int) get_queried_object_id();
	if ( ! $attachment_id ) {
		return;
	}

	// Escape hatch for themes / plugins with an actual attachment
	// gallery template they want to keep. Pass the attachment ID and
	// return false to skip the redirect.
	if ( ! apply_filters( 'livingdraft_seo_attachment_redirect_allow', true, $attachment_id ) ) {
		return;
	}

	$attachment = get_post( $attachment_id );
	if ( ! $attachment ) {
		return;
	}

	$target = '';

	// Prefer parent post URL — that's where the attachment actually
	// appears in context.
	if ( $attachment->post_parent ) {
		$parent_link = get_permalink( $attachment->post_parent );
		if ( $parent_link ) {
			$target = $parent_link;
		}
	}

	// Orphan attachment — 301 to the actual file. Better than the
	// default WP attachment page which is a thin dedicated wrapper.
	if ( '' === $target ) {
		$file_url = wp_get_attachment_url( $attachment_id );
		if ( $file_url ) {
			$target = $file_url;
		}
	}

	// If we still have nothing (attachment record has no file — shouldn't
	// happen, but defensively) fall back to the site root rather than
	// letting the attachment page render.
	if ( '' === $target ) {
		$target = home_url( '/' );
	}

	wp_safe_redirect( $target, 301 );
	exit;
}
add_action( 'template_redirect', 'livingdraft_seo_attachment_redirect', 1 );

/**
 * Save handler for the Webmasters settings tab toggle.
 */
function livingdraft_seo_attachment_redirect_handle_save() {
	if ( ! isset( $_POST['ld_seo_attachment_save'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'ld_seo_attachment' );

	$on = isset( $_POST['attachment_redirect'] ) && '1' === (string) $_POST['attachment_redirect'];
	update_option( 'livingdraft_seo_attachment_redirect', $on ? 'on' : 'off', false );

	wp_safe_redirect( add_query_arg( array( 'page' => 'livingdraft-seo', 'tab' => 'webmasters', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
	exit;
}
add_action( 'admin_init', 'livingdraft_seo_attachment_redirect_handle_save' );
