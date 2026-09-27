<?php
/**
 * Listen: settings.
 *
 * Hosted on the existing Settings screen through the
 * `livingdraft_settings_sections` hook rather than claiming a menu entry of
 * its own. Six fields do not justify a tab.
 *
 * @package LivingDraftCore
 * @since 4.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Save.
 *
 * @since 4.2.0
 * @return void
 */
function livingdraft_listen_handle_save() {
	if ( ! isset( $_POST['ld_listen_save'] ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to change these settings.', 'livingdraft-core' ) );
	}

	check_admin_referer( 'ld_listen_save' );

	$types = isset( $_POST['ld_listen_post_types'] ) ? (array) wp_unslash( $_POST['ld_listen_post_types'] ) : array();
	$types = array_values( array_filter( array_map( 'sanitize_key', $types ), 'post_type_exists' ) );

	$position = isset( $_POST['ld_listen_position'] ) ? sanitize_key( wp_unslash( $_POST['ld_listen_position'] ) ) : 'before';

	$selector = isset( $_POST['ld_listen_selector'] ) ? sanitize_text_field( wp_unslash( $_POST['ld_listen_selector'] ) ) : '.entry-content';
	$selector = '' !== trim( $selector ) ? trim( $selector ) : '.entry-content';

	$rate = isset( $_POST['ld_listen_rate'] ) ? (float) wp_unslash( $_POST['ld_listen_rate'] ) : 1.0;
	$rate = min( 2.0, max( 0.5, $rate ) );

	$min = isset( $_POST['ld_listen_min_words'] ) ? absint( wp_unslash( $_POST['ld_listen_min_words'] ) ) : 120;

	update_option(
		LD_LISTEN_SETTINGS,
		array(
			'enabled'    => ! empty( $_POST['ld_listen_enabled'] ),
			'post_types' => $types,
			'position'   => in_array( $position, array( 'before', 'after' ), true ) ? $position : 'before',
			'selector'   => $selector,
			'rate'       => $rate,
			'highlight'  => ! empty( $_POST['ld_listen_highlight'] ),
			'min_words'  => $min,
		)
	);

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'      => 'livingdraft-settings',
				'section'   => 'listen',
				'ld-listen' => 'saved',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}
add_action( 'admin_init', 'livingdraft_listen_handle_save' );

/**
 * The settings section.
 *
 * @since 4.2.0
 * @return void
 */
function livingdraft_listen_render_settings() {
	$settings = livingdraft_listen_settings();

	$types = get_post_types(
		array(
			'public'  => true,
			'show_ui' => true,
		),
		'objects'
	);

	unset( $types['attachment'] );

	if ( isset( $_GET['ld-listen'] ) && 'saved' === $_GET['ld-listen'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="tld-notice"><p>' . esc_html__( 'Listen settings saved.', 'livingdraft-core' ) . '</p></div>';
	}
	?>
	<div class="tld-card">
		<div class="tld-section-rule"><h2><?php esc_html_e( 'Listen', 'livingdraft-core' ); ?></h2></div>

		<p class="tld-help">
			<?php esc_html_e( 'The reader\'s own browser or phone speaks the page. No recording, no audio file, no third-party service, no per-article cost. Which voices are offered depends on the reader\'s device, so quality varies: excellent on recent iPhones and Macs, adequate on Android and Windows, occasionally robotic on Linux.', 'livingdraft-core' ); ?>
		</p>

		<form method="post" action="">
			<?php wp_nonce_field( 'ld_listen_save' ); ?>

			<div class="tld-field">
				<label class="tld-check">
					<input type="checkbox" name="ld_listen_enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
					<span><?php esc_html_e( 'Show the Listen control on articles', 'livingdraft-core' ); ?></span>
				</label>
			</div>

			<div class="tld-field">
				<span class="tld-label"><?php esc_html_e( 'Post types', 'livingdraft-core' ); ?></span>
				<div class="tld-check-grid">
					<?php foreach ( $types as $type ) : ?>
						<label class="tld-check">
							<input type="checkbox" name="ld_listen_post_types[]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, (array) $settings['post_types'], true ) ); ?> />
							<span><?php echo esc_html( $type->labels->name ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="tld-grid-2">
				<div class="tld-field">
					<label class="tld-label" for="ld-listen-position"><?php esc_html_e( 'Position', 'livingdraft-core' ); ?></label>
					<select id="ld-listen-position" name="ld_listen_position" class="tld-select">
						<option value="before" <?php selected( 'before', $settings['position'] ); ?>><?php esc_html_e( 'Above the first paragraph', 'livingdraft-core' ); ?></option>
						<option value="after" <?php selected( 'after', $settings['position'] ); ?>><?php esc_html_e( 'Below the article', 'livingdraft-core' ); ?></option>
					</select>
					<p class="tld-help"><?php esc_html_e( 'The [listen] shortcode places it anywhere else — next to the byline, for example.', 'livingdraft-core' ); ?></p>
				</div>

				<div class="tld-field">
					<label class="tld-label" for="ld-listen-rate"><?php esc_html_e( 'Starting speed', 'livingdraft-core' ); ?></label>
					<select id="ld-listen-rate" name="ld_listen_rate" class="tld-select">
						<?php foreach ( array( '0.75', '1', '1.25', '1.5' ) as $rate ) : ?>
							<option value="<?php echo esc_attr( $rate ); ?>" <?php selected( (float) $rate, (float) $settings['rate'] ); ?>><?php echo esc_html( $rate ); ?>&times;</option>
						<?php endforeach; ?>
					</select>
					<p class="tld-help"><?php esc_html_e( 'Readers can change this, and their choice is remembered on their device.', 'livingdraft-core' ); ?></p>
				</div>

				<div class="tld-field">
					<label class="tld-label" for="ld-listen-min"><?php esc_html_e( 'Shortest article', 'livingdraft-core' ); ?></label>
					<div class="tld-field-row">
						<input type="number" id="ld-listen-min" name="ld_listen_min_words" min="0" step="10" class="tld-input is-mono" style="max-width:110px" value="<?php echo esc_attr( (string) (int) $settings['min_words'] ); ?>" />
						<span class="tld-unit"><?php esc_html_e( 'words', 'livingdraft-core' ); ?></span>
					</div>
					<p class="tld-help"><?php esc_html_e( 'Below this, no control. Finding the button, waiting for a voice to load and deciding it is the wrong voice takes longer than reading a two-hundred-word brief.', 'livingdraft-core' ); ?></p>
				</div>

				<div class="tld-field">
					<label class="tld-label" for="ld-listen-selector"><?php esc_html_e( 'Content selector', 'livingdraft-core' ); ?></label>
					<input type="text" id="ld-listen-selector" name="ld_listen_selector" class="tld-input is-mono" value="<?php echo esc_attr( (string) $settings['selector'] ); ?>" />
					<p class="tld-help"><?php esc_html_e( 'The element holding the article body. Only change it if you move to a theme that does not use .entry-content — if it is wrong, the control never appears.', 'livingdraft-core' ); ?></p>
				</div>
			</div>

			<div class="tld-field">
				<label class="tld-check">
					<input type="checkbox" name="ld_listen_highlight" value="1" <?php checked( ! empty( $settings['highlight'] ) ); ?> />
					<span><?php esc_html_e( 'Mark the paragraph currently being spoken', 'livingdraft-core' ); ?></span>
				</label>
				<p class="tld-help"><?php esc_html_e( 'The mark never scrolls the page by itself. Most listeners read ahead of the voice, and moving the page under them is worse than losing their place.', 'livingdraft-core' ); ?></p>
			</div>

			<div class="tld-btn-row">
				<button type="submit" name="ld_listen_save" value="1" class="tld-btn is-primary"><?php esc_html_e( 'Save Listen settings', 'livingdraft-core' ); ?></button>
			</div>
		</form>
	</div>
	<?php
}

/**
 * Register Listen in the Settings rail.
 *
 * @since 4.4.0
 * @param array $sections Sections.
 * @return array
 */
function livingdraft_listen_register_settings_section( $sections ) {
	$sections['listen'] = array(
		'label'  => __( 'Listen', 'livingdraft-core' ),
		'desc'   => __( 'The read-aloud control on articles, and where it appears.', 'livingdraft-core' ),
		'render' => 'livingdraft_listen_render_settings',
		'cap'    => 'manage_options',
	);

	return $sections;
}
add_filter( 'livingdraft_settings_panels', 'livingdraft_listen_register_settings_section', 30 );
