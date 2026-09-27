<?php
/**
 * Single story.
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
				<?php if ( get_theme_mod( 'livingdraft_show_breadcrumbs', true ) ) { livingdraft_breadcrumbs(); } ?>

				<article <?php post_class(); ?>>

					<header class="entry-header">
						<?php livingdraft_kicker(); ?>

						<h1 class="entry-title"><?php the_title(); ?></h1>

						<?php if ( has_excerpt() ) : ?>
							<p class="entry-standfirst"><?php echo esc_html( get_the_excerpt() ); ?></p>
						<?php endif; ?>

						<?php livingdraft_byline( (bool) get_theme_mod( 'livingdraft_show_reading_time', true ) ); ?>
					</header>

					<?php if ( has_post_thumbnail() ) : ?>
						<figure class="figure-well">
							<?php
							// The image is bounded to --measure (68ch, ≈ 730px at the
							// default font size) on desktop by the .figure-well rule
							// in style.css. The sizes hint tells the srcset picker
							// to fetch the smallest variant that covers that width,
							// saving bandwidth vs the old "fills the whole column"
							// hint of 720px on a ~1400px column.
							//
							// CRITICAL: this sizes value MUST match inc/lcp.php's
							// preload sizes. They're both filtered through
							// `livingdraft_lcp_sizes` so a single change updates
							// both — a mismatch causes the browser to download the
							// image twice (once from preload, once from srcset).
							$ld_sizes = apply_filters(
								'livingdraft_lcp_sizes',
								'(min-width: 900px) 750px, 92vw',
								get_post_thumbnail_id()
							);
							the_post_thumbnail(
								'livingdraft-lede',
								array_merge(
									livingdraft_no_lazy_attrs(),
									array( 'sizes' => $ld_sizes )
								)
							);
							?>
							<?php
							$ld_caption = get_the_post_thumbnail_caption();
							if ( $ld_caption ) :
								?>
								<figcaption><?php echo esc_html( $ld_caption ); ?></figcaption>
							<?php endif; ?>
						</figure>
					<?php endif; ?>

					<?php get_template_part( 'template-parts/update-log' ); ?>

					<div class="entry-content">
						<?php
						the_content();

						wp_link_pages(
							array(
								'before'      => '<nav class="pagination page-links" aria-label="' . esc_attr__( 'Story pages', 'livingdraft' ) . '">',
								'after'       => '</nav>',
								'link_before' => '<span class="page-numbers">',
								'link_after'  => '</span>',
							)
						);
						?>
					</div>

					<?php
					$ld_tags = get_the_tags();
					if ( ! empty( $ld_tags ) && ! is_wp_error( $ld_tags ) ) :
						?>
						<div class="entry-tags">
							<span><?php esc_html_e( 'Filed under', 'livingdraft' ); ?></span>
							<?php foreach ( $ld_tags as $ld_tag ) : ?>
								<a href="<?php echo esc_url( get_tag_link( $ld_tag->term_id ) ); ?>"><?php echo esc_html( $ld_tag->name ); ?></a>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

				</article>

				<?php if ( get_theme_mod( 'livingdraft_show_author_box', true ) ) { get_template_part( 'template-parts/author-bio' ); } ?>

				<?php
				$ld_show_nav = (bool) get_theme_mod( 'livingdraft_show_post_nav', true );
				$ld_prev     = $ld_show_nav ? get_previous_post() : null;
				$ld_next     = $ld_show_nav ? get_next_post() : null;
				if ( $ld_prev || $ld_next ) :
					?>
					<nav class="post-nav" aria-label="<?php esc_attr_e( 'Story order', 'livingdraft' ); ?>">
						<?php if ( $ld_prev ) : ?>
							<a href="<?php echo esc_url( get_permalink( $ld_prev ) ); ?>" rel="prev">
								<span class="dir"><?php esc_html_e( 'Earlier', 'livingdraft' ); ?></span>
								<span class="t"><?php echo esc_html( get_the_title( $ld_prev ) ); ?></span>
							</a>
						<?php endif; ?>
						<?php if ( $ld_next ) : ?>
							<a href="<?php echo esc_url( get_permalink( $ld_next ) ); ?>" rel="next">
								<span class="dir"><?php esc_html_e( 'Later', 'livingdraft' ); ?></span>
								<span class="t"><?php echo esc_html( get_the_title( $ld_next ) ); ?></span>
							</a>
						<?php endif; ?>
					</nav>
				<?php endif; ?>

				<?php
				/*
				 * The story timeline sits between the article and Read Next
				 * on purpose. Read Next is a suggestion; this is the rest of
				 * the thing the reader has just been reading, so it belongs
				 * closer to the article. It prints nothing unless the Living
				 * Draft Core plugin has grouped this piece into a story.
				 */
				get_template_part( 'template-parts/timeline' );
				?>

				<?php if ( get_theme_mod( 'livingdraft_show_read_next', true ) ) { get_template_part( 'template-parts/read-next' ); } ?>

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
