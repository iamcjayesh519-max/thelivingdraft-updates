<?php
/**
 * Read next: stories from the same section or sharing tags.
 *
 * @package LivingDraft
 */

$ld_related = livingdraft_read_next( 3 );

if ( empty( $ld_related ) ) {
	return;
}

global $post;
$ld_original = $post;
?>
<section class="section-block read-next" aria-labelledby="read-next-title">
	<div class="section-rule"></div>
	<h2 class="section-head" id="read-next-title"><span><?php esc_html_e( 'Read Next', 'livingdraft' ); ?></span></h2>

	<div class="cols">
		<?php
		foreach ( $ld_related as $ld_related_post ) {
			$post = $ld_related_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			setup_postdata( $post );
			get_template_part( 'template-parts/column' );
		}

		$post = $ld_original; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_reset_postdata();
		?>
	</div>
</section>
