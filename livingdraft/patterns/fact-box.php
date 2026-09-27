<?php
/**
 * Title: Fact box
 * Slug: livingdraft/fact-box
 * Categories: livingdraft, text
 * Keywords: facts, box, sidebar, numbers
 * Description: A boxed set of figures to sit beside a running story.
 */

?>
<!-- wp:group {"className":"is-style-fact-box"} -->
<div class="wp-block-group is-style-fact-box">
	<!-- wp:heading {"level":3} -->
	<h3><?php echo esc_html_x( 'The numbers', 'Theme pattern text', 'livingdraft' ); ?></h3>
	<!-- /wp:heading -->

	<!-- wp:list {"className":"is-style-ruled"} -->
	<ul class="wp-block-list is-style-ruled">
		<!-- wp:list-item -->
		<li><strong><?php echo esc_html_x( '7.05 lakh', 'Theme pattern text', 'livingdraft' ); ?></strong> <?php echo esc_html_x( 'people affected across four districts', 'Theme pattern text', 'livingdraft' ); ?></li>
		<!-- /wp:list-item -->
		<!-- wp:list-item -->
		<li><strong><?php echo esc_html_x( '214', 'Theme pattern text', 'livingdraft' ); ?></strong> <?php echo esc_html_x( 'relief camps opened since Friday', 'Theme pattern text', 'livingdraft' ); ?></li>
		<!-- /wp:list-item -->
		<!-- wp:list-item -->
		<li><strong><?php echo esc_html_x( '4', 'Theme pattern text', 'livingdraft' ); ?></strong> <?php echo esc_html_x( 'gauging stations above the danger mark', 'Theme pattern text', 'livingdraft' ); ?></li>
		<!-- /wp:list-item -->
	</ul>
	<!-- /wp:list -->

	<!-- wp:paragraph {"fontSize":"small"} -->
	<p class="has-small-font-size"><?php echo esc_html_x( 'Source: state disaster management authority bulletin, 07:00 IST', 'Theme pattern text', 'livingdraft' ); ?></p>
	<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
