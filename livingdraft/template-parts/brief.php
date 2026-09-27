<?php
/**
 * A single item in the brief rail.
 *
 * @package LivingDraft
 */

$ld_classes = array( 'brief' );
if ( livingdraft_is_revised() ) {
	$ld_classes[] = 'is-revised';
}
?>
<article <?php post_class( $ld_classes ); ?>>
	<h3 class="brief-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
	<p class="brief-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 18, '…' ) ); ?></p>
</article>
