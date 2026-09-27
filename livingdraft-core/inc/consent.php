<?php
/**
 * Cookie consent.
 *
 * A banner that shows a message and then loads Google Analytics anyway is
 * legally worthless — under GDPR and under India's DPDP Act, consent has to
 * come BEFORE the tracking. So this actually holds the Analytics script back
 * until the reader agrees, and only then lets it through.
 *
 * The wording on your Cookie Notice page is a legal question, not a code one.
 * This handles the technical half correctly.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Has this reader agreed?
 *
 * @return bool
 */
function livingdraft_has_consent() {
	return isset( $_COOKIE['ld_consent'] ) && 'yes' === $_COOKIE['ld_consent']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
}

/**
 * Has this reader actively refused? Different from "has not answered yet".
 *
 * @return bool
 */
function livingdraft_refused_consent() {
	return isset( $_COOKIE['ld_consent'] ) && 'no' === $_COOKIE['ld_consent']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
}

/**
 * Is the banner switched on at all?
 *
 * @return bool
 */
function livingdraft_consent_enabled() {
	return (bool) get_theme_mod( 'livingdraft_consent_on', true );
}

/**
 * Hold back every analytics script until consent is given.
 *
 * Site Kit registers Google's tag under a handful of handles depending on
 * version, so all the known ones are listed.
 */
function livingdraft_core_block_trackers() {
	if ( ! livingdraft_consent_enabled() || livingdraft_has_consent() ) {
		return;
	}

	$handles = apply_filters(
		'livingdraft_blocked_scripts',
		array(
			'google_gtagjs',
			'googlesitekit-events-provider-contact-form-7',
			'googlesitekit-consent-mode',
			'google-tag-manager',
			'gtm4wp',
		)
	);

	foreach ( $handles as $handle ) {
		wp_dequeue_script( $handle );
		wp_deregister_script( $handle );
	}
}
add_action( 'wp_enqueue_scripts', 'livingdraft_core_block_trackers', 999 );
add_action( 'wp_print_scripts', 'livingdraft_core_block_trackers', 1 );

/**
 * Print the banner.
 */
function livingdraft_core_consent_banner() {
	if ( ! livingdraft_consent_enabled() || is_admin() ) {
		return;
	}

	/*
	 * Note what is NOT here: a check for whether this reader has already
	 * answered. That check used to live here, and it was wrong.
	 *
	 * A page cache (LiteSpeed, WP Rocket, Cloudflare) saves the finished HTML
	 * and serves the same copy to everybody. If PHP decides the banner is in
	 * the page, it is in the page for every visitor afterwards — including the
	 * one who just accepted. The banner would never go away.
	 *
	 * So the markup always ships, hidden, and JavaScript decides in the
	 * reader's own browser. That works whether the page came from PHP or from
	 * a cache, which is the whole point.
	 */
	$text = get_theme_mod(
		'livingdraft_consent_text',
		__( 'We use cookies to understand which stories are read. Nothing is loaded until you choose.', 'livingdraft-core' )
	);

	$policy = get_theme_mod( 'livingdraft_consent_link', '' );
	if ( ! $policy ) {
		$policy = get_privacy_policy_url();
	}
	?>
	<div class="ld-consent" id="ld-consent" role="dialog" aria-live="polite" hidden
		data-ld-consent-banner
		aria-label="<?php esc_attr_e( 'Cookie choice', 'livingdraft-core' ); ?>">
		<div class="ld-consent-inner">
			<p class="ld-consent-text">
				<?php echo esc_html( $text ); ?>
				<?php if ( $policy ) : ?>
					<a href="<?php echo esc_url( $policy ); ?>"><?php esc_html_e( 'Read the notice', 'livingdraft-core' ); ?></a>
				<?php endif; ?>
			</p>
			<div class="ld-consent-actions">
				<button type="button" class="ld-consent-no" data-ld-consent="no">
					<?php esc_html_e( 'Decline', 'livingdraft-core' ); ?>
				</button>
				<button type="button" class="ld-consent-yes" data-ld-consent="yes">
					<?php esc_html_e( 'Accept', 'livingdraft-core' ); ?>
				</button>
			</div>
		</div>
	</div>
	<?php
}
add_action( 'wp_footer', 'livingdraft_core_consent_banner', 5 );

/* ------------------------------------------------------------------
 * Living with a page cache
 * ------------------------------------------------------------------ */

/**
 * Keep a separate cached copy for readers who have answered.
 *
 * Without this, LiteSpeed serves one copy to everybody — so either nobody
 * gets Analytics or everybody does, regardless of what they clicked.
 *
 * @param array $cookies Cookies the cache already varies on.
 * @return array
 */
function livingdraft_core_litespeed_vary( $cookies ) {
	$cookies[] = 'ld_consent';
	return array_unique( $cookies );
}
add_filter( 'litespeed_vary_cookies', 'livingdraft_core_litespeed_vary' );
add_filter( 'litespeed_api_vary', 'livingdraft_core_litespeed_vary' );

/**
 * The same for WP Rocket.
 *
 * @param array $cookies Dynamic cookies.
 * @return array
 */
function livingdraft_core_rocket_vary( $cookies ) {
	$cookies[] = 'ld_consent';
	return array_unique( $cookies );
}
add_filter( 'rocket_cache_dynamic_cookies', 'livingdraft_core_rocket_vary' );

/**
 * Some caches read this header instead.
 */
function livingdraft_core_vary_header() {
	if ( ! livingdraft_consent_enabled() || is_admin() || headers_sent() ) {
		return;
	}

	header( 'Vary: Cookie', false );
}
add_action( 'send_headers', 'livingdraft_core_vary_header' );

/**
 * Warn in the admin if a page cache is running, because the cache has to be
 * cleared once after this update or the old banner markup stays in it.
 */
function livingdraft_core_cache_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// One screen is enough. This used to print on every admin page.
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
		return;
	}

	$caches = array(
		'LiteSpeed Cache' => defined( 'LSCWP_V' ),
		'WP Rocket'       => defined( 'WP_ROCKET_VERSION' ),
		'W3 Total Cache'  => defined( 'W3TC' ),
		'WP Super Cache'  => defined( 'WPCACHEHOME' ),
	);

	$found = array_keys( array_filter( $caches ) );

	if ( empty( $found ) || get_option( 'livingdraft_cache_notice_seen' ) ) {
		return;
	}

	$dismiss = wp_nonce_url(
		add_query_arg( 'ld_dismiss_cache', '1' ),
		'ld_dismiss_cache'
	);

	printf(
		'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
		esc_html__( 'The Living Draft:', 'livingdraft-core' ),
		esc_html(
			sprintf(
				/* translators: %s: name of the caching plugin. */
				__( 'You are running %s. Clear its cache once now, so the cookie banner update reaches your readers.', 'livingdraft-core' ),
				implode( ', ', $found )
			)
		),
		esc_url( $dismiss ),
		esc_html__( 'Dismiss this notice', 'livingdraft-core' )
	);
}
add_action( 'admin_notices', 'livingdraft_core_cache_notice' );

/**
 * Actually remember the dismissal.
 *
 * The notice tested livingdraft_cache_notice_seen, but nothing ever wrote it,
 * and core's is-dismissible only hides a notice for the current page load. On
 * a site with a page cache the warning therefore came back on every single
 * admin screen, permanently.
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
 * Clear the caches we can reach, when the plugin is updated or activated.
 */
function livingdraft_core_purge_caches() {
	// has_action() returns priority-or-false, and LiteSpeed's listener may
	// not be registered at activation time. defined() is the reliable check.
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
