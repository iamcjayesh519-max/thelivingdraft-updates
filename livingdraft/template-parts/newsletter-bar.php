<?php
/**
 * The newsletter band above the footer.
 *
 * Addresses are stored by the Living Draft Core plugin, in your own database.
 * Without the plugin this quietly does not appear, rather than showing a form
 * that saves nowhere.
 *
 * @package LivingDraft
 */

if ( ! get_theme_mod( 'livingdraft_newsletter_on', true ) ) {
	return;
}

if ( ! function_exists( 'livingdraft_subscriber_count' ) ) {
	return;
}

$ld_title = get_theme_mod( 'livingdraft_newsletter_title', __( 'The edition, by email', 'livingdraft' ) );
$ld_blurb = get_theme_mod(
	'livingdraft_newsletter_text',
	__( 'One email when something matters. No daily digest, no noise.', 'livingdraft' )
);
?>
<section class="signup-bar" aria-labelledby="signup-title">
	<div class="wrap signup-inner">
		<div class="signup-words">
			<h2 class="signup-title" id="signup-title"><?php echo esc_html( $ld_title ); ?></h2>
			<p class="signup-blurb"><?php echo esc_html( $ld_blurb ); ?></p>
		</div>

		<form class="ld-signup-form signup-form" data-endpoint="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
			<?php wp_nonce_field( 'livingdraft_subscribe', 'nonce', false ); ?>

			<label class="screen-reader-text" for="ld-signup-email">
				<?php esc_html_e( 'Your email address', 'livingdraft' ); ?>
			</label>
			<input type="email" id="ld-signup-email" name="email" required
				placeholder="<?php esc_attr_e( 'you@example.com', 'livingdraft' ); ?>">

			<?php // A field no human sees. Bots fill it in and give themselves away. ?>
			<div class="signup-trap" aria-hidden="true">
				<label for="ld-signup-website"><?php esc_html_e( 'Leave this empty', 'livingdraft' ); ?></label>
				<input type="text" id="ld-signup-website" name="website" tabindex="-1" autocomplete="off">
			</div>

			<button type="submit"><?php esc_html_e( 'Subscribe', 'livingdraft' ); ?></button>
			<p class="ld-signup-note signup-note" role="status" aria-live="polite"></p>
		</form>
	</div>
</section>
