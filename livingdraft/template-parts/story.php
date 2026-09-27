<?php
/**
 * A story row: archives, search results and other flat listings.
 *
 * @package LivingDraft
 */

$ld_classes = array( 'story' );
if ( livingdraft_is_revised() ) {
	$ld_classes[] = 'is-revised';
}
?>
<article <?php post_class( $ld_classes ); ?>>
	<?php if ( has_post_thumbnail() ) : ?>
		<a class="thumb" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
			<?php
			the_post_thumbnail(
				'livingdraft-thumb',
				array(
					'sizes' => '(min-width: 600px) 180px, 100vw',
					'alt'   => the_title_attribute( array( 'echo' => false ) ),
				)
			);
			?>
		</a>
	<?php endif; ?>

	<div class="story-body">
		<?php livingdraft_stamp(); ?>
		<h2 class="story-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
		<p class="excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 26, '…' ) ); ?></p>
	</div>
</article>
