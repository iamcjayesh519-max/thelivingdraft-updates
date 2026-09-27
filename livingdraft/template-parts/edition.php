<?php
/**
 * One edition: the lead, the brief rail, and the rest in ruled columns.
 *
 * Shared by the front page and by section fronts, so both read the same way.
 * Expects the main query, before the loop has started.
 *
 * @package LivingDraft
 */

?>
<?php if ( ! is_paged() ) : ?>

	<?php
	the_post();

	$ld_brief_max = (int) get_theme_mod( 'livingdraft_brief_count', 5 );

	/*
	 * === WHY THIS CAP EXISTS (fixed in 4.0) ===
	 *
	 * WP_Query::have_posts() does something surprising when a loop asks for
	 * one more post than the query holds: as well as returning false, it
	 * quietly calls rewind_posts() and resets the pointer to the beginning.
	 *
	 * The rail used to be written as:  while ( have_posts() && $briefs < $max )
	 *
	 * So if the rail was configured to hold as many stories as the whole
	 * page (Customizer > Front page > "In brief" set to 10, Settings >
	 * Reading also 10), the rail consumed everything, made one final
	 * have_posts() call, and rewound the query. The column grid below then
	 * started again from story one and reprinted the entire front page —
	 * lead included. Readers saw every headline twice.
	 *
	 * Two changes stop that for good:
	 *
	 *   1. The rail is capped to leave at least one story for the columns,
	 *      so it can never reach the end of the query at all.
	 *   2. The loop below tests the counter FIRST, so once the rail is full
	 *      have_posts() is not called again and cannot rewind anything.
	 *
	 * Neither depends on the editor choosing sensible numbers. Any
	 * combination of settings now produces a page that prints once.
	 */
	$ld_query     = $GLOBALS['wp_query'];
	$ld_remaining = (int) $ld_query->post_count - ( (int) $ld_query->current_post + 1 );
	$ld_brief_max = max( 0, min( $ld_brief_max, $ld_remaining - 1 ) );

	$ld_briefs   = 0;
	$ld_has_rail = ( $ld_brief_max > 0 );
	?>

	<div class="front <?php echo $ld_has_rail ? '' : 'is-full'; ?>">
		<div class="lead-column">
			<?php get_template_part( 'template-parts/lede' ); ?>
		</div>

		<?php if ( $ld_has_rail ) : ?>
			<aside class="rail" aria-label="<?php esc_attr_e( 'In brief', 'livingdraft' ); ?>">
				<h2 class="rail-head"><?php esc_html_e( 'In Brief', 'livingdraft' ); ?></h2>

				<?php
				// Counter first, deliberately. See the note above.
				while ( $ld_briefs < $ld_brief_max && have_posts() ) :
					the_post();
					$ld_briefs++;

					// A printed front page breaks a long rail into blocks.
					if ( $ld_brief_max > 4 && 5 === $ld_briefs ) {
						echo '<h2 class="rail-head">' . esc_html__( 'Also Today', 'livingdraft' ) . '</h2>';
					}

					get_template_part( 'template-parts/brief' );
				endwhile;
				?>

				<?php get_template_part( 'template-parts/newsletter' ); ?>
			</aside>
		<?php endif; ?>
	</div>

<?php endif; ?>

<?php
if ( is_front_page() || is_home() ) {
	get_template_part( 'template-parts/slider' );
	get_template_part( 'template-parts/tabs' );
}
?>

<?php if ( have_posts() ) : ?>
	<section class="section-block">
		<div class="section-rule"></div>
		<h2 class="section-head">
			<span><?php echo esc_html( livingdraft_section_title() ); ?></span>
			<span class="count">
				<?php
				printf(
					/* translators: %s: number of stories. */
					esc_html__( '%s filed', 'livingdraft' ),
					esc_html( number_format_i18n( (int) $GLOBALS['wp_query']->found_posts ) )
				);
				?>
			</span>
		</h2>

		<div class="cols">
			<?php
			while ( have_posts() ) :
				the_post();
				get_template_part( 'template-parts/column' );
			endwhile;
			?>
		</div>
	</section>
<?php endif; ?>

<?php livingdraft_pagination(); ?>

<?php if ( is_active_sidebar( 'sidebar-1' ) ) : ?>
	<section class="section-block standing">
		<div class="section-rule"></div>
		<h2 class="section-head"><span><?php esc_html_e( 'Standing Matter', 'livingdraft' ); ?></span></h2>
		<div class="sidebar"><?php dynamic_sidebar( 'sidebar-1' ); ?></div>
	</section>
<?php endif; ?>
