<?php
/**
 * Consent — retired in 4.9.0.
 *
 * === WHAT CHANGED, AND WHY ===
 *
 * The cookie banner is gone. No banner is printed, nothing is held back,
 * and Google's tag (added by Site Kit) loads and measures every reader in
 * full from the first page view.
 *
 * Three things are left in this file on purpose:
 *
 *   1. Google Consent Mode is told "granted" at the very top of <head>. If
 *      Site Kit's own consent mode, or any other tool, has set a "denied"
 *      default, Analytics would otherwise keep running cookieless and under-
 *      count exactly as it did behind the banner. The update is repeated in
 *      the footer so it wins over a default printed after ours.
 *
 *   2. The old helper functions still exist and answer "yes", so a child
 *      theme, snippet or older template that calls them keeps working.
 *
 *   3. The [ld_cookie_settings] shortcode returns an empty string, so a page
 *      or footer widget that still contains it does not print raw brackets.
 *
 * Caches are purged once after this update so no visitor is served an old
 * cached page with the banner still baked into it.
 *
 * A note that belongs in the code rather than in a settings screen: whether
 * a site may measure without asking is a legal question that depends on
 * where its readers are. If the site later needs a banner again for EU/UK
 * readers, the 4.8.0 version of this file is in the update repository's
 * history and can be restored as-is.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LD_CONSENT_SETTINGS', 'livingdraft_consent_settings' );

/* ==================================================================
 * 1. BACK-COMPAT HELPERS — always "yes"
 * ================================================================== */

/**
 * @return array{mode:string,ads:bool}
 */
function livingdraft_consent_settings() {
	return array(
		'mode' => 'off',
		'ads'  => (bool) apply_filters( 'livingdraft_grant_ad_storage', true ),
	);
}

/** @return bool Always true since 4.9.0. */
function livingdraft_has_consent() {
	return true;
}

/** @return bool Always false since 4.9.0. */
function livingdraft_refused_consent() {
	return false;
}

/** @return bool The banner no longer exists. */
function livingdraft_consent_enabled() {
	return false;
}

/** @return string[] Nothing is gated. */
function livingdraft_consent_gated_handles() {
	return array();
}

/* ==================================================================
 * 2. GOOGLE CONSENT MODE: GRANTED
 * ================================================================== */

/**
 * Consent Mode signals for "measure everything".
 *
 * Ad storage follows the livingdraft_grant_ad_storage filter (default on,
 * so AdSense / Google Ads measure normally if the site runs them).
 *
 * @return array
 */
function livingdraft_consent_granted_signals() {
	$ads = livingdraft_consent_settings()['ads'] ? 'granted' : 'denied';

	return array(
		'analytics_storage'     => 'granted',
		'ad_storage'            => $ads,
		'ad_user_data'          => $ads,
		'ad_personalization'    => $ads,
		'functionality_storage' => 'granted',
		'security_storage'      => 'granted',
	);
}

/**
 * Default "granted", before any Google tag.
 *
 * Priority 0 puts this ahead of every enqueued script. The data-* attributes
 * and `nowprocket` stop LiteSpeed, WP Rocket and Cloudflare Rocket Loader
 * from delaying it.
 */
function livingdraft_consent_mode_defaults() {
	if ( is_admin() ) {
		return;
	}
	?>
<script id="ld-consent-mode" data-no-optimize="1" data-no-defer="1" data-no-minify="1" data-cfasync="false" nowprocket>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('consent', 'default', <?php echo wp_json_encode( livingdraft_consent_granted_signals() ); ?>);
</script>
	<?php
}
add_action( 'wp_head', 'livingdraft_consent_mode_defaults', 0 );

/**
 * Repeat as an update, late, so it overrides a "denied" default that some
 * other plugin printed after ours.
 */
function livingdraft_consent_mode_update() {
	if ( is_admin() ) {
		return;
	}
	?>
<script id="ld-consent-update" data-no-optimize="1" data-cfasync="false" nowprocket>
window.dataLayer = window.dataLayer || [];
(function(){ function g(){dataLayer.push(arguments);} g('consent', 'update', <?php echo wp_json_encode( livingdraft_consent_granted_signals() ); ?>); })();
</script>
	<?php
}
add_action( 'wp_footer', 'livingdraft_consent_mode_update', 1 );

/* ==================================================================
 * 3. THE OLD SHORTCODE — prints nothing
 * ================================================================== */

add_shortcode( 'ld_cookie_settings', '__return_empty_string' );

/* ==================================================================
 * 4. CACHES
 * ================================================================== */

/**
 * Clear the caches we can reach.
 */
function livingdraft_core_purge_caches() {
	if ( defined( 'LSCWP_V' ) ) {
		do_action( 'litespeed_purge_all' );
	}

	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}

	if ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all();
	}

	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
	}
}
register_activation_hook( LIVINGDRAFT_CORE_FILE, 'livingdraft_core_purge_caches' );

/**
 * Purge once per plugin version, so pages cached with the banner go away.
 * Also removes the retired consent settings option.
 */
function livingdraft_core_purge_after_update() {
	if ( get_option( 'livingdraft_consent_purged_for' ) === LIVINGDRAFT_CORE_VERSION ) {
		return;
	}

	livingdraft_core_purge_caches();
	delete_option( LD_CONSENT_SETTINGS );
	update_option( 'livingdraft_consent_purged_for', LIVINGDRAFT_CORE_VERSION, false );
	delete_option( 'livingdraft_cache_notice_seen' );
}
add_action( 'admin_init', 'livingdraft_core_purge_after_update' );

/**
 * One-time reminder about caches outside WordPress.
 */
function livingdraft_core_cache_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
		return;
	}

	if ( get_option( 'livingdraft_cache_notice_seen' ) ) {
		return;
	}

	$dismiss = wp_nonce_url( add_query_arg( 'ld_dismiss_cache', '1' ), 'ld_dismiss_cache' );

	printf(
		'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
		esc_html__( 'The Living Draft:', 'livingdraft-core' ),
		esc_html__( 'The cookie banner has been removed and site analytics are now on. WordPress caching plugins were cleared automatically. If you use Cloudflare or a host-level cache, purge it once so no reader is served an old page with the banner.', 'livingdraft-core' ),
		esc_url( $dismiss ),
		esc_html__( 'Done — dismiss', 'livingdraft-core' )
	);
}
add_action( 'admin_notices', 'livingdraft_core_cache_notice' );

/**
 * Remember the dismissal.
 */
function livingdraft_core_dismiss_cache_notice() {
	if ( ! isset( $_GET['ld_dismiss_cache'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	check_admin_referer( 'ld_dismiss_cache' );

	update_option( 'livingdraft_cache_notice_seen', 1, false );

	wp_safe_redirect( remove_query_arg( array( 'ld_dismiss_cache', '_wpnonce' ) ) );
	exit;
}
add_action( 'admin_init', 'livingdraft_core_dismiss_cache_notice' );

/**
 * Old bookmarks to Settings → Analytics & consent land on the new
 * Analytics settings instead.
 */
function livingdraft_consent_legacy_redirect() {
	if ( isset( $_GET['page'], $_GET['section'] ) && 'livingdraft-settings' === $_GET['page'] && 'consent' === $_GET['section'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect( admin_url( 'admin.php?page=livingdraft-settings&section=analytics' ) );
		exit;
	}
}
add_action( 'admin_init', 'livingdraft_consent_legacy_redirect', 1 );
