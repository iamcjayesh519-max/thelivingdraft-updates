<?php
/**
 * Front page and blog listing — the broadsheet front.
 *
 * @package LivingDraft
 */

get_header();
?>

<div class="wrap">

	<?php if ( have_posts() ) : ?>

		<?php get_template_part( 'template-parts/edition' ); ?>

	<?php else : ?>

		<div class="notfound">
			<p class="code"><?php esc_html_e( 'Nothing filed', 'livingdraft' ); ?></p>
			<h1><?php esc_html_e( 'No stories have run yet', 'livingdraft' ); ?></h1>
			<p><?php esc_html_e( 'Publish a post and it will lead this page.', 'livingdraft' ); ?></p>
			<?php get_search_form(); ?>
		</div>

	<?php endif; ?>

</div>

<?php
get_footer();
