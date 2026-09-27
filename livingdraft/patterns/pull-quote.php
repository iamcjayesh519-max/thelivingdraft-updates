<?php
/**
 * Title: Pull quote with double rule
 * Slug: livingdraft/pull-quote
 * Categories: livingdraft, text
 * Keywords: quote, pullquote, broadsheet
 * Description: A quotation set between double rules, the way a printed page breaks a long column.
 */

?>
<!-- wp:quote {"className":"is-style-brief"} -->
<blockquote class="wp-block-quote is-style-brief">
	<!-- wp:paragraph -->
	<p><?php echo esc_html_x( 'The alert chain still ends at the district level — a gap identified after last year’s floods that has not yet been closed.', 'Theme pattern text', 'livingdraft' ); ?></p>
	<!-- /wp:paragraph -->
	<cite><?php echo esc_html_x( 'State disaster management authority, in its own review', 'Theme pattern text', 'livingdraft' ); ?></cite>
</blockquote>
<!-- /wp:quote -->
