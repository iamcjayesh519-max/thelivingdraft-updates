<?php
/**
 * The lead story on the front page: kicker, headline, deck, byline rule,
 * picture with caption, and the opening paragraphs under a drop cap.
 *
 * @package LivingDraft
 */

?>
<article <?php post_class( 'lede' ); ?>>

	<?php livingdraft_kicker(); ?>

	<h2 class="lede-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>

	<?php if ( has_excerpt() ) : ?>
		<p class="lede-deck"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 30, '…' ) ); ?></p>
	<?php endif; ?>

	<?php livingdraft_byline( false ); ?>

	<?php if ( has_post_thumbnail() ) : ?>
		<figure class="figure-well">
			<a href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
				<?php
				the_post_thumbnail(
					'livingdraft-lede',
					array_merge(
						livingdraft_no_lazy_attrs(),
						array(
							'sizes' => '(min-width: 900px) 700px, 92vw',
							'alt'   => the_title_attribute( array( 'echo' => false ) ),
						)
					)
				);
				?>
			</a>
			<?php
			$ld_caption = get_the_post_thumbnail_caption();
			if ( $ld_caption ) :
				?>
				<figcaption><?php echo esc_html( $ld_caption ); ?></figcaption>
			<?php endif; ?>
		</figure>
	<?php endif; ?>

	<div class="teaser">
		<?php echo livingdraft_teaser( 3 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	</div>

	<a class="read-on" href="<?php the_permalink(); ?>">
		<?php esc_html_e( 'Continue reading', 'livingdraft' ); ?> &rarr;
	</a>
</article>
