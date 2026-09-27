<?php
/**
 * The author box: who filed this, and where to read more of them.
 *
 * @package LivingDraft
 */

$ld_author_id = (int) get_the_author_meta( 'ID' );
$ld_bio       = get_the_author_meta( 'description', $ld_author_id );

if ( ! $ld_bio ) {
	return;
}
?>
<aside class="author-box">
	<?php echo get_avatar( $ld_author_id, 96, '', '', array( 'class' => 'author-avatar' ) ); ?>

	<div class="author-detail">
		<h2 class="author-name">
			<a href="<?php echo esc_url( get_author_posts_url( $ld_author_id ) ); ?>" rel="author">
				<?php echo esc_html( get_the_author_meta( 'display_name', $ld_author_id ) ); ?>
			</a>
		</h2>
		<p class="author-bio"><?php echo esc_html( $ld_bio ); ?></p>
		<a class="read-on" href="<?php echo esc_url( get_author_posts_url( $ld_author_id ) ); ?>">
			<?php esc_html_e( 'All stories by this writer', 'livingdraft' ); ?> &rarr;
		</a>
	</div>
</aside>
