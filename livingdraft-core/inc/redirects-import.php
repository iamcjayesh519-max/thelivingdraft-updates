<?php
/**
 * Redirect imports from Rank Math, Yoast SEO Premium, and the
 * Redirection plugin by John Godley.
 *
 * ---------------------------------------------------------------
 * WHY EACH LIVES SOMEWHERE DIFFERENT
 *
 *   Rank Math          {prefix}rank_math_redirections table
 *                      `sources` is a serialized array of
 *                      {pattern, comparison} objects — one row can
 *                      match multiple source URLs.
 *
 *   Yoast SEO Premium  Option `wpseo-premium-redirects-base`
 *                      Associative array `source_path => {url, type, ...}`.
 *                      Regex redirects live in a separate option
 *                      `wpseo-premium-redirects-export-regex-base`.
 *                      Yoast FREE has no redirect manager, so
 *                      absence of these options usually means "you
 *                      never had Yoast Premium".
 *
 *   Redirection        {prefix}redirection_items table (John Godley
 *   (plugin)           plugin, ~2M installs — most WP sites
 *                      that "have Yoast SEO redirects" actually mean
 *                      this).
 *
 * ---------------------------------------------------------------
 * WHAT WE DO AND DON'T IMPORT
 *
 * Import: exact source-path → target-URL mappings, preserving the
 *         HTTP redirect code (301 / 302 / 307). Notes column marks
 *         the import source for later auditing.
 *
 * Skip:   regex, pattern, "contains", "starts with", "ends with",
 *         and any other match type that isn't an exact URL. Our
 *         storage is exact-path only. We surface a count of what
 *         we skipped so a user knows there's more they need to
 *         handle by hand or via .htaccess.
 *
 * Skip:   disabled / inactive redirects. If Rank Math or Redirection
 *         had it turned off, we don't quietly turn it back on.
 *
 * Skip:   redirects that would collide with an existing one on our
 *         side. The importer never overwrites — if you already
 *         imported once and edited, re-running won't wipe your
 *         changes.
 *
 * @package LivingDraftCore
 * @since   3.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ==================================================================
 * 1. SOURCE DETECTION
 *
 * Each detector returns:
 *   [ 'available' => bool,      // Data source exists on this site
 *     'count'     => int,       // Number of redirects we WOULD import
 *     'skipped'   => int,       // Rules we'd skip (regex, disabled)
 *     'note'      => string,    // Human summary for the UI
 *   ]
 * ================================================================== */

/**
 * Rank Math redirect detector.
 */
function livingdraft_redirects_detect_rank_math() {
	global $wpdb;
	$table = $wpdb->prefix . 'rank_math_redirections';

	// Table absent = Rank Math never had redirects here.
	// Silence errors so we don't spam wp-debug.log on sites where
	// the table doesn't exist.
	$suppress = $wpdb->suppress_errors( true );
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	$wpdb->suppress_errors( $suppress );
	if ( $exists !== $table ) {
		return array( 'available' => false, 'count' => 0, 'skipped' => 0, 'note' => __( 'Rank Math redirections table not found.', 'livingdraft-core' ) );
	}

	// Cursor over every active row, count exact vs skipped.
	$rows = $wpdb->get_results( "SELECT sources, status FROM {$table}" );
	$importable = 0;
	$skipped    = 0;
	foreach ( (array) $rows as $row ) {
		if ( 'active' !== (string) $row->status ) {
			$skipped++;
			continue;
		}
		$sources = maybe_unserialize( $row->sources );
		if ( ! is_array( $sources ) ) {
			$skipped++;
			continue;
		}
		foreach ( $sources as $src ) {
			$comparison = isset( $src['comparison'] ) ? (string) $src['comparison'] : 'exact';
			if ( 'exact' === $comparison ) {
				$importable++;
			} else {
				$skipped++;
			}
		}
	}

	return array(
		'available' => true,
		'count'     => $importable,
		'skipped'   => $skipped,
		'note'      => sprintf(
			/* translators: 1: importable count, 2: skipped count */
			__( '%1$d exact redirects importable, %2$d skipped (regex, pattern, disabled).', 'livingdraft-core' ),
			$importable,
			$skipped
		),
	);
}

/**
 * Yoast Premium redirect detector.
 */
function livingdraft_redirects_detect_yoast() {
	$plain = get_option( 'wpseo-premium-redirects-base', array() );
	$regex = get_option( 'wpseo-premium-redirects-export-regex-base', array() );

	if ( ! is_array( $plain ) && ! is_array( $regex ) ) {
		return array( 'available' => false, 'count' => 0, 'skipped' => 0, 'note' => __( 'No Yoast Premium redirects option found. (Yoast Free doesn\'t have redirects.)', 'livingdraft-core' ) );
	}

	// Yoast stores plain redirects as source => data. We import all of
	// them. Regex ones are skipped (our storage is exact-only).
	$importable = is_array( $plain ) ? count( $plain ) : 0;
	$skipped    = is_array( $regex ) ? count( $regex ) : 0;

	if ( 0 === $importable && 0 === $skipped ) {
		return array( 'available' => false, 'count' => 0, 'skipped' => 0, 'note' => __( 'Yoast Premium option is present but empty.', 'livingdraft-core' ) );
	}

	return array(
		'available' => true,
		'count'     => $importable,
		'skipped'   => $skipped,
		'note'      => sprintf(
			/* translators: 1: importable count, 2: skipped count */
			__( '%1$d plain redirects importable, %2$d regex redirects skipped (not supported by this plugin\'s storage).', 'livingdraft-core' ),
			$importable,
			$skipped
		),
	);
}

/**
 * Redirection plugin (John Godley) detector.
 */
function livingdraft_redirects_detect_redirection() {
	global $wpdb;
	$table = $wpdb->prefix . 'redirection_items';

	$suppress = $wpdb->suppress_errors( true );
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	$wpdb->suppress_errors( $suppress );
	if ( $exists !== $table ) {
		return array( 'available' => false, 'count' => 0, 'skipped' => 0, 'note' => __( 'Redirection plugin table not found.', 'livingdraft-core' ) );
	}

	// Only "enabled" URL-action rows with a plain URL source count as
	// importable. Regex rows, login/referrer/agent/etc matchers, and
	// disabled rows are all skipped.
	$importable = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'enabled' AND regex = 0 AND action_type = 'url' AND match_type = 'url'" );
	$skipped    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE NOT (status = 'enabled' AND regex = 0 AND action_type = 'url' AND match_type = 'url')" );

	return array(
		'available' => true,
		'count'     => $importable,
		'skipped'   => $skipped,
		'note'      => sprintf(
			/* translators: 1: importable count, 2: skipped count */
			__( '%1$d URL redirects importable, %2$d skipped (regex, disabled, or non-URL match types).', 'livingdraft-core' ),
			$importable,
			$skipped
		),
	);
}

/**
 * Combined snapshot for the admin UI. Called once per page render.
 */
function livingdraft_redirects_available_sources() {
	return array(
		'rank_math'   => livingdraft_redirects_detect_rank_math(),
		'yoast'       => livingdraft_redirects_detect_yoast(),
		'redirection' => livingdraft_redirects_detect_redirection(),
	);
}

/* ==================================================================
 * 2. IMPORT LOGIC
 *
 * Each importer returns [ 'imported' => int, 'skipped' => int,
 * 'existing' => int ]. `existing` is how many rows we saw but did
 * not overwrite because a redirect with the same source already
 * exists on our side.
 * ================================================================== */

/**
 * Small helper: normalise a source path to what our storage expects.
 * Rank Math stores paths without a leading slash for some cases;
 * Yoast is usually rooted. livingdraft_redirects_add() also
 * trailing-slashes, so we don't do that here.
 */
function livingdraft_redirects_normalise_source( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return '';
	}
	// If a full URL was stored, strip the host so we get a path.
	if ( 0 === strpos( $raw, 'http://' ) || 0 === strpos( $raw, 'https://' ) ) {
		$path = wp_parse_url( $raw, PHP_URL_PATH );
		if ( is_string( $path ) && '' !== $path ) {
			$raw = $path;
		}
	}
	// Always leading-slash.
	if ( '/' !== $raw[0] ) {
		$raw = '/' . $raw;
	}
	return $raw;
}

/**
 * Import from Rank Math.
 */
function livingdraft_redirects_import_rank_math() {
	global $wpdb;
	$table = $wpdb->prefix . 'rank_math_redirections';

	// Detector already verified the table exists; call it again for
	// safety in case the caller bypassed the UI.
	$check = livingdraft_redirects_detect_rank_math();
	if ( ! $check['available'] ) {
		return array( 'imported' => 0, 'skipped' => 0, 'existing' => 0 );
	}

	$rows = $wpdb->get_results( "SELECT sources, url_to, header_code, status FROM {$table}" );

	$imported = 0;
	$skipped  = 0;
	$existing = 0;

	foreach ( (array) $rows as $row ) {
		if ( 'active' !== (string) $row->status ) {
			$skipped++;
			continue;
		}
		$sources = maybe_unserialize( $row->sources );
		if ( ! is_array( $sources ) ) {
			$skipped++;
			continue;
		}
		$target = (string) $row->url_to;
		$type   = (int) $row->header_code;
		if ( ! in_array( $type, array( 301, 302, 307 ), true ) ) {
			$type = 301;
		}

		foreach ( $sources as $src ) {
			$comparison = isset( $src['comparison'] ) ? (string) $src['comparison'] : 'exact';
			if ( 'exact' !== $comparison ) {
				$skipped++;
				continue;
			}
			$pattern = isset( $src['pattern'] ) ? livingdraft_redirects_normalise_source( $src['pattern'] ) : '';
			if ( '' === $pattern || '' === $target ) {
				$skipped++;
				continue;
			}
			$result = livingdraft_redirects_import_row( $pattern, $target, $type, 'Rank Math' );
			if ( 'imported' === $result ) {
				$imported++;
			} elseif ( 'existing' === $result ) {
				$existing++;
			} else {
				$skipped++;
			}
		}
	}

	return array( 'imported' => $imported, 'skipped' => $skipped, 'existing' => $existing );
}

/**
 * Import from Yoast Premium.
 */
function livingdraft_redirects_import_yoast() {
	$plain = get_option( 'wpseo-premium-redirects-base', array() );
	if ( ! is_array( $plain ) || empty( $plain ) ) {
		return array( 'imported' => 0, 'skipped' => 0, 'existing' => 0 );
	}

	$imported = 0;
	$skipped  = 0;
	$existing = 0;

	// Yoast format: array( '/old/' => array( 'url' => '/new/', 'type' => '301', 'origin' => '/old/', 'format' => 'plain' ) )
	foreach ( $plain as $source => $data ) {
		if ( ! is_array( $data ) ) {
			$skipped++;
			continue;
		}
		$target = isset( $data['url'] ) ? (string) $data['url'] : '';
		$type   = isset( $data['type'] ) ? (int) $data['type'] : 301;
		if ( ! in_array( $type, array( 301, 302, 307, 410, 451 ), true ) ) {
			$type = 301;
		}
		// Skip 410 (gone) and 451 (legal reasons) — our storage doesn't
		// model non-redirect responses. Note them as skipped so the UI
		// can show the count.
		if ( in_array( $type, array( 410, 451 ), true ) ) {
			$skipped++;
			continue;
		}
		$source = livingdraft_redirects_normalise_source( $source );
		if ( '' === $source || '' === $target ) {
			$skipped++;
			continue;
		}
		$result = livingdraft_redirects_import_row( $source, $target, $type, 'Yoast Premium' );
		if ( 'imported' === $result ) {
			$imported++;
		} elseif ( 'existing' === $result ) {
			$existing++;
		} else {
			$skipped++;
		}
	}

	return array( 'imported' => $imported, 'skipped' => $skipped, 'existing' => $existing );
}

/**
 * Import from the Redirection plugin.
 */
function livingdraft_redirects_import_redirection() {
	global $wpdb;
	$table = $wpdb->prefix . 'redirection_items';

	$check = livingdraft_redirects_detect_redirection();
	if ( ! $check['available'] ) {
		return array( 'imported' => 0, 'skipped' => 0, 'existing' => 0 );
	}

	// Only enabled URL-to-URL rows.
	$rows = $wpdb->get_results(
		"SELECT url, action_code, action_data
		 FROM {$table}
		 WHERE status = 'enabled'
		   AND regex = 0
		   AND action_type = 'url'
		   AND match_type = 'url'"
	);

	$imported = 0;
	$skipped  = 0;
	$existing = 0;

	foreach ( (array) $rows as $row ) {
		$source = livingdraft_redirects_normalise_source( $row->url );
		$target = (string) $row->action_data;
		$type   = (int) $row->action_code;
		if ( ! in_array( $type, array( 301, 302, 307 ), true ) ) {
			$type = 301;
		}
		if ( '' === $source || '' === $target ) {
			$skipped++;
			continue;
		}
		$result = livingdraft_redirects_import_row( $source, $target, $type, 'Redirection plugin' );
		if ( 'imported' === $result ) {
			$imported++;
		} elseif ( 'existing' === $result ) {
			$existing++;
		} else {
			$skipped++;
		}
	}

	return array( 'imported' => $imported, 'skipped' => $skipped, 'existing' => $existing );
}

/**
 * Single-row importer: check for existing, add if new, return status.
 *
 * @return string 'imported', 'existing', or 'skipped'.
 */
function livingdraft_redirects_import_row( $source, $target, $type, $source_label ) {
	global $wpdb;
	$table = $wpdb->prefix . 'livingdraft_redirects';

	// livingdraft_redirects_add() trailing-slashes the source before
	// storing, so match its normalisation before checking existence.
	$normalised = trailingslashit( $source );

	$existing = $wpdb->get_var(
		$wpdb->prepare( "SELECT id FROM {$table} WHERE source_path = %s LIMIT 1", $normalised )
	);
	if ( $existing ) {
		return 'existing';
	}

	$note = sprintf(
		/* translators: %s: import source name (Rank Math, Yoast Premium, Redirection plugin) */
		__( 'Imported from %s', 'livingdraft-core' ),
		$source_label
	);
	$id = livingdraft_redirects_add( $source, $target, $type, $note, false );
	return $id ? 'imported' : 'skipped';
}

/* ==================================================================
 * 3. ADMIN — form handler
 *
 * Hooked into the same `admin_init` path the other redirects
 * actions use. Uses its own nonce so it can't accidentally trigger
 * from a stale CSV import form.
 * ================================================================== */

function livingdraft_redirects_import_handle_actions() {
	if ( ! isset( $_GET['page'] ) || 'livingdraft-redirects' !== $_GET['page'] ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! isset( $_POST['ld_redirects_import_source'] ) ) {
		return;
	}
	check_admin_referer( 'livingdraft_redirects_import_source' );

	$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';

	switch ( $source ) {
		case 'rank_math':
			$result = livingdraft_redirects_import_rank_math();
			break;
		case 'yoast':
			$result = livingdraft_redirects_import_yoast();
			break;
		case 'redirection':
			$result = livingdraft_redirects_import_redirection();
			break;
		default:
			wp_safe_redirect( add_query_arg( array( 'page' => 'livingdraft-redirects', 'tab' => 'import', 'error' => 'bad_source' ), admin_url( 'admin.php' ) ) );
			exit;
	}

	wp_safe_redirect( add_query_arg(
		array(
			'page'      => 'livingdraft-redirects',
			'tab'       => 'import',
			'imported'  => (int) $result['imported'],
			'existing'  => (int) $result['existing'],
			'skipped'   => (int) $result['skipped'],
			'from'      => $source,
		),
		admin_url( 'admin.php' )
	) );
	exit;
}
add_action( 'admin_init', 'livingdraft_redirects_import_handle_actions' );

/**
 * Render helper for the Import tab. Called from redirects-admin.php.
 * Left in this file so all import logic lives in one place.
 */
function livingdraft_redirects_import_render_sources() {
	$sources = livingdraft_redirects_available_sources();

	$labels = array(
		'rank_math'   => __( 'Rank Math SEO', 'livingdraft-core' ),
		'yoast'       => __( 'Yoast SEO Premium', 'livingdraft-core' ),
		'redirection' => __( 'Redirection plugin (John Godley)', 'livingdraft-core' ),
	);

	// Post-import notice — read once, show once.
	if ( isset( $_GET['from'] ) && isset( $_GET['imported'] ) ) {
		$from     = sanitize_key( wp_unslash( $_GET['from'] ) );
		$imported = (int) $_GET['imported'];
		$existing = isset( $_GET['existing'] ) ? (int) $_GET['existing'] : 0;
		$skipped  = isset( $_GET['skipped'] ) ? (int) $_GET['skipped'] : 0;
		$label    = isset( $labels[ $from ] ) ? $labels[ $from ] : $from;
		echo '<div class="notice notice-success is-dismissible"><p>';
		echo esc_html( sprintf(
			/* translators: 1: source name, 2: imported, 3: skipped as existing, 4: skipped as unsupported */
			__( 'Imported from %1$s: %2$d added, %3$d skipped (already existed), %4$d skipped (unsupported type or disabled).', 'livingdraft-core' ),
			$label,
			$imported,
			$existing,
			$skipped
		) );
		echo '</p></div>';
	}
	?>
	<div style="background:#fff;padding:16px;border:1px solid #c3c4c7;margin-bottom:20px">
		<h3 style="margin-top:0"><?php esc_html_e( 'Import from another plugin', 'livingdraft-core' ); ?></h3>
		<p style="color:#666;max-width:65ch"><?php esc_html_e( 'Copies redirects from Rank Math, Yoast Premium, or the Redirection plugin into this plugin\'s storage. Existing entries here are never overwritten — safe to re-run. Only exact-URL rules are imported; regex/pattern rules are skipped (this plugin\'s storage is exact-path only). The other plugin\'s data is left untouched.', 'livingdraft-core' ); ?></p>

		<?php foreach ( $sources as $slug => $info ) : ?>
			<div style="margin-top:14px;padding:14px 16px;background:#fafaf7;border:1px solid #e5e5e5;display:flex;align-items:center;gap:16px">
				<div style="flex:1">
					<div style="font-family:var(--tld-mono, monospace);font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#666"><?php echo esc_html( $labels[ $slug ] ); ?></div>
					<div style="margin-top:4px;color:<?php echo $info['available'] ? '#333' : '#999'; ?>"><?php echo esc_html( $info['note'] ); ?></div>
				</div>
				<?php if ( $info['available'] && $info['count'] > 0 ) : ?>
					<form method="post" style="margin:0" onsubmit="return confirm('<?php echo esc_js( sprintf( __( 'Import %1$d redirects from %2$s? Nothing existing will be overwritten.', 'livingdraft-core' ), $info['count'], $labels[ $slug ] ) ); ?>');">
						<?php wp_nonce_field( 'livingdraft_redirects_import_source' ); ?>
						<input type="hidden" name="source" value="<?php echo esc_attr( $slug ); ?>">
						<button type="submit" name="ld_redirects_import_source" class="button button-primary">
							<?php echo esc_html( sprintf( _n( 'Import %d redirect', 'Import %d redirects', $info['count'], 'livingdraft-core' ), $info['count'] ) ); ?>
						</button>
					</form>
				<?php else : ?>
					<div style="color:#999;font-size:12px;font-style:italic"><?php esc_html_e( 'Nothing to import', 'livingdraft-core' ); ?></div>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
}
