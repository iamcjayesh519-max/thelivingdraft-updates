<?php
/**
 * "In brief" — the AI key points written in the editor sidebar.
 *
 * The bullets live in The Living Draft Core (livingdraft_ai_summary()), so
 * they survive a theme change. This file only decides how they look.
 * Nothing prints when the plugin is off, the article has no key points,
 * the editor hid them, or the Customizer switch is off.
 *
 * @package LivingDraft
 * @since   4.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'livingdraft_ai_summary' ) || ! get_theme_mod( 'livingdraft_show_ai_summary', true ) ) {
	return;
}

$ld_points = livingdraft_ai_summary( get_the_ID() );

if ( empty( $ld_points ) ) {
	return;
}
?>
<aside class="ld-brief-box" aria-labelledby="ld-brief-box-title-<?php the_ID(); ?>">
	<p class="ld-brief-box-title" id="ld-brief-box-title-<?php the_ID(); ?>"><?php esc_html_e( 'In brief', 'livingdraft' ); ?></p>
	<ul>
		<?php foreach ( $ld_points as $ld_point ) : ?>
			<li><?php echo esc_html( $ld_point ); ?></li>
		<?php endforeach; ?>
	</ul>
</aside>
