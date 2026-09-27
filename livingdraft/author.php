<?php
/**
 * Author page: who they are, then everything they have filed.
 *
 * @package LivingDraft
 */

get_header();

$ld_author_id = (int) get_queried_object_id();
?>

<div class="wrap">
	<div class="layout">
		<div class="content-col">

			<?php livingdraft_breadcrumbs(); ?>

			<header class="front-head author-head">
				<?php echo get_avatar( $ld_author_id, 128, '', '', array( 'class' => 'author-avatar' ) ); ?>

				<div>
					<div class="kicker-line"><?php esc_html_e( 'Writer', 'livingdraft' ); ?></div>
					<h1 class="front-title"><?php echo esc_html( get_the_author_meta( 'display_name', $ld_author_id ) ); ?></h1>

					<?php
					$ld_bio = get_the_author_meta( 'description', $ld_author_id );
					if ( $ld_bio ) :
						?>
						<p class="front-standfirst"><?php echo esc_html( $ld_bio ); ?></p>
					<?php endif; ?>

					<div class="front-count">
						<?php
						printf(
							/* translators: %s: number of stories by this writer. */
							esc_html( _n( '%s story filed', '%s stories filed', (int) $GLOBALS['wp_query']->found_posts, 'livingdraft' ) ),
							esc_html( number_format_i18n( (int) $GLOBALS['wp_query']->found_posts ) )
						);
						?>
					</div>
				</div>
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
				<p><?php esc_html_e( 'This writer has not filed anything yet.', 'livingdraft' ); ?></p>
			<?php endif; ?>

		</div>

		<?php get_sidebar(); ?>
	</div>
</div>

<?php
get_footer();
