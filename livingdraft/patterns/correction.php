<?php
/**
 * Title: Correction notice
 * Slug: livingdraft/correction
 * Categories: livingdraft, text
 * Keywords: correction, clarification, update
 * Description: A signed correction to append to a story that has been put right.
 */

?>
<!-- wp:group {"className":"is-style-correction"} -->
<div class="wp-block-group is-style-correction">
	<!-- wp:paragraph -->
	<p><strong><?php echo esc_html_x( 'Correction:', 'Theme pattern text', 'livingdraft' ); ?></strong> <?php echo esc_html_x( 'An earlier version of this story gave the wrong figure for the number of relief camps. It has been corrected to 214.', 'Theme pattern text', 'livingdraft' ); ?></p>
	<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
