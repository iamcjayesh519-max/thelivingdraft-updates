<?php
/**
 * Fact checking against Google's Fact Check Tools API.
 *
 * === WHAT THIS IS, AND WHAT IT IS NOT ===
 *
 * Google's Fact Check Tools API does not check facts. It is a search engine
 * over fact-checks that other organisations have already published. You send
 * it a short claim; it returns any published fact-check that matches, who
 * wrote it, what they rated it, and a link.
 *
 * It has no opinion on your sentence. It will not catch a wrong crore figure,
 * a misspelled name, or an out-of-date satellite count — the errors a
 * newsroom actually makes. If nobody has fact-checked something similar, it
 * returns nothing, which is the normal case.
 *
 * What it is genuinely good at is one thing worth having: catching a writer
 * repeating a claim that has already been debunked. Boom Live, Alt News,
 * Factly, Vishvas News, Newschecker and The Quint's WebQoof all publish into
 * this index, and Indian political and health misinformation is well covered.
 *
 * === THE RULE THAT GOVERNS THIS ENTIRE FILE ===
 *
 * The AI is never permitted to assert a fact from its own knowledge.
 *
 * The obvious next step after "this claim was rated False" is to ask a model
 * what the truth is. That is the single most dangerous thing that could be
 * built here. A model asked for a fact it does not have will produce a
 * fluent, confident, wrong answer, and this tool would hand it to a
 * journalist carrying the authority of a fact-checking system. A reporter
 * acting on a hallucinated correction is worse off than one who never ran the
 * check at all — the tool would have manufactured false confidence.
 *
 * So there is a strict hierarchy of sources, enforced in code and not merely
 * requested in a prompt:
 *
 *   1. THE FACT-CHECK ARTICLE ITSELF. Boom's piece already explains what is
 *      actually true. It is fetched and summarised. This is a real published
 *      correction by a named organisation, not a guess.
 *
 *   2. GROUNDED WEB SEARCH, where the provider supports it — Gemini's native
 *      google_search tool, OpenRouter's web plugin. Citations required.
 *
 *   3. THE MODEL'S OWN MEMORY. Never. Any summary that comes back without at
 *      least one source URL is DISCARDED rather than shown. See
 *      livingdraft_factcheck_summarise().
 *
 * Everything surfaced is framed as what those sources say, with links. Never
 * as what is true. The journalist decides.
 *
 * === WHY IT NEVER BLOCKS PUBLISHING ===
 *
 * If publishing depends on a Google API call, then an outage, a quota trip or
 * an expired key stops a breaking story going out. That is a worse failure
 * than an unchecked article. A newsroom tool that can stop the presses will
 * eventually stop the presses. This warns loudly and always yields.
 *
 * @package LivingDraftCore
 * @since 4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Post meta holding the last check result. */
const LD_FACTCHECK_META = '_ld_factcheck';

/** Google's endpoint. */
const LD_FACTCHECK_ENDPOINT = 'https://factchecktools.googleapis.com/v1alpha1/claims:search';

/* ==================================================================
 * 1. SETTINGS
 * ================================================================== */

/**
 * Is the feature configured and on?
 *
 * @since 4.0.0
 * @return bool
 */
function livingdraft_factcheck_enabled() {
	return (bool) apply_filters(
		'livingdraft_factcheck_enabled',
		'' !== livingdraft_factcheck_key() && (bool) get_option( 'livingdraft_factcheck_enabled', true )
	);
}

/**
 * The Google API key, decrypted.
 *
 * Stored with the same AES-256-CBC wrapper the AI keys use, so a database
 * dump alone does not expose it.
 *
 * @since 4.0.0
 * @return string
 */
function livingdraft_factcheck_key() {
	$stored = (string) get_option( 'livingdraft_factcheck_key', '' );

	if ( '' === $stored ) {
		return '';
	}

	return function_exists( 'livingdraft_ai_decrypt' ) ? livingdraft_ai_decrypt( $stored ) : '';
}

/**
 * Store the key.
 *
 * @since 4.0.0
 * @param string $key Raw key.
 * @return void
 */
function livingdraft_factcheck_set_key( $key ) {
	$key = trim( (string) $key );

	if ( '' === $key ) {
		delete_option( 'livingdraft_factcheck_key' );
		return;
	}

	if ( function_exists( 'livingdraft_ai_encrypt' ) ) {
		update_option( 'livingdraft_factcheck_key', livingdraft_ai_encrypt( $key ), false );
	}
}

/**
 * How many claims to pull out of one article.
 *
 * Each claim is one Google call plus, when flagged, one fetch and one AI
 * summary. Eight is enough to cover a long news piece without turning a
 * routine check into a minute of waiting.
 *
 * @since 4.0.0
 * @return int
 */
function livingdraft_factcheck_max_claims() {
	return max( 1, min( 20, (int) apply_filters( 'livingdraft_factcheck_max_claims', 8 ) ) );
}

/**
 * Fact-checkers to float to the top of the results.
 *
 * Google's API takes a single publisher filter, which is no use when the
 * intent is "everything, but ours first". So results come back unfiltered and
 * are ordered here instead: an Indian desk's verdict on an Indian claim is
 * more relevant to this newsroom than a US one, but a US one is still worth
 * seeing.
 *
 * @since 4.0.0
 * @return string[] Domain fragments, matched case-insensitively.
 */
function livingdraft_factcheck_preferred_publishers() {
	return (array) apply_filters(
		'livingdraft_factcheck_preferred_publishers',
		array(
			'boomlive.in',
			'altnews.in',
			'factly.in',
			'vishvasnews.com',
			'newschecker.in',
			'factcrescendo.com',
			'thequint.com',
			'thip.media',
			'digiteye.in',
			'indiatoday.in',
			'pib.gov.in',
			'logicallyfacts.com',
			'firstcheck.in',
		)
	);
}

/* ==================================================================
 * 2. WHAT COUNTS AS A BAD RATING
 * ================================================================== */

/**
 * Sort one publisher's verdict into a severity band.
 *
 * Ratings are free text and every organisation words them differently:
 * "False", "Pants on Fire", "Misleading", "Missing Context", "Half True",
 * "Mostly Correct". They are matched on substrings rather than exactly,
 * because an exact list would go stale the moment a desk changed its house
 * style, and a missed match here means a real debunk shown as neutral.
 *
 * @since 4.0.0
 * @param string $rating Textual rating.
 * @return string 'false', 'mixed', 'true' or 'unknown'.
 */
function livingdraft_factcheck_severity( $rating ) {
	if ( ! is_scalar( $rating ) ) {
		return 'unknown';
	}

	$text = strtolower( trim( (string) $rating ) );

	if ( '' === $text ) {
		return 'unknown';
	}

	$false = array(
		'false', 'fake', 'incorrect', 'inaccurate', 'debunked', 'hoax',
		'pants on fire', 'fabricated', 'no evidence', 'not true', 'untrue',
		'baseless', 'misinformation', 'manipulated', 'doctored', 'altered',
		'scam', 'satire', 'wrong',
	);

	$mixed = array(
		'misleading', 'missing context', 'partly', 'partially', 'half true',
		'half-true', 'mixture', 'exaggerated', 'unproven', 'unverified',
		'outdated', 'needs context', 'mostly false', 'lacks context',
		'disputed', 'overstated',
	);

	$true = array(
		'true', 'correct', 'accurate', 'verified', 'confirmed', 'legit',
	);

	// Mixed is tested first: "mostly false" and "half true" both contain a
	// term from another list, and the qualified reading is the right one.
	foreach ( $mixed as $needle ) {
		if ( false !== strpos( $text, $needle ) ) {
			return 'mixed';
		}
	}

	foreach ( $false as $needle ) {
		if ( false !== strpos( $text, $needle ) ) {
			return 'false';
		}
	}

	foreach ( $true as $needle ) {
		if ( false !== strpos( $text, $needle ) ) {
			return 'true';
		}
	}

	return 'unknown';
}

/* ==================================================================
 * 3. PULLING CLAIMS OUT OF A DRAFT
 * ================================================================== */

/**
 * Ask the model which sentences in this draft are checkable assertions.
 *
 * This is the one job in the file the AI is trusted with, and it is a safe
 * one: it is being asked to SELECT text that already exists in the article,
 * not to produce facts. The worst failure is a poorly chosen sentence, which
 * costs one wasted API call.
 *
 * @since 4.0.0
 * @param int $post_id Post.
 * @return array|WP_Error List of claim strings.
 */
function livingdraft_factcheck_extract_claims( $post_id ) {
	if ( ! function_exists( 'livingdraft_ai_complete' ) ) {
		return new WP_Error( 'no_ai', __( 'The AI provider is not available.', 'livingdraft-core' ) );
	}

	$post = get_post( (int) $post_id );

	if ( ! $post ) {
		return new WP_Error( 'no_post', __( 'Article not found.', 'livingdraft-core' ) );
	}

	$body = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
	$body = preg_replace( '/\s+/u', ' ', $body );
	$body = trim( (string) $body );

	if ( strlen( $body ) < 200 ) {
		return new WP_Error( 'too_short', __( 'This article is too short to check.', 'livingdraft-core' ) );
	}

	$max  = livingdraft_factcheck_max_claims();
	$body = substr( $body, 0, 12000 );

	$system = 'You extract checkable factual claims from news copy. '
		. 'You never evaluate whether a claim is true. You never add information. '
		. 'You only select sentences that are already present in the text.';

	$prompt = "From the article below, list at most {$max} factual claims that could plausibly have been fact-checked by a fact-checking organisation.\n\n"
		. "Prefer claims that:\n"
		. "- assert something about the world that could be false\n"
		. "- are attributed to someone, or circulate widely\n"
		. "- concern public figures, government action, health, or money\n\n"
		. "Ignore:\n"
		. "- the publication's own analysis or opinion\n"
		. "- routine procedural description\n"
		. "- anything that is obviously uncontroversial\n\n"
		. "Return ONLY a JSON array of strings. Each string is a short, self-contained "
		. "restatement of one claim in under 20 words, suitable as a search query. "
		. "No preamble, no markdown, no code fences.\n\n"
		. "ARTICLE:\n" . $body;

	$raw = livingdraft_ai_complete(
		$prompt,
		array(
			'task'        => 'factcheck_claims',
			'system'      => $system,
			'max_tokens'  => 700,
			'temperature' => 0.2,
			'post_id'     => (int) $post_id,
		)
	);

	if ( is_wp_error( $raw ) ) {
		return $raw;
	}

	$claims = livingdraft_factcheck_parse_json_array( $raw );

	if ( empty( $claims ) ) {
		return new WP_Error( 'no_claims', __( 'No checkable claims were found in this article.', 'livingdraft-core' ) );
	}

	return array_slice( $claims, 0, $max );
}

/**
 * Read a JSON array out of a model response.
 *
 * Models wrap JSON in code fences and prose however firmly they are told not
 * to, so the array is located rather than assumed.
 *
 * @since 4.0.0
 * @param string $raw Model output.
 * @return string[]
 */
function livingdraft_factcheck_parse_json_array( $raw ) {
	if ( ! is_scalar( $raw ) ) {
		return array();
	}

	$text = trim( (string) $raw );
	$text = preg_replace( '/^```(?:json)?|```$/mi', '', $text );

	$start = strpos( (string) $text, '[' );
	$end   = strrpos( (string) $text, ']' );

	if ( false === $start || false === $end || $end <= $start ) {
		return array();
	}

	$decoded = json_decode( substr( (string) $text, $start, $end - $start + 1 ), true );

	if ( ! is_array( $decoded ) ) {
		return array();
	}

	$out = array();

	foreach ( $decoded as $item ) {
		if ( ! is_string( $item ) ) {
			continue;
		}

		$item = trim( sanitize_text_field( $item ) );

		if ( strlen( $item ) > 8 ) {
			$out[] = $item;
		}
	}

	return array_values( array_unique( $out ) );
}

/* ==================================================================
 * 4. ASKING GOOGLE
 * ================================================================== */

/**
 * Pull a string out of decoded JSON, whatever the API actually sent.
 *
 * === WHY A BLIND (string) CAST IS NOT ENOUGH ===
 *
 * Every field read below comes from Google's response, and the shape of that
 * response is not ours to guarantee. Casting an array or an object to string
 * raises "Array to string conversion" and yields the literal word "Array",
 * which then flows into a rating comparison or a publisher match and quietly
 * produces a wrong answer rather than an obvious failure.
 *
 * The API is stable today. It is a v1alpha1 endpoint, so it need not stay
 * that way, and a fact-checking tool is the wrong place to find out by
 * silently mis-grading a verdict.
 *
 * @since 4.0.0
 * @param mixed  $value    Decoded JSON value.
 * @param string $fallback Returned when the value is not usable as a string.
 * @return string
 */
function livingdraft_factcheck_str( $value, $fallback = '' ) {
	if ( is_string( $value ) ) {
		return $value;
	}

	if ( is_int( $value ) || is_float( $value ) ) {
		return (string) $value;
	}

	// Arrays, objects, null and booleans are not text.
	return $fallback;
}

/**
 * Search published fact-checks for one claim.
 *
 * @since 4.0.0
 * @param string $claim Short claim text.
 * @return array|WP_Error List of reviews.
 */
function livingdraft_factcheck_search( $claim ) {
	$key = livingdraft_factcheck_key();

	if ( '' === $key ) {
		return new WP_Error( 'no_key', __( 'No Fact Check API key is configured.', 'livingdraft-core' ) );
	}

	$url = add_query_arg(
		array(
			'query'        => rawurlencode( $claim ),
			'languageCode' => rawurlencode( (string) apply_filters( 'livingdraft_factcheck_language', 'en' ) ),
			'pageSize'     => 10,
			'key'          => rawurlencode( $key ),
		),
		LD_FACTCHECK_ENDPOINT
	);

	$response = wp_remote_get( $url, array( 'timeout' => 20 ) );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

	if ( 200 !== $code ) {
		$message = isset( $body['error']['message'] ) ? (string) $body['error']['message'] : '';

		return new WP_Error(
			'api_error',
			sprintf(
				/* translators: 1: HTTP status, 2: message from Google. */
				__( 'Google returned %1$d. %2$s', 'livingdraft-core' ),
				$code,
				$message
			)
		);
	}

	// An empty result is the normal case, not a failure. Most claims have
	// never been fact-checked by anybody.
	if ( empty( $body['claims'] ) || ! is_array( $body['claims'] ) ) {
		return array();
	}

	$reviews = array();

	foreach ( $body['claims'] as $entry ) {
		if ( empty( $entry['claimReview'] ) || ! is_array( $entry['claimReview'] ) ) {
			continue;
		}

		foreach ( $entry['claimReview'] as $review ) {
			if ( ! is_array( $review ) ) {
				continue;
			}

			$publisher = isset( $review['publisher'] ) && is_array( $review['publisher'] )
				? $review['publisher']
				: array();

			$site = livingdraft_factcheck_str( isset( $publisher['site'] ) ? $publisher['site'] : '' );

			$reviews[] = array(
				'claim'     => livingdraft_factcheck_str( isset( $entry['text'] ) ? $entry['text'] : '' ),
				'claimant'  => livingdraft_factcheck_str( isset( $entry['claimant'] ) ? $entry['claimant'] : '' ),
				'publisher' => livingdraft_factcheck_str( isset( $publisher['name'] ) ? $publisher['name'] : '', $site ),
				'site'      => $site,
				'url'       => esc_url_raw( livingdraft_factcheck_str( isset( $review['url'] ) ? $review['url'] : '' ) ),
				'title'     => livingdraft_factcheck_str( isset( $review['title'] ) ? $review['title'] : '' ),
				'rating'    => livingdraft_factcheck_str( isset( $review['textualRating'] ) ? $review['textualRating'] : '' ),
				'date'      => livingdraft_factcheck_str( isset( $review['reviewDate'] ) ? $review['reviewDate'] : '' ),
			);
		}
	}

	foreach ( $reviews as $i => $review ) {
		$reviews[ $i ]['severity'] = livingdraft_factcheck_severity( $review['rating'] );
		$reviews[ $i ]['local']    = livingdraft_factcheck_is_preferred( $review['site'] );
	}

	return livingdraft_factcheck_rank( $reviews );
}

/**
 * Is this publisher one of the preferred desks?
 *
 * @since 4.0.0
 * @param string $site Publisher site.
 * @return bool
 */
function livingdraft_factcheck_is_preferred( $site ) {
	if ( ! is_scalar( $site ) ) {
		return false;
	}

	$site = strtolower( (string) $site );

	if ( '' === $site ) {
		return false;
	}

	foreach ( livingdraft_factcheck_preferred_publishers() as $domain ) {
		if ( false !== strpos( $site, strtolower( $domain ) ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Order results: preferred publishers first, then the most serious verdicts,
 * then the most recent.
 *
 * Severity outranks recency on purpose. A two-year-old "False" from a
 * reputable desk matters more to someone about to publish than a fresh
 * "True" from anywhere.
 *
 * @since 4.0.0
 * @param array $reviews Reviews.
 * @return array
 */
function livingdraft_factcheck_rank( $reviews ) {
	$weight = array(
		'false'   => 0,
		'mixed'   => 1,
		'unknown' => 2,
		'true'    => 3,
	);

	usort(
		$reviews,
		static function ( $a, $b ) use ( $weight ) {
			if ( $a['local'] !== $b['local'] ) {
				return $a['local'] ? -1 : 1;
			}

			$wa = isset( $weight[ $a['severity'] ] ) ? $weight[ $a['severity'] ] : 2;
			$wb = isset( $weight[ $b['severity'] ] ) ? $weight[ $b['severity'] ] : 2;

			if ( $wa !== $wb ) {
				return $wa <=> $wb;
			}

			return strcmp( (string) $b['date'], (string) $a['date'] );
		}
	);

	return $reviews;
}

/* ==================================================================
 * 5. WHAT THE RECORD ACTUALLY SAYS
 * ================================================================== */

/**
 * Summarise what published sources say about a debunked claim.
 *
 * === THE SAFETY RULE, IN CODE ===
 *
 * The model is given the fact-check article's own text and told to summarise
 * only that. It is forbidden from adding anything. And then — because a
 * prompt is a request, not a guarantee — the result is DISCARDED unless it
 * carries at least one source URL. A summary with no source is exactly the
 * hallucinated correction this whole design exists to prevent, so it is not
 * shown at all rather than shown with a caveat.
 *
 * @since 4.0.0
 * @param string $claim  The claim as written in the draft.
 * @param array  $review The strongest matching fact-check.
 * @return array|null {text, sources} or null when nothing safe was produced.
 */
function livingdraft_factcheck_summarise( $claim, $review ) {
	if ( ! function_exists( 'livingdraft_ai_complete' ) || empty( $review['url'] ) ) {
		return null;
	}

	// --- Source 1: the fact-check article itself ---
	$source_text = livingdraft_factcheck_fetch_article( $review['url'] );

	$settings = function_exists( 'livingdraft_ai_get_settings' ) ? livingdraft_ai_get_settings() : array();
	$provider = isset( $settings['provider'] ) ? $settings['provider'] : '';

	$args = array(
		'task'        => 'factcheck_summary',
		'max_tokens'  => 450,
		'temperature' => 0.1,
		'system'      => 'You summarise what a named fact-checking organisation has published. '
			. 'You never state a fact on your own authority. You never add information that is '
			. 'not in the material you were given. If the material does not answer the question, '
			. 'you say so plainly. Every statement you make must be attributable to a source you '
			. 'were shown.',
	);

	// --- Source 2: grounded search, where the provider offers it ---
	// Gemini's native google_search tool. Nothing is passed for OpenAI, which
	// would otherwise answer from memory — precisely what must not happen.
	if ( 'gemini' === $provider ) {
		$args['ld_grounding'] = true;
	}

	$prompt = "A journalist is about to publish this claim:\n\n\"" . $claim . "\"\n\n"
		. sprintf(
			"%s has reviewed a similar claim and rated it \"%s\".\nTheir article: %s\n\n",
			$review['publisher'],
			$review['rating'],
			$review['url']
		);

	if ( '' !== $source_text ) {
		$prompt .= "Here is the text of that fact-check:\n\n" . $source_text . "\n\n";
	}

	$prompt .= "In no more than 90 words, say what the published record shows about this claim, "
		. "attributing every statement to its source by name.\n\n"
		. "Then on a new line write SOURCES: followed by the URLs you relied on, comma separated.\n\n"
		. "If the material above does not actually address the journalist's claim, say exactly that "
		. "and give no sources. Do not fill gaps from your own knowledge.";

	$raw = livingdraft_ai_complete( $prompt, $args );

	if ( is_wp_error( $raw ) ) {
		return null;
	}

	$text = trim( (string) $raw );

	// --- The enforcement ---
	$sources = array();

	if ( preg_match( '/SOURCES:\s*(.+)$/is', $text, $m ) ) {
		$text = trim( (string) preg_replace( '/SOURCES:\s*.+$/is', '', $text ) );

		foreach ( preg_split( '/[\s,]+/', $m[1] ) as $candidate ) {
			$candidate = trim( $candidate, " \t\n\r\0\x0B.,;)(" );

			if ( 0 === strpos( $candidate, 'http' ) ) {
				$sources[] = esc_url_raw( $candidate );
			}
		}
	}

	$sources = array_values( array_unique( array_filter( $sources ) ) );

	/*
	 * No source, no summary. This is the load-bearing line of the whole
	 * feature. A model that answered from memory produces exactly this shape
	 * — confident prose, no citations — and showing it would hand a
	 * journalist a fabricated correction with a fact-checker's authority.
	 */
	if ( empty( $sources ) || strlen( $text ) < 20 ) {
		return null;
	}

	return array(
		'text'    => wp_kses_post( $text ),
		'sources' => $sources,
	);
}

/**
 * Fetch the readable text of a fact-check article.
 *
 * Best effort. A failure here is not an error: the summary step simply works
 * from the rating and headline instead, and the enforcement above still
 * applies.
 *
 * @since 4.0.0
 * @param string $url Article URL.
 * @return string Plain text, or ''.
 */
function livingdraft_factcheck_fetch_article( $url ) {
	$url = esc_url_raw( (string) $url );

	if ( '' === $url || 0 !== strpos( $url, 'http' ) ) {
		return '';
	}

	$cache_key = 'ld_fc_' . md5( $url );
	$cached    = get_transient( $cache_key );

	if ( is_string( $cached ) ) {
		return $cached;
	}

	/*
	 * A reader service, where one is configured.
	 *
	 * The fallback below strips tags with a regex, which on a modern news
	 * site leaves navigation, cookie banners, related-article rails and
	 * whatever else sits in the markup. That noise goes into the prompt and
	 * competes with the actual fact-check for the model's attention.
	 *
	 * Jina Reader and Firecrawl both return the article as clean text. The
	 * difference in summary quality is not subtle, and this is a feature
	 * where the summary either helps a journalist or misleads one.
	 */
	$reader = livingdraft_factcheck_read_via_service( $url );

	if ( '' !== $reader ) {
		set_transient( $cache_key, $reader, DAY_IN_SECONDS );
		return $reader;
	}

	$response = wp_remote_get(
		$url,
		array(
			'timeout'    => 15,
			'user-agent' => 'Mozilla/5.0 (compatible; LivingDraftFactCheck/1.0)',
		)
	);

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		set_transient( $cache_key, '', HOUR_IN_SECONDS );
		return '';
	}

	$html = (string) wp_remote_retrieve_body( $response );

	// Drop everything that is not prose before stripping tags, or the result
	// is a wall of minified JavaScript.
	$html = preg_replace( '#<(script|style|nav|header|footer|aside|form)\b[^>]*>.*?</\1>#is', ' ', $html );
	$text = wp_strip_all_tags( (string) $html );
	$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
	$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	$text = substr( $text, 0, 5000 );

	set_transient( $cache_key, $text, DAY_IN_SECONDS );

	return $text;
}

/**
 * Reader-service keys, if configured.
 *
 * @since 4.0.0
 * @return array provider => key
 */
function livingdraft_factcheck_reader_keys() {
	$stored = get_option( 'livingdraft_reader_keys', array() );
	$stored = is_array( $stored ) ? $stored : array();
	$out    = array();

	foreach ( array( 'jina', 'firecrawl' ) as $provider ) {
		if ( ! empty( $stored[ $provider ] ) && function_exists( 'livingdraft_ai_decrypt' ) ) {
			$key = livingdraft_ai_decrypt( $stored[ $provider ] );

			if ( '' !== $key ) {
				$out[ $provider ] = $key;
			}
		}
	}

	return $out;
}

/**
 * Store a reader-service key.
 *
 * @since 4.0.0
 * @param string $provider 'jina' or 'firecrawl'.
 * @param string $key      Raw key.
 * @return void
 */
function livingdraft_factcheck_set_reader_key( $provider, $key ) {
	if ( ! in_array( $provider, array( 'jina', 'firecrawl' ), true ) ) {
		return;
	}

	$stored = get_option( 'livingdraft_reader_keys', array() );
	$stored = is_array( $stored ) ? $stored : array();
	$key    = trim( (string) $key );

	if ( '' === $key ) {
		unset( $stored[ $provider ] );
	} elseif ( function_exists( 'livingdraft_ai_encrypt' ) ) {
		$stored[ $provider ] = livingdraft_ai_encrypt( $key );
	}

	update_option( 'livingdraft_reader_keys', $stored, false );
}

/**
 * Read a page through Jina Reader or Firecrawl.
 *
 * Returns '' on any failure so the caller falls through to its own fetch.
 * A reader service being down must never stop a fact check.
 *
 * @since 4.0.0
 * @param string $url Page to read.
 * @return string Clean text, or ''.
 */
function livingdraft_factcheck_read_via_service( $url ) {
	$keys = livingdraft_factcheck_reader_keys();

	if ( empty( $keys ) ) {
		return '';
	}

	if ( isset( $keys['jina'] ) ) {
		// Jina Reader takes the target URL as a path suffix and returns
		// markdown. The key raises the rate limit; the endpoint works
		// without one but not reliably enough to depend on.
		$response = wp_remote_get(
			'https://r.jina.ai/' . $url,
			array(
				'timeout' => 25,
				'headers' => array(
					'Authorization' => 'Bearer ' . $keys['jina'],
					'X-Return-Format' => 'text',
				),
			)
		);

		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$text = trim( (string) wp_remote_retrieve_body( $response ) );

			if ( strlen( $text ) > 200 ) {
				return substr( $text, 0, 5000 );
			}
		}
	}

	if ( isset( $keys['firecrawl'] ) ) {
		$response = wp_remote_post(
			'https://api.firecrawl.dev/v1/scrape',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $keys['firecrawl'],
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'url'             => $url,
						'formats'         => array( 'markdown' ),
						'onlyMainContent' => true,
					)
				),
			)
		);

		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$text = isset( $body['data']['markdown'] ) ? $body['data']['markdown'] : '';

			if ( is_string( $text ) && strlen( trim( $text ) ) > 200 ) {
				return substr( trim( $text ), 0, 5000 );
			}
		}
	}

	return '';
}

/* ==================================================================
 * 6. RUNNING A CHECK
 * ================================================================== */

/**
 * Check one article end to end.
 *
 * @since 4.0.0
 * @param int $post_id Post.
 * @return array|WP_Error
 */
function livingdraft_factcheck_run( $post_id ) {
	$post_id = (int) $post_id;

	if ( ! livingdraft_factcheck_enabled() ) {
		return new WP_Error( 'off', __( 'Fact checking is not configured.', 'livingdraft-core' ) );
	}

	$claims = livingdraft_factcheck_extract_claims( $post_id );

	if ( is_wp_error( $claims ) ) {
		return $claims;
	}

	$findings = array();
	$flagged  = 0;

	foreach ( $claims as $claim ) {
		$reviews = livingdraft_factcheck_search( $claim );

		if ( is_wp_error( $reviews ) ) {
			// One failed lookup should not lose the other seven.
			$findings[] = array(
				'claim'   => $claim,
				'status'  => 'error',
				'message' => $reviews->get_error_message(),
				'reviews' => array(),
			);
			continue;
		}

		if ( empty( $reviews ) ) {
			$findings[] = array(
				'claim'   => $claim,
				'status'  => 'clear',
				'reviews' => array(),
			);
			continue;
		}

		$worst = $reviews[0]['severity'];
		$is_bad = in_array( $worst, array( 'false', 'mixed' ), true );

		$finding = array(
			'claim'   => $claim,
			'status'  => $is_bad ? 'flagged' : 'seen',
			'reviews' => array_slice( $reviews, 0, 4 ),
			'summary' => null,
		);

		if ( $is_bad ) {
			$flagged++;
			$finding['summary'] = livingdraft_factcheck_summarise( $claim, $reviews[0] );
		}

		$findings[] = $finding;
	}

	$result = array(
		'checked_at' => current_time( 'mysql' ),
		'flagged'    => $flagged,
		'total'      => count( $findings ),
		'findings'   => $findings,
		'acked'      => false,
	);

	update_post_meta( $post_id, LD_FACTCHECK_META, $result );

	return $result;
}

/**
 * The stored result for an article.
 *
 * @since 4.0.0
 * @param int $post_id Post.
 * @return array|null
 */
function livingdraft_factcheck_result( $post_id ) {
	$stored = get_post_meta( (int) $post_id, LD_FACTCHECK_META, true );

	return is_array( $stored ) ? $stored : null;
}

/**
 * Clear the stored result when the article changes.
 *
 * A check run against an earlier draft says nothing about the current one,
 * and a stale green tick is more dangerous than no tick at all.
 *
 * @since 4.0.0
 * @param int     $post_id Post.
 * @param WP_Post $post    Post object.
 * @param bool    $update  Whether this is an update.
 * @return void
 */
function livingdraft_factcheck_invalidate( $post_id, $post, $update ) {
	if ( ! $update || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	$stored = livingdraft_factcheck_result( $post_id );

	if ( ! $stored ) {
		return;
	}

	$hash = md5( (string) $post->post_content );

	if ( isset( $stored['hash'] ) && $stored['hash'] === $hash ) {
		return;
	}

	$stored['stale'] = true;
	update_post_meta( $post_id, LD_FACTCHECK_META, $stored );
}
add_action( 'save_post', 'livingdraft_factcheck_invalidate', 30, 3 );
