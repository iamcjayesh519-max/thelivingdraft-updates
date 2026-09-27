<?php
/**
 * 404.
 *
 * @package LivingDraft
 */

get_header();
?>

<div class="wrap">
	<div class="notfound">
		<p class="code"><?php esc_html_e( 'Page 404 — not in this edition', 'livingdraft' ); ?></p>
		<h1><?php esc_html_e( 'This story has moved or never ran', 'livingdraft' ); ?></h1>
		<p><?php esc_html_e( 'Search for it below, or go back to the front page for today’s edition.', 'livingdraft' ); ?></p>
		<?php get_search_form(); ?>
		<p style="margin-top:26px">
			<a class="btn" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<?php esc_html_e( 'Front page', 'livingdraft' ); ?>
			</a>
		</p>
	</div>
</div>

<?php
get_footer();
