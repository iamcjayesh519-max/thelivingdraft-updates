<?php
/**
 * A story in the column grid under a section head.
 *
 * @package LivingDraft
 */

$ld_classes = array( 'col-item' );
if ( livingdraft_is_revised() ) {
	$ld_classes[] = 'is-revised';
}
// Mark the first card in the loop so "Varied" category card mode can style
// it larger than the rest. Only meaningful on archive pages, but harmless
// elsewhere — no CSS rule matches without body.ld-catcard-varied.
if ( isset( $GLOBALS['wp_query']->current_post ) && 0 === (int) $GLOBALS['wp_query']->current_post ) {
	$ld_classes[] = 'is-first';
}
?>
<article <?php post_class( $ld_classes ); ?>>
	<?php if ( has_post_thumbnail() ) : ?>
		<a class="thumb" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
			<?php
			the_post_thumbnail(
				'livingdraft-column',
				array(
					'sizes' => '(min-width: 900px) 340px, (min-width: 600px) 45vw, 100vw',
					'alt'   => the_title_attribute( array( 'echo' => false ) ),
				)
			);
			?>
		</a>
	<?php endif; ?>

	<?php livingdraft_kicker(); ?>

	<h3 class="col-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>

	<p class="col-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 22, '…' ) ); ?></p>

	<?php livingdraft_stamp( array( 'show_cat' => false ) ); ?>
</article>
