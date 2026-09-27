<?php
/**
 * The update log: what changed on this story after it ran.
 *
 * @package LivingDraft
 */

// These live in the Living Draft Core plugin. Deactivating the plugin should
// hide the log, not fatal the site.
if ( ! function_exists( 'livingdraft_get_updates' ) || ! function_exists( 'livingdraft_update_kinds' ) ) {
	return;
}

$ld_updates = livingdraft_get_updates();

if ( empty( $ld_updates ) ) {
	return;
}

$ld_kinds = livingdraft_update_kinds();
?>
<section class="update-log" aria-labelledby="update-log-title">
	<h2 class="update-log-title" id="update-log-title">
		<?php
		printf(
			/* translators: %s: number of logged changes. */
			esc_html( _n( '%s update to this story', '%s updates to this story', count( $ld_updates ), 'livingdraft' ) ),
			esc_html( number_format_i18n( count( $ld_updates ) ) )
		);
		?>
	</h2>

	<ol class="update-list">
		<?php foreach ( $ld_updates as $ld_row ) : ?>
			<li class="update-item update-<?php echo esc_attr( $ld_row['kind'] ); ?>">
				<div class="update-meta">
					<span class="update-kind"><?php echo esc_html( $ld_kinds[ $ld_row['kind'] ] ); ?></span>
					<time datetime="<?php echo esc_attr( mysql2date( DATE_W3C, $ld_row['time'] ) ); ?>">
						<?php echo esc_html( mysql2date( 'j M Y, H:i', $ld_row['time'] ) ); ?>
					</time>
				</div>
				<p class="update-note"><?php echo esc_html( $ld_row['note'] ); ?></p>
			</li>
		<?php endforeach; ?>
	</ol>
</section>
