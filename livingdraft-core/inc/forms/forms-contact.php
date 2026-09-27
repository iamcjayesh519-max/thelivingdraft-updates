<?php
/**
 * The contact form.
 *
 * === WHY REPLY-TO CARRIES THE SENDER AND FROM DOES NOT ===
 *
 * The tempting thing is to send the notification email "from" the person who
 * filled in the form, so it looks like a normal message in your inbox. Doing
 * that forges the From header: your server claims to be sending as
 * someone@gmail.com, gmail's SPF record says your server is not authorised
 * to do that, and DMARC tells the receiving server to reject it.
 *
 * The result is a contact form whose notifications land in spam or vanish,
 * on a domain that is now also accumulating DMARC failure reports against
 * its own reputation.
 *
 * So the message is From your own verified address, and Reply-To is the
 * sender. Pressing reply in any mail client goes to the right place, which
 * is the only part of the illusion that was ever load-bearing.
 *
 * @package LivingDraftCore
 * @since 4.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `[contact_form]` — the form.
 *
 * @since 4.5.0
 * @param array $atts Shortcode attributes.
 * @return string
 */
function livingdraft_forms_contact_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'title'  => '',
			'intro'  => '',
			'button' => __( 'Send message', 'livingdraft-core' ),
		),
		$atts,
		'contact_form'
	);

	$uid = 'ld-contact-' . wp_rand( 1000, 9999 );

	ob_start();
	?>
	<form class="ld-form ld-form--contact" data-ld-form="contact" novalidate>
		<?php wp_nonce_field( 'livingdraft_contact', 'nonce', false ); ?>

		<?php if ( $atts['title'] ) : ?>
			<h2 class="ld-form__title"><?php echo esc_html( $atts['title'] ); ?></h2>
		<?php endif; ?>

		<?php if ( $atts['intro'] ) : ?>
			<p class="ld-form__intro"><?php echo esc_html( $atts['intro'] ); ?></p>
		<?php endif; ?>

		<div class="ld-form__row">
			<p class="ld-form__field">
				<label for="<?php echo esc_attr( $uid ); ?>-name"><?php esc_html_e( 'Your name', 'livingdraft-core' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $uid ); ?>-name" name="name" required maxlength="120" autocomplete="name" />
			</p>

			<p class="ld-form__field">
				<label for="<?php echo esc_attr( $uid ); ?>-email"><?php esc_html_e( 'Your email', 'livingdraft-core' ); ?></label>
				<input type="email" id="<?php echo esc_attr( $uid ); ?>-email" name="email" required maxlength="200" autocomplete="email" />
			</p>
		</div>

		<p class="ld-form__field">
			<label for="<?php echo esc_attr( $uid ); ?>-subject"><?php esc_html_e( 'Subject', 'livingdraft-core' ); ?></label>
			<input type="text" id="<?php echo esc_attr( $uid ); ?>-subject" name="subject" required maxlength="200" />
		</p>

		<p class="ld-form__field">
			<label for="<?php echo esc_attr( $uid ); ?>-message"><?php esc_html_e( 'Message', 'livingdraft-core' ); ?></label>
			<textarea id="<?php echo esc_attr( $uid ); ?>-message" name="message" rows="7" required maxlength="6000"></textarea>
		</p>

		<?php livingdraft_forms_guard_fields(); ?>

		<div class="ld-form__foot">
			<button type="submit" class="ld-form__submit"><?php echo esc_html( $atts['button'] ); ?></button>
			<p class="ld-form__note">
				<?php esc_html_e( 'Corrections and complaints are read first.', 'livingdraft-core' ); ?>
			</p>
		</div>

		<p class="ld-form__status" role="status" aria-live="polite"></p>
	</form>
	<?php

	return (string) ob_get_clean();
}
add_shortcode( 'contact_form', 'livingdraft_forms_contact_shortcode' );

/**
 * Handle a contact submission.
 *
 * @since 4.5.0
 * @return void
 */
function livingdraft_forms_contact_submit() {
	check_ajax_referer( 'livingdraft_contact', 'nonce' );

	$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
	$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
	$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

	$guard = livingdraft_forms_check_guards( $email ? $email : 'anon' );

	// A bot: answer exactly as if it had worked, and do nothing.
	if ( 'silent' === $guard ) {
		wp_send_json_success( array( 'message' => livingdraft_forms_thanks() ) );
	}

	if ( 'ok' !== $guard ) {
		wp_send_json_error( array( 'message' => $guard ) );
	}

	if ( ! is_email( $email ) ) {
		wp_send_json_error( array( 'message' => __( 'That email address does not look right.', 'livingdraft-core' ) ) );
	}

	if ( '' === $name || '' === $subject ) {
		wp_send_json_error( array( 'message' => __( 'Please fill in your name and a subject.', 'livingdraft-core' ) ) );
	}

	if ( strlen( $message ) < 10 ) {
		wp_send_json_error( array( 'message' => __( 'Please write a little more so we know what this is about.', 'livingdraft-core' ) ) );
	}

	$data = array(
		'name'    => $name,
		'email'   => $email,
		'subject' => $subject,
		'message' => $message,
	);

	// --- Stored before anything is mailed. See the header of forms.php. ---
	$id = livingdraft_forms_store( $data );

	if ( is_wp_error( $id ) ) {
		wp_send_json_error( array( 'message' => __( 'We could not save your message. Please try again.', 'livingdraft-core' ) ) );
	}

	livingdraft_forms_notify( $id, $data );

	wp_send_json_success( array( 'message' => livingdraft_forms_thanks() ) );
}
add_action( 'wp_ajax_livingdraft_contact', 'livingdraft_forms_contact_submit' );
add_action( 'wp_ajax_nopriv_livingdraft_contact', 'livingdraft_forms_contact_submit' );

/**
 * The thank-you line.
 *
 * Deliberately does not promise a reply by any particular time. A form that
 * says "we will get back to you within 24 hours" and then does not is worse
 * than one that says nothing.
 *
 * @since 4.5.0
 * @return string
 */
function livingdraft_forms_thanks() {
	/**
	 * Filter the confirmation shown after a message is sent.
	 *
	 * @since 4.5.0
	 * @param string $text The message.
	 */
	return (string) apply_filters(
		'livingdraft_forms_thanks',
		__( 'Thank you — your message is with us and someone will read it.', 'livingdraft-core' )
	);
}

/**
 * Email the newsroom, and optionally the sender.
 *
 * Failures are recorded against the message rather than surfaced to the
 * reader, because from their side nothing failed: the message arrived and is
 * on the Messages screen. The person who needs to know the notification did
 * not send is the site owner, and they find out by seeing the flag.
 *
 * @since 4.5.0
 * @param int   $id   Message id.
 * @param array $data Submitted data.
 * @return void
 */
function livingdraft_forms_notify( $id, $data ) {
	if ( ! function_exists( 'livingdraft_mail_send' ) ) {
		update_post_meta( $id, '_ld_notify_error', __( 'Mail module not loaded.', 'livingdraft-core' ) );
		return;
	}

	$settings = livingdraft_forms_settings();
	$to       = is_email( $settings['recipient'] ) ? $settings['recipient'] : get_option( 'admin_email' );

	$body = sprintf(
		'<p style="margin:0 0 14px;"><strong>%s</strong> &lt;%s&gt;</p>
		<p style="margin:0 0 6px;font-size:13px;color:#63635c;">%s</p>
		<div style="margin:0 0 18px;padding:14px;background:#f4f3ee;border-left:3px solid #111111;white-space:pre-wrap;">%s</div>
		<p style="margin:0;font-size:13px;color:#63635c;">%s</p>',
		esc_html( $data['name'] ),
		esc_html( $data['email'] ),
		esc_html( $data['subject'] ),
		esc_html( $data['message'] ),
		esc_html__( 'Press reply to answer the sender directly.', 'livingdraft-core' )
	);

	$result = livingdraft_mail_send(
		$to,
		trim( $settings['subject_prefix'] . ' ' . $data['subject'] ),
		livingdraft_mail_wrap(
			$body,
			array(
				'preheader'   => wp_trim_words( $data['message'], 18, '' ),
				'footer_html' => '<p style="margin:0;"><a href="' . esc_url( admin_url( 'admin.php?page=livingdraft-messages&message=' . $id ) ) . '">' . esc_html__( 'Open in the Messages screen', 'livingdraft-core' ) . '</a></p>',
			)
		),
		array(
			'kind'     => 'transactional',
			'context'  => 'contact',
			// The whole point: From stays ours, Reply-To is the reader.
			'reply_to' => $data['email'],
		)
	);

	if ( is_wp_error( $result ) ) {
		update_post_meta( $id, '_ld_notify_error', $result->get_error_message() );
	} else {
		update_post_meta( $id, '_ld_notified', 1 );
		delete_post_meta( $id, '_ld_notify_error' );
	}

	// --- The reader's copy ---
	if ( empty( $settings['acknowledge'] ) ) {
		return;
	}

	$ack = sprintf(
		'<p style="margin:0 0 14px;">%s</p>
		<div style="margin:0 0 18px;padding:14px;background:#f4f3ee;border-left:3px solid #d8d5cc;white-space:pre-wrap;font-size:14px;">%s</div>
		<p style="margin:0;font-size:13px;color:#63635c;">%s</p>',
		esc_html__( 'We have your message. This is a copy for your records — there is no need to reply to it.', 'livingdraft-core' ),
		esc_html( $data['message'] ),
		esc_html__( 'If it was about a correction, it goes to the top of the pile.', 'livingdraft-core' )
	);

	livingdraft_mail_send(
		$data['email'],
		sprintf(
			/* translators: %s: the subject the reader wrote. */
			__( 'We received your message: %s', 'livingdraft-core' ),
			$data['subject']
		),
		livingdraft_mail_wrap( $ack, array( 'preheader' => __( 'A copy of what you sent us.', 'livingdraft-core' ) ) ),
		array(
			'kind'    => 'transactional',
			'context' => 'contact-ack',
		)
	);
}
