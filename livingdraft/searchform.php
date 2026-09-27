<?php
/**
 * Search form.
 *
 * @package LivingDraft
 */

$ld_id = 'search-' . wp_unique_id();
?>
<form role="search" method="get" class="search-form" action="<?php echo esc_url( home_url( '/' ) ); ?>">
	<label class="screen-reader-text" for="<?php echo esc_attr( $ld_id ); ?>">
		<?php esc_html_e( 'Search stories', 'livingdraft' ); ?>
	</label>
	<input type="search" id="<?php echo esc_attr( $ld_id ); ?>" name="s"
		value="<?php echo esc_attr( get_search_query() ); ?>"
		placeholder="<?php esc_attr_e( 'Search stories', 'livingdraft' ); ?>">
	<button type="submit"><?php esc_html_e( 'Find', 'livingdraft' ); ?></button>
</form>
