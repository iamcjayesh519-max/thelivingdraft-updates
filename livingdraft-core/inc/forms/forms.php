<?php
/**
 * Forms: messages from readers.
 *
 * === STORE FIRST, MAIL SECOND ===
 *
 * The single most important decision in this file, and the one most contact
 * form plugins get wrong.
 *
 * The obvious implementation of a contact form is: validate, send an email to
 * the site owner, show a thank-you. It works right up until the mail does not
 * go — an expired API key, a mailbox over quota, a provider outage, a DNS
 * change that broke DKIM last Tuesday. When that happens the reader sees the
 * thank-you, believes they have been in touch, and the message is gone. There
 * is nothing to recover because it was never anywhere.
 *
 * So every message is written to the database first and mailed second. If the
 * mail fails, the message is still on the Messages screen, flagged as
 * undelivered, and the reader's thank-you was honest — they did reach you,
 * even if your notification did not reach your inbox.
 *
 * === WHY THERE IS NO CAPTCHA ===
 *
 * A captcha means a third-party script on the front end of a site that ships
 * no external requests, plus a reader's interaction data going to an
 * advertising company, plus a measurable share of real people who fail it and
 * leave. reCAPTCHA v3 also scores users invisibly, which means some readers
 * are silently refused with no way to know why or appeal.
 *
 * Three cheaper defences catch nearly everything a small site sees:
 *
 *   1. A honeypot field no human can see. Most bots fill every input.
 *   2. A timestamp trap. A form submitted under four seconds after it was
 *      rendered was not filled in by a person reading it.
 *   3. A per-address rate limit, so nobody can flood the table.
 *
 * None of them are perfect. All of them are invisible to a real reader, which
 * is the property that matters.
 *
 * @package LivingDraftCore
 * @since 4.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings option.
 *
 * @since 4.5.0
 * @var string
 */
const LD_FORMS_SETTINGS = 'livingdraft_forms_settings';

/**
 * Shortest believable time between a form rendering and being submitted.
 *
 * @since 4.5.0
 * @var int
 */
const LD_FORMS_MIN_SECONDS = 4;

/**
 * Settings.
 *
 * @since 4.5.0
 * @return array
 */
function livingdraft_forms_settings() {
	$defaults = array(
		'recipient'     => get_option( 'admin_email' ),
		'subject_prefix' => sprintf(
			/* translators: %s: site name. */
			__( '[%s]', 'livingdraft-core' ),
			get_bloginfo( 'name' )
		),
		'acknowledge'   => true,  // Send the reader a copy.
		'store_ip'      => false, // Off by default. See below.
	);

	$stored = get_option( LD_FORMS_SETTINGS, array() );

	return wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );
}

/* ==================================================================
 * 1. STORAGE
 * ================================================================== */

/**
 * The message post type.
 *
 * Private, no public archive, and no create_posts capability — the only way a
 * message gets in is through the form.
 *
 * @since 4.5.0
 * @return void
 */
function livingdraft_forms_message_type() {
	register_post_type(
		'ld_message',
		array(
			'labels'          => array(
				'name'          => __( 'Messages', 'livingdraft-core' ),
				'singular_name' => __( 'Message', 'livingdraft-core' ),
			),
			'public'          => false,
			'show_ui'         => false, // Our own screen handles these.
			'capability_type' => 'post',
			'map_meta_cap'    => true,
			'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
			'supports'        => array( 'title', 'editor' ),
			'has_archive'     => false,
			'rewrite'         => false,
			'show_in_rest'    => false,
		)
	);
}
add_action( 'init', 'livingdraft_forms_message_type' );

/**
 * Message states.
 *
 * @since 4.5.0
 * @return array
 */
function livingdraft_forms_states() {
	return array(
		'new'      => __( 'New', 'livingdraft-core' ),
		'read'     => __( 'Read', 'livingdraft-core' ),
		'answered' => __( 'Answered', 'livingdraft-core' ),
	);
}

/**
 * Store one message.
 *
 * @since 4.5.0
 * @param array $data Name, email, subject, message.
 * @return int|WP_Error Message id.
 */
function livingdraft_forms_store( $data ) {
	$id = wp_insert_post(
		array(
			'post_type'    => 'ld_message',
			'post_status'  => 'publish',
			'post_title'   => $data['subject'],
			'post_content' => $data['message'],
		),
		true
	);

	if ( is_wp_error( $id ) ) {
		return $id;
	}

	update_post_meta( $id, '_ld_from_name', $data['name'] );
	update_post_meta( $id, '_ld_from_email', $data['email'] );
	update_post_meta( $id, '_ld_state', 'new' );
	update_post_meta( $id, '_ld_source', esc_url_raw( (string) wp_get_referer() ) );

	$settings = livingdraft_forms_settings();

	/*
	 * The IP is off by default and stored anonymised when on. A contact form
	 * does not need to know where someone was standing when they wrote to
	 * you; it is kept only as a spam-pattern signal, and the last octet adds
	 * nothing to that while making the record personal data.
	 */
	if ( ! empty( $settings['store_ip'] ) ) {
		$raw = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		update_post_meta(
			$id,
			'_ld_ip',
			function_exists( 'wp_privacy_anonymize_ip' ) ? wp_privacy_anonymize_ip( $raw ) : ''
		);
	}

	/**
	 * Fires after a message is stored, before any mail is attempted.
	 *
	 * @since 4.5.0
	 * @param int   $id   Message id.
	 * @param array $data Submitted data.
	 */
	do_action( 'livingdraft_message_received', $id, $data );

	return (int) $id;
}

/**
 * Read a message's stored fields.
 *
 * @since 4.5.0
 * @param int $id Message id.
 * @return array
 */
function livingdraft_forms_message( $id ) {
	$post = get_post( $id );

	if ( ! $post || 'ld_message' !== $post->post_type ) {
		return array();
	}

	return array(
		'id'        => (int) $id,
		'subject'   => $post->post_title,
		'message'   => $post->post_content,
		'name'      => (string) get_post_meta( $id, '_ld_from_name', true ),
		'email'     => (string) get_post_meta( $id, '_ld_from_email', true ),
		'state'     => (string) ( get_post_meta( $id, '_ld_state', true ) ?: 'new' ),
		'source'    => (string) get_post_meta( $id, '_ld_source', true ),
		'notified'  => (bool) get_post_meta( $id, '_ld_notified', true ),
		'error'     => (string) get_post_meta( $id, '_ld_notify_error', true ),
		'received'  => get_the_date( 'j M Y, H:i', $post ),
	);
}

/**
 * How many messages are unread.
 *
 * @since 4.5.0
 * @return int
 */
function livingdraft_forms_unread_count() {
	$ids = get_posts(
		array(
			'post_type'      => 'ld_message',
			'post_status'    => 'any',
			'posts_per_page' => 100,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => '_ld_state', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => 'new', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);

	return count( $ids );
}

/* ==================================================================
 * 2. SPAM GUARDS
 * ================================================================== */

/**
 * The hidden fields every form carries.
 *
 * The timestamp is signed with wp_hash so it cannot be back-dated by editing
 * the value in devtools — a bot that rewrites it to an hour ago would sail
 * through the time trap otherwise.
 *
 * @since 4.5.0
 * @return void
 */
function livingdraft_forms_guard_fields() {
	$now = time();
	?>
	<p class="ld-form-trap" aria-hidden="true">
		<label for="ld-form-website-<?php echo esc_attr( (string) $now ); ?>"><?php esc_html_e( 'Leave this empty', 'livingdraft-core' ); ?></label>
		<input type="text" id="ld-form-website-<?php echo esc_attr( (string) $now ); ?>" name="website" tabindex="-1" autocomplete="off" />
	</p>
	<input type="hidden" name="rendered" value="<?php echo esc_attr( (string) $now ); ?>" />
	<input type="hidden" name="rendered_sig" value="<?php echo esc_attr( wp_hash( (string) $now ) ); ?>" />
	<?php
}

/**
 * Run the guards against a submission.
 *
 * Returns a WP_Error the caller should treat carefully: for the honeypot and
 * the time trap it reports success to the sender anyway. Telling a bot which
 * check it failed is how the next version of the bot passes.
 *
 * @since 4.5.0
 * @param string $gate_key Rate-limit bucket, usually the email address.
 * @return true|string 'ok', 'silent' (pretend success), or an error message.
 */
function livingdraft_forms_check_guards( $gate_key ) {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Caller verifies the nonce first.

	// 1. Honeypot.
	if ( ! empty( $_POST['website'] ) ) {
		return 'silent';
	}

	// 2. Time trap.
	$rendered = isset( $_POST['rendered'] ) ? absint( wp_unslash( $_POST['rendered'] ) ) : 0;
	$sig      = isset( $_POST['rendered_sig'] ) ? sanitize_text_field( wp_unslash( $_POST['rendered_sig'] ) ) : '';

	if ( ! $rendered || ! hash_equals( wp_hash( (string) $rendered ), $sig ) ) {
		return 'silent';
	}

	if ( ( time() - $rendered ) < LD_FORMS_MIN_SECONDS ) {
		return 'silent';
	}

	/*
	 * A form rendered more than twelve hours ago is almost certainly a
	 * cached page being replayed. Rejecting it out loud is right — a real
	 * person who left a tab open overnight should be told to try again
	 * rather than have their message quietly dropped.
	 */
	if ( ( time() - $rendered ) > 12 * HOUR_IN_SECONDS ) {
		return __( 'This page has been open a long time. Reload it and send again.', 'livingdraft-core' );
	}

	// 3. Rate limit.
	$gate  = 'ld_form_' . md5( strtolower( $gate_key ) );
	$count = (int) get_transient( $gate );

	if ( $count >= 5 ) {
		return __( 'That is several messages in a short time. Try again in an hour.', 'livingdraft-core' );
	}

	set_transient( $gate, $count + 1, HOUR_IN_SECONDS );

	// phpcs:enable WordPress.Security.NonceVerification.Missing

	return 'ok';
}

/* ==================================================================
 * 3. ASSETS
 * ================================================================== */

/**
 * Front-end script and styles.
 *
 * Loaded on any page whose content contains one of the shortcodes, plus any
 * page at all when the theme prints its own signup form — which it does in
 * the footer bar, on every view. Rather than guess, the script is enqueued
 * site-wide but is 3 KB and exits immediately when it finds no form.
 *
 * @since 4.5.0
 * @return void
 */
function livingdraft_forms_assets() {
	if ( is_admin() ) {
		return;
	}

	$css = LIVINGDRAFT_CORE_DIR . 'assets/css/forms.css';
	$js  = LIVINGDRAFT_CORE_DIR . 'assets/js/forms.js';

	if ( file_exists( $css ) ) {
		wp_enqueue_style(
			'livingdraft-forms',
			LIVINGDRAFT_CORE_URL . 'assets/css/forms.css',
			array(),
			(string) filemtime( $css )
		);
	}

	if ( ! file_exists( $js ) ) {
		return;
	}

	wp_enqueue_script(
		'livingdraft-forms',
		LIVINGDRAFT_CORE_URL . 'assets/js/forms.js',
		array(),
		(string) filemtime( $js ),
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);

	wp_localize_script(
		'livingdraft-forms',
		'ldForms',
		array(
			'ajax' => admin_url( 'admin-ajax.php' ),
			'i18n' => array(
				'sending' => __( 'Sending…', 'livingdraft-core' ),
				'failed'  => __( 'Something went wrong. Please try again.', 'livingdraft-core' ),
				'offline' => __( 'That did not reach us — check your connection and try again.', 'livingdraft-core' ),
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'livingdraft_forms_assets' );
