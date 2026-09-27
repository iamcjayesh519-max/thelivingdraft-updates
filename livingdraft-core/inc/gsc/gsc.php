<?php
/**
 * Google Search Console + Indexing API — core auth + API layer.
 *
 * Uses a Google Cloud service account (JWT bearer) rather than user
 * OAuth. The site owner uploads the service account JSON key once, adds
 * the service account email as an owner or viewer in Search Console,
 * and the plugin talks to Google on that account's behalf. No per-user
 * OAuth dance, no expiring refresh tokens to babysit.
 *
 * === WHAT WE TALK TO ===
 *
 * Search Analytics API — clicks, impressions, CTR, position per page.
 *   Endpoint: searchconsole.googleapis.com/webmasters/v3/…/searchAnalytics/query
 *   Scope:    webmasters.readonly
 *
 * Indexing API — notify Google when a URL is added/updated.
 *   Endpoint: indexing.googleapis.com/v3/urlNotifications:publish
 *   Scope:    indexing
 *
 *   IMPORTANT: officially, Google restricts the Indexing API to pages
 *   with JobPosting or BroadcastEvent schema. For anything else it
 *   accepts requests and returns success, but treats them as an
 *   optional crawl hint rather than a first-class signal. No penalty
 *   for using it on regular pages — Google's own words are "may
 *   deprioritize" — but the quota is real. Default 200/day per project.
 *   We surface this in the admin so the user decides per-site.
 *
 * === CACHING ACCESS TOKENS ===
 *
 * Tokens are valid for 3600s. We cache in a transient, keyed by hash
 * of the service account email so switching accounts doesn't reuse a
 * stale token.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------
 * 1. SETTINGS + CREDENTIAL STORAGE
 * ------------------------------------------------------------------ */

function livingdraft_gsc_get_settings() {
	$stored = get_option( 'livingdraft_gsc_settings', array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	return wp_parse_args(
		$stored,
		array(
			'property_url'      => '', // e.g. 'https://example.com/' or 'sc-domain:example.com'
			'indexing_enabled'  => 0,  // Auto-submit on publish.
			'analytics_enabled' => 0,  // Nightly analytics sync.
		)
	);
}

/**
 * The service account JSON key blob. Encrypted at rest so a database
 * dump doesn't expose the private key.
 */
function livingdraft_gsc_get_credentials() {
	$stored = get_option( 'livingdraft_gsc_credentials', '' );
	if ( '' === $stored ) {
		return null;
	}
	$json = livingdraft_ai_decrypt( $stored ); // Reuses the AI provider's encryption helper.
	if ( '' === $json ) {
		return null;
	}
	$data = json_decode( $json, true );
	return is_array( $data ) && ! empty( $data['client_email'] ) && ! empty( $data['private_key'] ) ? $data : null;
}

function livingdraft_gsc_set_credentials( $json_string ) {
	if ( '' === $json_string ) {
		delete_option( 'livingdraft_gsc_credentials' );
		return true;
	}
	$data = json_decode( $json_string, true );
	if ( ! is_array( $data ) || empty( $data['client_email'] ) || empty( $data['private_key'] ) ) {
		return new WP_Error( 'ld_gsc_bad_json', __( 'That doesn\'t look like a service account JSON key.', 'livingdraft-core' ) );
	}
	if ( ! isset( $data['type'] ) || 'service_account' !== $data['type'] ) {
		return new WP_Error( 'ld_gsc_wrong_type', __( 'The JSON is not a service account key (wrong "type").', 'livingdraft-core' ) );
	}
	// Store the raw JSON as encrypted string — we JSON-decode again on read.
	update_option( 'livingdraft_gsc_credentials', livingdraft_ai_encrypt( wp_json_encode( $data ) ), false );
	return true;
}

function livingdraft_gsc_configured() {
	if ( null === livingdraft_gsc_get_credentials() ) {
		return false;
	}
	$s = livingdraft_gsc_get_settings();
	return '' !== $s['property_url'];
}

/* ------------------------------------------------------------------
 * 2. JWT + TOKEN EXCHANGE
 *
 * Standard RS256 JWT signed with the service account's private key,
 * exchanged for a short-lived OAuth access token at the Google token
 * endpoint. Tokens cached per-scope so we don't re-sign on every call.
 * ------------------------------------------------------------------ */

/**
 * Get an access token for the given scope. Returns the raw token string
 * or WP_Error on failure. Cached until the token's actual expiry minus
 * a 60-second safety margin.
 */
function livingdraft_gsc_access_token( $scope ) {
	$creds = livingdraft_gsc_get_credentials();
	if ( ! $creds ) {
		return new WP_Error( 'ld_gsc_no_creds', __( 'No service account credentials configured.', 'livingdraft-core' ) );
	}

	$cache_key = 'ld_gsc_tok_' . substr( md5( $creds['client_email'] . '|' . $scope ), 0, 20 );
	$cached    = get_transient( $cache_key );
	if ( is_string( $cached ) && '' !== $cached ) {
		return $cached;
	}

	$jwt = livingdraft_gsc_build_jwt( $creds['client_email'], $creds['private_key'], $scope );
	if ( is_wp_error( $jwt ) ) {
		return $jwt;
	}

	$response = wp_remote_post(
		'https://oauth2.googleapis.com/token',
		array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			'body'    => array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt,
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

	if ( 200 !== $code || empty( $body['access_token'] ) ) {
		$msg = is_array( $body ) && isset( $body['error_description'] )
			? $body['error_description']
			: ( is_array( $body ) && isset( $body['error'] ) ? $body['error'] : 'HTTP ' . $code );
		return new WP_Error( 'ld_gsc_token_error', $msg );
	}

	$token = (string) $body['access_token'];
	$ttl   = isset( $body['expires_in'] ) ? max( 60, (int) $body['expires_in'] - 60 ) : 3300;
	set_transient( $cache_key, $token, $ttl );
	return $token;
}

/**
 * Build a signed JWT for the Google OAuth token endpoint.
 * RS256, standard three-part encoding.
 */
function livingdraft_gsc_build_jwt( $client_email, $private_key, $scope ) {
	if ( ! function_exists( 'openssl_sign' ) ) {
		return new WP_Error( 'ld_gsc_no_openssl', __( 'PHP OpenSSL extension required for JWT signing.', 'livingdraft-core' ) );
	}

	$now = time();
	$header = array( 'alg' => 'RS256', 'typ' => 'JWT' );
	$claims = array(
		'iss'   => $client_email,
		'scope' => $scope,
		'aud'   => 'https://oauth2.googleapis.com/token',
		'exp'   => $now + 3600,
		'iat'   => $now,
	);

	$h = livingdraft_gsc_b64url( wp_json_encode( $header ) );
	$c = livingdraft_gsc_b64url( wp_json_encode( $claims ) );
	$signing_input = $h . '.' . $c;

	$signature = '';
	$ok = openssl_sign( $signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256 );
	if ( ! $ok ) {
		return new WP_Error( 'ld_gsc_sign_fail', __( 'JWT signing failed — the private key may be malformed.', 'livingdraft-core' ) );
	}

	return $signing_input . '.' . livingdraft_gsc_b64url( $signature );
}

/**
 * URL-safe Base64 without padding — required by the JWT spec.
 */
function livingdraft_gsc_b64url( $data ) {
	return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore
}

/* ------------------------------------------------------------------
 * 3. API WRAPPERS
 * ------------------------------------------------------------------ */

/**
 * Query the Search Analytics API for one URL (or all URLs if $url is
 * empty). Default date range: last 28 days ending 3 days ago (GSC data
 * has a 2-3 day latency).
 *
 * @param string $url        Full URL to filter by, or '' for site-wide.
 * @param int    $row_limit  Max rows to return (up to 25000).
 * @param string $start_date YYYY-MM-DD or '' for default.
 * @param string $end_date   YYYY-MM-DD or '' for default.
 * @return array|WP_Error {
 *   rows: [ { keys: [page_url], clicks, impressions, ctr, position }, ... ]
 * }
 */
function livingdraft_gsc_query_analytics( $url = '', $row_limit = 1000, $start_date = '', $end_date = '' ) {
	$token = livingdraft_gsc_access_token( 'https://www.googleapis.com/auth/webmasters.readonly' );
	if ( is_wp_error( $token ) ) {
		return $token;
	}

	$settings = livingdraft_gsc_get_settings();
	$property = $settings['property_url'];
	if ( '' === $property ) {
		return new WP_Error( 'ld_gsc_no_property', __( 'GSC property URL not configured.', 'livingdraft-core' ) );
	}

	// Default window: last 28 days ending 3 days ago (GSC data latency).
	if ( '' === $start_date ) {
		$start_date = gmdate( 'Y-m-d', strtotime( '-31 days' ) );
	}
	if ( '' === $end_date ) {
		$end_date = gmdate( 'Y-m-d', strtotime( '-3 days' ) );
	}

	$body = array(
		'startDate'  => $start_date,
		'endDate'    => $end_date,
		'dimensions' => array( 'page' ),
		'rowLimit'   => (int) $row_limit,
	);

	if ( '' !== $url ) {
		$body['dimensionFilterGroups'] = array(
			array(
				'filters' => array(
					array(
						'dimension'  => 'page',
						'operator'   => 'equals',
						'expression' => $url,
					),
				),
			),
		);
	}

	$endpoint = sprintf(
		'https://searchconsole.googleapis.com/webmasters/v3/sites/%s/searchAnalytics/query',
		rawurlencode( $property )
	);

	$response = wp_remote_post(
		$endpoint,
		array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

	if ( 200 !== $code ) {
		$msg = is_array( $decoded ) && isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : 'HTTP ' . $code;
		return new WP_Error( 'ld_gsc_analytics_error', $msg );
	}

	return $decoded;
}

/**
 * Notify Google that a URL was updated. Wraps Indexing API's
 * urlNotifications:publish endpoint.
 *
 * @param string $url  Full URL of the page.
 * @param string $type URL_UPDATED (default) or URL_DELETED.
 * @return array|WP_Error [ 'code' => int, 'message' => str, 'body' => array ]
 */
function livingdraft_gsc_indexing_submit( $url, $type = 'URL_UPDATED' ) {
	$token = livingdraft_gsc_access_token( 'https://www.googleapis.com/auth/indexing' );
	if ( is_wp_error( $token ) ) {
		return array(
			'code'    => 0,
			'message' => $token->get_error_message(),
			'body'    => null,
		);
	}

	if ( ! in_array( $type, array( 'URL_UPDATED', 'URL_DELETED' ), true ) ) {
		$type = 'URL_UPDATED';
	}

	$response = wp_remote_post(
		'https://indexing.googleapis.com/v3/urlNotifications:publish',
		array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array( 'url' => $url, 'type' => $type ) ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return array(
			'code'    => 0,
			'message' => $response->get_error_message(),
			'body'    => null,
		);
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

	// v3.4.0: extract the specific error reason from Google's response
	// body — the top-level "message" is often generic ("Permission
	// denied") while the `errors[0].reason` or `status` fields tell you
	// exactly which of the seven auth failures happened
	// ("SERVICE_DISABLED", "PERMISSION_DENIED", "IAM_PERMISSION_DENIED"
	// etc). This is the difference between a support ticket ("it says
	// 403") and a fix ("your Indexing API isn't enabled").
	$detail = '';
	if ( is_array( $body ) && isset( $body['error'] ) && is_array( $body['error'] ) ) {
		$parts = array();
		if ( ! empty( $body['error']['status'] ) ) {
			$parts[] = $body['error']['status'];
		}
		if ( ! empty( $body['error']['errors'][0]['reason'] ) ) {
			$parts[] = $body['error']['errors'][0]['reason'];
		}
		if ( ! empty( $body['error']['message'] ) ) {
			$parts[] = $body['error']['message'];
		}
		$detail = implode( ' — ', array_unique( $parts ) );
	}

	// Codes: 200 = accepted; 429 = daily quota exceeded; 403 = auth /
	// site verification issue; 400 = malformed URL.
	// When we have a detailed message from Google, it wins — it's more
	// actionable than our static mapping.
	$msg_map = array(
		200 => __( 'Accepted — Google will fetch soon.', 'livingdraft-core' ),
		400 => __( 'Bad request — check the URL format.', 'livingdraft-core' ),
		403 => __( 'Forbidden — service account not verified in Search Console as Owner, or Indexing API not enabled in Google Cloud project.', 'livingdraft-core' ),
		404 => __( 'Not found — the resource endpoint responded with 404.', 'livingdraft-core' ),
		429 => __( 'Daily quota exceeded — resets at UTC midnight.', 'livingdraft-core' ),
	);

	if ( 200 === $code ) {
		$msg = $msg_map[200];
	} elseif ( '' !== $detail ) {
		$msg = $detail;
	} else {
		$msg = $msg_map[ $code ] ?? sprintf( 'HTTP %d', $code );
	}

	return array(
		'code'    => $code,
		'message' => $msg,
		'body'    => $body,
	);
}

/**
 * Convenience: how many Indexing API submissions have we made today?
 * Used by the admin quota display and to skip auto-submits when we're
 * about to hit the daily cap.
 */
function livingdraft_gsc_indexing_submissions_today() {
	$log = get_option( 'livingdraft_gsc_indexing_log', array() );
	if ( ! is_array( $log ) ) {
		return 0;
	}
	$today = gmdate( 'Y-m-d' );
	$count = 0;
	foreach ( $log as $row ) {
		if ( isset( $row['time'] ) && gmdate( 'Y-m-d', (int) $row['time'] ) === $today && 200 === (int) ( $row['code'] ?? 0 ) ) {
			$count++;
		}
	}
	return $count;
}
