<?php
/**
 * Search results.
 *
 * @package LivingDraft
 */

get_header();
?>

<div class="wrap">
	<div class="layout">
		<div class="content-col">

			<?php livingdraft_breadcrumbs(); ?>

			<header class="front-head">
				<h1 class="front-title">
					<?php
					printf(
						/* translators: %s: search term. */
						esc_html__( 'Results for %s', 'livingdraft' ),
						'&#8220;' . esc_html( get_search_query() ) . '&#8221;'
					);
					?>
				</h1>
				<div class="front-count">
					<?php
					printf(
						/* translators: %s: number of matches. */
						esc_html( _n( '%s match', '%s matches', (int) $GLOBALS['wp_query']->found_posts, 'livingdraft' ) ),
						esc_html( number_format_i18n( (int) $GLOBALS['wp_query']->found_posts ) )
					);
					?>
				</div>
				<?php get_search_form(); ?>
			</header>

			<?php if ( have_posts() ) : ?>
				<div class="story-list">
					<?php
					while ( have_posts() ) :
						the_post();
						get_template_part( 'template-parts/story' );
					endwhile;
					?>
				</div>
				<?php livingdraft_pagination(); ?>
			<?php else : ?>
				<p><?php esc_html_e( 'No stories matched that. Try a shorter phrase, or pick a section from the menu.', 'livingdraft' ); ?></p>
			<?php endif; ?>

		</div>

		<?php get_sidebar(); ?>
	</div>
</div>

<?php
get_footer();
