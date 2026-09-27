<?php
/**
 * Updates without the download-and-upload dance.
 *
 * Point this at a small JSON file you control. WordPress then treats the
 * theme and the plugin like any other update: a notice on the Plugins screen
 * and a single Update button. No deleting, no re-uploading, no losing settings
 * — and it works from your phone.
 *
 * Nothing is checked until you set a URL, and nothing installs on its own
 * unless you switch auto-updates on yourself.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where to look for new versions.
 *
 * A constant in wp-config.php wins, so a staging site can be pointed
 * somewhere else without touching the database.
 *
 * @return string
 */
function livingdraft_update_url() {
	if ( defined( 'LIVINGDRAFT_UPDATE_URL' ) && LIVINGDRAFT_UPDATE_URL ) {
		return (string) LIVINGDRAFT_UPDATE_URL;
	}

	return (string) get_option( 'livingdraft_update_url', '' );
}

/**
 * An optional token, for a private repository or a protected file.
 *
 * @return string
 */
function livingdraft_update_token() {
	if ( defined( 'LIVINGDRAFT_UPDATE_TOKEN' ) && LIVINGDRAFT_UPDATE_TOKEN ) {
		return (string) LIVINGDRAFT_UPDATE_TOKEN;
	}

	return (string) get_option( 'livingdraft_update_token', '' );
}

/**
 * Fetch and cache the manifest.
 *
 * Cached for six hours so every admin page load is not a network request, and
 * so a rate limit somewhere cannot take the admin screen down with it.
 *
 * @param bool $force Ignore the cache.
 * @return array
 */
function livingdraft_update_manifest( $force = false ) {
	$url = livingdraft_update_url();

	if ( ! $url ) {
		return array();
	}

	// Refuse anything that is not HTTPS. A plain-text manifest is trivially
	// swapped in transit — even though the download itself is HTTPS-gated
	// further down, the manifest points to which URL to download.
	if ( 0 !== strpos( $url, 'https://' ) ) {
		set_transient( 'livingdraft_manifest', array(), 20 * MINUTE_IN_SECONDS );
		return array();
	}

	if ( ! $force ) {
		$cached = get_transient( 'livingdraft_manifest' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$args = array(
		'timeout' => 12,
		'headers' => array( 'Accept' => 'application/json' ),
	);

	$token = livingdraft_update_token();
	if ( $token ) {
		$args['headers']['Authorization'] = 'Bearer ' . $token;
	}

	$response = wp_remote_get( $url, $args );

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		// Cache the failure briefly too, or a dead URL is retried on every
		// single admin page load.
		set_transient( 'livingdraft_manifest', array(), 20 * MINUTE_IN_SECONDS );
		return array();
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! is_array( $data ) ) {
		set_transient( 'livingdraft_manifest', array(), 20 * MINUTE_IN_SECONDS );
		return array();
	}

	set_transient( 'livingdraft_manifest', $data, 6 * HOUR_IN_SECONDS );

	return $data;
}

/**
 * One entry from the manifest, sanitised.
 *
 * @param string $which 'plugin' or 'theme'.
 * @return array
 */
function livingdraft_update_entry( $which ) {
	$manifest = livingdraft_update_manifest();

	if ( empty( $manifest[ $which ] ) || ! is_array( $manifest[ $which ] ) ) {
		return array();
	}

	$entry = $manifest[ $which ];

	if ( empty( $entry['version'] ) || empty( $entry['download'] ) ) {
		return array();
	}

	$download = esc_url_raw( $entry['download'] );

	// Only ever fetch a package over HTTPS. A plain http:// download could be
	// swapped in transit for something else, and it would be installed.
	if ( 0 !== strpos( $download, 'https://' ) ) {
		return array();
	}

	return array(
		'version'      => sanitize_text_field( $entry['version'] ),
		'download'     => $download,
		'requires'     => isset( $entry['requires'] ) ? sanitize_text_field( $entry['requires'] ) : '6.2',
		'requires_php' => isset( $entry['requires_php'] ) ? sanitize_text_field( $entry['requires_php'] ) : '7.4',
		'tested'       => isset( $entry['tested'] ) ? sanitize_text_field( $entry['tested'] ) : '',
		'changelog'    => isset( $entry['changelog'] ) ? wp_kses_post( $entry['changelog'] ) : '',
	);
}

/* ------------------------------------------------------------------
 * The plugin
 * ------------------------------------------------------------------ */

/**
 * @param object $transient Update data.
 * @return object
 */
function livingdraft_check_plugin_update( $transient ) {
	if ( ! is_object( $transient ) ) {
		return $transient;
	}

	$entry = livingdraft_update_entry( 'plugin' );
	if ( empty( $entry ) ) {
		return $transient;
	}

	$slug = plugin_basename( LIVINGDRAFT_CORE_FILE );

	if ( ! version_compare( $entry['version'], LIVINGDRAFT_CORE_VERSION, '>' ) ) {
		return $transient;
	}

	$transient->response[ $slug ] = (object) array(
		'slug'         => dirname( $slug ),
		'plugin'       => $slug,
		'new_version'  => $entry['version'],
		'package'      => $entry['download'],
		'url'          => 'https://thelivingdraft.com',
		'requires'     => $entry['requires'],
		'requires_php' => $entry['requires_php'],
		'tested'       => $entry['tested'],
		'icons'        => array(
			'1x' => LIVINGDRAFT_CORE_URL . 'assets/icon-128x128.png',
			'2x' => LIVINGDRAFT_CORE_URL . 'assets/icon-256x256.png',
		),
		'banners'      => array(
			'low'  => LIVINGDRAFT_CORE_URL . 'assets/banner-772x250.png',
			'high' => LIVINGDRAFT_CORE_URL . 'assets/banner-1544x500.png',
		),
	);

	return $transient;
}
add_filter( 'pre_set_site_transient_update_plugins', 'livingdraft_check_plugin_update' );

/**
 * Fill in the "View details" panel, so the update is not a blank box.
 *
 * @param mixed  $result Result.
 * @param string $action Action.
 * @param object $args   Arguments.
 * @return mixed
 */
function livingdraft_plugin_details( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
		return $result;
	}

	if ( dirname( plugin_basename( LIVINGDRAFT_CORE_FILE ) ) !== $args->slug ) {
		return $result;
	}

	$entry = livingdraft_update_entry( 'plugin' );
	if ( empty( $entry ) ) {
		return $result;
	}

	return (object) array(
		'name'          => 'The Living Draft Core',
		'slug'          => $args->slug,
		'version'       => $entry['version'],
		'requires'      => $entry['requires'],
		'requires_php'  => $entry['requires_php'],
		'tested'        => $entry['tested'],
		'download_link' => $entry['download'],
		'sections'      => array(
			'changelog' => $entry['changelog'] ? $entry['changelog'] : __( 'No notes were published for this release.', 'livingdraft-core' ),
		),
	);
}
add_filter( 'plugins_api', 'livingdraft_plugin_details', 10, 3 );

/* ------------------------------------------------------------------
 * The theme
 * ------------------------------------------------------------------ */

/**
 * @param object $transient Update data.
 * @return object
 */
function livingdraft_check_theme_update( $transient ) {
	if ( ! is_object( $transient ) ) {
		return $transient;
	}

	$entry = livingdraft_update_entry( 'theme' );
	if ( empty( $entry ) ) {
		return $transient;
	}

	$slug  = 'livingdraft';
	$theme = wp_get_theme( $slug );

	if ( ! $theme->exists() ) {
		return $transient;
	}

	if ( ! version_compare( $entry['version'], $theme->get( 'Version' ), '>' ) ) {
		return $transient;
	}

	$transient->response[ $slug ] = array(
		'theme'        => $slug,
		'new_version'  => $entry['version'],
		'package'      => $entry['download'],
		'url'          => 'https://thelivingdraft.com',
		'requires'     => $entry['requires'],
		'requires_php' => $entry['requires_php'],
	);

	return $transient;
}
add_filter( 'pre_set_site_transient_update_themes', 'livingdraft_check_theme_update' );

/* ------------------------------------------------------------------
 * The folder-name trap
 * ------------------------------------------------------------------ */

/**
 * Put the unpacked folder back to the name WordPress expects.
 *
 * A zip downloaded straight from a git host unpacks as something like
 * livingdraft-core-1.1.2/ or livingdraft-main/. Installed under that name the
 * plugin is a stranger to WordPress: it deactivates, and every setting keyed
 * to the old path is orphaned. This renames it back.
 *
 * @param string $source        Unpacked folder.
 * @param string $remote_source Parent folder.
 * @param object $upgrader      Upgrader.
 * @param array  $extra         Hook extra.
 * @return string|WP_Error
 */
function livingdraft_fix_folder_name( $source, $remote_source, $upgrader, $extra = array() ) {
	global $wp_filesystem;

	$wanted = '';
	$is_plugin = false;

	if ( ! empty( $extra['plugin'] ) && false !== strpos( $extra['plugin'], 'livingdraft-core' ) ) {
		$wanted    = 'livingdraft-core';
		$is_plugin = true;
	} elseif ( ! empty( $extra['theme'] ) && 'livingdraft' === $extra['theme'] ) {
		$wanted = 'livingdraft';
	}

	if ( ! $wanted || ! $wp_filesystem ) {
		return $source;
	}

	// Copy the two files a user is invited to edit — assets/custom.css and
	// inc/custom.php — from the OLD install into the fresh unpack, so a plugin
	// update does not wipe their custom CSS and pasted snippets. If the old
	// file does not exist (first install) we skip; if the write fails we still
	// let the update proceed rather than block it on a preservation error.
	if ( $is_plugin ) {
		$old_root  = trailingslashit( WP_PLUGIN_DIR ) . 'livingdraft-core/';
		$new_root  = trailingslashit( $source );
		$preserve  = array( 'assets/custom.css', 'inc/custom.php' );

		foreach ( $preserve as $rel ) {
			$old_file = $old_root . $rel;
			$new_file = $new_root . $rel;

			if ( $wp_filesystem->exists( $old_file ) ) {
				$wp_filesystem->copy( $old_file, $new_file, true );
			}
		}
	}

	$correct = trailingslashit( $remote_source ) . $wanted;

	if ( untrailingslashit( $source ) === $correct ) {
		return $source;
	}

	if ( $wp_filesystem->move( $source, $correct, true ) ) {
		return trailingslashit( $correct );
	}

	return new WP_Error(
		'livingdraft_rename_failed',
		__( 'Could not rename the update folder. Nothing has been changed.', 'livingdraft-core' )
	);
}
add_filter( 'upgrader_source_selection', 'livingdraft_fix_folder_name', 10, 4 );

/**
 * Throw the cached manifest away after any update runs.
 */
function livingdraft_clear_manifest_cache() {
	delete_transient( 'livingdraft_manifest' );
}
add_action( 'upgrader_process_complete', 'livingdraft_clear_manifest_cache' );

/* ------------------------------------------------------------------
 * Settings
 * ------------------------------------------------------------------ */

/**
 * A page under "The Living Draft" for the plugin auto-updater manifest URL.
 *
 * Historically this lived under wp-admin > Settings, but v2 consolidates
 * every Living Draft admin surface under one top-level menu so operators
 * have a single place to look. The old `options-general.php?page=livingdraft-updates`
 * URL still works because we register the same slug — WP just resolves
 * it under the new parent.
 */
function livingdraft_update_settings_page() {
	add_submenu_page(
		'livingdraft-overview',
		__( 'The Living Draft Updates', 'livingdraft-core' ),
		__( 'Plugin Updates', 'livingdraft-core' ),
		'manage_options',
		'livingdraft-updates',
		'livingdraft_update_settings_render'
	);
}
add_action( 'admin_menu', 'livingdraft_update_settings_page', 40 );

/**
 * Register the two options.
 */
function livingdraft_update_settings_init() {
	register_setting(
		'livingdraft_updates',
		'livingdraft_update_url',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'livingdraft_sanitize_update_url',
			'default'           => '',
		)
	);

	register_setting(
		'livingdraft_updates',
		'livingdraft_update_token',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		)
	);
}
add_action( 'admin_init', 'livingdraft_update_settings_init' );

/**
 * Validate the manifest URL before it is stored. HTTPS-only, otherwise the
 * option is rejected and a settings error is registered so the admin sees why.
 *
 * @param string $value Raw input from the settings form.
 * @return string
 */
function livingdraft_sanitize_update_url( $value ) {
	$value = esc_url_raw( (string) $value );

	if ( '' === $value ) {
		return '';
	}

	if ( 0 !== strpos( $value, 'https://' ) ) {
		add_settings_error(
			'livingdraft_update_url',
			'livingdraft_update_url_not_https',
			__( 'The update URL must start with https://. A plain http:// manifest can be intercepted and swapped for a malicious download.', 'livingdraft-core' )
		);
		return (string) get_option( 'livingdraft_update_url', '' );
	}

	return $value;
}

/**
 * Draw the page (old top-level, kept for backward compatibility).
 * The heavy lifting lives in livingdraft_update_channel_render_form()
 * so the new "The Living Draft > Settings" page can embed the same form.
 */
function livingdraft_update_settings_render() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'The Living Draft Updates', 'livingdraft-core' ); ?></h1>
		<?php livingdraft_update_channel_render_form(); ?>
	</div>
	<?php
}

/**
 * Render just the form + "What the site can see" table. No page wrapper.
 * Called from two places:
 *   - livingdraft_update_settings_render() → the old Settings > … page
 *   - livingdraft_admin_render_settings() → the new The Living Draft > Settings
 *
 * The form's action still points at options.php with the same
 * setting group, so save behaviour is identical no matter which entry
 * point the user came from.
 */
function livingdraft_update_channel_render_form() {

	if ( isset( $_GET['ld_check'], $_GET['_wpnonce'] ) ) {
		$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );

		if ( wp_verify_nonce( $nonce, 'livingdraft_check' ) ) {
			delete_transient( 'livingdraft_manifest' );
			delete_site_transient( 'update_plugins' );
			delete_site_transient( 'update_themes' );
			livingdraft_update_manifest( true );
		}
	}

	$manifest = livingdraft_update_manifest();
	$plugin   = livingdraft_update_entry( 'plugin' );
	$theme    = livingdraft_update_entry( 'theme' );
	$live     = wp_get_theme( 'livingdraft' );
	?>
	<p style="max-width:46em;color:var(--tld-ink-3,#666)">
		<?php esc_html_e( 'Paste the address of your version file below. Once it is set, new releases appear on the Plugins and Themes screens like any other update — one button, no deleting and re-uploading, and your settings are kept.', 'livingdraft-core' ); ?>
	</p>

	<form method="post" action="options.php">
		<?php settings_fields( 'livingdraft_updates' ); ?>

		<div class="tld-field">
			<label for="ld_url" class="tld-label"><?php esc_html_e( 'Version file URL', 'livingdraft-core' ); ?></label>
			<input type="url" id="ld_url" name="livingdraft_update_url" class="tld-input is-mono"
				value="<?php echo esc_attr( get_option( 'livingdraft_update_url', '' ) ); ?>"
				placeholder="https://raw.githubusercontent.com/you/repo/main/updates.json"
				style="max-width:none">
			<p class="tld-help">
				<?php esc_html_e( 'A small JSON file listing the latest version of each. Leave blank to switch update checking off entirely.', 'livingdraft-core' ); ?>
			</p>
		</div>

		<div class="tld-field">
			<label for="ld_token" class="tld-label"><?php esc_html_e( 'Access token', 'livingdraft-core' ); ?></label>
			<input type="password" id="ld_token" name="livingdraft_update_token" class="tld-input is-mono"
				value="<?php echo esc_attr( get_option( 'livingdraft_update_token', '' ) ); ?>"
				autocomplete="off"
				style="max-width:640px">
			<p class="tld-help">
				<?php esc_html_e( 'Only needed if the file is private. Leave blank otherwise.', 'livingdraft-core' ); ?>
			</p>
		</div>

		<p><button type="submit" class="tld-btn is-primary"><?php esc_html_e( 'Save changes', 'livingdraft-core' ); ?></button></p>
	</form>

	<hr style="margin:32px 0;border:0;border-top:1px solid var(--tld-rule,#d4d4d4)">

	<h2 style="font-family:var(--tld-serif,Georgia);font-weight:400;font-size:18px"><?php esc_html_e( 'What the site can see', 'livingdraft-core' ); ?></h2>

	<?php if ( ! livingdraft_update_url() ) : ?>
		<p><em><?php esc_html_e( 'No URL set yet, so nothing is being checked.', 'livingdraft-core' ); ?></em></p>
	<?php elseif ( empty( $manifest ) ) : ?>
		<div class="notice notice-error inline"><p>
			<?php esc_html_e( 'That address could not be read. Check it opens in a browser and returns JSON.', 'livingdraft-core' ); ?>
		</p></div>
	<?php else : ?>
		<table class="tld-table" style="max-width:640px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Item', 'livingdraft-core' ); ?></th>
					<th><?php esc_html_e( 'Installed', 'livingdraft-core' ); ?></th>
					<th><?php esc_html_e( 'Available', 'livingdraft-core' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><?php esc_html_e( 'The Living Draft Core (plugin)', 'livingdraft-core' ); ?></td>
					<td><code><?php echo esc_html( LIVINGDRAFT_CORE_VERSION ); ?></code></td>
					<td><code><?php echo esc_html( ! empty( $plugin ) ? $plugin['version'] : '—' ); ?></code></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'The Living Draft (theme)', 'livingdraft-core' ); ?></td>
					<td><code><?php echo esc_html( $live->exists() ? $live->get( 'Version' ) : '—' ); ?></code></td>
					<td><code><?php echo esc_html( ! empty( $theme ) ? $theme['version'] : '—' ); ?></code></td>
				</tr>
			</tbody>
		</table>
	<?php endif; ?>

	<p style="margin-top:20px">
		<?php
		/*
		 * Nonce URL points back at wherever we are — old page OR new
		 * admin.php page. Both superglobals are unslashed and the query
		 * values sanitised before they are rebuilt into a URL; the result is
		 * escaped again on output below.
		 */
		$ld_self  = basename( sanitize_text_field( wp_unslash( $_SERVER['PHP_SELF'] ?? 'admin.php' ) ) );
		$ld_query = array_map( 'sanitize_text_field', wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current  = admin_url( $ld_self . '?' . http_build_query( array_merge( $ld_query, array( 'ld_check' => 1 ) ) ) );
		?>
		<a class="tld-btn is-ghost" href="<?php echo esc_url( wp_nonce_url( $current, 'livingdraft_check' ) ); ?>">
			<?php esc_html_e( 'Check now', 'livingdraft-core' ); ?>
		</a>
		<span class="tld-help" style="display:inline-block;margin:0 0 0 12px">
			<?php esc_html_e( 'Otherwise checked every six hours.', 'livingdraft-core' ); ?>
		</span>
	</p>
	<?php
}
