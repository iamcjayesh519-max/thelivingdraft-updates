<?php
/**
 * The story timeline, at the foot of an article.
 *
 * Prints every article in the same running story, oldest first, with the one
 * being read marked in place. A reader who arrived halfway through a story
 * from a search result can see the whole shape of it and read backwards.
 *
 * The grouping itself lives in The Living Draft Core, which works it out from
 * the words shared by the articles' URLs. Which articles belong to which
 * story is a fact about the journalism and must survive a change of theme,
 * so it belongs there rather than here. This file is only the drawing.
 * Without the plugin the function below does not exist and nothing is
 * printed, which is the correct outcome rather than an error.
 *
 * @package LivingDraft
 * @since 4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'livingdraft_timeline_for_post' ) ) {
	return;
}

if ( ! get_theme_mod( 'livingdraft_show_timeline', true ) ) {
	return;
}

$ld_story = livingdraft_timeline_for_post();

if ( empty( $ld_story['posts'] ) ) {
	return;
}

$ld_entries  = $ld_story['posts'];
$ld_total    = (int) $ld_story['total'];
$ld_position = (int) $ld_story['position'];
$ld_label    = (string) $ld_story['label'];

/*
 * A long-running story would otherwise print thirty rows under a short
 * article. Everything stays in the HTML for a crawler and for anyone
 * without JavaScript — the overflow is folded into a <details>, which is
 * open to a keyboard and to a screen reader on demand and costs no script.
 */
$ld_max     = 8;
$ld_visible = $ld_entries;
$ld_folded  = array();

if ( count( $ld_entries ) > $ld_max ) {
	// Keep the beginning of the story and the part around where the reader
	// is. The middle is what folds.
	$ld_keep_head = 3;
	$ld_around    = max( 0, $ld_position - 2 );

	$ld_visible = array_slice( $ld_entries, 0, $ld_keep_head );
	$ld_folded  = array_slice( $ld_entries, $ld_keep_head, max( 0, $ld_around - $ld_keep_head ) );
	$ld_visible = array_merge( $ld_visible, array_slice( $ld_entries, $ld_keep_head + count( $ld_folded ) ) );
}

/**
 * Render one row.
 *
 * @param WP_Post $entry     The article.
 * @param int     $number    Its place in the story.
 * @param bool    $is_current Whether it is the article being read.
 * @return void
 */
$ld_row = static function ( $entry, $number, $is_current ) {
	$classes = 'tl-item' . ( $is_current ? ' is-current' : '' );
	?>
	<li class="<?php echo esc_attr( $classes ); ?>"<?php echo $is_current ? ' aria-current="true"' : ''; ?>>
		<span class="tl-marker" aria-hidden="true"></span>
		<time class="tl-date" datetime="<?php echo esc_attr( get_post_time( DATE_W3C, true, $entry ) ); ?>">
			<?php echo esc_html( get_post_time( 'j M Y', false, $entry ) ); ?>
		</time>
		<?php if ( $is_current ) : ?>
			<span class="tl-title">
				<?php echo esc_html( get_the_title( $entry ) ); ?>
				<span class="tl-here"><?php esc_html_e( 'you are here', 'livingdraft' ); ?></span>
			</span>
		<?php else : ?>
			<a class="tl-title" href="<?php echo esc_url( (string) get_permalink( $entry ) ); ?>">
				<?php echo esc_html( get_the_title( $entry ) ); ?>
			</a>
		<?php endif; ?>
		<span class="screen-reader-text">
			<?php
			printf(
				/* translators: %d: position in the story. */
				esc_html__( 'Part %d of this story', 'livingdraft' ),
				(int) $number
			);
			?>
		</span>
	</li>
	<?php
};

$ld_index = 0;
?>

<section class="story-timeline" aria-labelledby="story-timeline-title">
	<div class="section-rule"></div>

	<h2 class="section-head" id="story-timeline-title">
		<span><?php esc_html_e( 'This Story So Far', 'livingdraft' ); ?></span>
		<span class="count">
			<?php
			printf(
				/* translators: 1: reader's position, 2: total articles. */
				esc_html__( '%1$d of %2$d', 'livingdraft' ),
				(int) $ld_position,
				(int) $ld_total
			);
			?>
		</span>
	</h2>

	<p class="tl-strap">
		<?php
		printf(
			/* translators: %s: the name of the running story. */
			esc_html__( 'The %s story, in the order it happened.', 'livingdraft' ),
			'<strong>' . esc_html( $ld_label ) . '</strong>'
		);
		?>
	</p>

	<ol class="tl-list">
		<?php
		foreach ( $ld_entries as $ld_i => $ld_entry ) :
			$ld_is_current = ( (int) $ld_entry->ID === (int) get_the_ID() );
			$ld_number     = $ld_i + 1;

			// The folded middle, printed once, in place.
			if ( ! empty( $ld_folded ) && $ld_i === 3 ) :
				?>
				<li class="tl-fold">
					<details>
						<summary>
							<?php
							printf(
								/* translators: %d: number of hidden articles. */
								esc_html( _n( '%d earlier article', '%d earlier articles', count( $ld_folded ), 'livingdraft' ) ),
								count( $ld_folded )
							);
							?>
						</summary>
						<ol class="tl-list is-folded">
							<?php
							foreach ( $ld_folded as $ld_j => $ld_hidden ) {
								$ld_row( $ld_hidden, 4 + $ld_j, false );
							}
							?>
						</ol>
					</details>
				</li>
				<?php
			endif;

			// Skip the entries already printed inside the fold.
			if ( ! empty( $ld_folded ) && $ld_i >= 3 && $ld_i < ( 3 + count( $ld_folded ) ) ) {
				continue;
			}

			$ld_row( $ld_entry, $ld_number, $ld_is_current );
		endforeach;
		?>
	</ol>
</section>
