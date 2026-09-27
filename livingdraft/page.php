<?php
/**
 * Static page.
 *
 * @package LivingDraft
 */

get_header();
?>

<div class="wrap">
	<div class="layout">
		<div class="content-col">
			<?php
			while ( have_posts() ) :
				the_post();
				?>
				<?php livingdraft_breadcrumbs(); ?>

				<article <?php post_class(); ?>>
					<header class="entry-header">
						<h1 class="entry-title"><?php the_title(); ?></h1>
						<div class="byline">
							<time datetime="<?php echo esc_attr( get_the_modified_date( DATE_W3C ) ); ?>">
								<?php
								printf(
									/* translators: %s: date the page was last updated. */
									esc_html__( 'Last updated %s', 'livingdraft' ),
									esc_html( get_the_modified_date( 'j M Y' ) )
								);
								?>
							</time>
						</div>
					</header>

					<?php if ( has_post_thumbnail() ) : ?>
						<figure class="figure-well">
							<?php
							the_post_thumbnail(
								'livingdraft-lede',
								array(
									'fetchpriority' => 'high',
									'loading'       => 'eager',
								)
							);
							?>
						</figure>
					<?php endif; ?>

					<div class="entry-content">
						<?php
						the_content();

						wp_link_pages(
							array(
								'before'      => '<nav class="pagination page-links" aria-label="' . esc_attr__( 'Page sections', 'livingdraft' ) . '">',
								'after'       => '</nav>',
								'link_before' => '<span class="page-numbers">',
								'link_after'  => '</span>',
							)
						);
						?>
					</div>
				</article>

				<?php
				if ( comments_open() || get_comments_number() ) {
					comments_template();
				}
				?>
			<?php endwhile; ?>
		</div>

		<?php get_sidebar(); ?>
	</div>
</div>

<?php
get_footer();
