<?php
/**
 * The newsletter slot at the foot of the brief rail.
 *
 * @package LivingDraft
 */

$ld_note = get_theme_mod( 'livingdraft_newsletter_text', '' );
$ld_url  = get_theme_mod( 'livingdraft_newsletter_url', '' );

if ( ! $ld_note || ! $ld_url ) {
	return;
}
?>
<div class="rail-signup">
	<h2 class="rail-head"><?php esc_html_e( 'By Email', 'livingdraft' ); ?></h2>
	<p><?php echo esc_html( $ld_note ); ?></p>
	<a class="btn" href="<?php echo esc_url( $ld_url ); ?>">
		<?php esc_html_e( 'Subscribe', 'livingdraft' ); ?>
	</a>
</div>
