<?php
/**
 * The AI provider layer.
 *
 * Every AI feature in this plugin — meta generation, alt text, "fix this
 * SEO check", the eventual content brief — goes through this one function:
 *
 *   $text = livingdraft_ai_complete( $prompt, [ 'task' => 'meta_description' ] );
 *
 * The user picks their provider in Settings → AI. This module owns the
 * translation into OpenAI, Gemini, or OpenRouter shapes; the caller does
 * not have to care which one is active or what its request format looks
 * like. Same code works when the user switches providers.
 *
 * === KEY STORAGE ===
 *
 * API keys are encrypted at rest with openssl_encrypt against WP's own
 * SECURE_AUTH_KEY. That is not FIPS-grade, and anyone with database
 * access AND wp-config.php can still read them — but it defends against
 * a database dump alone (backups, logs, replication targets), which is
 * the realistic threat here.
 *
 * === RATE LIMITING ===
 *
 * Per-user transient bucket, 30 calls per 5 minutes by default. Stops
 * a runaway JavaScript loop from burning through the user's own API
 * budget in a minute. Filterable.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------
 * 1. THE ONE FUNCTION EVERYTHING ELSE CALLS
 * ------------------------------------------------------------------ */

/**
 * Send a prompt to the user's configured provider and return the text.
 *
 * @param string $prompt The prompt (single-turn user message).
 * @param array  $args {
 *   @type string $task         Short identifier for rate-limit + logging.
 *                              e.g. 'meta_description', 'seo_title'.
 *   @type string $system       Optional system message.
 *   @type int    $max_tokens   Max output tokens. Default 400.
 *   @type float  $temperature  0..2. Default 0.6.
 *   @type string $model        Override the configured model.
 *   @type array  $image        Optional image for vision tasks:
 *                              [ 'data' => raw bytes, 'mime' => 'image/jpeg' ].
 *                              Sent as base64 (data URL for OpenAI/OpenRouter,
 *                              inline_data for Gemini) so the model doesn't
 *                              have to fetch from our site — matters for staging
 *                              environments and private URLs.
 * }
 * @return string|WP_Error Text response, or WP_Error on failure.
 */
function livingdraft_ai_complete( $prompt, $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'task'        => 'generic',
			'system'      => '',
			'max_tokens'  => 400,
			'temperature' => 0.6,
			'model'       => '',
			'provider'    => '',
			'image'       => null,
		)
	);

	$settings = livingdraft_ai_get_settings();

	// Per-invocation provider override — every AI-invoking surface can
	// pass its own choice through. Falls back to the active provider
	// configured in Settings when not specified. The key still comes
	// from the chosen provider's own stored credential.
	$provider = '' !== $args['provider'] ? $args['provider'] : $settings['provider'];
	if ( ! in_array( $provider, array( 'openai', 'gemini', 'openrouter', 'groq' ), true ) ) {
		$provider = $settings['provider'];
	}

	$key = livingdraft_ai_get_key( $provider );

	if ( '' === $key ) {
		return new WP_Error(
			'ld_ai_no_key',
			sprintf(
				/* translators: %s: provider name. */
				__( 'No API key configured for %s. Add one in The Living Draft → SEO → AI.', 'livingdraft-core' ),
				$provider
			)
		);
	}

	// Rate limit: stops runaway loops eating the user's own AI quota.
	//
	// v3.7.2: server-set callers can pass `bypass_rate_limit => true`
	// to skip this check. Meant for INTENTIONAL bulk operations (the
	// bulk term generator, future backfill workers) where the user
	// explicitly kicked off a batch job and the rate limit's
	// "runaway UI protection" purpose doesn't apply.
	//
	// Critical: this flag is ONLY set by trusted server-side code
	// paths (e.g. livingdraft_seo_desk_ajax_generate_term after it
	// has authenticated the request and confirmed capabilities). It
	// is NEVER read from $_POST or $_GET — livingdraft_ai_read_request_override()
	// does not pass it through. Adding it to a client-facing sanitizer
	// in the future would be a security regression.
	if ( empty( $args['bypass_rate_limit'] ) ) {
		$limit_check = livingdraft_ai_check_rate_limit();
		if ( is_wp_error( $limit_check ) ) {
			return $limit_check;
		}
	}

	$model = $args['model'] ?: livingdraft_ai_default_model( $provider );

	switch ( $provider ) {
		case 'openai':
			return livingdraft_ai_call_openai( $key, $model, $prompt, $args );

		case 'gemini':
			return livingdraft_ai_call_gemini( $key, $model, $prompt, $args );

		case 'openrouter':
			return livingdraft_ai_call_openrouter( $key, $model, $prompt, $args );

		case 'groq':
			return livingdraft_ai_call_groq( $key, $model, $prompt, $args );
	}

	return new WP_Error( 'ld_ai_unknown_provider', __( 'Unknown AI provider.', 'livingdraft-core' ) );
}

/* ------------------------------------------------------------------
 * 2. PROVIDER ADAPTERS
 * ------------------------------------------------------------------ */

/**
 * OpenAI Chat Completions.
 * Docs: https://platform.openai.com/docs/api-reference/chat
 *
 * Vision: when args.image is present, the user message becomes a
 * content array with a text part and an image_url part carrying a
 * base64 data URL. Vision-capable models (gpt-4o family, gpt-4-turbo)
 * accept this shape transparently; older text-only models return a 400.
 */
function livingdraft_ai_call_openai( $key, $model, $prompt, $args ) {
	$messages = array();
	if ( '' !== $args['system'] ) {
		$messages[] = array( 'role' => 'system', 'content' => $args['system'] );
	}
	$messages[] = array(
		'role'    => 'user',
		'content' => livingdraft_ai_build_user_content( $prompt, $args['image'], 'openai' ),
	);

	$response = wp_remote_post(
		'https://api.openai.com/v1/chat/completions',
		array(
			'timeout' => 45, // Vision takes longer than text.
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode(
				array(
					'model'       => $model,
					'messages'    => $messages,
					'max_tokens'  => (int) $args['max_tokens'],
					'temperature' => (float) $args['temperature'],
				)
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

	if ( 200 !== $code ) {
		$msg = is_array( $body ) && isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
		return new WP_Error( 'ld_ai_openai_error', $msg );
	}

	$text = isset( $body['choices'][0]['message']['content'] ) ? (string) $body['choices'][0]['message']['content'] : '';
	return trim( $text );
}

/**
 * Google Gemini generateContent.
 * Docs: https://ai.google.dev/api/generate-content
 *
 * Different shape from OpenAI: uses "contents" not "messages", nests
 * text in "parts", and system instructions go in a separate top-level
 * field rather than as a role.
 *
 * Vision: images go in the same parts array as inline_data with a
 * mime_type and base64 payload. All modern Gemini models are natively
 * multimodal — no separate vision endpoint.
 */
function livingdraft_ai_call_gemini( $key, $model, $prompt, $args ) {
	$url = sprintf(
		'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
		rawurlencode( $model ),
		rawurlencode( $key )
	);

	$parts = array( array( 'text' => $prompt ) );
	if ( is_array( $args['image'] ) && ! empty( $args['image']['data'] ) ) {
		$parts[] = array(
			'inline_data' => array(
				'mime_type' => $args['image']['mime'] ?: 'image/jpeg',
				'data'      => base64_encode( $args['image']['data'] ), // phpcs:ignore
			),
		);
	}

	$body = array(
		'contents'         => array( array( 'parts' => $parts ) ),
		'generationConfig' => array(
			'maxOutputTokens' => (int) $args['max_tokens'],
			'temperature'     => (float) $args['temperature'],
		),
	);

	if ( '' !== $args['system'] ) {
		$body['systemInstruction'] = array(
			'parts' => array( array( 'text' => $args['system'] ) ),
		);
	}

	// Native Google Search grounding — pass ld_grounding => true and we
	// enable the google_search tool. Free tier gets 5,000 grounded
	// prompts per month across the Gemini 3.x family, no separate
	// search API needed. On free-tier models where the tool isn't
	// supported (rare edge case), Gemini returns 400 — we bubble that
	// up as an error so the operator sees why grounding failed.
	if ( ! empty( $args['ld_grounding'] ) ) {
		$body['tools'] = array( array( 'google_search' => new stdClass() ) );
	}

	$response = wp_remote_post(
		$url,
		array(
			'timeout' => 120, // Grounded calls take longer than plain generation.
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code    = (int) wp_remote_retrieve_response_code( $response );
	$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

	if ( 200 !== $code ) {
		$msg = is_array( $decoded ) && isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : 'HTTP ' . $code;
		return new WP_Error( 'ld_ai_gemini_error', $msg );
	}

	// Gemini can return text split across multiple parts; concatenate.
	$parts = isset( $decoded['candidates'][0]['content']['parts'] ) ? $decoded['candidates'][0]['content']['parts'] : array();
	$text  = '';
	foreach ( $parts as $part ) {
		if ( isset( $part['text'] ) ) {
			$text .= $part['text'];
		}
	}
	return trim( $text );
}

/**
 * OpenRouter — same request shape as OpenAI, different endpoint.
 * Docs: https://openrouter.ai/docs
 *
 * Adds HTTP-Referer and X-Title headers so the site shows up in the
 * user's OpenRouter dashboard as the source of the traffic.
 *
 * Vision: same as OpenAI (content array with image_url data URL) —
 * OpenRouter routes to the underlying model which decides. For alt
 * text, models like anthropic/claude-3.5-sonnet, google/gemini-2.0-flash,
 * or openai/gpt-4o all work.
 */
function livingdraft_ai_call_openrouter( $key, $model, $prompt, $args ) {
	$messages = array();
	if ( '' !== $args['system'] ) {
		$messages[] = array( 'role' => 'system', 'content' => $args['system'] );
	}
	$messages[] = array(
		'role'    => 'user',
		'content' => livingdraft_ai_build_user_content( $prompt, $args['image'], 'openai' ),
	);

	// Base request. Extra top-level keys the caller can pass through:
	// `plugins`, `tools`, `route`, `transforms`, `response_format`,
	// `top_p` — all forwarded verbatim if present. Kept flexible so
	// future SEO features can pass provider-specific config
	// (web-search plugins, routing, etc.).
	//
	// v3.7.1 BUG FIX: `provider` used to be in this list. That was
	// wrong — our internal `$args['provider']` is a string like
	// "openrouter" used to route between OpenAI/Gemini/OpenRouter
	// internally, but OpenRouter's own API requires `provider` to
	// be an OBJECT (e.g. { order: [...], only: [...] }) for
	// provider-routing preferences. Forwarding our string into
	// their schema made every OpenRouter request fail with:
	// "provider: Invalid input: expected object, received string".
	// Callers who genuinely want to pin OpenRouter routing pass
	// `openrouter_provider` instead — we map it below.
	$body = array(
		'model'       => $model,
		'messages'    => $messages,
		'max_tokens'  => (int) $args['max_tokens'],
		'temperature' => (float) $args['temperature'],
	);

	$forwardable = array( 'plugins', 'tools', 'route', 'transforms', 'response_format', 'top_p' );
	foreach ( $forwardable as $k ) {
		if ( isset( $args[ $k ] ) ) {
			$body[ $k ] = $args[ $k ];
		}
	}

	// Explicit passthrough for OpenRouter's `provider` routing block.
	// Callers who want it MUST pass an array/object under a distinct
	// key so it can never collide with our internal string selector.
	if ( isset( $args['openrouter_provider'] ) && is_array( $args['openrouter_provider'] ) ) {
		$body['provider'] = $args['openrouter_provider'];
	}

	$response = wp_remote_post(
		'https://openrouter.ai/api/v1/chat/completions',
		array(
			'timeout' => 120, // Web search can add 20–40s to a normal generation.
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
				'HTTP-Referer'  => home_url( '/' ),
				'X-Title'       => get_bloginfo( 'name' ),
			),
			'body'    => wp_json_encode( $body ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

	if ( 200 !== $code ) {
		$msg = is_array( $body ) && isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
		return new WP_Error( 'ld_ai_openrouter_error', $msg );
	}

	$text = isset( $body['choices'][0]['message']['content'] ) ? (string) $body['choices'][0]['message']['content'] : '';
	return trim( $text );
}

/**
 * Build the `content` value for a user message in OpenAI-shape APIs
 * (OpenAI + OpenRouter). Without an image this is a plain string; with
 * one it's a content array carrying text + image_url with a base64
 * data URL. Base64 rather than a public URL so the model can see the
 * image even on staging or local dev sites.
 *
 * @param string $prompt
 * @param array|null $image [ 'data' => bytes, 'mime' => 'image/jpeg' ]
 * @param string $shape For future providers; only 'openai' today.
 * @return string|array
 */
/**
 * Groq.
 *
 * === WHY THIS IS WORTH A FOURTH PROVIDER ===
 *
 * Not for quality — the open-weight models Groq serves are good but not
 * better than Gemini Flash for this work. For SPEED and QUOTA.
 *
 * A Desk draft costs three sequential AI calls: the India check, the body,
 * and the headline plus SEO fields. On a rate-limited free tier those three
 * are also the burst most likely to trip a per-minute cap, and the run has to
 * space them out and wait. Groq returns in a fraction of the time, so a run
 * that drafts three articles finishes inside PHP's execution limit instead of
 * stopping on its own clock and deferring the rest.
 *
 * The API is OpenAI-compatible, so this is the OpenAI caller with a different
 * host — no new response shape to parse.
 *
 * Groq does not offer an embeddings endpoint, so internal-link suggestions
 * still need OpenAI or Gemini configured. That is why the provider is
 * deliberately absent from livingdraft_ai_embed().
 *
 * @since 4.0.0
 * @param string $key    API key.
 * @param string $model  Model id.
 * @param string $prompt Prompt.
 * @param array  $args   Request args.
 * @return string|WP_Error
 */
function livingdraft_ai_call_groq( $key, $model, $prompt, $args ) {
	$messages = array();

	if ( '' !== $args['system'] ) {
		$messages[] = array(
			'role'    => 'system',
			'content' => $args['system'],
		);
	}

	$messages[] = array(
		'role'    => 'user',
		'content' => livingdraft_ai_build_user_content( $prompt, $args['image'] ?? '', 'openai' ),
	);

	$response = wp_remote_post(
		'https://api.groq.com/openai/v1/chat/completions',
		array(
			'timeout' => 90,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode(
				array(
					'model'       => $model,
					'messages'    => $messages,
					'max_tokens'  => (int) $args['max_tokens'],
					'temperature' => (float) $args['temperature'],
				)
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code    = (int) wp_remote_retrieve_response_code( $response );
	$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

	if ( 200 !== $code ) {
		$message = isset( $decoded['error']['message'] ) ? (string) $decoded['error']['message'] : '';

		return new WP_Error(
			'ld_ai_groq',
			sprintf(
				/* translators: 1: HTTP status code, 2: error message from Groq. */
				__( 'Groq returned %1$d. %2$s', 'livingdraft-core' ),
				$code,
				$message
			)
		);
	}

	$text = isset( $decoded['choices'][0]['message']['content'] )
		? (string) $decoded['choices'][0]['message']['content']
		: '';

	if ( '' === trim( $text ) ) {
		return new WP_Error( 'ld_ai_groq_empty', __( 'Groq returned an empty response.', 'livingdraft-core' ) );
	}

	return trim( $text );
}

function livingdraft_ai_build_user_content( $prompt, $image, $shape = 'openai' ) {
	if ( ! is_array( $image ) || empty( $image['data'] ) ) {
		return $prompt;
	}
	$mime = ! empty( $image['mime'] ) ? $image['mime'] : 'image/jpeg';
	$data = 'data:' . $mime . ';base64,' . base64_encode( $image['data'] ); // phpcs:ignore

	return array(
		array( 'type' => 'text',      'text' => $prompt ),
		array( 'type' => 'image_url', 'image_url' => array( 'url' => $data ) ),
	);
}

/* ------------------------------------------------------------------
 * 3. SETTINGS + KEY STORAGE
 * ------------------------------------------------------------------ */

/**
 * All AI settings, with sensible defaults.
 *
 * @return array
 */
function livingdraft_ai_get_settings() {
	$stored = get_option( 'livingdraft_ai_settings', array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	return wp_parse_args(
		$stored,
		array(
			// Free-tier first: Gemini's Flash line is free (rate-limited) and
			// supports native Google Search grounding — best fit for a small
			// newsroom operating on zero AI spend. Operator can switch to
			// OpenAI in Settings if they already pay for it.
			'provider'                => 'gemini',
			// v3.7.1: model defaults updated for August 2026.
			//   OpenAI    — GPT-5.4 family (Mar 2026 release), replaces
			//               the now-old GPT-4o family. Mini is the
			//               cost/quality workhorse; nano is cheapest.
			//   Gemini    — Gemini 3.6 Flash (GA), replaces 2.5 Flash
			//               which shuts down Oct 16 2026.
			//   OpenRouter — GPT-5.4 mini as default. Anthropic Claude
			//               Sonnet 5 and Opus 4.7 added for strong
			//               writing quality; Haiku 4.5 for cheap.
			'model_openai'            => 'gpt-5.4-mini',
			'model_gemini'            => 'gemini-3.6-flash',
			'model_openrouter'        => 'openai/gpt-5.4-mini',
			// Groq runs open-weight models on its own inference hardware.
			// Free tier is generous and, more usefully here, FAST — a Desk
			// draft costs three sequential calls, and Groq turns those round
			// in a fraction of the time a hosted frontier model takes.
			'model_groq'              => 'llama-3.3-70b-versatile',
			// Additional models available in the picker.
			'models_openai'           => "gpt-5.4-mini\ngpt-5.4-nano\ngpt-5.4\ngpt-4.1-mini\ngpt-4.1",
			'models_gemini'           => "gemini-3.6-flash\ngemini-3.5-flash-lite\ngemini-3-pro\ngemini-2.5-flash",
			'models_openrouter'       => "openai/gpt-5.4-mini\nopenai/gpt-5.4\nanthropic/claude-sonnet-5\nanthropic/claude-opus-4.7\nanthropic/claude-haiku-4.5\ngoogle/gemini-3.6-flash\ngoogle/gemini-3-pro",
			'models_groq'             => "llama-3.3-70b-versatile\nllama-3.1-8b-instant\nmixtral-8x7b-32768\ngemma2-9b-it",
			// Embedding models — used by the internal-links module. OpenRouter
			// doesn't proxy embeddings, so it isn't listed.
			'embedding_model_openai'  => 'text-embedding-3-small',
			'embedding_model_gemini'  => 'text-embedding-004',
		)
	);
}

/**
 * The default model for a given provider (falls back to a safe cheap
 * choice if the user hasn't set one).
 */
function livingdraft_ai_default_model( $provider ) {
	$s = livingdraft_ai_get_settings();
	switch ( $provider ) {
		case 'openai':     return $s['model_openai']     ?: 'gpt-5.4-mini';
		case 'gemini':     return $s['model_gemini']     ?: 'gemini-3.6-flash';
		case 'openrouter': return $s['model_openrouter'] ?: 'openai/gpt-5.4-mini';
		case 'groq':       return $s['model_groq']       ?: 'llama-3.3-70b-versatile';
	}
	return '';
}

/**
 * Encrypted-at-rest API key storage.
 *
 * Keys go into a separate option from the general settings so the
 * settings blob can be dumped/logged without exposing credentials.
 */
function livingdraft_ai_get_key( $provider ) {
	$store = get_option( 'livingdraft_ai_keys', array() );
	if ( ! is_array( $store ) || empty( $store[ $provider ] ) ) {
		return '';
	}
	return livingdraft_ai_decrypt( $store[ $provider ] );
}

function livingdraft_ai_set_key( $provider, $key ) {
	$store = get_option( 'livingdraft_ai_keys', array() );
	if ( ! is_array( $store ) ) {
		$store = array();
	}
	if ( '' === $key ) {
		unset( $store[ $provider ] );
	} else {
		$store[ $provider ] = livingdraft_ai_encrypt( $key );
	}
	update_option( 'livingdraft_ai_keys', $store, false );
}

/**
 * Encrypt/decrypt using AES-256-CBC with a key derived from wp_salt().
 * This defends against database-only exposure (backups, dumps, replicas)
 * — anyone who also has wp-config.php can still decrypt.
 */
function livingdraft_ai_encrypt( $plaintext ) {
	if ( ! function_exists( 'openssl_encrypt' ) ) {
		// OpenSSL should always be present on modern PHP; if not, fall
		// back to base64 so the site still works. Not secret, but not
		// broken either.
		return 'plain:' . base64_encode( $plaintext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}
	$iv     = openssl_random_pseudo_bytes( 16 );
	$key    = substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
	$cipher = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
	return 'enc:' . base64_encode( $iv . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
}

function livingdraft_ai_decrypt( $value ) {
	if ( 0 === strpos( $value, 'plain:' ) ) {
		return (string) base64_decode( substr( $value, 6 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}
	if ( 0 !== strpos( $value, 'enc:' ) || ! function_exists( 'openssl_decrypt' ) ) {
		return '';
	}
	$blob = base64_decode( substr( $value, 4 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	if ( strlen( $blob ) < 17 ) {
		return '';
	}
	$iv     = substr( $blob, 0, 16 );
	$cipher = substr( $blob, 16 );
	$key    = substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
	$out    = openssl_decrypt( $cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
	return false === $out ? '' : (string) $out;
}

/* ------------------------------------------------------------------
 * 4. RATE LIMITING
 * ------------------------------------------------------------------ */

/**
 * Per-user sliding window. Return WP_Error when the user has burned
 * through their quota, so the JS can show a helpful message rather
 * than a mystery failure.
 */
function livingdraft_ai_check_rate_limit() {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return new WP_Error( 'ld_ai_no_user', __( 'AI calls require a logged-in user.', 'livingdraft-core' ) );
	}

	$window  = (int) apply_filters( 'livingdraft_ai_rate_window', 5 * MINUTE_IN_SECONDS );
	$limit   = (int) apply_filters( 'livingdraft_ai_rate_limit', 30 );
	$key     = 'ld_ai_rate_' . $user_id;

	$count = (int) get_transient( $key );
	if ( $count >= $limit ) {
		return new WP_Error(
			'ld_ai_rate_limited',
			sprintf(
				/* translators: 1: limit, 2: minutes. */
				__( 'Rate limit hit (%1$d AI calls in %2$d minutes). Wait a moment and try again.', 'livingdraft-core' ),
				$limit,
				(int) ( $window / MINUTE_IN_SECONDS )
			)
		);
	}
	set_transient( $key, $count + 1, $window );
	return true;
}

/* ------------------------------------------------------------------
 * 5. TASK-SPECIFIC HELPERS
 * ------------------------------------------------------------------ */

/**
 * Prompt library. Keeping these here (not scattered across features)
 * means anyone tuning prompts has one file to open.
 *
 * The prompts are deliberately terse and end with an instruction to
 * return only the answer, no wrapping quotes. Every provider will
 * occasionally add "Sure! Here's your..." if you don't tell it not to.
 *
 * @param string $task    Task identifier.
 * @param array  $context Task-specific context.
 * @return string Rendered prompt.
 */
function livingdraft_ai_build_prompt( $task, $context = array() ) {
	$title    = isset( $context['title'] ) ? (string) $context['title'] : '';
	$excerpt  = isset( $context['excerpt'] ) ? (string) $context['excerpt'] : '';
	$keyword  = isset( $context['keyword'] ) ? (string) $context['keyword'] : '';
	$existing = isset( $context['existing'] ) ? (string) $context['existing'] : '';
	$intro    = isset( $context['intro'] ) ? (string) $context['intro'] : '';
	$mentions = isset( $context['mentions'] ) ? (int) $context['mentions'] : 0;
	$words    = isset( $context['words'] ) ? (int) $context['words'] : 0;

	// Trim article context so we don't send 20KB of body to every call.
	$excerpt = wp_trim_words( wp_strip_all_tags( $excerpt ), 250 );

	switch ( $task ) {
		case 'meta_description':
			return trim( "You are writing a Google search meta description for the article below.
Constraints:
- Between 140 and 160 characters, inclusive.
- Written in the same language as the article.
- Includes the focus keyword naturally if one is provided.
- Reads like a headline-writer's summary, not a marketing tagline.
- No quotes, no emoji, no trailing period unless the sentence needs one.

Focus keyword: {$keyword}
Article title: {$title}
Article body: {$excerpt}

Return ONLY the description, no preamble." );

		case 'seo_title':
			return trim( "Write an SEO title for the article below. Constraints:
- 50 to 60 characters.
- Same language as the article.
- Includes the focus keyword if given.
- Descriptive, not clickbait.

Focus keyword: {$keyword}
Article title: {$title}
Article body: {$excerpt}
Current SEO title (may be empty): {$existing}

Return ONLY the title, no quotes, no site name suffix." );

		case 'focus_keyword':
			return trim( "Suggest ONE focus keyword for the article below. A focus keyword is 1-4 words, in the same language as the article, that best captures what a reader would type into Google to find this piece. Do not use branded terms or the site's own name.

Article title: {$title}
Article body: {$excerpt}

Return ONLY the keyword phrase, lowercase, no quotes." );

		case 'seo_slug':
			return trim( "Write a URL slug for the article below. Constraints:
- Lowercase ASCII only. Hyphens between words, no underscores, no spaces, no punctuation.
- 3 to 6 words. Between 15 and 60 characters total.
- Descriptive and specific — a reader glancing at the URL should know what the article is about.
- Includes the focus keyword when one is given, unless doing so would make the slug awkward.
- Never include the site name, publication date, or the word 'article'.
- Never include stop words like 'the', 'a', 'and', 'of', 'in' unless removing them would garble the meaning.
- If the article title is in a non-English script, transliterate to ASCII rather than URL-encoding.

Focus keyword: {$keyword}
Article title: {$title}
Article body: {$excerpt}
Current slug (may be empty): {$existing}

Return ONLY the slug, no quotes, no leading or trailing slashes, no explanation." );

		case 'term_meta_bundle':
			// v3.7.0: single call that returns all three term SEO fields
			// as JSON. Used by the bulk term-generator to cut API cost
			// by 3x versus three separate calls per term. The excerpt
			// context here is the term description + titles of recent
			// posts in the term (composed by the caller).
			$taxonomy_label = isset( $context['taxonomy_label'] ) ? (string) $context['taxonomy_label'] : 'category';
			return trim( "You are writing SEO metadata for a {$taxonomy_label} archive page on a news publication. The archive page lists all articles filed under this {$taxonomy_label}.

{$taxonomy_label} name: {$title}
What this archive covers:
{$excerpt}

Return a JSON object with EXACTLY these three fields:
{
  \"focus_keyword\": \"1-4 words, lowercase, no quotes — what a reader would type into Google to find this archive\",
  \"seo_title\": \"50-60 character title — descriptive not clickbait, includes the focus keyword naturally, no site name suffix (that's appended automatically)\",
  \"meta_description\": \"140-160 character description — sounds like an editor summarizing the archive, includes the focus keyword naturally, no marketing fluff, no trailing period unless the sentence needs one\"
}

Everything MUST be in the same language as the archive's content. Return ONLY the JSON — no markdown fences, no preamble, no explanation." );

		/* ----- SUGGEST-MODE prompts. Output goes into an inline panel
		 * with a Copy button — the writer edits their post body. ----- */

		case 'suggest_intro':
			return trim( "The article's opening paragraph does not include the focus keyword. Write a rewritten opening paragraph — 2 to 3 sentences, same language as the article — that naturally includes the focus keyword and hooks a reader. Base it on the article's actual subject; do not invent facts.

Focus keyword: {$keyword}
Article title: {$title}
Current opening paragraph: {$intro}
Article body (for context): {$excerpt}

Return ONLY the new opening paragraph. No preamble, no quotes, no explanation." );

		case 'suggest_density':
			return trim( "The article mentions the focus keyword {$mentions} time(s) in about {$words} words, which is low. Suggest TWO short additions (1–2 sentences each) that could be woven into the article to use the keyword more naturally, without sounding stuffed. Base them on the article's actual content.

Focus keyword: {$keyword}
Article title: {$title}
Article body: {$excerpt}

Return exactly two bullets, each starting with '- '. Same language as the article. No preamble." );

		case 'suggest_headings':
			return trim( "The article has no H2 subheadings. Suggest 2 to 3 H2 subheadings that would break this piece into scannable sections. Base the headings on what the article actually covers — do not invent topics that aren't there. Same language as the article.

Article title: {$title}
Article body: {$excerpt}

Return only the headings, one per line, no numbering, no punctuation at the end, no preamble." );

		case 'suggest_expansion':
			return trim( "The article is only {$words} words, which is thin. Suggest THREE specific angles or questions this article could cover more deeply to reach 600+ words. Do not say generic things like 'add more examples' — name concrete gaps in the current coverage.

Article title: {$title}
Article body: {$excerpt}

Return exactly three bullets, each starting with '- '. Same language as the article. No preamble." );

		/* ----- VISION-MODE prompts. Sent with an image attached; see
		 * `livingdraft_ai_build_user_content()` for the wire shape. ----- */

		case 'image_alt':
			$ctx_line = '' !== $title ? "Article this image appears in: {$title}" : '';
			return trim( "Look at this image and write alt text for it. Alt text is what a screen reader announces to someone who can't see the image.

Constraints:
- 60 to 140 characters.
- Same language as the article title if one is given, otherwise English.
- Describe the subject and what's happening, not the aesthetics.
- Do NOT start with 'Image of', 'Photo of', 'A picture of' — that wastes a screen reader's time.
- No quotes, no emoji, no trailing period unless the sentence genuinely needs one.
- If the image contains readable text that's central to it (a sign, a headline, a chart title), quote that text.

{$ctx_line}

Return ONLY the alt text, nothing else." );

		case 'image_caption':
			$ctx_line = '' !== $title ? "Article this image appears in: {$title}" : '';
			return trim( "Write a caption for this image — the visible line that runs beneath it in an article. Different from alt text: a caption adds context or attribution, not just description.

Constraints:
- One sentence, 80 to 180 characters.
- Same language as the article title if given.
- Say something the reader can't get from just looking (context, when, where, who).
- No quotes, no emoji.

{$ctx_line}

Return ONLY the caption." );

		/* ----- CONTENT BRIEF: structured JSON for the workspace tool. ----- */

		case 'content_brief':
			$content_type = isset( $context['content_type'] ) ? (string) $context['content_type'] : '';
			$audience     = isset( $context['audience'] ) ? (string) $context['audience'] : '';
			$language     = isset( $context['language'] ) ? (string) $context['language'] : 'English';
			$type_line    = '' !== $content_type ? "Content type: {$content_type}" : '';
			$aud_line     = '' !== $audience ? "Target audience: {$audience}" : '';

			return trim( "You are producing a content brief for a writer who is about to write an article targeting the focus keyword below. A content brief is a structured plan the writer will follow — not the article itself.

Focus keyword: {$keyword}
Language: {$language}
{$type_line}
{$aud_line}

Return a JSON object with EXACTLY these fields:
{
  \"suggested_titles\": [3 title options, each 50-60 characters, includes or plays on the keyword],
  \"meta_description\": \"one 140-160 character meta description\",
  \"target_word_count\": integer between 800 and 2500 based on what a strong article on this topic needs,
  \"outline\": [
    {\"heading\": \"H2 heading text\", \"notes\": \"one sentence on what this section should cover\"},
    ... (6 to 10 sections total, in the order the writer should use them)
  ],
  \"key_entities\": [10 people, places, organisations, products, or concepts the article must mention to be considered complete],
  \"questions_to_answer\": [8 concrete questions a reader with this keyword in mind wants answered],
  \"semantic_terms\": [15 related terms and phrases the writer should weave in for topical coverage],
  \"internal_link_hints\": [4 short descriptions of the kinds of pages on the same site this article should link to, e.g. \"the earlier explainer on X\", \"the author's Y series index\"]
}

Everything MUST be in the language specified above. Return ONLY the JSON — no markdown code fences, no preamble, no explanation." );
	}

	return '';
}

/* ------------------------------------------------------------------
 * 6. AVAILABLE-MODELS POOL + MODEL PICKER
 *
 * Every AI-invoking surface (metabox buttons, brief workspace, image
 * alt) can render a picker to let the user pick the model right
 * before invoking the task. The picker is populated from this pool.
 * ------------------------------------------------------------------ */

/**
 * Build the flat list of {provider, model, label, is_default} available
 * to the picker. Only includes providers with a stored key.
 */
function livingdraft_ai_available_models() {
	$s   = livingdraft_ai_get_settings();
	$out = array();

	$providers = array(
		'openai'     => 'OpenAI',
		'gemini'     => 'Gemini',
		'openrouter' => 'OpenRouter',
	);

	foreach ( $providers as $p => $label ) {
		if ( '' === livingdraft_ai_get_key( $p ) ) {
			continue; // Skip providers with no key.
		}

		$default = $s[ 'model_' . $p ];
		$extras  = array_filter( array_map( 'trim', preg_split( '/\r?\n/', (string) $s[ 'models_' . $p ] ) ) );

		// Deduplicate: default first, then extras minus anything equal to default.
		$models = array( $default );
		foreach ( $extras as $m ) {
			if ( '' !== $m && ! in_array( $m, $models, true ) ) {
				$models[] = $m;
			}
		}

		foreach ( $models as $m ) {
			$out[] = array(
				'provider'   => $p,
				'model'      => $m,
				'value'      => $p . ':' . $m, // What the dropdown submits.
				'label'      => $label . ': ' . $m,
				'is_default' => ( $m === $default ),
			);
		}
	}

	return apply_filters( 'livingdraft_ai_available_models', $out );
}

/**
 * Read a `provider:model` override from an AJAX request into arg shape
 * suitable for `livingdraft_ai_complete()`. Silently ignores unknown
 * providers so a malformed client payload doesn't blow up.
 *
 * SECURITY NOTE: this function reads ONLY provider + model from the
 * request. Do NOT extend it to pass through `bypass_rate_limit`,
 * `system`, or any other arg that changes execution behaviour or
 * elevates trust. Those must stay server-set. Client-supplied
 * bypass would defeat the rate-limit's cost-protection purpose.
 *
 * @return array Empty when nothing overriding, else [ 'provider' => …, 'model' => … ].
 */
function livingdraft_ai_read_request_override() {
	$val = isset( $_POST['ai_model'] ) ? sanitize_text_field( wp_unslash( $_POST['ai_model'] ) ) : '';
	if ( '' === $val || false === strpos( $val, ':' ) ) {
		return array();
	}
	list( $provider, $model ) = array_map( 'trim', explode( ':', $val, 2 ) );
	if ( ! in_array( $provider, array( 'openai', 'gemini', 'openrouter', 'groq' ), true ) ) {
		return array();
	}
	if ( '' === $model ) {
		return array();
	}
	// Return ONLY provider + model. Never bypass_rate_limit — see docblock.
	return array( 'provider' => $provider, 'model' => $model );
}

/**
 * Render an inline model picker. Small helper so every surface uses the
 * same markup, styling, and localStorage key. The chosen value is
 * `provider:model` — the AJAX handler split it back via
 * `livingdraft_ai_read_request_override()`.
 *
 * @param array $args {
 *   @type string $label       Prefix shown before the dropdown.
 *   @type string $storage_key localStorage key to persist selection.
 * }
 */
function livingdraft_ai_render_model_picker( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'label'       => __( 'Model', 'livingdraft-core' ),
			'storage_key' => 'livingdraft.ai.model',
		)
	);

	$models = livingdraft_ai_available_models();
	if ( empty( $models ) ) {
		return; // No configured provider, nothing to pick.
	}
	?>
	<label class="ld-model-picker" style="display:inline-flex;align-items:center;gap:6px;font-family:var(--tld-mono, 'IBM Plex Mono', monospace);font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:#666">
		<span><?php echo esc_html( $args['label'] ); ?></span>
		<select data-ld-model-picker
			data-ld-storage-key="<?php echo esc_attr( $args['storage_key'] ); ?>"
			style="font-family:var(--tld-mono, monospace);font-size:11px;padding:3px 8px;border:1px solid #d4d4d4;background:#fff;text-transform:none;letter-spacing:0">
			<?php foreach ( $models as $m ) : ?>
				<option value="<?php echo esc_attr( $m['value'] ); ?>" <?php selected( $m['is_default'] ); ?>>
					<?php echo esc_html( $m['label'] ); ?><?php echo $m['is_default'] ? esc_html__( ' (default)', 'livingdraft-core' ) : ''; ?>
				</option>
			<?php endforeach; ?>
		</select>
	</label>
	<script>
	// Inline so the picker works even without a bundled script.
	// Reads the stored preference on load, saves on change, and exposes
	// a getter so caller-side AJAX code can grab the current value
	// consistently: window.livingdraftAiCurrentModel()
	( function () {
		var pickers = document.querySelectorAll( 'select[data-ld-model-picker]' );
		pickers.forEach( function ( sel ) {
			var key = sel.getAttribute( 'data-ld-storage-key' );
			if ( key ) {
				try {
					var stored = window.localStorage.getItem( key );
					if ( stored ) {
						var found = Array.from( sel.options ).some( function ( o ) { return o.value === stored; } );
						if ( found ) sel.value = stored;
					}
				} catch ( e ) {}
				sel.addEventListener( 'change', function () {
					try { window.localStorage.setItem( key, sel.value ); } catch ( e ) {}
				} );
			}
		} );
		window.livingdraftAiCurrentModel = function ( key ) {
			key = key || 'livingdraft.ai.model';
			var sel = document.querySelector( 'select[data-ld-storage-key="' + key + '"]' );
			if ( sel ) return sel.value;
			try { return window.localStorage.getItem( key ) || ''; } catch ( e ) { return ''; }
		};
	}() );
	</script>
	<?php
}

/* ------------------------------------------------------------------
 * 7. EMBEDDINGS (for internal-link suggestions)
 *
 * A separate function from livingdraft_ai_complete() because
 * embeddings are a distinct endpoint on both OpenAI and Gemini, and
 * OpenRouter doesn't broker embeddings at all (it's a chat proxy).
 *
 * Returns [ 'vector' => [floats], 'provider' => …, 'model' => … ] so
 * downstream code can tag stored embeddings and refuse to compare
 * vectors from different embedding spaces.
 * ------------------------------------------------------------------ */

function livingdraft_ai_embed( $text, $args = array() ) {
	$args = wp_parse_args(
		$args,
		array( 'provider' => '', 'model' => '' )
	);

	$s = livingdraft_ai_get_settings();

	// OpenRouter doesn't do embeddings — fall back to OpenAI if the
	// active provider is OR and the caller didn't override.
	$provider = '' !== $args['provider'] ? $args['provider'] : $s['provider'];
	if ( 'openrouter' === $provider ) {
		$provider = 'openai';
	}
	if ( ! in_array( $provider, array( 'openai', 'gemini' ), true ) ) {
		return new WP_Error( 'ld_ai_embed_unsupported', __( 'Embeddings require OpenAI or Gemini.', 'livingdraft-core' ) );
	}

	$key = livingdraft_ai_get_key( $provider );
	if ( '' === $key ) {
		return new WP_Error( 'ld_ai_no_key', sprintf( __( 'No API key for %s.', 'livingdraft-core' ), $provider ) );
	}

	$model = $args['model'] ?: $s[ 'embedding_model_' . $provider ];

	$text = trim( (string) $text );
	if ( '' === $text ) {
		return new WP_Error( 'ld_ai_empty_text', __( 'Empty text.', 'livingdraft-core' ) );
	}
	// Hard cap on characters to stay under any provider's token limit.
	if ( strlen( $text ) > 30000 ) {
		$text = substr( $text, 0, 30000 );
	}

	if ( 'openai' === $provider ) {
		return livingdraft_ai_call_openai_embed( $key, $model, $text );
	}
	return livingdraft_ai_call_gemini_embed( $key, $model, $text );
}

function livingdraft_ai_call_openai_embed( $key, $model, $text ) {
	$response = wp_remote_post(
		'https://api.openai.com/v1/embeddings',
		array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'model' => $model,
				'input' => $text,
			) ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( 200 !== $code ) {
		$msg = is_array( $body ) && isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
		return new WP_Error( 'ld_ai_openai_embed_error', $msg );
	}

	$vector = isset( $body['data'][0]['embedding'] ) ? $body['data'][0]['embedding'] : null;
	if ( ! is_array( $vector ) || empty( $vector ) ) {
		return new WP_Error( 'ld_ai_openai_embed_empty', __( 'OpenAI returned no embedding.', 'livingdraft-core' ) );
	}
	return array( 'vector' => $vector, 'provider' => 'openai', 'model' => $model );
}

function livingdraft_ai_call_gemini_embed( $key, $model, $text ) {
	$url = sprintf(
		'https://generativelanguage.googleapis.com/v1beta/models/%s:embedContent?key=%s',
		rawurlencode( $model ),
		rawurlencode( $key )
	);
	$response = wp_remote_post(
		$url,
		array(
			'timeout' => 30,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'content'  => array( 'parts' => array( array( 'text' => $text ) ) ),
				// SEMANTIC_SIMILARITY is Gemini's task hint for pairwise
				// retrieval — matches what we do downstream.
				'taskType' => 'SEMANTIC_SIMILARITY',
			) ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( 200 !== $code ) {
		$msg = is_array( $body ) && isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
		return new WP_Error( 'ld_ai_gemini_embed_error', $msg );
	}

	$vector = isset( $body['embedding']['values'] ) ? $body['embedding']['values'] : null;
	if ( ! is_array( $vector ) || empty( $vector ) ) {
		return new WP_Error( 'ld_ai_gemini_embed_empty', __( 'Gemini returned no embedding.', 'livingdraft-core' ) );
	}
	return array( 'vector' => $vector, 'provider' => 'gemini', 'model' => $model );
}
