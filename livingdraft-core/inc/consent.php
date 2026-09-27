<?php
/**
 * Cookie consent, and Google Analytics that actually counts.
 *
 * === WHAT CHANGED IN 4.6.1, AND WHY ===
 *
 * Until 4.6.0 the decision was made in PHP: if the reader had no
 * `ld_consent=yes` cookie, Site Kit's Google tag was taken out of the page.
 * That is only correct when PHP runs for every visitor, and on this site it
 * does not. A page cache (LiteSpeed, WP Rocket, Cloudflare, the host's own)
 * builds the page once — without the tag, because the cache has no cookie —
 * and serves that copy to everybody, including readers who pressed Accept.
 * `Vary: Cookie` was meant to split the cache, but Cloudflare and most host
 * caches ignore it. Result: Analytics recorded almost nobody.
 *
 * It also removed `googlesitekit-consent-mode`, which is Google Consent Mode
 * — the very mechanism built to solve this properly.
 *
 * Now the page is the same for everybody, and the decision is made in the
 * reader's own browser:
 *
 *   1. A tiny script in <head>, printed before any Google tag, sets Google
 *      Consent Mode v2. It reads the reader's cookie right there, in the
 *      browser, so a cached page still gets the right answer.
 *   2. Site Kit's tag is always on the page. Until the reader accepts, it
 *      may not store or read cookies.
 *   3. Pressing Accept switches consent on immediately. No reload.
 *
 * Two modes, chosen under The Living Draft → Settings → Analytics & consent:
 *
 *   anonymous (default)  Google's tag loads, but before consent it only
 *                        sends cookieless pings — no identifier, nothing
 *                        stored on the device. Google can model the visits
 *                        of readers who never answer the banner.
 *   strict               Google's tag is not even downloaded until Accept.
 *                        Still cache-proof: the tag is shipped inert and the
 *                        browser switches it on.
 *
 * Which one your policy needs is a legal question, not a code one.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LD_CONSENT_SETTINGS', 'livingdraft_consent_settings' );

/* ==================================================================
 * 1. SETTINGS AND HELPERS
 * ================================================================== */

/**
 * Plugin-side consent settings.
 *
 * The banner's on/off switch and wording stay in the Customizer, where they
 * have always been. These are the technical choices.
 *
 * @since 4.6.1
 * @return array{mode:string,ads:bool}
 */
function livingdraft_consent_settings() {
	$saved = get_option( LD_CONSENT_SETTINGS, array() );
	$saved = is_array( $saved ) ? $saved : array();

	$settings = wp_parse_args(
		$saved,
		array(
			'mode' => 'anonymous',
			'ads'  => false,
		)
	);

	$settings['mode'] = in_array( $settings['mode'], array( 'anonymous', 'strict' ), true ) ? $settings['mode'] : 'anonymous';
	$settings['ads']  = (bool) $settings['ads'];

	return $settings;
}

/**
 * Has this reader agreed?
 *
 * Kept for anything that still calls it. Do not use it to decide what goes
 * into the page — a cached page cannot see the cookie. Decide in JavaScript.
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
 * Script handles that carry Google's tag.
 *
 * Only used in strict mode, where they are shipped inert until Accept.
 * Site Kit has used several handles across versions, so all known ones are
 * listed. Add your own through the filter.
 *
 * @since 4.6.1
 * @return string[]
 */
function livingdraft_consent_gated_handles() {
	return (array) apply_filters(
		'livingdraft_consent_gated_scripts',
		array(
			'google_gtagjs',
			'google-tag-manager',
			'gtm4wp',
		)
	);
}

/* ==================================================================
 * 2. GOOGLE CONSENT MODE, BEFORE ANY GOOGLE TAG
 * ================================================================== */

/**
 * Print the Consent Mode defaults at the very top of <head>.
 *
 * Priority 0 puts this ahead of wp_enqueue_scripts (priority 1) and every
 * script WordPress prints afterwards, so Google's tag always finds the
 * defaults already set. The data-* attributes and `nowprocket` ask LiteSpeed,
 * WP Rocket and Cloudflare Rocket Loader not to delay or move it: a
 * consent default that runs after the tag is no default at all.
 */
function livingdraft_consent_mode_defaults() {
	if ( ! livingdraft_consent_enabled() || is_admin() ) {
		return;
	}

	$settings = livingdraft_consent_settings();

	$config = array(
		'strict' => 'strict' === $settings['mode'],
		'ads'    => $settings['ads'],
	);
	?>
<script id="ld-consent-mode" data-no-optimize="1" data-no-defer="1" data-no-minify="1" data-cfasync="false" nowprocket>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
window.ldConsentConfig = <?php echo wp_json_encode( $config ); ?>;
(function () {
	var m = document.cookie.match(/(?:^|;\s*)ld_consent=(yes|no)/);
	var yes = !!(m && m[1] === 'yes');
	var ads = yes && window.ldConsentConfig.ads;
	gtag('consent', 'default', {
		analytics_storage: yes ? 'granted' : 'denied',
		ad_storage: ads ? 'granted' : 'denied',
		ad_user_data: ads ? 'granted' : 'denied',
		ad_personalization: ads ? 'granted' : 'denied',
		functionality_storage: 'granted',
		security_storage: 'granted'
	});
	gtag('set', 'ads_data_redaction', true);
})();
</script>
	<?php
}
add_action( 'wp_head', 'livingdraft_consent_mode_defaults', 0 );

/**
 * Strict mode: ship Google's tag inert, for the browser to switch on.
 *
 * The <script src> becomes <script type="text/plain" data-ld-consent-src>.
 * Every visitor gets the same markup, so the cache is fine; blocks-front.js
 * turns it into a real script the moment the reader's cookie says yes.
 *
 * Only the external tag is touched. Site Kit's inline "after" script, which
 * queues gtag('config', …) into dataLayer, is left alone: it is harmless
 * without the library, and the library processes the queue when it arrives.
 *
 * @param string $tag    Full markup WordPress is about to print.
 * @param string $handle Script handle.
 * @return string
 */
function livingdraft_consent_gate_tag( $tag, $handle ) {
	if ( is_admin() || ! livingdraft_consent_enabled() ) {
		return $tag;
	}

	if ( 'strict' !== livingdraft_consent_settings()['mode'] ) {
		return $tag;
	}

	if ( ! in_array( $handle, livingdraft_consent_gated_handles(), true ) ) {
		return $tag;
	}

	return preg_replace_callback(
		'#<script\b([^>]*)\ssrc=(["\'])([^"\']+)\2([^>]*)>#i',
		static function ( $m ) {
			$attrs = $m[1] . $m[4];
			$attrs = preg_replace( '#\stype=(["\'])[^"\']*\1#i', '', $attrs );
			$attrs = preg_replace( '#\s(async|defer)(=(["\'])[^"\']*\3)?(?=[\s>]|$)#i', '', $attrs );

			return '<script type="text/plain" data-ld-consent-src="' . esc_attr( $m[3] ) . '"' . $attrs . '>';
		},
		$tag
	);
}
add_filter( 'script_loader_tag', 'livingdraft_consent_gate_tag', 999, 2 );

/* ==================================================================
 * 3. THE BANNER, AND THE WAY BACK TO IT
 * ================================================================== */

/**
 * Print the banner.
 *
 * The markup always ships, hidden, and JavaScript decides in the reader's
 * own browser whether to show it. That works whether the page came from PHP
 * or from a cache.
 */
function livingdraft_core_consent_banner() {
	if ( ! livingdraft_consent_enabled() || is_admin() ) {
		return;
	}

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

/**
 * [ld_cookie_settings] — a link that reopens the banner.
 *
 * Withdrawing consent has to be as easy as giving it. Put this in a footer
 * widget or policy page, or skip the shortcode and add a Custom Link with
 * the URL #cookie-settings to any menu: blocks-front.js handles both.
 *
 * @since 4.6.1
 * @param array $atts Shortcode attributes.
 * @return string
 */
function livingdraft_consent_settings_shortcode( $atts ) {
	$atts = shortcode_atts(
		array( 'label' => __( 'Cookie settings', 'livingdraft-core' ) ),
		$atts,
		'ld_cookie_settings'
	);

	return '<a href="#cookie-settings" class="ld-cookie-settings" data-ld-consent-open>' . esc_html( $atts['label'] ) . '</a>';
}
add_shortcode( 'ld_cookie_settings', 'livingdraft_consent_settings_shortcode' );

/* ==================================================================
 * 4. CACHES
 * ================================================================== */

/*
 * 4.6.0 asked LiteSpeed and WP Rocket to keep a separate cached copy per
 * consent cookie, and sent `Vary: Cookie` on every page. None of that is
 * needed now that every visitor gets identical markup — and `Vary: Cookie`
 * was quietly making the cache less effective for everybody, because any
 * cookie at all (a comment author, a theme preference) split it further.
 * Those hooks are gone.
 */

/**
 * Clear the caches we can reach.
 *
 * Pages cached under 4.6.0 have Google's tag taken out, so they must go once
 * after this update or those pages stay untracked until they expire.
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
 * Purge once per plugin version.
 *
 * The activation hook does not run on an update — WordPress only fires it
 * when a plugin is switched on — so a site updated through the update
 * channel would otherwise keep serving old pages. The first admin screen
 * loaded after an update does it instead.
 *
 * @since 4.6.1
 */
function livingdraft_core_purge_after_update() {
	if ( get_option( 'livingdraft_consent_purged_for' ) === LIVINGDRAFT_CORE_VERSION ) {
		return;
	}

	livingdraft_core_purge_caches();
	update_option( 'livingdraft_consent_purged_for', LIVINGDRAFT_CORE_VERSION, false );
	delete_option( 'livingdraft_cache_notice_seen' );
}
add_action( 'admin_init', 'livingdraft_core_purge_after_update' );

/**
 * Remind the admin about caches this plugin cannot reach.
 *
 * Cloudflare and host-level caches sit outside WordPress. The ones inside it
 * were purged automatically, above.
 */
function livingdraft_core_cache_notice() {
	if ( ! current_user_can( 'manage_options' ) || ! livingdraft_consent_enabled() ) {
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
		esc_html__( 'Google Analytics now works with page caching. WordPress caching plugins were cleared automatically. If you use Cloudflare or your host has its own cache, purge it once so every page picks up the change.', 'livingdraft-core' ),
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

/* ==================================================================
 * 5. SETTINGS SCREEN
 * ================================================================== */

/**
 * Save.
 *
 * @since 4.6.1
 */
function livingdraft_consent_handle_save() {
	if ( ! isset( $_POST['ld_consent_save'] ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to change these settings.', 'livingdraft-core' ) );
	}

	check_admin_referer( 'ld_consent_save' );

	$mode = isset( $_POST['ld_consent_mode'] ) ? sanitize_key( wp_unslash( $_POST['ld_consent_mode'] ) ) : 'anonymous';

	update_option(
		LD_CONSENT_SETTINGS,
		array(
			'mode' => in_array( $mode, array( 'anonymous', 'strict' ), true ) ? $mode : 'anonymous',
			'ads'  => ! empty( $_POST['ld_consent_ads'] ),
		)
	);

	// Changing mode changes the markup, so cached pages must go.
	livingdraft_core_purge_caches();

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'       => 'livingdraft-settings',
				'section'    => 'consent',
				'ld-consent' => 'saved',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}
add_action( 'admin_init', 'livingdraft_consent_handle_save' );

/**
 * Render.
 *
 * @since 4.6.1
 */
function livingdraft_consent_render_settings() {
	$settings  = livingdraft_consent_settings();
	$site_kit  = defined( 'GOOGLESITEKIT_VERSION' );
	$banner_on = livingdraft_consent_enabled();

	if ( isset( $_GET['ld-consent'] ) && 'saved' === $_GET['ld-consent'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="tld-notice"><p>' . esc_html__( 'Analytics & consent settings saved. WordPress caches were cleared; purge Cloudflare or your host cache too if you use one.', 'livingdraft-core' ) . '</p></div>';
	}
	?>
	<div class="tld-card">
		<div class="tld-section-rule"><h2><?php esc_html_e( 'Analytics & consent', 'livingdraft-core' ); ?></h2></div>

		<p class="tld-help">
			<?php esc_html_e( 'Google Analytics is added by Site Kit. This screen decides what it may do before a reader answers the cookie banner. It works the same whether or not a page cache is running.', 'livingdraft-core' ); ?>
		</p>

		<ul class="tld-help" style="list-style:none;padding:0;margin:0 0 18px">
			<li><?php echo $site_kit ? '&#10003; ' . esc_html__( 'Site Kit is active.', 'livingdraft-core' ) : '&#9888; ' . esc_html__( 'Site Kit is not active, so no Google Analytics tag is being added.', 'livingdraft-core' ); ?></li>
			<li>
				<?php
				if ( $banner_on ) {
					echo '&#10003; ' . esc_html__( 'The cookie banner is on.', 'livingdraft-core' );
				} else {
					echo '&#9888; ' . esc_html__( 'The cookie banner is off, so Analytics runs for everyone without asking. Switch it on under Appearance → Customize → Privacy.', 'livingdraft-core' );
				}
				?>
			</li>
		</ul>

		<form method="post" action="">
			<?php wp_nonce_field( 'ld_consent_save' ); ?>

			<div class="tld-field">
				<span class="tld-label"><?php esc_html_e( 'Before a reader accepts', 'livingdraft-core' ); ?></span>

				<label class="tld-check">
					<input type="radio" name="ld_consent_mode" value="anonymous" <?php checked( 'anonymous', $settings['mode'] ); ?> />
					<span><?php esc_html_e( 'Measure anonymously (recommended)', 'livingdraft-core' ); ?></span>
				</label>
				<p class="tld-help"><?php esc_html_e( 'Google\'s tag loads but stores nothing on the device and sends no identifier — only cookieless pings. Google can then estimate the readers who never answer the banner, if your property has enough traffic. Readers who accept are counted in full.', 'livingdraft-core' ); ?></p>

				<label class="tld-check">
					<input type="radio" name="ld_consent_mode" value="strict" <?php checked( 'strict', $settings['mode'] ); ?> />
					<span><?php esc_html_e( 'Load nothing from Google', 'livingdraft-core' ); ?></span>
				</label>
				<p class="tld-help"><?php esc_html_e( 'Google\'s tag is not downloaded at all until the reader presses Accept. Only readers who accept are counted.', 'livingdraft-core' ); ?></p>
			</div>

			<div class="tld-field">
				<label class="tld-check">
					<input type="checkbox" name="ld_consent_ads" value="1" <?php checked( $settings['ads'] ); ?> />
					<span><?php esc_html_e( 'Accept also allows advertising cookies', 'livingdraft-core' ); ?></span>
				</label>
				<p class="tld-help"><?php esc_html_e( 'Leave this off unless you run Google Ads or AdSense, and say so in your banner text and cookie notice. With it off, Accept allows analytics only.', 'livingdraft-core' ); ?></p>
			</div>

			<div class="tld-field">
				<span class="tld-label"><?php esc_html_e( 'Letting readers change their mind', 'livingdraft-core' ); ?></span>
				<p class="tld-help">
					<?php esc_html_e( 'Add a Custom Link with the URL #cookie-settings to your footer menu (Appearance → Menus), or place the shortcode [ld_cookie_settings] anywhere. Clicking it reopens the banner.', 'livingdraft-core' ); ?>
				</p>
			</div>

			<div class="tld-btn-row">
				<button type="submit" name="ld_consent_save" value="1" class="tld-btn is-primary"><?php esc_html_e( 'Save', 'livingdraft-core' ); ?></button>
			</div>
		</form>
	</div>
	<?php
}

/**
 * Register in the Settings rail.
 *
 * @since 4.6.1
 * @param array $sections Sections.
 * @return array
 */
function livingdraft_consent_register_settings_section( $sections ) {
	$sections['consent'] = array(
		'label'  => __( 'Analytics & consent', 'livingdraft-core' ),
		'desc'   => __( 'What Google Analytics may do before a reader answers the cookie banner.', 'livingdraft-core' ),
		'render' => 'livingdraft_consent_render_settings',
		'cap'    => 'manage_options',
	);

	return $sections;
}
add_filter( 'livingdraft_settings_panels', 'livingdraft_consent_register_settings_section', 25 );
