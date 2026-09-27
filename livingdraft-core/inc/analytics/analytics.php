<?php
/**
 * Site analytics — first-party, cache-proof, on your own database.
 *
 * === WHAT IT MEASURES ===
 *
 *   Pageviews     every page a reader opens (not just articles)
 *   Visitors      distinct readers in the period (a random id kept in the
 *                 reader's browser; no name, no IP, no cookie)
 *   Sessions      a run of pageviews with no gap longer than 30 minutes
 *   New visitors  readers seen for the first time
 *   Engaged time  seconds the tab was visible and in use (idle is not counted)
 *   Scroll depth  how far down the page the reader got
 *   Bounce        a session of one page with under 10 seconds engaged
 *
 * Plus, per session: the channel it came from (search, social, AI
 * assistants, news apps, email, paid, referral, direct), the referring
 * site, UTM campaign, device, browser, OS and — when the site is behind
 * Cloudflare — country.
 *
 * === WHY IT COUNTS IN THE BROWSER ===
 *
 * Same reason as views.php: a page cache means PHP does not run for most
 * readers. tracker.js makes one small uncached request after the page has
 * loaded. Crawlers do not run JavaScript, so most bots exclude themselves.
 *
 * === STORAGE ===
 *
 *   {prefix}ld_hits    one row per pageview, kept for the retention window
 *                      (default 25 months) so any period can be recomputed
 *   {prefix}ld_stats   one row per closed day / week / month / year, kept
 *                      forever — the permanent record once raw rows expire
 *
 * The nightly job writes yesterday's day, and recomputes the week, month
 * and year that yesterday belongs to. Current periods are always computed
 * live (cached for a few minutes), so today's number is never stale.
 *
 * @package LivingDraftCore
 * @since   4.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LIVINGDRAFT_AN_DB_VERSION = '1.0';
const LIVINGDRAFT_AN_OPTION     = 'livingdraft_analytics_settings';

/* ==================================================================
 * 1. SETTINGS
 * ================================================================== */

/**
 * @return array
 */
function livingdraft_an_settings() {
	$saved = get_option( LIVINGDRAFT_AN_OPTION, array() );
	$saved = is_array( $saved ) ? $saved : array();

	$s = wp_parse_args(
		$saved,
		array(
			'enabled'       => true,
			'exclude_staff' => true,
			'exclude_ips'   => '',
			'retention'     => 760, // Days of raw pageviews. ~25 months.
			'scope'         => 'all', // 'all' front-end pages, or 'singular'.
		)
	);

	$s['enabled']       = (bool) $s['enabled'];
	$s['exclude_staff'] = (bool) $s['exclude_staff'];
	$s['retention']     = max( 400, min( 3650, (int) $s['retention'] ) );
	$s['scope']         = in_array( $s['scope'], array( 'all', 'singular' ), true ) ? $s['scope'] : 'all';

	return $s;
}

/**
 * @return bool
 */
function livingdraft_analytics_enabled() {
	return livingdraft_an_settings()['enabled'];
}

/**
 * @return string
 */
function livingdraft_an_table() {
	global $wpdb;
	return $wpdb->prefix . 'ld_hits';
}

/**
 * @return string
 */
function livingdraft_an_stats_table() {
	global $wpdb;
	return $wpdb->prefix . 'ld_stats';
}

/* ==================================================================
 * 2. SCHEMA
 * ================================================================== */

/**
 * Create or upgrade the two tables. Runs on activation and, as a safety
 * net for sites updated by overwriting files, on the first admin load
 * after the DB version changes.
 */
function livingdraft_an_install() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset = $wpdb->get_charset_collate();
	$hits    = livingdraft_an_table();
	$stats   = livingdraft_an_stats_table();

	dbDelta(
		"CREATE TABLE {$hits} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			hit_uid char(16) NOT NULL,
			created datetime NOT NULL,
			day date NOT NULL,
			visitor char(16) NOT NULL,
			session char(16) NOT NULL,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			path varchar(255) NOT NULL DEFAULT '',
			ref_host varchar(191) NOT NULL DEFAULT '',
			channel varchar(12) NOT NULL DEFAULT 'direct',
			utm_source varchar(100) NOT NULL DEFAULT '',
			utm_medium varchar(100) NOT NULL DEFAULT '',
			utm_campaign varchar(150) NOT NULL DEFAULT '',
			device varchar(8) NOT NULL DEFAULT '',
			browser varchar(16) NOT NULL DEFAULT '',
			os varchar(16) NOT NULL DEFAULT '',
			country char(2) NOT NULL DEFAULT '',
			is_new tinyint(1) unsigned NOT NULL DEFAULT 0,
			is_entry tinyint(1) unsigned NOT NULL DEFAULT 0,
			engaged smallint(5) unsigned NOT NULL DEFAULT 0,
			scroll tinyint(3) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY hit_uid (hit_uid),
			KEY day (day),
			KEY created (created),
			KEY post_day (post_id,day),
			KEY session (session)
		) {$charset};"
	);

	dbDelta(
		"CREATE TABLE {$stats} (
			period char(1) NOT NULL,
			pkey varchar(10) NOT NULL,
			pageviews int(10) unsigned NOT NULL DEFAULT 0,
			visitors int(10) unsigned NOT NULL DEFAULT 0,
			sessions int(10) unsigned NOT NULL DEFAULT 0,
			new_visitors int(10) unsigned NOT NULL DEFAULT 0,
			bounces int(10) unsigned NOT NULL DEFAULT 0,
			engaged bigint(20) unsigned NOT NULL DEFAULT 0,
			updated datetime NOT NULL,
			PRIMARY KEY  (period,pkey)
		) {$charset};"
	);

	update_option( 'livingdraft_an_db_version', LIVINGDRAFT_AN_DB_VERSION, false );
}
register_activation_hook( LIVINGDRAFT_CORE_FILE, 'livingdraft_an_install' );

/**
 * Upgrade path for sites that update by replacing files.
 */
function livingdraft_an_maybe_install() {
	if ( get_option( 'livingdraft_an_db_version' ) !== LIVINGDRAFT_AN_DB_VERSION ) {
		livingdraft_an_install();
	}
}
add_action( 'admin_init', 'livingdraft_an_maybe_install' );

/**
 * Is the hits table there? Cached per request.
 *
 * @return bool
 */
function livingdraft_an_ready() {
	static $ready = null;

	if ( null === $ready ) {
		$ready = ( get_option( 'livingdraft_an_db_version' ) === LIVINGDRAFT_AN_DB_VERSION );
	}

	return $ready;
}

/* ==================================================================
 * 3. THE TRACKER
 * ================================================================== */

/**
 * Is this browser a member of staff who should not be counted?
 *
 * @return bool
 */
function livingdraft_an_is_staff() {
	return is_user_logged_in() && current_user_can( 'edit_posts' );
}

/**
 * Enqueue tracker.js on front-end pages.
 *
 * Staff browsers get a one-line opt-out flag instead: that flag lives in
 * localStorage, so the same browser stays excluded even when it later
 * reads a cached page while logged out.
 */
function livingdraft_an_enqueue() {
	if ( is_admin() || is_preview() || is_customize_preview() || is_feed() || is_robots() || is_404() ) {
		return;
	}

	$s = livingdraft_an_settings();

	if ( ! $s['enabled'] || ! livingdraft_an_ready() ) {
		return;
	}

	if ( 'singular' === $s['scope'] && ! is_singular() ) {
		return;
	}

	if ( $s['exclude_staff'] && livingdraft_an_is_staff() ) {
		wp_register_script( 'livingdraft-an-optout', false, array(), LIVINGDRAFT_CORE_VERSION, true );
		wp_enqueue_script( 'livingdraft-an-optout' );
		wp_add_inline_script( 'livingdraft-an-optout', "try{localStorage.setItem('ld_optout','1')}catch(e){}" );
		return;
	}

	$path = LIVINGDRAFT_CORE_DIR . 'assets/js/tracker.js';
	if ( ! file_exists( $path ) ) {
		return;
	}

	wp_enqueue_script(
		'livingdraft-tracker',
		LIVINGDRAFT_CORE_URL . 'assets/js/tracker.js',
		array(),
		(string) filemtime( $path ),
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);

	$post_id = 0;
	$count   = false;
	if ( is_singular() ) {
		$post_id = (int) get_queried_object_id();
		$count   = is_singular( 'post' ); // Feeds the per-article counter in views.php.
	}

	wp_add_inline_script(
		'livingdraft-tracker',
		'window.ldTracker=' . wp_json_encode(
			array(
				'hit'    => rest_url( 'livingdraft/v1/hit' ),
				'ping'   => rest_url( 'livingdraft/v1/ping' ),
				'post'   => $post_id,
				'count'  => $count,
				'window' => function_exists( 'livingdraft_views_repeat_window' ) ? (int) livingdraft_views_repeat_window() : 21600,
			)
		) . ';',
		'before'
	);
}
add_action( 'wp_enqueue_scripts', 'livingdraft_an_enqueue' );

/**
 * REST routes. See views.php for why there is no nonce on an anonymous
 * counter served from a cached page.
 */
function livingdraft_an_register_routes() {
	register_rest_route(
		'livingdraft/v1',
		'/hit',
		array(
			'methods'             => 'POST',
			'callback'            => 'livingdraft_an_record_hit',
			'permission_callback' => '__return_true',
		)
	);

	register_rest_route(
		'livingdraft/v1',
		'/ping',
		array(
			'methods'             => 'POST',
			'callback'            => 'livingdraft_an_record_ping',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'livingdraft_an_register_routes' );

/**
 * Read the JSON body whether it arrived by fetch (application/json) or by
 * sendBeacon (text/plain, to avoid a CORS preflight).
 *
 * @param WP_REST_Request $request Request.
 * @return array
 */
function livingdraft_an_body( $request ) {
	$data = $request->get_json_params();

	if ( ! is_array( $data ) || empty( $data ) ) {
		$data = json_decode( (string) $request->get_body(), true );
	}

	return is_array( $data ) ? $data : array();
}

/**
 * @param mixed $v Value.
 * @return string 16 lowercase hex chars, or ''.
 */
function livingdraft_an_id( $v ) {
	$v = strtolower( (string) $v );
	return preg_match( '/^[a-f0-9]{16}$/', $v ) ? $v : '';
}

/**
 * Anonymised client address. Never stored — only used for the IP
 * exclusion list and, when a browser blocks storage, a same-day hash.
 *
 * @return string
 */
function livingdraft_an_client_ip() {
	$raw = '';
	foreach ( array( 'HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR' ) as $h ) {
		if ( ! empty( $_SERVER[ $h ] ) ) {
			$raw = sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) );
			break;
		}
	}
	return $raw;
}

/**
 * Is this address on the exclusion list? Accepts exact IPs and prefixes
 * ending in a dot or colon ("203.0.113.").
 *
 * @param string $ip IP.
 * @return bool
 */
function livingdraft_an_ip_excluded( $ip ) {
	$list = trim( livingdraft_an_settings()['exclude_ips'] );
	if ( '' === $list || '' === $ip ) {
		return false;
	}
	foreach ( preg_split( '/[\s,]+/', $list ) as $rule ) {
		$rule = trim( $rule );
		if ( '' === $rule ) {
			continue;
		}
		if ( $rule === $ip || ( in_array( substr( $rule, -1 ), array( '.', ':' ), true ) && 0 === strpos( $ip, $rule ) ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Record one pageview.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function livingdraft_an_record_hit( $request ) {
	$empty = new WP_REST_Response( null, 204 );

	if ( ! livingdraft_analytics_enabled() || ! livingdraft_an_ready() ) {
		return $empty;
	}

	$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	$lower = strtolower( $agent );

	if ( '' === $lower || livingdraft_an_is_bot( $lower ) ) {
		return $empty;
	}

	$ip = livingdraft_an_client_ip();
	if ( livingdraft_an_ip_excluded( $ip ) ) {
		return $empty;
	}

	$d   = livingdraft_an_body( $request );
	$uid = livingdraft_an_id( $d['u'] ?? '' );
	if ( '' === $uid ) {
		return $empty;
	}

	$visitor = livingdraft_an_id( $d['v'] ?? '' );
	$session = livingdraft_an_id( $d['s'] ?? '' );

	// Browser refused storage: fall back to a same-day hash that is never
	// reversible and changes at midnight.
	if ( '' === $visitor ) {
		$anon    = function_exists( 'wp_privacy_anonymize_ip' ) ? wp_privacy_anonymize_ip( $ip ) : $ip;
		$visitor = substr( hash( 'sha256', current_time( 'Y-m-d' ) . wp_salt( 'nonce' ) . $anon . $lower ), 0, 16 );
	}
	if ( '' === $session ) {
		$session = $visitor;
	}

	$post_id = absint( $d['p'] ?? 0 );
	$path    = livingdraft_an_clean_path( (string) ( $d['path'] ?? '/' ) );
	$ref     = (string) ( $d['r'] ?? '' );
	$utm     = isset( $d['utm'] ) && is_array( $d['utm'] ) ? $d['utm'] : array();

	$utm_source   = substr( sanitize_text_field( (string) ( $utm['source'] ?? '' ) ), 0, 100 );
	$utm_medium   = substr( sanitize_text_field( (string) ( $utm['medium'] ?? '' ) ), 0, 100 );
	$utm_campaign = substr( sanitize_text_field( (string) ( $utm['campaign'] ?? '' ) ), 0, 150 );

	$ref_host = livingdraft_an_ref_host( $ref );
	$channel  = livingdraft_an_channel( $ref_host, $utm_source, $utm_medium );
	$ua       = livingdraft_an_parse_ua( $lower, absint( $d['w'] ?? 0 ) );

	$now = current_time( 'mysql' );

	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->insert(
		livingdraft_an_table(),
		array(
			'hit_uid'      => $uid,
			'created'      => $now,
			'day'          => substr( $now, 0, 10 ),
			'visitor'      => $visitor,
			'session'      => $session,
			'post_id'      => $post_id,
			'path'         => $path,
			'ref_host'     => $ref_host,
			'channel'      => $channel,
			'utm_source'   => $utm_source,
			'utm_medium'   => $utm_medium,
			'utm_campaign' => $utm_campaign,
			'device'       => $ua['device'],
			'browser'      => $ua['browser'],
			'os'           => $ua['os'],
			'country'      => livingdraft_an_country(),
			'is_new'       => empty( $d['n'] ) ? 0 : 1,
			'is_entry'     => empty( $d['e'] ) ? 0 : 1,
		),
		array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d' )
	);

	// The per-article lifetime / month / day counters (views.php) ride on
	// the same request, so an article costs one call, not two.
	if ( ! empty( $d['k'] ) && $post_id && function_exists( 'livingdraft_views_increment' ) ) {
		$post = get_post( $post_id );
		if ( $post && 'post' === $post->post_type && 'publish' === $post->post_status ) {
			if ( ! function_exists( 'livingdraft_views_repeat_ok' ) || livingdraft_views_repeat_ok( $post_id, $lower ) ) {
				livingdraft_views_increment( $post_id );
			}
		}
	}

	return $empty;
}

/**
 * Engagement ping: seconds engaged and scroll depth for a pageview already
 * recorded. Only ever raises the numbers, never lowers them.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function livingdraft_an_record_ping( $request ) {
	$empty = new WP_REST_Response( null, 204 );

	if ( ! livingdraft_an_ready() ) {
		return $empty;
	}

	$d   = livingdraft_an_body( $request );
	$uid = livingdraft_an_id( $d['u'] ?? '' );
	if ( '' === $uid ) {
		return $empty;
	}

	$t = min( 1800, absint( $d['t'] ?? 0 ) );
	$s = min( 100, absint( $d['d'] ?? 0 ) );

	if ( ! $t && ! $s ) {
		return $empty;
	}

	global $wpdb;
	$table  = livingdraft_an_table();
	$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 6 * HOUR_IN_SECONDS );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET engaged = GREATEST(engaged, %d), scroll = GREATEST(scroll, %d) WHERE hit_uid = %s AND created >= %s",
			$t,
			$s,
			$uid,
			$cutoff
		)
	);

	return $empty;
}

/* ==================================================================
 * 4. CLASSIFICATION
 * ================================================================== */

/**
 * @param string $agent Lower-case user agent.
 * @return bool
 */
function livingdraft_an_is_bot( $agent ) {
	$sigs = function_exists( 'livingdraft_views_bot_signatures' )
		? livingdraft_views_bot_signatures()
		: array( 'bot', 'crawl', 'spider', 'slurp', 'headless', 'lighthouse', 'pagespeed' );

	foreach ( $sigs as $needle ) {
		if ( '' !== $needle && false !== strpos( $agent, $needle ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Keep a path short and free of query noise. UTM and click-id parameters
 * are removed; anything else in the query string is dropped too, because
 * a query string is almost never a different page on a news site.
 *
 * @param string $path Path from the browser.
 * @return string
 */
function livingdraft_an_clean_path( $path ) {
	$path = (string) wp_parse_url( $path, PHP_URL_PATH );
	$path = '/' . ltrim( $path, '/' );
	$path = preg_replace( '#/+#', '/', $path );
	$path = sanitize_text_field( rawurldecode( $path ) );
	return substr( $path, 0, 255 );
}

/**
 * Referring host, or '' when internal or absent.
 *
 * @param string $ref Referrer URL.
 * @return string
 */
function livingdraft_an_ref_host( $ref ) {
	if ( '' === $ref ) {
		return '';
	}

	// Android apps report as android-app://com.package/.
	if ( 0 === strpos( $ref, 'android-app://' ) ) {
		return substr( sanitize_text_field( trim( substr( $ref, 14 ), '/' ) ), 0, 191 );
	}

	$host = strtolower( (string) wp_parse_url( $ref, PHP_URL_HOST ) );
	if ( '' === $host ) {
		return '';
	}

	$host = preg_replace( '/^(www\.|m\.|l\.|lm\.|mobile\.)/', '', $host );
	$own  = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );

	if ( $host === $own ) {
		return '';
	}

	return substr( sanitize_text_field( $host ), 0, 191 );
}

/**
 * Channel labels, in display order.
 *
 * @return array
 */
function livingdraft_an_channels() {
	return array(
		'search'   => __( 'Search', 'livingdraft-core' ),
		'news'     => __( 'News apps & Discover', 'livingdraft-core' ),
		'social'   => __( 'Social', 'livingdraft-core' ),
		'ai'       => __( 'AI assistants', 'livingdraft-core' ),
		'email'    => __( 'Email & newsletter', 'livingdraft-core' ),
		'paid'     => __( 'Paid', 'livingdraft-core' ),
		'referral' => __( 'Other websites', 'livingdraft-core' ),
		'direct'   => __( 'Direct', 'livingdraft-core' ),
	);
}

/**
 * Decide where a session came from.
 *
 * @param string $host   Referring host.
 * @param string $source utm_source.
 * @param string $medium utm_medium.
 * @return string
 */
function livingdraft_an_channel( $host, $source, $medium ) {
	$medium = strtolower( $medium );
	$source = strtolower( $source );

	if ( preg_match( '/^(cpc|ppc|paid|paidsearch|paid_social|paidsocial|display|cpm|banner|ads?)$/', $medium ) ) {
		return 'paid';
	}
	if ( in_array( $medium, array( 'email', 'e-mail', 'newsletter' ), true ) || in_array( $source, array( 'newsletter', 'email' ), true ) ) {
		return 'email';
	}
	if ( 'social' === $medium ) {
		return 'social';
	}

	$check = $host ? $host : $source;
	if ( '' === $check ) {
		return 'direct';
	}

	$lists = apply_filters(
		'livingdraft_an_channel_hosts',
		array(
			'email'  => array( 'com.google.android.gm', 'mail.', 'outlook.live', 'outlook.office', 'webmail' ),
			'news'   => array( 'news.google', 'googlequicksearchbox', 'discover.google', 'flipboard', 'newsbreak', 'dailyhunt', 'inshorts', 'smartnews', 'msn.com', 'news.yahoo', 'apple.news', 'feedly', 'inoreader', 'newsnow' ),
			'ai'     => array( 'chatgpt', 'chat.openai', 'openai.com', 'perplexity', 'gemini.google', 'bard.google', 'copilot.microsoft', 'claude.ai', 'grok.com', 'x.ai', 'you.com', 'phind', 'meta.ai', 'deepseek', 'kagi.com' ),
			'search' => array( 'google.', 'bing.', 'duckduckgo', 'yahoo.', 'yandex', 'baidu', 'ecosia', 'search.brave', 'qwant', 'startpage', 'naver', 'seznam', 'sogou', 'so.com' ),
			'social' => array( 'facebook', 'fb.me', 't.co', 'twitter', 'x.com', 'linkedin', 'lnkd.in', 'instagram', 'reddit', 'pinterest', 'youtube', 'youtu.be', 'whatsapp', 'telegram', 't.me', 'threads', 'quora', 'tumblr', 'mastodon', 'bsky', 'tiktok', 'snapchat', 'sharechat', 'koo' ),
		)
	);

	// Order matters: news.google before google., gemini.google before google.
	foreach ( array( 'email', 'news', 'ai', 'search', 'social' ) as $channel ) {
		foreach ( (array) ( $lists[ $channel ] ?? array() ) as $needle ) {
			// Whole domain labels only: "t.co" must not match microsoft.com,
			// "x.com" must not match box.com. A trailing dot ("google.")
			// means "any ending", for google.co.in, google.com.au, …
			$tail = ( '.' === substr( $needle, -1 ) ) ? '' : '(?:$|\.)';
			if ( preg_match( '/(?:^|\.)' . preg_quote( $needle, '/' ) . $tail . '/', $check ) ) {
				return $channel;
			}
		}
	}

	return $host ? 'referral' : 'direct';
}

/**
 * Device, browser, OS from the user agent. Deliberately coarse.
 *
 * @param string $ua    Lower-case UA.
 * @param int    $width Screen width reported by the browser.
 * @return array{device:string,browser:string,os:string}
 */
function livingdraft_an_parse_ua( $ua, $width = 0 ) {
	if ( preg_match( '/ipad|tablet|kindle|silk|playbook|(android(?!.*mobile))/', $ua ) ) {
		$device = 'tablet';
	} elseif ( preg_match( '/mobi|iphone|ipod|android|opera mini|windows phone|blackberry/', $ua ) ) {
		$device = 'mobile';
	} elseif ( $width && $width < 768 ) {
		$device = 'mobile';
	} else {
		$device = 'desktop';
	}

	$browsers = array(
		'edg/'           => 'Edge',
		'opr/'           => 'Opera',
		'opera'          => 'Opera',
		'samsungbrowser' => 'Samsung',
		'ucbrowser'      => 'UC Browser',
		'yabrowser'      => 'Yandex',
		'fban'           => 'Facebook app',
		'fbav'           => 'Facebook app',
		'instagram'      => 'Instagram app',
		'firefox'        => 'Firefox',
		'fxios'          => 'Firefox',
		'crios'          => 'Chrome',
		'chrome'         => 'Chrome',
		'safari'         => 'Safari',
	);
	$browser = 'Other';
	foreach ( $browsers as $needle => $name ) {
		if ( false !== strpos( $ua, $needle ) ) {
			$browser = $name;
			break;
		}
	}

	$systems = array(
		'windows'  => 'Windows',
		'android'  => 'Android',
		'iphone'   => 'iOS',
		'ipad'     => 'iPadOS',
		'ipod'     => 'iOS',
		'mac os x' => 'macOS',
		'cros'     => 'ChromeOS',
		'linux'    => 'Linux',
	);
	$os = 'Other';
	foreach ( $systems as $needle => $name ) {
		if ( false !== strpos( $ua, $needle ) ) {
			$os = $name;
			break;
		}
	}

	return array(
		'device'  => $device,
		'browser' => $browser,
		'os'      => $os,
	);
}

/**
 * Country from a CDN header, when one is present. No lookup database, no
 * third-party call.
 *
 * @return string ISO 3166-1 alpha-2, or ''.
 */
function livingdraft_an_country() {
	foreach ( array( 'HTTP_CF_IPCOUNTRY', 'HTTP_X_COUNTRY_CODE', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'HTTP_X_VERCEL_IP_COUNTRY', 'GEOIP_COUNTRY_CODE' ) as $h ) {
		if ( ! empty( $_SERVER[ $h ] ) ) {
			$c = strtoupper( substr( sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) ), 0, 2 ) );
			if ( preg_match( '/^[A-Z]{2}$/', $c ) && 'XX' !== $c && 'T1' !== $c ) {
				return $c;
			}
		}
	}
	return '';
}

/* ==================================================================
 * 5. PERIODS
 * ================================================================== */

/**
 * The first day of the week containing $date, honouring Settings → General
 * → "Week starts on".
 *
 * @param string $date Y-m-d.
 * @return string Y-m-d.
 */
function livingdraft_an_week_start( $date ) {
	$start = (int) get_option( 'start_of_week', 1 ); // 0 = Sunday.
	$ts    = strtotime( $date . ' 00:00:00' );
	$dow   = (int) gmdate( 'w', $ts );
	$diff  = ( $dow - $start + 7 ) % 7;
	return gmdate( 'Y-m-d', $ts - $diff * DAY_IN_SECONDS );
}

/**
 * The named periods on the dashboard, each with a "to date" comparison.
 *
 * "This week" is compared with last week up to the same moment, not with
 * the whole of last week — otherwise every Monday looks like a collapse.
 *
 * @param string $key today|yesterday|week|month|year|all.
 * @return array{from:string,to:string,prev_from:string,prev_to:string,label:string}
 */
function livingdraft_an_named_period( $key ) {
	$now   = current_time( 'timestamp' );
	$today = gmdate( 'Y-m-d', $now );
	$nowdt = gmdate( 'Y-m-d H:i:s', $now );

	switch ( $key ) {
		case 'yesterday':
			$from      = gmdate( 'Y-m-d 00:00:00', $now - DAY_IN_SECONDS );
			$to        = gmdate( 'Y-m-d 23:59:59', $now - DAY_IN_SECONDS );
			$prev_from = gmdate( 'Y-m-d 00:00:00', $now - 2 * DAY_IN_SECONDS );
			$prev_to   = gmdate( 'Y-m-d 23:59:59', $now - 2 * DAY_IN_SECONDS );
			$label     = __( 'Yesterday', 'livingdraft-core' );
			break;

		case 'week':
			$ws        = livingdraft_an_week_start( $today );
			$from      = $ws . ' 00:00:00';
			$to        = $nowdt;
			$prev_from = gmdate( 'Y-m-d H:i:s', strtotime( $from ) - WEEK_IN_SECONDS );
			$prev_to   = gmdate( 'Y-m-d H:i:s', $now - WEEK_IN_SECONDS );
			$label     = __( 'This week', 'livingdraft-core' );
			break;

		case 'month':
			$from      = gmdate( 'Y-m-01 00:00:00', $now );
			$to        = $nowdt;
			$pm        = strtotime( '-1 month', strtotime( gmdate( 'Y-m-01', $now ) ) );
			$prev_from = gmdate( 'Y-m-01 00:00:00', $pm );
			$dim       = (int) gmdate( 't', $pm );
			$day       = min( (int) gmdate( 'j', $now ), $dim );
			$prev_to   = gmdate( 'Y-m-', $pm ) . sprintf( '%02d', $day ) . gmdate( ' H:i:s', $now );
			$label     = __( 'This month', 'livingdraft-core' );
			break;

		case 'year':
			$from      = gmdate( 'Y-01-01 00:00:00', $now );
			$to        = $nowdt;
			$py        = (int) gmdate( 'Y', $now ) - 1;
			$prev_from = $py . '-01-01 00:00:00';
			$prev_to   = $py . gmdate( '-m-d H:i:s', $now );
			if ( '02-29' === gmdate( 'm-d', $now ) ) {
				$prev_to = $py . '-02-28' . gmdate( ' H:i:s', $now );
			}
			$label = __( 'This year', 'livingdraft-core' );
			break;

		case 'all':
			$from      = '1970-01-01 00:00:00';
			$to        = $nowdt;
			$prev_from = '';
			$prev_to   = '';
			$label     = __( 'All time', 'livingdraft-core' );
			break;

		case 'today':
		default:
			$from      = $today . ' 00:00:00';
			$to        = $nowdt;
			$prev_from = gmdate( 'Y-m-d 00:00:00', $now - DAY_IN_SECONDS );
			$prev_to   = gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS );
			$label     = __( 'Today', 'livingdraft-core' );
	}

	return compact( 'from', 'to', 'prev_from', 'prev_to', 'label' );
}

/* ==================================================================
 * 6. QUERIES
 * ================================================================== */

/**
 * Cache wrapper. Short TTL for anything touching today.
 *
 * @param string   $key Key.
 * @param int      $ttl Seconds.
 * @param callable $fn  Producer.
 * @return mixed
 */
function livingdraft_an_cached( $key, $ttl, $fn ) {
	$key = 'ld_an_' . md5( $key . '|' . get_option( 'livingdraft_an_cache_gen', 1 ) );
	$hit = get_transient( $key );
	if ( false !== $hit ) {
		return $hit;
	}
	$val = call_user_func( $fn );
	set_transient( $key, $val, $ttl );
	return $val;
}

/**
 * Drop every cached figure (bumps a generation number, so no LIKE scan of
 * the options table is needed).
 */
function livingdraft_an_flush_cache() {
	update_option( 'livingdraft_an_cache_gen', (int) get_option( 'livingdraft_an_cache_gen', 1 ) + 1, false );
}

/**
 * Totals for a datetime range, straight from raw pageviews.
 *
 * @param string $from Y-m-d H:i:s.
 * @param string $to   Y-m-d H:i:s.
 * @return array
 */
function livingdraft_an_totals( $from, $to ) {
	$ttl = ( strtotime( $to ) >= current_time( 'timestamp' ) - HOUR_IN_SECONDS ) ? 3 * MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS;

	return livingdraft_an_cached(
		'totals|' . $from . '|' . $to,
		$ttl,
		static function () use ( $from, $to ) {
			global $wpdb;
			$t = livingdraft_an_table();

			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT(*) AS pageviews,
						COUNT(DISTINCT visitor) AS visitors,
						COUNT(DISTINCT session) AS sessions,
						COUNT(DISTINCT CASE WHEN is_new = 1 THEN visitor END) AS new_visitors,
						COALESCE(SUM(engaged),0) AS engaged,
						COALESCE(AVG(NULLIF(scroll,0)),0) AS scroll
					FROM {$t} WHERE created BETWEEN %s AND %s",
					$from,
					$to
				),
				ARRAY_A
			);

			$bounces = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM (
						SELECT session, COUNT(*) c, SUM(engaged) e FROM {$t}
						WHERE created BETWEEN %s AND %s GROUP BY session
					) x WHERE x.c = 1 AND x.e < 10",
					$from,
					$to
				)
			);
			// phpcs:enable

			return livingdraft_an_shape( $row, $bounces );
		}
	);
}

/**
 * Normalise a totals row and derive the ratios.
 *
 * @param array|null $row     Row.
 * @param int        $bounces Bounced sessions.
 * @return array
 */
function livingdraft_an_shape( $row, $bounces = 0 ) {
	$row = is_array( $row ) ? $row : array();

	$pv  = (int) ( $row['pageviews'] ?? 0 );
	$vis = (int) ( $row['visitors'] ?? 0 );
	$ses = (int) ( $row['sessions'] ?? 0 );
	$new = (int) ( $row['new_visitors'] ?? 0 );
	$eng = (int) ( $row['engaged'] ?? 0 );

	return array(
		'pageviews'        => $pv,
		'visitors'         => $vis,
		'sessions'         => $ses,
		'new_visitors'     => $new,
		'returning'        => max( 0, $vis - $new ),
		'bounces'          => (int) $bounces,
		'engaged'          => $eng,
		'pages_per_session'=> $ses ? round( $pv / $ses, 2 ) : 0,
		'avg_engaged'      => $ses ? (int) round( $eng / $ses ) : 0,
		'bounce_rate'      => $ses ? round( 100 * $bounces / $ses, 1 ) : 0,
		'avg_scroll'       => isset( $row['scroll'] ) ? (int) round( (float) $row['scroll'] ) : 0,
	);
}

/**
 * All-time totals: rolled-up years whose raw rows have expired, plus raw.
 * Visitors across the boundary are summed, so for very old data the
 * all-time visitor figure is an upper bound. The UI says so.
 *
 * @return array
 */
function livingdraft_an_all_time() {
	return livingdraft_an_cached(
		'alltime',
		10 * MINUTE_IN_SECONDS,
		static function () {
			global $wpdb;
			$t = livingdraft_an_table();
			$s = livingdraft_an_stats_table();

			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$first = (string) $wpdb->get_var( "SELECT MIN(day) FROM {$t}" );
			$raw   = livingdraft_an_totals( '1970-01-01 00:00:00', current_time( 'mysql' ) );

			if ( $first ) {
				$old = $wpdb->get_row(
					$wpdb->prepare( "SELECT SUM(pageviews) pageviews, SUM(visitors) visitors, SUM(sessions) sessions, SUM(new_visitors) new_visitors, SUM(engaged) engaged, SUM(bounces) bounces FROM {$s} WHERE period = 'd' AND pkey < %s", $first ),
					ARRAY_A
				);
				// phpcs:enable
				if ( $old && (int) $old['pageviews'] > 0 ) {
					foreach ( array( 'pageviews', 'visitors', 'sessions', 'new_visitors', 'engaged' ) as $k ) {
						$raw[ $k ] += (int) $old[ $k ];
					}
					$raw = livingdraft_an_shape( $raw, $raw['bounces'] + (int) $old['bounces'] );
				}
			}

			$raw['since'] = $first;
			return $raw;
		}
	);
}

/**
 * Real-time: readers active in the last N minutes.
 *
 * @param int $minutes Window.
 * @return array{visitors:int,pageviews:int,pages:array}
 */
function livingdraft_an_realtime( $minutes = 5 ) {
	global $wpdb;
	$t    = livingdraft_an_table();
	$from = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $minutes * MINUTE_IN_SECONDS );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(DISTINCT visitor) v, COUNT(*) p FROM {$t} WHERE created >= %s", $from ), ARRAY_A );

	$from30 = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * MINUTE_IN_SECONDS );
	$pages  = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, path, COUNT(*) pageviews FROM {$t} WHERE created >= %s GROUP BY post_id, path ORDER BY pageviews DESC LIMIT 5", $from30 ), ARRAY_A );
	// phpcs:enable

	return array(
		'visitors'  => (int) ( $row['v'] ?? 0 ),
		'pageviews' => (int) ( $row['p'] ?? 0 ),
		'pages'     => $pages ? $pages : array(),
	);
}

/**
 * A time series.
 *
 * @param string $grain day|week|month|year.
 * @param string $from  Y-m-d.
 * @param string $to    Y-m-d.
 * @return array[] Each: key, label, pageviews, visitors, sessions.
 */
function livingdraft_an_series( $grain, $from, $to ) {
	$grain = in_array( $grain, array( 'day', 'week', 'month', 'year' ), true ) ? $grain : 'day';

	return livingdraft_an_cached(
		'series|' . $grain . '|' . $from . '|' . $to,
		( $to >= current_time( 'Y-m-d' ) ) ? 3 * MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS,
		static function () use ( $grain, $from, $to ) {
			global $wpdb;
			$t = livingdraft_an_table();
			$s = livingdraft_an_stats_table();

			$sow = (int) get_option( 'start_of_week', 1 );
			$off = ( $sow + 6 ) % 7; // start_of_week expressed in MySQL WEEKDAY() terms (Mon=0).

			switch ( $grain ) {
				case 'week':
					$expr = "DATE_SUB(day, INTERVAL MOD(WEEKDAY(day) - {$off} + 7, 7) DAY)";
					$per  = 'w';
					break;
				case 'month':
					$expr = "DATE_FORMAT(day, '%%Y-%%m')"; // %% survives wpdb::prepare().
					$per  = 'm';
					break;
				case 'year':
					$expr = 'YEAR(day)';
					$per  = 'y';
					break;
				default:
					$expr = 'day';
					$per  = 'd';
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$expr} AS k, COUNT(*) pageviews, COUNT(DISTINCT visitor) visitors, COUNT(DISTINCT session) sessions
					FROM {$t} WHERE day BETWEEN %s AND %s GROUP BY k ORDER BY k",
					$from,
					$to
				),
				ARRAY_A
			);

			$roll = $wpdb->get_results(
				$wpdb->prepare( "SELECT pkey k, pageviews, visitors, sessions FROM {$s} WHERE period = %s", $per ),
				ARRAY_A
			);
			// phpcs:enable

			$live = array();
			foreach ( (array) $rows as $r ) {
				$live[ (string) $r['k'] ] = $r;
			}
			$saved = array();
			foreach ( (array) $roll as $r ) {
				$saved[ (string) $r['k'] ] = $r;
			}

			$out = array();
			foreach ( livingdraft_an_period_keys( $grain, $from, $to ) as $key => $label ) {
				$src   = $live[ $key ] ?? ( $saved[ $key ] ?? null ); // Raw wins; rollup fills expired history.
				$out[] = array(
					'key'       => $key,
					'label'     => $label,
					'pageviews' => (int) ( $src['pageviews'] ?? 0 ),
					'visitors'  => (int) ( $src['visitors'] ?? 0 ),
					'sessions'  => (int) ( $src['sessions'] ?? 0 ),
				);
			}
			return $out;
		}
	);
}

/**
 * Every period key between two dates, so gaps show as zero.
 *
 * @param string $grain Grain.
 * @param string $from  Y-m-d.
 * @param string $to    Y-m-d.
 * @return array key => label
 */
function livingdraft_an_period_keys( $grain, $from, $to ) {
	$keys = array();
	$ts   = strtotime( $from );
	$end  = strtotime( $to );

	switch ( $grain ) {
		case 'week':
			$ts = strtotime( livingdraft_an_week_start( $from ) );
			for ( $i = 0; $ts <= $end && $i < 600; $i++, $ts += WEEK_IN_SECONDS ) {
				$keys[ gmdate( 'Y-m-d', $ts ) ] = date_i18n( 'j M', $ts );
			}
			break;
		case 'month':
			$ts = strtotime( gmdate( 'Y-m-01', $ts ) );
			for ( $i = 0; $ts <= $end && $i < 240; $i++, $ts = strtotime( '+1 month', $ts ) ) {
				$keys[ gmdate( 'Y-m', $ts ) ] = date_i18n( 'M Y', $ts );
			}
			break;
		case 'year':
			for ( $y = (int) gmdate( 'Y', $ts ); $y <= (int) gmdate( 'Y', $end ); $y++ ) {
				$keys[ (string) $y ] = (string) $y;
			}
			break;
		default:
			for ( $i = 0; $ts <= $end && $i < 1200; $i++, $ts += DAY_IN_SECONDS ) {
				$keys[ gmdate( 'Y-m-d', $ts ) ] = date_i18n( 'j M', $ts );
			}
	}

	return $keys;
}

/**
 * Break-down tables for a date range.
 *
 * @param string $dim   pages|entries|channels|referrers|campaigns|devices|browsers|os|countries|hours.
 * @param string $from  Y-m-d H:i:s.
 * @param string $to    Y-m-d H:i:s.
 * @param int    $limit Rows.
 * @return array[]
 */
function livingdraft_an_breakdown( $dim, $from, $to, $limit = 10 ) {
	$ttl = ( strtotime( $to ) >= current_time( 'timestamp' ) - HOUR_IN_SECONDS ) ? 5 * MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS;

	return livingdraft_an_cached(
		'bd|' . $dim . '|' . $from . '|' . $to . '|' . $limit,
		$ttl,
		static function () use ( $dim, $from, $to, $limit ) {
			global $wpdb;
			$t     = livingdraft_an_table();
			$limit = max( 1, min( 500, (int) $limit ) );

			// Session-level dimensions count entries (one per session);
			// page-level ones count every pageview.
			$map = array(
				'pages'     => array( 'post_id, path', "COUNT(*) pageviews, COUNT(DISTINCT visitor) visitors, ROUND(AVG(NULLIF(engaged,0))) avg_engaged, ROUND(AVG(NULLIF(scroll,0))) avg_scroll", '1=1', 'pageviews' ),
				'entries'   => array( 'post_id, path', 'COUNT(*) sessions', 'is_entry = 1', 'sessions' ),
				'channels'  => array( 'channel', 'COUNT(*) sessions, COUNT(DISTINCT visitor) visitors', 'is_entry = 1', 'sessions' ),
				'referrers' => array( 'ref_host', 'COUNT(*) sessions, COUNT(DISTINCT visitor) visitors', "is_entry = 1 AND ref_host <> ''", 'sessions' ),
				'campaigns' => array( 'utm_source, utm_medium, utm_campaign', 'COUNT(*) sessions, COUNT(DISTINCT visitor) visitors', "is_entry = 1 AND (utm_campaign <> '' OR utm_source <> '')", 'sessions' ),
				'devices'   => array( 'device', 'COUNT(DISTINCT visitor) visitors, COUNT(*) pageviews', '1=1', 'visitors' ),
				'browsers'  => array( 'browser', 'COUNT(DISTINCT visitor) visitors, COUNT(*) pageviews', '1=1', 'visitors' ),
				'os'        => array( 'os', 'COUNT(DISTINCT visitor) visitors, COUNT(*) pageviews', '1=1', 'visitors' ),
				'countries' => array( 'country', 'COUNT(DISTINCT visitor) visitors, COUNT(*) pageviews', "country <> ''", 'visitors' ),
				'hours'     => array( 'HOUR(created) AS hour', 'COUNT(*) pageviews', '1=1', 'hour' ),
			);

			if ( ! isset( $map[ $dim ] ) ) {
				return array();
			}

			list( $group, $cols, $where, $order ) = $map[ $dim ];
			$group_by = ( 'hours' === $dim ) ? 'hour' : $group;
			$dir      = ( 'hours' === $dim ) ? 'ASC' : 'DESC';
			$lim      = ( 'hours' === $dim ) ? 24 : $limit;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$group}, {$cols} FROM {$t} WHERE created BETWEEN %s AND %s AND {$where} GROUP BY {$group_by} ORDER BY {$order} {$dir} LIMIT %d",
					$from,
					$to,
					$lim
				),
				ARRAY_A
			);

			return is_array( $rows ) ? $rows : array();
		}
	);
}

/**
 * Human title for a page row.
 *
 * @param int    $post_id Post.
 * @param string $path    Path.
 * @return string
 */
function livingdraft_an_page_title( $post_id, $path ) {
	if ( $post_id ) {
		$title = get_the_title( $post_id );
		if ( '' !== $title ) {
			return wp_strip_all_tags( $title );
		}
	}
	if ( '/' === $path ) {
		return __( 'Home page', 'livingdraft-core' );
	}
	return $path;
}

/* ==================================================================
 * 7. PUBLIC HELPERS FOR THEMES AND SNIPPETS
 * ================================================================== */

/**
 * Site-wide visitors and pageviews for a named period.
 *
 * Example: $s = livingdraft_site_stats( 'month' ); echo $s['visitors'];
 *
 * @param string $period today|yesterday|week|month|year|all.
 * @return array
 */
function livingdraft_site_stats( $period = 'today' ) {
	if ( ! livingdraft_an_ready() ) {
		return livingdraft_an_shape( array() );
	}
	if ( 'all' === $period ) {
		return livingdraft_an_all_time();
	}
	$p = livingdraft_an_named_period( $period );
	return livingdraft_an_totals( $p['from'], $p['to'] );
}

/* ==================================================================
 * 8. NIGHTLY: ROLLUPS AND RETENTION
 * ================================================================== */

/**
 * Save one period's totals to the permanent table.
 *
 * @param string $period d|w|m|y.
 * @param string $pkey   Key.
 * @param string $from   Y-m-d.
 * @param string $to     Y-m-d.
 */
function livingdraft_an_rollup_one( $period, $pkey, $from, $to ) {
	global $wpdb;
	$t = livingdraft_an_table();

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT COUNT(*) pageviews, COUNT(DISTINCT visitor) visitors, COUNT(DISTINCT session) sessions,
				COUNT(DISTINCT CASE WHEN is_new = 1 THEN visitor END) new_visitors, COALESCE(SUM(engaged),0) engaged
			FROM {$t} WHERE day BETWEEN %s AND %s",
			$from,
			$to
		),
		ARRAY_A
	);

	if ( ! $row || ! (int) $row['pageviews'] ) {
		return;
	}

	$bounces = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM (SELECT session, COUNT(*) c, SUM(engaged) e FROM {$t} WHERE day BETWEEN %s AND %s GROUP BY session) x WHERE x.c = 1 AND x.e < 10",
			$from,
			$to
		)
	);

	$wpdb->replace(
		livingdraft_an_stats_table(),
		array(
			'period'       => $period,
			'pkey'         => $pkey,
			'pageviews'    => (int) $row['pageviews'],
			'visitors'     => (int) $row['visitors'],
			'sessions'     => (int) $row['sessions'],
			'new_visitors' => (int) $row['new_visitors'],
			'bounces'      => $bounces,
			'engaged'      => (int) $row['engaged'],
			'updated'      => current_time( 'mysql' ),
		),
		array( '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s' )
	);
	// phpcs:enable
}

/**
 * Roll up the day, week, month and year that a given day belongs to.
 *
 * @param string $day Y-m-d.
 */
function livingdraft_an_rollup_for_day( $day ) {
	livingdraft_an_rollup_one( 'd', $day, $day, $day );

	$ws = livingdraft_an_week_start( $day );
	livingdraft_an_rollup_one( 'w', $ws, $ws, gmdate( 'Y-m-d', strtotime( $ws ) + 6 * DAY_IN_SECONDS ) );

	$ms = substr( $day, 0, 7 ) . '-01';
	livingdraft_an_rollup_one( 'm', substr( $day, 0, 7 ), $ms, gmdate( 'Y-m-t', strtotime( $ms ) ) );

	$y = substr( $day, 0, 4 );
	livingdraft_an_rollup_one( 'y', $y, $y . '-01-01', $y . '-12-31' );
}

/**
 * The nightly job.
 */
function livingdraft_an_nightly() {
	if ( ! livingdraft_an_ready() ) {
		return;
	}

	$yesterday = gmdate( 'Y-m-d', current_time( 'timestamp' ) - DAY_IN_SECONDS );

	// Catch up on any nights cron missed (low-traffic sites), up to 14.
	$last = (string) get_option( 'livingdraft_an_rolled_through', '' );
	$day  = $last ? gmdate( 'Y-m-d', strtotime( $last ) + DAY_IN_SECONDS ) : $yesterday;
	if ( $day < gmdate( 'Y-m-d', strtotime( $yesterday ) - 13 * DAY_IN_SECONDS ) ) {
		$day = gmdate( 'Y-m-d', strtotime( $yesterday ) - 13 * DAY_IN_SECONDS );
	}

	for ( $i = 0; $day <= $yesterday && $i < 14; $i++ ) {
		livingdraft_an_rollup_for_day( $day );
		$day = gmdate( 'Y-m-d', strtotime( $day ) + DAY_IN_SECONDS );
	}
	update_option( 'livingdraft_an_rolled_through', $yesterday, false );

	// Retention: delete raw rows past the window, in batches so a big site
	// never locks the table. Every deleted day already has its rollup.
	global $wpdb;
	$t      = livingdraft_an_table();
	$cutoff = gmdate( 'Y-m-d', current_time( 'timestamp' ) - livingdraft_an_settings()['retention'] * DAY_IN_SECONDS );

	for ( $i = 0; $i < 50; $i++ ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$gone = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE day < %s LIMIT 5000", $cutoff ) );
		if ( $gone < 5000 ) {
			break;
		}
	}

	livingdraft_an_flush_cache();
}
add_action( 'livingdraft_analytics_nightly', 'livingdraft_an_nightly' );

/**
 * Schedule at ~00:20 site time.
 */
function livingdraft_an_schedule() {
	if ( wp_next_scheduled( 'livingdraft_analytics_nightly' ) ) {
		return;
	}
	$tz   = wp_timezone();
	$next = new DateTime( 'tomorrow 00:20', $tz );
	wp_schedule_event( $next->getTimestamp(), 'daily', 'livingdraft_analytics_nightly' );
}
add_action( 'init', 'livingdraft_an_schedule' );
