<?php
/**
 * The latest-stories strip.
 *
 * Swipe, trackpad, arrow keys and the two buttons all move it. There is no
 * carousel library and nothing rotates on its own — an auto-rotating slider is
 * the most reliable way to slow a news homepage down, and it moves the thing
 * a reader was about to click.
 *
 * @package LivingDraft
 */

if ( ! get_theme_mod( 'livingdraft_slider_on', true ) ) {
	return;
}

$ld_count = max( 3, (int) get_theme_mod( 'livingdraft_slider_count', 8 ) );

// Cache the ID list. Keyed by count so bumping the customizer setting picks
// up a fresh query; invalidated on publish by livingdraft_flush_edition_cache().
$ld_slider_key = 'livingdraft_slider_' . $ld_count;
$ld_slide_ids  = get_transient( $ld_slider_key );

if ( ! is_array( $ld_slide_ids ) ) {
	$ld_slide_ids = get_posts(
		array(
			'posts_per_page'      => $ld_count,
			'post_status'         => 'publish',
			'ignore_sticky_posts' => false,
			'no_found_rows'       => true,
			'fields'              => 'ids',
		)
	);
	set_transient( $ld_slider_key, $ld_slide_ids, HOUR_IN_SECONDS );
}

$ld_slides = array_filter( array_map( 'get_post', $ld_slide_ids ) );

if ( count( $ld_slides ) < 3 ) {
	return;
}

global $post;
$ld_keep = $post;
?>
<section class="strip" aria-labelledby="strip-title">
	<div class="section-rule"></div>

	<div class="strip-head">
		<h2 class="section-head" id="strip-title"><span><?php esc_html_e( 'Latest', 'livingdraft' ); ?></span></h2>

		<div class="strip-controls">
			<button type="button" class="strip-prev" aria-label="<?php esc_attr_e( 'Scroll left', 'livingdraft' ); ?>">&larr;</button>
			<button type="button" class="strip-next" aria-label="<?php esc_attr_e( 'Scroll right', 'livingdraft' ); ?>">&rarr;</button>
		</div>
	</div>

	<div class="strip-rail" tabindex="0" role="region"
		aria-label="<?php esc_attr_e( 'Latest stories, scrollable', 'livingdraft' ); ?>">
		<?php
		foreach ( $ld_slides as $ld_slide ) {
			$post = $ld_slide; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			setup_postdata( $post );
			?>
			<article class="slide">
				<?php if ( has_post_thumbnail() ) : ?>
					<a class="thumb" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
						<?php
						the_post_thumbnail(
							'livingdraft-column',
							array(
								'loading' => 'lazy',
								'sizes'   => '(min-width: 900px) 300px, 70vw',
							)
						);
						?>
					</a>
				<?php endif; ?>

				<?php livingdraft_stamp( array( 'show_rev' => true ) ); ?>

				<h3 class="slide-title">
					<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
				</h3>
			</article>
			<?php
		}

		$post = $ld_keep; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_reset_postdata();
		?>
	</div>
</section>
