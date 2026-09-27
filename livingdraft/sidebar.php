<?php
/**
 * The right-hand rail.
 *
 * @package LivingDraft
 */

if ( ! is_active_sidebar( 'sidebar-1' ) ) {
	return;
}
?>
<aside class="sidebar" aria-label="<?php esc_attr_e( 'More from this paper', 'livingdraft' ); ?>">
	<?php dynamic_sidebar( 'sidebar-1' ); ?>
</aside>
