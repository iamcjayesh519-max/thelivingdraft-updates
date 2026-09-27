<?php
/**
 * Mail: AI drafting.
 *
 * === WHAT THIS IS ALLOWED TO DO ===
 *
 * Write drafts. Nothing else. There is no code path from this file to
 * livingdraft_campaign_start(), and there is not going to be one. A campaign
 * goes out because a person read it and pressed a button on a screen that
 * showed them the recipient count first.
 *
 * This is the same line the Desk crossed in v4.0 and was removed for in v4.1.
 * The distinction is not squeamishness about AI — the SEO metabox has had
 * Generate buttons on it for four versions and they are useful. It is that a
 * draft in an editor can be read, rewritten or thrown away, and a sent email
 * cannot be any of those things. Nine hundred people have it.
 *
 * === WHY THE NEWSLETTER DRAFT USES YOUR OWN WORDS ===
 *
 * The three tasks below all work from material that already exists: your
 * published headlines and standfirsts, your own draft, the message you were
 * sent. None of them asks a model to originate a claim.
 *
 * That is deliberate, and it is why this is worth having when the Desk was
 * not. A model summarising six headlines you wrote this week is doing a
 * mechanical job with the facts already fixed. A model writing a news story
 * from wire copy is inventing the parts it does not have — and reads exactly
 * the same either way.
 *
 * @package LivingDraftCore
 * @since 4.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Is AI drafting usable?
 *
 * @since 4.3.0
 * @return bool
 */
function livingdraft_mail_ai_ready() {
	return function_exists( 'livingdraft_ai_complete' );
}

/**
 * The house voice, as far as it can be stated in a prompt.
 *
 * Kept short on purpose. A long style prompt does not produce house style; it
 * produces a model performing a description of house style, which is worse
 * than plain prose because it is plain prose wearing a costume. What a prompt
 * CAN reliably do is rule things out, so this is mostly a list of things not
 * to do.
 *
 * @since 4.3.0
 * @return string
 */
function livingdraft_mail_ai_voice() {
	$voice = sprintf(
		/* translators: %s: site name. */
		__( 'You are drafting for %s, an Indian news publication. Write plainly, in short declarative sentences. Never use marketing language, exclamation marks, or phrases like "dive in", "unpack", "must-read", "you won\'t believe" or "in today\'s fast-moving world". Do not editorialise, do not add enthusiasm the material does not have, and never state a fact that is not in the material you were given. If something is missing, leave it out rather than filling the gap.', 'livingdraft-core' ),
		get_bloginfo( 'name' )
	);

	/**
	 * Filter the drafting voice instruction.
	 *
	 * @since 4.3.0
	 * @param string $voice The instruction.
	 */
	return (string) apply_filters( 'livingdraft_mail_ai_voice', $voice );
}

/* ==================================================================
 * 1. DRAFT A NEWSLETTER FROM RECENT POSTS
 * ================================================================== */

/**
 * Posts published in a window, as material for a draft.
 *
 * @since 4.3.0
 * @param int $days How far back.
 * @param int $max  Most posts to include.
 * @return array
 */
function livingdraft_mail_ai_recent_posts( $days = 7, $max = 8 ) {
	$posts = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => $max,
			'date_query'     => array(
				array( 'after' => $days . ' days ago' ),
			),
		)
	);

	$out = array();

	foreach ( $posts as $post ) {
		$out[] = array(
			'title'   => get_the_title( $post ),
			'url'     => get_permalink( $post ),
			'excerpt' => has_excerpt( $post )
				? get_the_excerpt( $post )
				: wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 40, '' ),
			'date'    => get_the_date( 'j F', $post ),
		);
	}

	return $out;
}

/**
 * Draft a newsletter from recent posts.
 *
 * Every link in the output is one this function put there, taken from
 * get_permalink(). The model is told to use the markers and never to write a
 * URL, because a model asked for a newsletter with links will invent
 * plausible ones — and a plausible wrong URL in a mailed newsletter cannot be
 * corrected afterwards.
 *
 * @since 4.3.0
 * @param int $days How far back to look.
 * @return array|WP_Error { 'html' => string, 'posts' => int }
 */
function livingdraft_mail_ai_draft_newsletter( $days = 7 ) {
	if ( ! livingdraft_mail_ai_ready() ) {
		return new WP_Error( 'no_ai', __( 'No AI provider is configured. Add a key under Settings → AI.', 'livingdraft-core' ) );
	}

	$posts = livingdraft_mail_ai_recent_posts( $days );

	if ( empty( $posts ) ) {
		return new WP_Error(
			'no_posts',
			sprintf(
				/* translators: %d: number of days. */
				__( 'Nothing has been published in the last %d days.', 'livingdraft-core' ),
				(int) $days
			)
		);
	}

	$material = '';
	$n        = 0;

	foreach ( $posts as $post ) {
		$n++;
		$material .= "[{$n}] {$post['title']}\n"
			. "DATE: {$post['date']}\n"
			. "SUMMARY: {$post['excerpt']}\n\n";
	}

	$prompt = sprintf(
		"%s\n\n%s\n\n%s",
		__( 'Below are the stories this publication ran this week, numbered. Write the body of an email newsletter introducing them to subscribers.', 'livingdraft-core' ),
		$material,
		__(
			"Rules:\n"
			. "- Open with two or three sentences on what mattered this week. Draw only on the stories below.\n"
			. "- Then one short paragraph per story, in the order given, two sentences at most.\n"
			. "- Mark where each story's link goes by writing {{1}}, {{2}} and so on at the end of its paragraph, using the number in brackets above.\n"
			. "- Never write a URL. Never invent a headline, figure, date or name that is not above.\n"
			. "- No sign-off, no subject line, no greeting. Body text only.\n"
			. '- Return plain paragraphs separated by blank lines. No markdown, no HTML.',
			'livingdraft-core'
		)
	);

	$text = livingdraft_ai_complete(
		$prompt,
		array(
			'task'        => 'newsletter_draft',
			'system'      => livingdraft_mail_ai_voice(),
			'max_tokens'  => 1100,
			'temperature' => 0.5,
		)
	);

	if ( is_wp_error( $text ) ) {
		return $text;
	}

	return array(
		'html'  => livingdraft_mail_ai_resolve_links( $text, $posts ),
		'posts' => count( $posts ),
	);
}

/**
 * Replace {{n}} markers with real anchors, and flag any that are wrong.
 *
 * A marker pointing at a story that does not exist means the model
 * hallucinated a reference. It becomes a visible bracket in the draft rather
 * than being silently dropped, for the same reason the old Desk turned
 * unsourced claims into visible gaps: an editor can act on a gap they can
 * see, and cannot act on one that was quietly tidied away.
 *
 * @since 4.3.0
 * @param string $text  Model output.
 * @param array  $posts Material.
 * @return string HTML.
 */
function livingdraft_mail_ai_resolve_links( $text, $posts ) {
	/*
	 * ORDER MATTERS, and getting it wrong is silent.
	 *
	 * The scrub for invented URLs has to run FIRST, on the raw model output,
	 * before any markers become anchors. Run the other way round it matches
	 * the href of every link this function just inserted and destroys the
	 * markup — producing a draft full of broken anchors that still looks
	 * roughly like prose in a textarea.
	 *
	 * At this point every URL in the text is by definition one the model
	 * wrote, because it was told to use markers and never write a URL. So
	 * anything matching here is a fabrication.
	 */
	$text = preg_replace(
		'#https?://\S+#i',
		'[' . esc_html__( 'REMOVED — invented link', 'livingdraft-core' ) . ']',
		(string) $text
	);

	// Now the markers, which are the only source of real URLs.
	$text = preg_replace_callback(
		'/\{\{\s*(\d+)\s*\}\}/',
		function ( $m ) use ( $posts ) {
			$i = (int) $m[1] - 1;

			if ( ! isset( $posts[ $i ] ) ) {
				return '[' . esc_html__( 'LINK ERROR — no such story', 'livingdraft-core' ) . ']';
			}

			return sprintf(
				'<a href="%s">%s</a>',
				esc_url( $posts[ $i ]['url'] ),
				esc_html( $posts[ $i ]['title'] )
			);
		},
		(string) $text
	);

	return trim( (string) $text );
}

/* ==================================================================
 * 2. SUBJECT LINES AND PREVIEW TEXT
 * ================================================================== */

/**
 * Suggest subject lines and preheaders for a body.
 *
 * @since 4.3.0
 * @param string $body Campaign body.
 * @return array|WP_Error List of [ 'subject', 'preview' ].
 */
function livingdraft_mail_ai_subjects( $body ) {
	if ( ! livingdraft_mail_ai_ready() ) {
		return new WP_Error( 'no_ai', __( 'No AI provider is configured.', 'livingdraft-core' ) );
	}

	$body = trim( wp_strip_all_tags( $body ) );

	if ( strlen( $body ) < 60 ) {
		return new WP_Error( 'too_short', __( 'Write the newsletter first — there is not enough here to write a subject line from.', 'livingdraft-core' ) );
	}

	$prompt = sprintf(
		"%s\n\n---\n%s\n---\n\n%s",
		__( 'Here is the body of a newsletter about to go out.', 'livingdraft-core' ),
		wp_trim_words( $body, 500, '' ),
		__(
			"Give five subject lines with matching preview text.\n"
			. "- Subject: under 50 characters. State what is in the email. No questions, no teases, no colons used to make a headline sound clever, no emoji.\n"
			. "- Preview: under 90 characters, and it must add information rather than repeat the subject.\n"
			. "- Draw only on what is in the body above.\n"
			. 'Return ONLY a JSON array like [{"subject":"...","preview":"..."}]. No markdown fences, no commentary.',
			'livingdraft-core'
		)
	);

	$text = livingdraft_ai_complete(
		$prompt,
		array(
			'task'        => 'newsletter_subject',
			'system'      => livingdraft_mail_ai_voice(),
			'max_tokens'  => 500,
			'temperature' => 0.7,
		)
	);

	if ( is_wp_error( $text ) ) {
		return $text;
	}

	$rows = livingdraft_mail_ai_parse_json( $text );

	if ( empty( $rows ) ) {
		return new WP_Error( 'unparsable', __( 'The model did not return usable suggestions. Try again.', 'livingdraft-core' ) );
	}

	$out = array();

	foreach ( $rows as $row ) {
		if ( empty( $row['subject'] ) ) {
			continue;
		}

		$out[] = array(
			'subject' => sanitize_text_field( (string) $row['subject'] ),
			'preview' => sanitize_text_field( (string) ( $row['preview'] ?? '' ) ),
		);
	}

	return $out;
}

/* ==================================================================
 * 3. REPLY DRAFTS
 * ================================================================== */

/**
 * Draft replies to a message.
 *
 * Takes pasted text. Nothing reads a mailbox: doing that would mean storing
 * the password to your actual email account in the WordPress database,
 * polling it on cron, and putting full inbox access behind whatever the
 * weakest admin password on the site happens to be. Pasting a message in is
 * four seconds of work and moves none of that risk onto the site.
 *
 * @since 4.3.0
 * @param string $message  The message being replied to.
 * @param string $intent   What the reply should do.
 * @return array|WP_Error List of [ 'label', 'body' ].
 */
function livingdraft_mail_ai_reply( $message, $intent = '' ) {
	if ( ! livingdraft_mail_ai_ready() ) {
		return new WP_Error( 'no_ai', __( 'No AI provider is configured.', 'livingdraft-core' ) );
	}

	$message = trim( wp_strip_all_tags( $message ) );

	if ( strlen( $message ) < 20 ) {
		return new WP_Error( 'too_short', __( 'Paste the message you want to reply to.', 'livingdraft-core' ) );
	}

	$prompt = sprintf(
		"%s\n\n---\n%s\n---\n\n%s\n\n%s",
		__( 'Here is a message sent to the newsroom.', 'livingdraft-core' ),
		wp_trim_words( $message, 900, '' ),
		$intent
			? sprintf(
				/* translators: %s: what the sender wants the reply to do. */
				__( 'The reply should: %s', 'livingdraft-core' ),
				sanitize_text_field( $intent )
			)
			: __( 'Work out what a reasonable reply would be.', 'livingdraft-core' ),
		__(
			"Draft three replies taking different approaches — for example accepting, declining, and asking for more before deciding.\n"
			. "- Short. Most email replies should be three or four sentences.\n"
			. "- Never promise anything specific the message does not already establish: no dates, no fees, no commitments to publish or correct.\n"
			. "- Where a detail is needed that you do not have, write [DETAIL NEEDED] rather than inventing it.\n"
			. "- No subject line and no sign-off name.\n"
			. 'Return ONLY a JSON array like [{"label":"Accept","body":"..."}]. No markdown fences.',
			'livingdraft-core'
		)
	);

	$text = livingdraft_ai_complete(
		$prompt,
		array(
			'task'        => 'mail_reply',
			'system'      => livingdraft_mail_ai_voice(),
			'max_tokens'  => 900,
			'temperature' => 0.6,
		)
	);

	if ( is_wp_error( $text ) ) {
		return $text;
	}

	$rows = livingdraft_mail_ai_parse_json( $text );

	if ( empty( $rows ) ) {
		return new WP_Error( 'unparsable', __( 'The model did not return usable drafts. Try again.', 'livingdraft-core' ) );
	}

	$out = array();

	foreach ( $rows as $row ) {
		if ( empty( $row['body'] ) ) {
			continue;
		}

		$out[] = array(
			'label' => sanitize_text_field( (string) ( $row['label'] ?? __( 'Reply', 'livingdraft-core' ) ) ),
			'body'  => sanitize_textarea_field( (string) $row['body'] ),
		);
	}

	return $out;
}

/* ==================================================================
 * 4. SHARED
 * ================================================================== */

/**
 * Parse a JSON array out of a model response.
 *
 * Models add markdown fences and a sentence of preamble however firmly they
 * are told not to. Stripping both and then taking the outermost bracketed
 * span is more reliable than any amount of additional instruction.
 *
 * @since 4.3.0
 * @param string $text Response.
 * @return array
 */
function livingdraft_mail_ai_parse_json( $text ) {
	$text = trim( (string) $text );
	$text = preg_replace( '/^```(?:json)?\s*|\s*```$/m', '', $text );

	$start = strpos( (string) $text, '[' );
	$end   = strrpos( (string) $text, ']' );

	if ( false === $start || false === $end || $end <= $start ) {
		return array();
	}

	$decoded = json_decode( substr( (string) $text, $start, $end - $start + 1 ), true );

	return is_array( $decoded ) ? $decoded : array();
}

/* ==================================================================
 * 5. AJAX
 * ================================================================== */

/**
 * Shared permission check.
 *
 * @since 4.3.0
 * @return void
 */
function livingdraft_mail_ai_guard() {
	check_ajax_referer( 'ld_mail_ai', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'livingdraft-core' ) ), 403 );
	}
}

/**
 * AJAX: draft a newsletter.
 *
 * @since 4.3.0
 * @return void
 */
function livingdraft_mail_ai_ajax_newsletter() {
	livingdraft_mail_ai_guard();

	$days   = isset( $_POST['days'] ) ? absint( wp_unslash( $_POST['days'] ) ) : 7;
	$result = livingdraft_mail_ai_draft_newsletter( min( 60, max( 1, $days ) ) );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success( $result );
}
add_action( 'wp_ajax_ld_mail_ai_newsletter', 'livingdraft_mail_ai_ajax_newsletter' );

/**
 * AJAX: subject lines.
 *
 * @since 4.3.0
 * @return void
 */
function livingdraft_mail_ai_ajax_subjects() {
	livingdraft_mail_ai_guard();

	$body   = isset( $_POST['body'] ) ? wp_kses_post( wp_unslash( $_POST['body'] ) ) : '';
	$result = livingdraft_mail_ai_subjects( $body );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success( array( 'suggestions' => $result ) );
}
add_action( 'wp_ajax_ld_mail_ai_subjects', 'livingdraft_mail_ai_ajax_subjects' );

/**
 * AJAX: reply drafts.
 *
 * @since 4.3.0
 * @return void
 */
function livingdraft_mail_ai_ajax_reply() {
	livingdraft_mail_ai_guard();

	$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
	$intent  = isset( $_POST['intent'] ) ? sanitize_text_field( wp_unslash( $_POST['intent'] ) ) : '';

	$result = livingdraft_mail_ai_reply( $message, $intent );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success( array( 'drafts' => $result ) );
}
add_action( 'wp_ajax_ld_mail_ai_reply', 'livingdraft_mail_ai_ajax_reply' );
