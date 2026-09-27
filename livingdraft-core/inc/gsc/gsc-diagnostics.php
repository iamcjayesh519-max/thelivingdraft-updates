<?php
/**
 * GSC diagnostics.
 *
 * Seven checks that pinpoint exactly which of the many possible
 * failure modes is happening between "I filled in the form" and
 * "I see metrics on my posts". Run from the Diagnostics button
 * on the SEO → Search Console tab; results render inline.
 *
 * Order matters — earlier checks are prerequisites for later ones
 * (no point testing an Indexing API token if openssl isn't loaded),
 * so we run sequentially and stop noting "SKIPPED" for anything
 * downstream once a prerequisite fails.
 *
 * @package LivingDraftCore
 * @since   3.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run all diagnostics and return an ordered list of check results.
 * Each result: [ 'name', 'status' => pass|fail|warn|skip, 'detail' => string, 'fix' => string ]
 *
 * `status`:
 *   pass  — everything OK
 *   warn  — works, but suboptimal (e.g. cache is old)
 *   fail  — broken, blocks downstream capability
 *   skip  — downstream check skipped because a prerequisite failed
 */
function livingdraft_gsc_run_diagnostics() {
	$out = array();

	/* -----------------------------------------------------------
	 * 1. OpenSSL PHP extension. Required by every subsequent step
	 * because JWT signing uses openssl_sign(). Fails silently on
	 * cheap shared hosts where PHP was compiled without openssl.
	 * ----------------------------------------------------------- */
	$openssl_ok = function_exists( 'openssl_sign' );
	$out[] = array(
		'name'   => __( '1. PHP openssl extension', 'livingdraft-core' ),
		'status' => $openssl_ok ? 'pass' : 'fail',
		'detail' => $openssl_ok
			? __( 'Available. JWT signing will work.', 'livingdraft-core' )
			: __( 'The openssl PHP extension is not loaded. Google Search Console integration cannot sign the JWT tokens it needs.', 'livingdraft-core' ),
		'fix'    => $openssl_ok ? '' : __( 'Ask your host to enable the PHP openssl extension. On most managed WordPress hosts this is a support ticket, not a code change.', 'livingdraft-core' ),
	);

	/* -----------------------------------------------------------
	 * 2. Credentials decrypt to a valid service account object.
	 * ----------------------------------------------------------- */
	if ( ! $openssl_ok ) {
		$out[] = array(
			'name'   => __( '2. Service account credentials', 'livingdraft-core' ),
			'status' => 'skip',
			'detail' => __( 'Skipped because openssl is unavailable.', 'livingdraft-core' ),
			'fix'    => '',
		);
	} else {
		$creds = livingdraft_gsc_get_credentials();
		if ( ! $creds ) {
			$out[] = array(
				'name'   => __( '2. Service account credentials', 'livingdraft-core' ),
				'status' => 'fail',
				'detail' => __( 'No service account credentials stored, or the stored JSON failed to decrypt / parse.', 'livingdraft-core' ),
				'fix'    => __( 'Paste your Google Cloud service account JSON key into the Service account JSON field above and Save.', 'livingdraft-core' ),
			);
		} else {
			$out[] = array(
				'name'   => __( '2. Service account credentials', 'livingdraft-core' ),
				'status' => 'pass',
				'detail' => sprintf(
					/* translators: %s: service account email */
					__( 'Loaded. Account: %s', 'livingdraft-core' ),
					$creds['client_email']
				),
				'fix'    => '',
			);
		}
	}

	/* -----------------------------------------------------------
	 * 3. Property URL configured, and in a plausible format.
	 * We don't hit the API yet — that's step 5. We just validate
	 * the syntactic form here so the user gets an early warning
	 * before the network round-trip.
	 * ----------------------------------------------------------- */
	$s        = livingdraft_gsc_get_settings();
	$property = trim( (string) $s['property_url'] );
	if ( '' === $property ) {
		$out[] = array(
			'name'   => __( '3. Property URL', 'livingdraft-core' ),
			'status' => 'fail',
			'detail' => __( 'No property URL set.', 'livingdraft-core' ),
			'fix'    => __( 'In the Property URL field, paste the exact identifier from Search Console\'s property picker. Either "https://example.com/" (URL-prefix, must match protocol + subdomain + trailing slash) or "sc-domain:example.com" (domain property).', 'livingdraft-core' ),
		);
	} elseif ( 0 === strpos( $property, 'sc-domain:' ) ) {
		$domain = substr( $property, 10 );
		if ( '' === $domain || false !== strpos( $domain, '/' ) || false !== strpos( $domain, '://' ) ) {
			$out[] = array(
				'name'   => __( '3. Property URL', 'livingdraft-core' ),
				'status' => 'fail',
				'detail' => sprintf( __( 'Property "%s" is a domain property but the domain part looks wrong. Domain properties are "sc-domain:example.com" — no protocol, no slash, no path.', 'livingdraft-core' ), $property ),
				'fix'    => __( 'Fix the Property URL field.', 'livingdraft-core' ),
			);
		} else {
			$out[] = array(
				'name'   => __( '3. Property URL', 'livingdraft-core' ),
				'status' => 'pass',
				'detail' => sprintf( __( 'Valid domain property: %s', 'livingdraft-core' ), $property ),
				'fix'    => '',
			);
		}
	} else {
		// URL-prefix. Must be a valid URL with a scheme and end with /.
		$parts = wp_parse_url( $property );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			$out[] = array(
				'name'   => __( '3. Property URL', 'livingdraft-core' ),
				'status' => 'fail',
				'detail' => sprintf( __( 'Property "%s" is not a valid URL. URL-prefix properties look like "https://example.com/".', 'livingdraft-core' ), $property ),
				'fix'    => __( 'Fix the Property URL field or use the domain-property form "sc-domain:example.com".', 'livingdraft-core' ),
			);
		} elseif ( '/' !== substr( $property, -1 ) ) {
			$out[] = array(
				'name'   => __( '3. Property URL', 'livingdraft-core' ),
				'status' => 'warn',
				'detail' => sprintf( __( 'Property "%s" is a URL-prefix but has no trailing slash. Google is strict — the string must match Search Console exactly.', 'livingdraft-core' ), $property ),
				'fix'    => __( 'Add a trailing slash to the Property URL, or copy-paste it verbatim from Search Console\'s property picker.', 'livingdraft-core' ),
			);
		} else {
			// Also warn if property doesn't match the actual home_url.
			$home = home_url( '/' );
			$out[] = array(
				'name'   => __( '3. Property URL', 'livingdraft-core' ),
				'status' => $home === $property ? 'pass' : 'warn',
				'detail' => $home === $property
					? sprintf( __( 'Valid URL-prefix property: %s', 'livingdraft-core' ), $property )
					: sprintf( __( 'Valid URL-prefix property (%1$s), but this site\'s home URL is (%2$s). If Google indexes URLs under the site\'s home URL and your property is registered against a different form, cache lookups will miss.', 'livingdraft-core' ), $property, $home ),
				'fix'    => $home === $property ? '' : __( 'Either set the Property URL to match this site\'s home URL, or ensure Search Console is verified for the domain-property form (sc-domain:).', 'livingdraft-core' ),
			);
		}
	}

	/* -----------------------------------------------------------
	 * 4. Analytics access token — real network call.
	 * ----------------------------------------------------------- */
	if ( ! $openssl_ok || ! livingdraft_gsc_get_credentials() ) {
		$out[] = array(
			'name'   => __( '4. Analytics access token (webmasters.readonly)', 'livingdraft-core' ),
			'status' => 'skip',
			'detail' => __( 'Skipped because credentials are missing.', 'livingdraft-core' ),
			'fix'    => '',
		);
	} else {
		// Clear any cached token so we're actually testing the current
		// key material, not a token that pre-dates a fix.
		$creds     = livingdraft_gsc_get_credentials();
		$cache_key = 'ld_gsc_tok_' . substr( md5( $creds['client_email'] . '|https://www.googleapis.com/auth/webmasters.readonly' ), 0, 20 );
		delete_transient( $cache_key );
		$tok = livingdraft_gsc_access_token( 'https://www.googleapis.com/auth/webmasters.readonly' );
		if ( is_wp_error( $tok ) ) {
			$out[] = array(
				'name'   => __( '4. Analytics access token (webmasters.readonly)', 'livingdraft-core' ),
				'status' => 'fail',
				'detail' => $tok->get_error_message(),
				'fix'    => __( 'This usually means the private_key in your JSON is malformed (line breaks stripped on paste). Re-download the JSON key from Google Cloud and paste it whole, without editing.', 'livingdraft-core' ),
			);
		} else {
			$out[] = array(
				'name'   => __( '4. Analytics access token (webmasters.readonly)', 'livingdraft-core' ),
				'status' => 'pass',
				'detail' => __( 'Token obtained. Google accepted the JWT signed with your private key.', 'livingdraft-core' ),
				'fix'    => '',
			);
		}
	}

	/* -----------------------------------------------------------
	 * 5. Search Analytics query works for the configured property.
	 * This is the check that catches "service account not added as
	 * a user in Search Console" — the #1 support issue.
	 * ----------------------------------------------------------- */
	if ( ! $openssl_ok || ! livingdraft_gsc_get_credentials() || '' === $property ) {
		$out[] = array(
			'name'   => __( '5. Search Console property query', 'livingdraft-core' ),
			'status' => 'skip',
			'detail' => __( 'Skipped due to earlier failures.', 'livingdraft-core' ),
			'fix'    => '',
		);
	} else {
		$result = livingdraft_gsc_query_analytics( '', 1 );
		if ( is_wp_error( $result ) ) {
			$msg = $result->get_error_message();
			// The error message from Google is often specific enough to
			// pin down which of the three sub-failures it is.
			$fix = __( 'Most likely: the service account (see step 2) is not added as a user in Search Console for this property. Go to Search Console → Settings → Users and permissions → Add User, paste the service-account email, and give it Owner (Owner is required for Indexing API, and works for Analytics too).', 'livingdraft-core' );
			if ( false !== stripos( $msg, 'not been used' ) || false !== stripos( $msg, 'has not been enabled' ) ) {
				$fix = __( 'The Search Console API is not enabled for your Google Cloud project. Visit console.cloud.google.com → APIs & Services → Library → Search Console API → Enable.', 'livingdraft-core' );
			} elseif ( false !== stripos( $msg, 'not found' ) || false !== stripos( $msg, '404' ) ) {
				$fix = __( 'The property URL doesn\'t match a verified property. Check the exact format in Search Console\'s property picker and paste it verbatim.', 'livingdraft-core' );
			}
			$out[] = array(
				'name'   => __( '5. Search Console property query', 'livingdraft-core' ),
				'status' => 'fail',
				'detail' => $msg,
				'fix'    => $fix,
			);
		} else {
			$row_count = isset( $result['rows'] ) && is_array( $result['rows'] ) ? count( $result['rows'] ) : 0;
			$out[] = array(
				'name'   => __( '5. Search Console property query', 'livingdraft-core' ),
				'status' => 'pass',
				'detail' => 0 === $row_count
					? __( 'Query accepted but no rows returned. This is normal for very new sites — Google needs time to accumulate impressions. Come back after a few days.', 'livingdraft-core' )
					: sprintf( __( 'Query succeeded, %d row(s) returned.', 'livingdraft-core' ), $row_count ),
				'fix'    => '',
			);
		}
	}

	/* -----------------------------------------------------------
	 * 6. Indexing API access token + capability. Only tests the
	 * token exchange, not an actual submission (we don't want to
	 * burn quota on a diagnostic click).
	 * ----------------------------------------------------------- */
	if ( ! $openssl_ok || ! livingdraft_gsc_get_credentials() ) {
		$out[] = array(
			'name'   => __( '6. Indexing API access token', 'livingdraft-core' ),
			'status' => 'skip',
			'detail' => __( 'Skipped due to earlier failures.', 'livingdraft-core' ),
			'fix'    => '',
		);
	} elseif ( ! $s['indexing_enabled'] ) {
		$out[] = array(
			'name'   => __( '6. Indexing API access token', 'livingdraft-core' ),
			'status' => 'warn',
			'detail' => __( 'Auto-indexing is turned off in settings, so this test was skipped. If you want new posts submitted to Google\'s Indexing API on publish, enable the toggle above.', 'livingdraft-core' ),
			'fix'    => '',
		);
	} else {
		$creds     = livingdraft_gsc_get_credentials();
		$cache_key = 'ld_gsc_tok_' . substr( md5( $creds['client_email'] . '|https://www.googleapis.com/auth/indexing' ), 0, 20 );
		delete_transient( $cache_key );
		$tok = livingdraft_gsc_access_token( 'https://www.googleapis.com/auth/indexing' );
		if ( is_wp_error( $tok ) ) {
			$out[] = array(
				'name'   => __( '6. Indexing API access token', 'livingdraft-core' ),
				'status' => 'fail',
				'detail' => $tok->get_error_message(),
				'fix'    => __( 'The token exchange failed. Check that the Indexing API is enabled in your Google Cloud project (console.cloud.google.com → APIs & Services → Library → Indexing API → Enable).', 'livingdraft-core' ),
			);
		} else {
			$today = livingdraft_gsc_indexing_submissions_today();
			$out[] = array(
				'name'   => __( '6. Indexing API access token', 'livingdraft-core' ),
				'status' => 'pass',
				'detail' => sprintf( __( 'Token obtained. Today\'s usage: %1$d / 200. Note: Google\'s Indexing API is officially for JobPosting and BroadcastEvent schema; other content types are accepted as a "crawl hint" but Google reserves the right not to process them. For regular articles, discovery via sitemap + IndexNow is more reliable.', 'livingdraft-core' ), $today ),
				'fix'    => '',
			);
		}
	}

	/* -----------------------------------------------------------
	 * 7. Cache health. Warn on stale, warn on empty, pass on fresh.
	 * ----------------------------------------------------------- */
	$meta = get_option( LD_GSC_CACHE_META_OPT, array() );
	if ( ! $s['analytics_enabled'] ) {
		$out[] = array(
			'name'   => __( '7. Analytics cache', 'livingdraft-core' ),
			'status' => 'warn',
			'detail' => __( 'Analytics sync is disabled — no metrics will appear on posts. Turn on the "Sync analytics daily" toggle above.', 'livingdraft-core' ),
			'fix'    => '',
		);
	} else {
		$refreshed = isset( $meta['refreshed'] ) ? (int) $meta['refreshed'] : 0;
		$count     = isset( $meta['count'] ) ? (int) $meta['count'] : 0;
		if ( 0 === $refreshed ) {
			$out[] = array(
				'name'   => __( '7. Analytics cache', 'livingdraft-core' ),
				'status' => 'warn',
				'detail' => __( 'Cache has never been populated.', 'livingdraft-core' ),
				'fix'    => __( 'Click "Sync now" on this tab to populate it. From v3.4.0 onwards, saving settings for the first time also warms the cache automatically.', 'livingdraft-core' ),
			);
		} elseif ( $refreshed < time() - 3 * DAY_IN_SECONDS ) {
			$out[] = array(
				'name'   => __( '7. Analytics cache', 'livingdraft-core' ),
				'status' => 'warn',
				'detail' => sprintf(
					/* translators: 1: how long ago, 2: page count */
					__( 'Cache is stale — last refreshed %1$s ago. WP-Cron may have stopped firing (common on sites with a page cache). Contains %2$d pages.', 'livingdraft-core' ),
					human_time_diff( $refreshed, time() ),
					$count
				),
				'fix'    => __( 'Click "Sync now" to refresh manually. If the nightly cron never fires, install a real cron job via your host to trigger wp-cron.php once per hour.', 'livingdraft-core' ),
			);
		} else {
			$out[] = array(
				'name'   => __( '7. Analytics cache', 'livingdraft-core' ),
				'status' => 'pass',
				'detail' => sprintf(
					/* translators: 1: how long ago, 2: page count */
					__( 'Fresh (%1$s ago). Holds %2$d pages.', 'livingdraft-core' ),
					human_time_diff( $refreshed, time() ),
					$count
				),
				'fix'    => '',
			);
		}
	}

	return $out;
}

/**
 * AJAX endpoint — runs diagnostics and returns the result set as JSON.
 * The tab-render side prints it as an inline card of pass/fail rows.
 */
function livingdraft_gsc_ajax_diagnostics() {
	check_ajax_referer( 'ld_gsc_diagnostics', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'livingdraft-core' ) ) );
	}
	wp_send_json_success( array( 'checks' => livingdraft_gsc_run_diagnostics() ) );
}
add_action( 'wp_ajax_ld_gsc_diagnostics', 'livingdraft_gsc_ajax_diagnostics' );
