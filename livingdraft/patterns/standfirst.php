<?php
/**
 * Title: Standfirst and byline opening
 * Slug: livingdraft/standfirst
 * Categories: livingdraft, text
 * Keywords: standfirst, deck, intro, opening
 * Description: An italic standfirst over a ruled byline line, the way a printed lead opens.
 */

?>
<!-- wp:paragraph {"className":"ld-standfirst"} -->
<p class="ld-standfirst"><em><?php echo esc_html_x( 'More than seven lakh people are affected as the river breaches embankments across four districts.', 'Theme pattern text', 'livingdraft' ); ?></em></p>
<!-- /wp:paragraph -->

<!-- wp:separator {"className":"is-style-double-rule"} -->
<hr class="wp-block-separator is-style-double-rule"/>
<!-- /wp:separator -->

<!-- wp:paragraph -->
<p><?php echo esc_html_x( 'Open here. The first paragraph carries the drop cap, so give it a full sentence that can stand on its own.', 'Theme pattern text', 'livingdraft' ); ?></p>
<!-- /wp:paragraph -->
