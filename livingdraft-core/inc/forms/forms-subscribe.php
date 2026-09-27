<?php
/**
 * The subscribe form.
 *
 * === THE BUG THIS FILE EXISTS TO FIX ===
 *
 * The theme has printed a newsletter signup bar on every page for several
 * versions. The markup is correct: an email field, a honeypot, a nonce for
 * `livingdraft_subscribe`, and a `data-endpoint` pointing at admin-ajax.php.
 *
 * Nothing ever submitted it.
 *
 * There is no submit handler anywhere in the theme's JavaScript, and the form
 * carries no `action` field, so even a native submit would have posted to the
 * current page and reloaded it. The plugin's `wp_ajax_livingdraft_subscribe`
 * endpoint has been sitting there the whole time with nothing calling it.
 *
 * The failure mode is the quiet kind: the form looks right, pressing the
 * button does nothing visible, and the subscriber list stays empty in a way
 * that looks like nobody wanted to subscribe.
 *
 * === WHY THE FIX IS IN THE PLUGIN ===
 *
 * The handler could go in the theme, next to the markup. It goes here instead
 * for the reason the plugin exists at all: subscribers are data, not
 * presentation. A theme change must not be able to disconnect the signup
 * form again.
 *
 * So forms.js binds to `.ld-signup-form` — the class the theme already
 * uses — as well as to this file's own shortcode. The existing theme markup
 * starts working with no theme edit, and any future theme that keeps the
 * class keeps working too.
 *
 * @package LivingDraftCore
 * @since 4.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `[subscribe_form]` — a signup form that does not depend on the theme.
 *
 * @since 4.5.0
 * @param array $atts Shortcode attributes.
 * @return string
 */
function livingdraft_forms_subscribe_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'title'  => '',
			'intro'  => '',
			'button' => __( 'Subscribe', 'livingdraft-core' ),
		),
		$atts,
		'subscribe_form'
	);

	$uid = 'ld-sub-' . wp_rand( 1000, 9999 );

	ob_start();
	?>
	<form class="ld-form ld-form--subscribe ld-signup-form" data-ld-form="subscribe" novalidate>
		<?php wp_nonce_field( 'livingdraft_subscribe', 'nonce', false ); ?>

		<?php if ( $atts['title'] ) : ?>
			<h2 class="ld-form__title"><?php echo esc_html( $atts['title'] ); ?></h2>
		<?php endif; ?>

		<?php if ( $atts['intro'] ) : ?>
			<p class="ld-form__intro"><?php echo esc_html( $atts['intro'] ); ?></p>
		<?php endif; ?>

		<div class="ld-form__inline">
			<p class="ld-form__field">
				<label class="screen-reader-text" for="<?php echo esc_attr( $uid ); ?>-email"><?php esc_html_e( 'Your email address', 'livingdraft-core' ); ?></label>
				<input type="email" id="<?php echo esc_attr( $uid ); ?>-email" name="email" required autocomplete="email"
					placeholder="<?php esc_attr_e( 'you@example.com', 'livingdraft-core' ); ?>" />
			</p>
			<button type="submit" class="ld-form__submit"><?php echo esc_html( $atts['button'] ); ?></button>
		</div>

		<?php livingdraft_forms_guard_fields(); ?>

		<p class="ld-form__note">
			<?php esc_html_e( 'One confirmation email, then the newsletter. Unsubscribe in one press, any time.', 'livingdraft-core' ); ?>
		</p>

		<p class="ld-form__status" role="status" aria-live="polite"></p>
	</form>
	<?php

	return (string) ob_get_clean();
}
add_shortcode( 'subscribe_form', 'livingdraft_forms_subscribe_shortcode' );

/**
 * Apply the shared spam guards to the subscribe endpoint too.
 *
 * The existing handler in newsletter.php has a honeypot and a rate limit of
 * its own but no time trap, and it predates this module. Rather than edit a
 * working handler, the guards hook in ahead of it.
 *
 * The theme's own markup does NOT carry the timestamp fields — it was written
 * before they existed — so a missing timestamp is allowed through here.
 * Requiring it would break the very form this module was written to fix.
 *
 * @since 4.5.0
 * @return void
 */
function livingdraft_forms_subscribe_guard() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- newsletter.php verifies it immediately after.
	if ( empty( $_POST['rendered'] ) ) {
		return;
	}

	$rendered = absint( wp_unslash( $_POST['rendered'] ) );
	$sig      = isset( $_POST['rendered_sig'] ) ? sanitize_text_field( wp_unslash( $_POST['rendered_sig'] ) ) : '';

	if ( ! hash_equals( wp_hash( (string) $rendered ), $sig ) || ( time() - $rendered ) < LD_FORMS_MIN_SECONDS ) {
		// Same silence as the contact form: report success, store nothing.
		wp_send_json_success( array( 'message' => __( 'Thank you.', 'livingdraft-core' ) ) );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing
}
add_action( 'wp_ajax_nopriv_livingdraft_subscribe', 'livingdraft_forms_subscribe_guard', 5 );
add_action( 'wp_ajax_livingdraft_subscribe', 'livingdraft_forms_subscribe_guard', 5 );
