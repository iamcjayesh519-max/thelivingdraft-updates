<?php
/**
 * Latest, Popular and Trending.
 *
 * Popular counts every view ever recorded. Trending counts recent views, with
 * the score fading a little each day — so a big story from March cannot sit at
 * the top forever. Both need the Living Draft Core plugin, which does the
 * counting; without it only Latest is shown.
 *
 * @package LivingDraft
 */

if ( ! get_theme_mod( 'livingdraft_tabs_on', true ) ) {
	return;
}

$ld_n     = (int) get_theme_mod( 'livingdraft_tabs_count', 5 );
$ld_has_views = function_exists( 'livingdraft_get_popular' );

$ld_panels = array(
	'latest' => array(
		'label' => __( 'Latest', 'livingdraft' ),
		'posts' => get_posts(
			array(
				'posts_per_page' => $ld_n,
				'post_status'    => 'publish',
				'no_found_rows'  => true,
			)
		),
	),
);

if ( $ld_has_views ) {
	$ld_panels['popular']  = array(
		'label' => __( 'Popular', 'livingdraft' ),
		'posts' => livingdraft_get_popular( 'popular', $ld_n ),
	);
	$ld_panels['trending'] = array(
		'label' => __( 'Trending', 'livingdraft' ),
		'posts' => livingdraft_get_popular( 'trending', $ld_n ),
	);
}

if ( empty( $ld_panels['latest']['posts'] ) ) {
	return;
}

global $post;
$ld_keep  = $post;
$ld_first = true;
?>
<section class="tabbed" aria-labelledby="tabbed-title">
	<div class="section-rule"></div>

	<h2 class="screen-reader-text" id="tabbed-title"><?php esc_html_e( 'Stories by popularity', 'livingdraft' ); ?></h2>

	<div class="tablist" role="tablist" aria-label="<?php esc_attr_e( 'Story lists', 'livingdraft' ); ?>">
		<?php foreach ( $ld_panels as $ld_key => $ld_panel ) : ?>
			<button type="button" role="tab" class="tab<?php echo $ld_first ? ' is-on' : ''; ?>"
				id="tab-<?php echo esc_attr( $ld_key ); ?>"
				aria-controls="panel-<?php echo esc_attr( $ld_key ); ?>"
				aria-selected="<?php echo $ld_first ? 'true' : 'false'; ?>"
				tabindex="<?php echo $ld_first ? '0' : '-1'; ?>">
				<?php echo esc_html( $ld_panel['label'] ); ?>
			</button>
			<?php $ld_first = false; ?>
		<?php endforeach; ?>
	</div>

	<?php $ld_first = true; ?>
	<?php foreach ( $ld_panels as $ld_key => $ld_panel ) : ?>
		<div class="tabpanel<?php echo $ld_first ? ' is-on' : ''; ?>"
			id="panel-<?php echo esc_attr( $ld_key ); ?>"
			role="tabpanel"
			aria-labelledby="tab-<?php echo esc_attr( $ld_key ); ?>"
			<?php echo $ld_first ? '' : 'hidden'; ?>>

			<ol class="ranked">
				<?php
				foreach ( $ld_panel['posts'] as $ld_item ) {
					$post = $ld_item; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
					setup_postdata( $post );
					?>
					<li class="ranked-item">
						<a class="ranked-link" href="<?php the_permalink(); ?>">
							<span class="ranked-no" aria-hidden="true"></span>
							<span class="ranked-text">
								<span class="ranked-title"><?php the_title(); ?></span>
								<span class="ranked-meta">
									<?php echo esc_html( get_the_date( 'j M Y' ) ); ?>
									<?php
									/*
									 * On the Popular and Trending lists the read
									 * count is the reason the story is in the
									 * list at all, so it belongs here even when
									 * it is hidden elsewhere. Latest is ordered
									 * by date, so a count there would just be
									 * noise.
									 */
									if ( 'latest' !== $ld_key && function_exists( 'livingdraft_views_total' ) ) :
										$ld_reads = ( 'trending' === $ld_key )
											? (int) get_post_meta( get_the_ID(), '_ld_views_trend', true )
											: (int) livingdraft_views_total();
										if ( $ld_reads > 0 ) :
											?>
											<span class="ranked-reads">
												<?php echo esc_html( number_format_i18n( $ld_reads ) ); ?>
											</span>
											<?php
										endif;
									endif;
									?>
								</span>
							</span>
						</a>
					</li>
					<?php
				}
				?>
			</ol>
		</div>
		<?php $ld_first = false; ?>
	<?php endforeach; ?>
</section>
<?php
$post = $ld_keep; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
wp_reset_postdata();
