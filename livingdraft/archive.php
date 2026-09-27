<?php
/**
 * Section fronts: category, tag, taxonomy and date archives.
 *
 * A section is a front page in miniature — lead, brief rail, then columns —
 * so the paper does not fall apart on its second-most-visited pages.
 *
 * @package LivingDraft
 */

get_header();
?>

<div class="wrap">

	<?php livingdraft_breadcrumbs(); ?>

	<header class="front-head">
		<h1 class="front-title"><?php the_archive_title(); ?></h1>

		<?php
		$ld_arch_desc = get_the_archive_description();
		if ( $ld_arch_desc ) :
			?>
			<div class="front-standfirst"><?php echo wp_kses_post( $ld_arch_desc ); ?></div>
		<?php endif; ?>

		<div class="front-count">
			<?php
			printf(
				/* translators: %s: number of stories in this archive. */
				esc_html( _n( '%s story filed', '%s stories filed', (int) $GLOBALS['wp_query']->found_posts, 'livingdraft' ) ),
				esc_html( number_format_i18n( (int) $GLOBALS['wp_query']->found_posts ) )
			);
			?>
		</div>
	</header>

	<?php if ( have_posts() ) : ?>
		<?php get_template_part( 'template-parts/edition' ); ?>
	<?php else : ?>
		<p><?php esc_html_e( 'Nothing has been filed under this heading yet.', 'livingdraft' ); ?></p>
	<?php endif; ?>

</div>

<?php
get_footer();
