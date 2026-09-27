<?php
/**
 * Redirections — send old URLs to their new home.
 *
 * === WHAT THIS MODULE DOES ===
 *
 *   1. Serves redirects (301, 302, 307, 308) and "Gone" answers (410)
 *      from one table of exact paths.
 *   2. PROPOSES a redirect when a published story's URL changes, or when a
 *      story is unpublished or deleted. Proposals wait under "Awaiting
 *      approval" and do nothing until an editor approves them (4.8.0).
 *   3. Logs 404s from real readers so they can be turned into redirects.
 *
 * === THE RULES EVERY REDIRECT PASSES THROUGH (4.8.0) ===
 *
 * Every way a redirect can be created — the form, an edit, an approval,
 * the Suggestions tab, CSV, the Rank Math / Yoast / Redirection importers
 * and the automatic proposals — goes through livingdraft_redirects_save().
 * One function, one set of rules:
 *
 *   - The "From" path is normalised the same way the incoming request is:
 *     full URLs become paths, a leading and trailing slash are added, and
 *     percent-encoding is decoded. A redirect that is saved is a redirect
 *     that can match.
 *   - A redirect may not point at itself, and may not complete a loop.
 *   - A redirect that would land on another redirect is pointed straight
 *     at the final destination, and redirects that pointed at this one are
 *     re-pointed too. Readers and search engines always take one hop.
 *   - A redirect may not hide a live story unless the editor says so.
 *   - An automatic proposal never replaces a redirect a person made.
 *   - Saving a redirect clears the matching 404 from the log and purges
 *     that URL from the page cache.
 *
 * === WORDPRESS'S OWN FALLBACK ===
 *
 * WordPress remembers a post's old slugs and sends readers on by itself,
 * and guesses the right post from a broken address. While a proposal waits
 * for approval these keep readers from a dead end. Both can be switched off
 * in Settings for a strict approval-only site.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------
 * 1. DATABASE
 * ------------------------------------------------------------------ */

define( 'LIVINGDRAFT_REDIRECTS_DB_VERSION', '1.1' );

/** 404 log states. */
define( 'LIVINGDRAFT_404_OPEN', 0 );
define( 'LIVINGDRAFT_404_FIXED', 1 );
define( 'LIVINGDRAFT_404_IGNORED', 2 );

/**
 * Redirects table name.
 *
 * @return string
 */
function livingdraft_redirects_table() {
	global $wpdb;
	return $wpdb->prefix . 'livingdraft_redirects';
}

/**
 * 404 log table name.
 *
 * @return string
 */
function livingdraft_404s_table() {
	global $wpdb;
	return $wpdb->prefix . 'livingdraft_404s';
}

/**
 * The response codes a row can carry.
 *
 * @return int[]
 */
function livingdraft_redirects_types() {
	return array( 301, 302, 307, 308, 410 );
}

/**
 * Create or upgrade the tables. Runs only when the stored schema version
 * is behind.
 */
function livingdraft_redirects_maybe_install() {
	$installed = get_option( 'livingdraft_redirects_db_version' );

	if ( LIVINGDRAFT_REDIRECTS_DB_VERSION === $installed ) {
		return;
	}

	global $wpdb;
	$charset = $wpdb->get_charset_collate();

	$redirects_table = livingdraft_redirects_table();
	$log_table       = livingdraft_404s_table();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	// Two spaces after PRIMARY KEY are required by dbDelta().
	$sql1 = "CREATE TABLE $redirects_table (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		source_path VARCHAR(2048) NOT NULL,
		target_url VARCHAR(2048) NOT NULL DEFAULT '',
		redirect_type SMALLINT UNSIGNED NOT NULL DEFAULT 301,
		status VARCHAR(20) NOT NULL DEFAULT 'active',
		post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
		last_hit DATETIME DEFAULT NULL,
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at DATETIME DEFAULT NULL,
		notes VARCHAR(255) DEFAULT NULL,
		auto_generated TINYINT(1) NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY source_path_idx (source_path(191)),
		KEY status_idx (status)
	) $charset;";

	$sql2 = "CREATE TABLE $log_table (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		url_path VARCHAR(2048) NOT NULL,
		referrer VARCHAR(2048) DEFAULT NULL,
		user_agent VARCHAR(500) DEFAULT NULL,
		hits BIGINT UNSIGNED NOT NULL DEFAULT 1,
		last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		resolved TINYINT(1) NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY url_path_idx (url_path(191)),
		KEY last_seen_idx (last_seen)
	) $charset;";

	dbDelta( $sql1 );
	dbDelta( $sql2 );

	// Existing site coming from 1.0: clean up what the old rules let in.
	// The repair needs permalinks, which are not ready at plugins_loaded,
	// so it runs on init.
	if ( $installed && version_compare( (string) $installed, '1.1', '<' ) ) {
		update_option( 'livingdraft_redirects_needs_migration', 1, true );
	}

	update_option( 'livingdraft_redirects_db_version', LIVINGDRAFT_REDIRECTS_DB_VERSION );
	livingdraft_redirects_changed();
}
add_action( 'plugins_loaded', 'livingdraft_redirects_maybe_install' );

/**
 * Run the 1.0 → 1.1 repair once, when permalinks are available.
 */
function livingdraft_redirects_maybe_migrate() {
	if ( ! get_option( 'livingdraft_redirects_needs_migration' ) ) {
		return;
	}
	delete_option( 'livingdraft_redirects_needs_migration' );
	livingdraft_redirects_migrate_1_1();
}
add_action( 'init', 'livingdraft_redirects_maybe_migrate', 99 );

/**
 * One-time repair of data saved under the 1.0 rules.
 *
 *   1. Normalise every "From" path so it can actually match.
 *   2. Merge rows that normalise to the same path (a person's row wins
 *      over an automatic one, then the newest wins).
 *   3. Remove automatic rows that hide a live story — the "renamed and
 *      renamed back" loop.
 *   4. Point every chain straight at its final destination.
 *   5. Park any loop that is left under Awaiting approval for review.
 *
 * Existing automatic redirects stay live: they were already serving
 * readers. Only new proposals need approval.
 *
 * The counts are kept so the Redirections page can say what changed.
 */
function livingdraft_redirects_migrate_1_1() {
	global $wpdb;
	$table  = livingdraft_redirects_table();
	$report = array(
		'normalised' => 0,
		'merged'     => 0,
		'unhidden'   => 0,
		'flattened'  => 0,
		'loops'      => 0,
	);

	$rows = $wpdb->get_results( "SELECT id, source_path, auto_generated FROM $table ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$by_path = array();
	foreach ( (array) $rows as $row ) {
		$norm = livingdraft_redirects_normalize_path( $row->source_path );

		if ( '' === $norm || '/' === $norm ) {
			$wpdb->delete( $table, array( 'id' => (int) $row->id ), array( '%d' ) );
			$report['merged']++;
			continue;
		}

		if ( isset( $by_path[ $norm ] ) ) {
			$keep = $by_path[ $norm ];
			// A person's row beats an automatic one; otherwise the newer row wins.
			$drop_current = ( ! $keep->auto_generated && $row->auto_generated );
			$loser        = $drop_current ? $row : $keep;
			$winner       = $drop_current ? $keep : $row;
			$wpdb->delete( $table, array( 'id' => (int) $loser->id ), array( '%d' ) );
			$report['merged']++;
			$by_path[ $norm ] = $winner;
			if ( $drop_current ) {
				continue;
			}
		} else {
			$by_path[ $norm ] = $row;
		}

		if ( $norm !== $row->source_path ) {
			$wpdb->update( $table, array( 'source_path' => $norm ), array( 'id' => (int) $row->id ), array( '%s' ), array( '%d' ) );
			$report['normalised']++;
		}
	}

	// Automatic rows that sit on top of a live story.
	$autos = $wpdb->get_results( "SELECT id, source_path FROM $table WHERE auto_generated = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( (array) $autos as $row ) {
		if ( livingdraft_redirects_live_post_for_path( $row->source_path ) ) {
			$wpdb->delete( $table, array( 'id' => (int) $row->id ), array( '%d' ) );
			$report['unhidden']++;
		}
	}

	$repair = livingdraft_redirects_repair_all();

	$report['flattened'] = $repair['flattened'];
	$report['loops']     = $repair['loops'];

	if ( array_sum( $report ) > 0 ) {
		update_option( 'livingdraft_redirects_migration_report', $report, false );
	}
}

/**
 * Flatten every chain and park every loop. Safe to run at any time.
 *
 * @return array{flattened:int,loops:int}
 */
function livingdraft_redirects_repair_all() {
	global $wpdb;
	$table = livingdraft_redirects_table();
	$out   = array(
		'flattened' => 0,
		'loops'     => 0,
	);

	$rows = $wpdb->get_results( "SELECT id, source_path, target_url, redirect_type, notes FROM $table WHERE status = 'active' AND redirect_type <> 410" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	foreach ( (array) $rows as $row ) {
		$chain = livingdraft_redirects_resolve_chain( $row->source_path, $row->target_url, (int) $row->id );

		if ( $chain['loop'] ) {
			$wpdb->update(
				$table,
				array(
					'status' => 'pending',
					'notes'  => mb_substr( __( 'Loop found — review before approving.', 'livingdraft-core' ) . ' ' . (string) $row->notes, 0, 255 ),
				),
				array( 'id' => (int) $row->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			$out['loops']++;
			continue;
		}

		if ( $chain['hops'] > 0 && $chain['target'] !== $row->target_url ) {
			$wpdb->update( $table, array( 'target_url' => $chain['target'] ), array( 'id' => (int) $row->id ), array( '%s' ), array( '%d' ) );
			$out['flattened']++;
		}
	}

	livingdraft_redirects_changed();
	return $out;
}

/* ------------------------------------------------------------------
 * 2. NORMALISATION — one definition of "the same URL"
 * ------------------------------------------------------------------ */

/**
 * Turn anything a person might paste into the path the site will compare
 * against: '/decoded/path/'.
 *
 *   https://site.com/Old%20Story/?x=1#top  →  /Old Story/
 *   old-story                              →  /old-story/
 *   /economy/%e0%a4%ac%e0%a4%9c%e0%a4%9f/  →  /economy/बजट/
 *
 * @param string $raw Path or URL.
 * @return string '' when nothing usable is left.
 */
function livingdraft_redirects_normalize_path( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return '';
	}

	// Strip control characters (newlines pasted from spreadsheets etc.).
	$raw = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $raw );

	if ( preg_match( '#^([a-z][a-z0-9+.\-]*:)?//#i', $raw ) ) {
		$path = wp_parse_url( $raw, PHP_URL_PATH );
		$raw  = is_string( $path ) ? $path : '/';
	} else {
		$raw = (string) preg_replace( '/[?#].*$/s', '', $raw );
	}

	// rawurldecode, not urldecode: a literal "+" in a path is a plus sign.
	$raw = rawurldecode( $raw );

	if ( '' === $raw || '/' !== $raw[0] ) {
		$raw = '/' . $raw;
	}

	$raw = (string) preg_replace( '#/{2,}#', '/', $raw );
	$raw = trailingslashit( $raw );

	return mb_substr( $raw, 0, 2000 );
}

/**
 * Clean a redirect target. Relative paths become absolute on this site.
 *
 * @param string $raw Target as typed.
 * @return string '' when unusable.
 */
function livingdraft_redirects_normalize_target( $raw ) {
	$raw = trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $raw ) );
	if ( '' === $raw ) {
		return '';
	}

	if ( '/' === $raw[0] && ( ! isset( $raw[1] ) || '/' !== $raw[1] ) ) {
		$raw = livingdraft_redirects_origin() . $raw;
	}

	$url = esc_url_raw( $raw, array( 'http', 'https' ) );
	if ( '' === $url ) {
		return '';
	}

	// A real host: letters, digits, dots and hyphens (or an IP / localhost),
	// with an optional port. Stops "http://some text" from being saved.
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	// Unicode letters are allowed so international domains (बजट.भारत) work.
	if ( ! preg_match( '/^[\p{L}\p{M}\p{N}]([\p{L}\p{M}\p{N}\-]*[\p{L}\p{M}\p{N}])?(\.[\p{L}\p{M}\p{N}]([\p{L}\p{M}\p{N}\-]*[\p{L}\p{M}\p{N}])?)*$/u', $host ) ) {
		return '';
	}
	if ( false === strpos( $host, '.' ) && 'localhost' !== strtolower( $host ) && livingdraft_redirects_bare_host( $host ) !== livingdraft_redirects_bare_host( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) ) {
		return '';
	}

	return $url;
}

/**
 * Scheme and host of the site, no trailing slash.
 *
 * @return string
 */
function livingdraft_redirects_origin() {
	$home   = home_url( '/' );
	$scheme = wp_parse_url( $home, PHP_URL_SCHEME );
	$host   = wp_parse_url( $home, PHP_URL_HOST );
	$port   = wp_parse_url( $home, PHP_URL_PORT );

	return ( $scheme ? $scheme : 'https' ) . '://' . $host . ( $port ? ':' . $port : '' );
}

/**
 * Compare hosts, ignoring case and a leading "www.".
 *
 * @param string $host Host.
 * @return string
 */
function livingdraft_redirects_bare_host( $host ) {
	$host = strtolower( (string) $host );
	return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
}

/**
 * If a URL points at this site, its normalised path; otherwise null.
 *
 * @param string $url URL or path.
 * @return string|null
 */
function livingdraft_redirects_local_path( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return null;
	}

	if ( '/' === $url[0] && ( ! isset( $url[1] ) || '/' !== $url[1] ) ) {
		return livingdraft_redirects_normalize_path( $url );
	}

	$host = wp_parse_url( $url, PHP_URL_HOST );
	if ( ! $host ) {
		return null;
	}

	$home_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	if ( livingdraft_redirects_bare_host( $host ) !== livingdraft_redirects_bare_host( $home_host ) ) {
		return null;
	}

	return livingdraft_redirects_normalize_path( $url );
}

/**
 * A normalised path as a URL on this site (non-ASCII percent-encoded the
 * way WordPress writes permalinks).
 *
 * @param string $path Normalised path.
 * @return string
 */
function livingdraft_redirects_path_to_url( $path ) {
	$encoded = preg_replace_callback(
		'/[^\x21-\x7E]/',
		static function ( $m ) {
			return strtolower( rawurlencode( $m[0] ) );
		},
		(string) $path
	);
	return livingdraft_redirects_origin() . $encoded;
}

/* ------------------------------------------------------------------
 * 3. LOOKUPS
 * ------------------------------------------------------------------ */

/**
 * Called after every write: invalidates cached lookups and recounts.
 */
function livingdraft_redirects_changed() {
	global $wpdb;
	$table = livingdraft_redirects_table();

	update_option( 'livingdraft_redirects_generation', (int) get_option( 'livingdraft_redirects_generation', 1 ) + 1, true );

	$suppress = $wpdb->suppress_errors( true );
	$active   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status = 'active'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$pending  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status = 'pending'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->suppress_errors( $suppress );

	update_option( 'livingdraft_redirects_active_count', $active, true );
	update_option( 'livingdraft_redirects_pending_count', $pending, true );
	delete_transient( 'ld_sug_actionable' );
}

/**
 * Number of proposals waiting for approval.
 *
 * @return int
 */
function livingdraft_redirects_pending_count() {
	return (int) get_option( 'livingdraft_redirects_pending_count', 0 );
}

/**
 * The live row for a path, or null. Cached per request (and across
 * requests when a persistent object cache is present); skipped entirely
 * when no redirect is active.
 *
 * @param string $path Normalised path.
 * @return object|null
 */
function livingdraft_redirects_find_active( $path ) {
	if ( 0 === (int) get_option( 'livingdraft_redirects_active_count', 1 ) ) {
		return null;
	}

	$key   = (int) get_option( 'livingdraft_redirects_generation', 1 ) . ':' . md5( $path );
	$found = false;
	$row   = wp_cache_get( $key, 'livingdraft_redirects', false, $found );

	if ( $found ) {
		return $row ? $row : null;
	}

	global $wpdb;
	$table = livingdraft_redirects_table();
	$row   = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, source_path, target_url, redirect_type FROM $table WHERE source_path = %s AND status = 'active' LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$path
		)
	);

	wp_cache_set( $key, $row ? $row : 0, 'livingdraft_redirects', HOUR_IN_SECONDS );
	return $row ? $row : null;
}

/**
 * Any row (any status) for a path.
 *
 * @param string $path Normalised path.
 * @return object|null
 */
function livingdraft_redirects_get_by_source( $path ) {
	global $wpdb;
	$table = livingdraft_redirects_table();
	return $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM $table WHERE source_path = %s LIMIT 1", $path ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);
}

/**
 * One row by id.
 *
 * @param int $id Row id.
 * @return object|null
 */
function livingdraft_redirects_get( $id ) {
	global $wpdb;
	$table = livingdraft_redirects_table();
	return $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM $table WHERE id = %d", (int) $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);
}

/**
 * The post that is live at exactly this path, or 0.
 *
 * url_to_postid() is forgiving — it will find a post from a URL with the
 * wrong category in it — so the post's real permalink is compared too.
 *
 * @param string $path Normalised path.
 * @return int
 */
function livingdraft_redirects_live_post_for_path( $path ) {
	$path = livingdraft_redirects_normalize_path( $path );
	if ( '' === $path || '/' === $path ) {
		return 0;
	}

	$post_id = url_to_postid( livingdraft_redirects_path_to_url( $path ) );
	if ( ! $post_id || 'publish' !== get_post_status( $post_id ) ) {
		return 0;
	}

	$live = livingdraft_redirects_normalize_path( (string) get_permalink( $post_id ) );
	return $live === $path ? (int) $post_id : 0;
}

/**
 * Follow a target through the active redirect table.
 *
 * @param string $source     Normalised source of the redirect being saved.
 * @param string $target     Its target URL.
 * @param int    $exclude_id Row being edited, ignored while following.
 * @return array{target:string,loop:bool,hops:int,gone:bool}
 */
function livingdraft_redirects_resolve_chain( $source, $target, $exclude_id = 0 ) {
	$seen    = array( $source => true );
	$current = $target;
	$hops    = 0;

	while ( $hops < 10 ) {
		$path = livingdraft_redirects_local_path( $current );
		if ( null === $path ) {
			break; // Another site: the end of the chain.
		}
		if ( isset( $seen[ $path ] ) ) {
			return array( 'target' => $current, 'loop' => true, 'hops' => $hops, 'gone' => false );
		}
		$seen[ $path ] = true;

		$row = livingdraft_redirects_find_active_uncached( $path, $exclude_id );
		if ( ! $row ) {
			break;
		}
		if ( 410 === (int) $row->redirect_type ) {
			return array( 'target' => $current, 'loop' => false, 'hops' => $hops, 'gone' => true );
		}

		$current = (string) $row->target_url;
		$hops++;
	}

	if ( $hops >= 10 ) {
		return array( 'target' => $current, 'loop' => true, 'hops' => $hops, 'gone' => false );
	}

	return array( 'target' => $current, 'loop' => false, 'hops' => $hops, 'gone' => false );
}

/**
 * Uncached lookup for use inside writes.
 *
 * @param string $path       Normalised path.
 * @param int    $exclude_id Row id to skip.
 * @return object|null
 */
function livingdraft_redirects_find_active_uncached( $path, $exclude_id = 0 ) {
	global $wpdb;
	$table = livingdraft_redirects_table();
	return $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, source_path, target_url, redirect_type FROM $table WHERE source_path = %s AND status = 'active' AND id <> %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$path,
			(int) $exclude_id
		)
	);
}

/* ------------------------------------------------------------------
 * 4. SAVING — the single write path
 * ------------------------------------------------------------------ */

/**
 * Create, replace or edit a redirect under the module's rules.
 *
 * @param array $args {
 *     @type int    $id          Row to edit (0 = new).
 *     @type string $source      Path or URL.
 *     @type string $target      URL or path (ignored for 410).
 *     @type int    $type        301|302|307|308|410.
 *     @type string $notes       Up to 255 characters.
 *     @type bool   $auto        Automatic proposal.
 *     @type string $status      'active' or 'pending'.
 *     @type int    $post_id     Story this row is about (automatic rows).
 *     @type string $on_existing 'error' | 'skip' | 'replace' | 'keep_manual'.
 *     @type bool   $allow_live  Allow hiding a live story.
 * }
 * @return array{ok:bool,code:string,message:string,id:int,warnings:string[]}
 */
function livingdraft_redirects_save( $args ) {
	global $wpdb;
	$table = livingdraft_redirects_table();

	$args = wp_parse_args(
		$args,
		array(
			'id'          => 0,
			'source'      => '',
			'target'      => '',
			'type'        => 301,
			'notes'       => '',
			'auto'        => false,
			'status'      => 'active',
			'post_id'     => 0,
			'on_existing' => 'error',
			'allow_live'  => false,
		)
	);

	$fail = static function ( $code, $message, $id = 0 ) {
		return array( 'ok' => false, 'code' => $code, 'message' => $message, 'id' => (int) $id, 'warnings' => array() );
	};

	$warnings = array();
	$id       = (int) $args['id'];
	$status   = 'pending' === $args['status'] ? 'pending' : 'active';
	$type     = (int) $args['type'];
	if ( ! in_array( $type, livingdraft_redirects_types(), true ) ) {
		$type = 301;
	}

	$source = livingdraft_redirects_normalize_path( $args['source'] );
	if ( '' === $source || '/' === $source ) {
		return $fail( 'bad_source', __( 'The "From" path is empty or is the home page. Enter the old path, for example /old-headline/.', 'livingdraft-core' ) );
	}

	$target = '';
	if ( 410 !== $type ) {
		$target = livingdraft_redirects_normalize_target( $args['target'] );
		if ( '' === $target ) {
			return $fail( 'bad_target', __( 'The "To" address is missing or not a web address. Use a full URL or a path on this site starting with /.', 'livingdraft-core' ) );
		}
		if ( livingdraft_redirects_local_path( $target ) === $source ) {
			return $fail( 'self', __( 'A redirect cannot point at its own address.', 'livingdraft-core' ) );
		}
	}

	// Another row already uses this path?
	$existing = livingdraft_redirects_get_by_source( $source );
	if ( $id ) {
		if ( ! livingdraft_redirects_get( $id ) ) {
			return $fail( 'missing', __( 'That redirect no longer exists.', 'livingdraft-core' ) );
		}
		if ( $existing && (int) $existing->id !== $id ) {
			return $fail( 'duplicate', __( 'Another redirect already uses this "From" path. Edit that one instead.', 'livingdraft-core' ), (int) $existing->id );
		}
	} elseif ( $existing ) {
		switch ( $args['on_existing'] ) {
			case 'skip':
				return array( 'ok' => false, 'code' => 'existing', 'message' => __( 'A redirect for this path already exists.', 'livingdraft-core' ), 'id' => (int) $existing->id, 'warnings' => array() );
			case 'keep_manual':
				if ( ! (int) $existing->auto_generated ) {
					return array( 'ok' => false, 'code' => 'kept_manual', 'message' => __( 'A redirect you made already covers this path, so it was kept.', 'livingdraft-core' ), 'id' => (int) $existing->id, 'warnings' => array() );
				}
				$id = (int) $existing->id;
				break;
			case 'replace':
				$id = (int) $existing->id;
				break;
			default:
				return $fail( 'exists', __( 'A redirect for this path already exists. Edit it instead of adding a second one.', 'livingdraft-core' ), (int) $existing->id );
		}
	}

	// Would this hide a live story?
	if ( ! $args['allow_live'] ) {
		$live_id = livingdraft_redirects_live_post_for_path( $source );
		if ( $live_id ) {
			return $fail(
				'live',
				sprintf(
					/* translators: %s: post title. */
					__( 'This path is the live address of “%s”. A redirect here would hide the story. Tick "Redirect even though a story is live here" if that is what you want.', 'livingdraft-core' ),
					get_the_title( $live_id )
				)
			);
		}
	}

	// One hop only, and never a loop.
	if ( 410 !== $type ) {
		$chain = livingdraft_redirects_resolve_chain( $source, $target, $id );
		if ( $chain['loop'] ) {
			return $fail( 'loop', __( 'This would create a redirect loop: following the "To" address leads back here. Change the destination.', 'livingdraft-core' ) );
		}
		if ( $chain['hops'] > 0 ) {
			$target     = $chain['target'];
			$warnings[] = sprintf(
				/* translators: %s: final URL. */
				__( 'The destination was itself redirected, so this now points straight to %s.', 'livingdraft-core' ),
				$target
			);
		}
		if ( $chain['gone'] ) {
			$warnings[] = __( 'The destination is marked as Gone (410), so readers will land on a "removed" page.', 'livingdraft-core' );
		}

		$target_path = livingdraft_redirects_local_path( $target );
		if ( null !== $target_path && livingdraft_404_is_open( $target_path ) && ! livingdraft_redirects_live_post_for_path( $target_path ) ) {
			$warnings[] = __( 'The destination is in the 404 log — check that it opens.', 'livingdraft-core' );
		}
	}

	$now  = current_time( 'mysql' );
	$data = array(
		'source_path'    => $source,
		'target_url'     => $target,
		'redirect_type'  => $type,
		'status'         => $status,
		'post_id'        => (int) $args['post_id'],
		'notes'          => mb_substr( (string) $args['notes'], 0, 255 ),
		'auto_generated' => $args['auto'] ? 1 : 0,
		'updated_at'     => $now,
	);
	$format = array( '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%s' );

	if ( $id ) {
		$wpdb->update( $table, $data, array( 'id' => $id ), $format, array( '%d' ) );
	} else {
		$data['created_at'] = $now;
		$format[]           = '%s';
		$wpdb->insert( $table, $data, $format );
		$id = (int) $wpdb->insert_id;
	}

	if ( ! $id ) {
		return $fail( 'db', __( 'The database refused the write. Nothing was saved.', 'livingdraft-core' ) );
	}

	if ( 'active' === $status ) {
		$repointed = livingdraft_redirects_repoint_incoming( $source, $target, $type, $id );
		if ( $repointed > 0 ) {
			$warnings[] = sprintf(
				/* translators: %d: number of redirects. */
				_n( '%d older redirect pointed here and was updated to go straight to the new destination.', '%d older redirects pointed here and were updated to go straight to the new destination.', $repointed, 'livingdraft-core' ),
				$repointed
			);
		}
		livingdraft_404_mark_path( $source, LIVINGDRAFT_404_FIXED );
		livingdraft_redirects_purge_cache( array( $source ) );
	}

	livingdraft_redirects_changed();

	return array( 'ok' => true, 'code' => 'saved', 'message' => '', 'id' => $id, 'warnings' => $warnings );
}

/**
 * Rows that pointed at $source now go straight to where $source goes.
 *
 * @param string $source Normalised path that just became a redirect.
 * @param string $target Its final target ('' for 410).
 * @param int    $type   Its type.
 * @param int    $id     Its row id.
 * @return int Rows updated.
 */
function livingdraft_redirects_repoint_incoming( $source, $target, $type, $id ) {
	if ( 410 === (int) $type ) {
		return 0;
	}

	global $wpdb;
	$table = livingdraft_redirects_table();

	// Narrow in SQL by the last path segment, in the forms a URL may hold
	// it (decoded, or percent-encoded in either case), then compare exactly.
	$segments = array_values( array_filter( explode( '/', $source ), 'strlen' ) );
	$last     = (string) end( $segments );
	if ( '' === $last ) {
		return 0;
	}
	$encoded = rawurlencode( $last );
	$forms   = array_unique( array( $last, $encoded, strtolower( $encoded ) ) );
	$likes   = array();
	$params  = array( (int) $id );
	foreach ( $forms as $form ) {
		$likes[]  = 'target_url LIKE %s';
		$params[] = '%' . $wpdb->esc_like( $form ) . '%';
	}

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, source_path, target_url FROM $table WHERE id <> %d AND redirect_type <> 410 AND (" . implode( ' OR ', $likes ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$params
		)
	);

	$target_path = livingdraft_redirects_local_path( $target );
	$count       = 0;

	foreach ( (array) $rows as $row ) {
		if ( livingdraft_redirects_local_path( $row->target_url ) !== $source ) {
			continue;
		}
		if ( null !== $target_path && $target_path === $row->source_path ) {
			continue; // Would point at itself; the loop check already refused real loops.
		}
		$wpdb->update( $table, array( 'target_url' => $target ), array( 'id' => (int) $row->id ), array( '%s' ), array( '%d' ) );
		$count++;
	}

	return $count;
}

/**
 * Backward-compatible wrapper. A person's redirect replaces an existing
 * one; an automatic one never replaces a person's.
 *
 * @param string $source_path Path.
 * @param string $target_url  URL.
 * @param int    $type        Type.
 * @param string $notes       Notes.
 * @param bool   $auto        Automatic.
 * @return int|false
 */
function livingdraft_redirects_add( $source_path, $target_url, $type = 301, $notes = '', $auto = false ) {
	$result = livingdraft_redirects_save(
		array(
			'source'      => $source_path,
			'target'      => $target_url,
			'type'        => $type,
			'notes'       => $notes,
			'auto'        => $auto,
			'status'      => $auto ? 'pending' : 'active',
			'on_existing' => $auto ? 'keep_manual' : 'replace',
		)
	);
	return $result['ok'] ? (int) $result['id'] : false;
}

/**
 * Approve a waiting proposal. It passes the same checks as a new redirect,
 * against the site as it is now.
 *
 * @param int $id Row id.
 * @return array Same shape as livingdraft_redirects_save().
 */
function livingdraft_redirects_approve( $id ) {
	$row = livingdraft_redirects_get( $id );
	if ( ! $row || 'pending' !== $row->status ) {
		return array( 'ok' => false, 'code' => 'missing', 'message' => __( 'That proposal is no longer waiting.', 'livingdraft-core' ), 'id' => (int) $id, 'warnings' => array() );
	}

	return livingdraft_redirects_save(
		array(
			'id'      => (int) $row->id,
			'source'  => $row->source_path,
			'target'  => $row->target_url,
			'type'    => (int) $row->redirect_type,
			'notes'   => (string) $row->notes,
			'auto'    => (bool) $row->auto_generated,
			'post_id' => (int) $row->post_id,
			'status'  => 'active',
		)
	);
}

/**
 * Delete a redirect by id.
 *
 * @param int $id Row id.
 * @return bool
 */
function livingdraft_redirects_delete( $id ) {
	global $wpdb;
	$row = livingdraft_redirects_get( $id );
	$ok  = (bool) $wpdb->delete( livingdraft_redirects_table(), array( 'id' => (int) $id ), array( '%d' ) );
	if ( $ok && $row ) {
		livingdraft_redirects_purge_cache( array( $row->source_path ) );
	}
	livingdraft_redirects_changed();
	return $ok;
}

/* ------------------------------------------------------------------
 * 5. SERVING
 * ------------------------------------------------------------------ */

/**
 * Match the request and redirect, or answer 410.
 */
function livingdraft_redirects_run() {
	if ( is_admin() ) {
		return;
	}

	$uri  = wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$path = livingdraft_redirects_normalize_path( $uri );

	if ( '' === $path || '/' === $path ) {
		return;
	}

	$row = livingdraft_redirects_find_active( $path );
	if ( ! $row ) {
		return;
	}

	global $wpdb;
	$table = livingdraft_redirects_table();
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE $table SET hits = hits + 1, last_hit = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			current_time( 'mysql' ),
			(int) $row->id
		)
	);

	$type = (int) $row->redirect_type;

	if ( 410 === $type ) {
		livingdraft_redirects_serve_gone();
		return;
	}

	if ( ! in_array( $type, array( 301, 302, 307, 308 ), true ) ) {
		$type = 301;
	}

	$target = (string) $row->target_url;

	if ( (bool) get_option( 'livingdraft_redirects_preserve_query', true ) ) {
		$query = wp_parse_url( $uri, PHP_URL_QUERY );
		if ( is_string( $query ) && '' !== $query ) {
			$target = livingdraft_redirects_append_query( $target, $query );
		}
	}

	// Last line of defence against a loop that slipped in some other way.
	if ( livingdraft_redirects_local_path( $target ) === $path ) {
		return;
	}

	if ( 301 !== $type && 308 !== $type ) {
		nocache_headers();
	}

	// wp_safe_redirect() would refuse external hosts, and some targets are
	// external on purpose. Targets are written only by manage_options users
	// and by this module.
	wp_redirect( $target, $type, 'Living Draft' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
	exit;
}
add_action( 'template_redirect', 'livingdraft_redirects_run', 1 );

/**
 * Carry the reader's query string over, without overriding parameters the
 * target already sets.
 *
 * @param string $target URL.
 * @param string $query  Raw query string from the request.
 * @return string
 */
function livingdraft_redirects_append_query( $target, $query ) {
	$fragment = '';
	$hash_pos = strpos( $target, '#' );
	if ( false !== $hash_pos ) {
		$fragment = substr( $target, $hash_pos );
		$target   = substr( $target, 0, $hash_pos );
	}

	$existing_keys = array();
	$target_query  = wp_parse_url( $target, PHP_URL_QUERY );
	if ( is_string( $target_query ) ) {
		foreach ( explode( '&', $target_query ) as $pair ) {
			$existing_keys[ urldecode( strtok( $pair, '=' ) ) ] = true;
		}
	}

	$keep = array();
	foreach ( explode( '&', $query ) as $pair ) {
		if ( '' === $pair ) {
			continue;
		}
		$key = urldecode( (string) strtok( $pair, '=' ) );
		if ( isset( $existing_keys[ $key ] ) ) {
			continue;
		}
		$keep[] = $pair;
	}

	if ( empty( $keep ) ) {
		return $target . $fragment;
	}

	return $target . ( false === strpos( $target, '?' ) ? '?' : '&' ) . implode( '&', $keep ) . $fragment;
}

/**
 * Answer 410 Gone using the theme's 404 template.
 */
function livingdraft_redirects_serve_gone() {
	global $wp_query;

	$GLOBALS['livingdraft_redirects_serving_gone'] = true;

	if ( $wp_query instanceof WP_Query ) {
		$wp_query->set_404();
	}
	status_header( 410 );
	nocache_headers();

	$template = get_404_template();
	if ( $template ) {
		load_template( $template, false );
	} else {
		wp_die(
			esc_html__( 'This page has been removed.', 'livingdraft-core' ),
			esc_html__( 'Gone', 'livingdraft-core' ),
			array( 'response' => 410 )
		);
	}
	exit;
}

/**
 * Optionally switch off WordPress's own old-slug redirect, for sites that
 * want every redirect to be approved first.
 */
function livingdraft_redirects_core_fallback() {
	if ( ! (bool) get_option( 'livingdraft_redirects_core_fallback', true ) ) {
		// WordPress has two ways of sending a reader on by itself: the
		// old-slug redirect, and guessing a post from a broken address
		// ("state-budget" → "state-budget-2027"). Both are switched off.
		remove_action( 'template_redirect', 'wp_old_slug_redirect' );
		add_filter( 'do_redirect_guess_404_permalink', '__return_false' );
	}
}
add_action( 'template_redirect', 'livingdraft_redirects_core_fallback', 0 );

/**
 * Answer Chrome's private prefetch proxy. Without this file the proxy
 * reads a 404 as "do not prefetch", and search clicks lose a free speed-up.
 */
function livingdraft_redirects_traffic_advice() {
	if ( ! (bool) get_option( 'livingdraft_traffic_advice', true ) ) {
		return;
	}
	if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}

	$path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	if ( '/.well-known/traffic-advice' !== untrailingslashit( (string) $path ) ) {
		return;
	}

	status_header( 200 );
	header( 'Content-Type: application/trafficadvice+json' );
	header( 'Cache-Control: public, max-age=86400' );
	echo '[{"user_agent":"prefetch-proxy","fraction":1.0}]';
	exit;
}
add_action( 'init', 'livingdraft_redirects_traffic_advice', 0 );

/**
 * Purge URLs from the page caches this site is likely to run, so a new
 * redirect or 410 takes effect at once instead of when a cached 404 expires.
 *
 * @param string[] $paths Normalised paths.
 */
function livingdraft_redirects_purge_cache( $paths ) {
	foreach ( (array) $paths as $path ) {
		if ( '' === (string) $path ) {
			continue;
		}
		$url = livingdraft_redirects_path_to_url( $path );

		do_action( 'litespeed_purge_url', $url );

		if ( function_exists( 'rocket_clean_files' ) ) {
			rocket_clean_files( array( $url ) );
		}
		if ( function_exists( 'w3tc_flush_url' ) ) {
			w3tc_flush_url( $url );
		}
		if ( function_exists( 'wpsc_delete_url_cache' ) ) {
			wpsc_delete_url_cache( $url );
		}

		/**
		 * Purge one URL from any other cache.
		 *
		 * @since 4.8.0
		 * @param string $url Absolute URL.
		 */
		do_action( 'livingdraft_redirects_purge_url', $url );
	}
}

/* ------------------------------------------------------------------
 * 6. AUTOMATIC PROPOSALS — they wait for approval
 * ------------------------------------------------------------------ */

/**
 * Post types whose URL changes are tracked.
 *
 * @return string[]
 */
function livingdraft_redirects_tracked_post_types() {
	return (array) apply_filters( 'livingdraft_redirects_tracked_post_types', array( 'post', 'page' ) );
}

/**
 * Whether automatic proposals are on.
 *
 * @return bool
 */
function livingdraft_redirects_proposals_enabled() {
	return (bool) get_option( 'livingdraft_redirects_auto_slug', true );
}

/**
 * React to a post being saved.
 *
 *   - Whatever sits on the story's live address is cleared, so a story
 *     can never be hidden by a redirect (this is what fixes "renamed, then
 *     renamed back"), including a brand-new story published at an address
 *     that used to redirect.
 *   - Address changed → propose old → new.
 *   - Unpublished → propose 410 Gone for the old address.
 *
 * Hooked to wp_after_insert_post, which runs after categories and tags are
 * saved (in the block editor too), so the new permalink is the real one.
 *
 * @param int          $post_id Post id.
 * @param WP_Post      $after   Post after the save.
 * @param bool         $update  Whether this was an update.
 * @param WP_Post|null $before  Post before the save (null for a new post).
 */
function livingdraft_redirects_on_post_saved( $post_id, $after, $update = false, $before = null ) {
	if ( ! $after instanceof WP_Post ) {
		return;
	}
	if ( ! $before instanceof WP_Post ) {
		// New post: treat the "before" state as not published.
		$before              = clone $after;
		$before->post_status = 'new';
	}
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( ! in_array( $after->post_type, livingdraft_redirects_tracked_post_types(), true ) ) {
		return;
	}

	$was_live = 'publish' === $before->post_status;
	$is_live  = 'publish' === $after->post_status;

	if ( ! $was_live && ! $is_live ) {
		return;
	}

	$new_path = $is_live ? livingdraft_redirects_normalize_path( (string) get_permalink( $after ) ) : '';
	$old_path = '';
	if ( $was_live ) {
		$old_path = livingdraft_redirects_normalize_path( (string) get_permalink( $before ) );
	}

	$purge = array();

	if ( $is_live && '' !== $new_path ) {
		livingdraft_redirects_clear_live_path( $new_path, $post_id );
		livingdraft_404_mark_path( $new_path, LIVINGDRAFT_404_FIXED );
		$purge[] = $new_path;
	}

	if ( livingdraft_redirects_proposals_enabled() && '' !== $old_path && '/' !== $old_path ) {
		$title = get_the_title( $after );

		if ( $is_live && $old_path !== $new_path ) {
			livingdraft_redirects_save(
				array(
					'source'      => $old_path,
					'target'      => get_permalink( $after ),
					'type'        => 301,
					/* translators: %s: post title. */
					'notes'       => sprintf( __( 'Address changed on “%s”', 'livingdraft-core' ), $title ),
					'auto'        => true,
					'status'      => 'pending',
					'post_id'     => $post_id,
					'on_existing' => 'keep_manual',
				)
			);
			livingdraft_redirects_retarget_pending( $post_id, get_permalink( $after ) );
			$purge[] = $old_path;
		} elseif ( ! $is_live ) {
			$status_obj = get_post_status_object( $after->post_status );
			livingdraft_redirects_save(
				array(
					'source'      => $old_path,
					'type'        => 410,
					'notes'       => sprintf(
						/* translators: 1: post title, 2: new status label. */
						__( '“%1$s” was unpublished (%2$s). Approve to tell search engines it is gone, or edit to send readers elsewhere.', 'livingdraft-core' ),
						$title,
						$status_obj ? $status_obj->label : $after->post_status
					),
					'auto'        => true,
					'status'      => 'pending',
					'post_id'     => $post_id,
					'on_existing' => 'keep_manual',
				)
			);
		}
	}

	if ( $purge ) {
		livingdraft_redirects_purge_cache( $purge );
	}
}
add_action( 'wp_after_insert_post', 'livingdraft_redirects_on_post_saved', 10, 4 );

/**
 * A published story deleted outright (not via the Trash).
 *
 * @param int     $post_id Post id.
 * @param WP_Post $post    Post.
 */
function livingdraft_redirects_on_delete( $post_id, $post = null ) {
	$post = $post instanceof WP_Post ? $post : get_post( $post_id );
	if ( ! $post || 'publish' !== $post->post_status ) {
		return;
	}
	if ( ! in_array( $post->post_type, livingdraft_redirects_tracked_post_types(), true ) ) {
		return;
	}
	if ( ! livingdraft_redirects_proposals_enabled() ) {
		return;
	}

	livingdraft_redirects_save(
		array(
			'source'      => get_permalink( $post ),
			'type'        => 410,
			/* translators: %s: post title. */
			'notes'       => sprintf( __( '“%s” was deleted. Approve to tell search engines it is gone, or edit to send readers elsewhere.', 'livingdraft-core' ), get_the_title( $post ) ),
			'auto'        => true,
			'status'      => 'pending',
			'post_id'     => (int) $post_id,
			'on_existing' => 'keep_manual',
			'allow_live'  => true,
		)
	);
}
add_action( 'before_delete_post', 'livingdraft_redirects_on_delete', 10, 2 );

/**
 * A story is live at $path: remove automatic and waiting rows there, and
 * pause (not delete) a redirect a person made, so the decision stays on
 * record but the story is reachable.
 *
 * @param string $path    Normalised live path.
 * @param int    $post_id The live post.
 */
function livingdraft_redirects_clear_live_path( $path, $post_id ) {
	global $wpdb;
	$table = livingdraft_redirects_table();
	$row   = livingdraft_redirects_get_by_source( $path );

	if ( ! $row ) {
		return;
	}

	if ( (int) $row->auto_generated ) {
		$wpdb->delete( $table, array( 'id' => (int) $row->id ), array( '%d' ) );
	} elseif ( 'pending' === $row->status ) {
		return; // A person's redirect, already paused: keep it on record.
	} else {
		$wpdb->update(
			$table,
			array(
				'status'     => 'pending',
				'notes'      => mb_substr(
					sprintf(
						/* translators: %s: post title. */
						__( 'Paused: “%s” is live at this address again.', 'livingdraft-core' ),
						get_the_title( $post_id )
					) . ' ' . (string) $row->notes,
					0,
					255
				),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $row->id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	livingdraft_redirects_changed();
}

/**
 * Waiting proposals for a story follow it when it moves again.
 *
 * @param int    $post_id Post.
 * @param string $url     Its new permalink.
 */
function livingdraft_redirects_retarget_pending( $post_id, $url ) {
	global $wpdb;
	$table = livingdraft_redirects_table();
	$url   = livingdraft_redirects_normalize_target( $url );
	$path  = livingdraft_redirects_local_path( $url );

	$rows = $wpdb->get_results(
		$wpdb->prepare( "SELECT id, source_path FROM $table WHERE post_id = %d AND status = 'pending' AND auto_generated = 1 AND redirect_type <> 410", (int) $post_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);

	foreach ( (array) $rows as $row ) {
		if ( $row->source_path === $path ) {
			continue;
		}
		$wpdb->update( $table, array( 'target_url' => $url ), array( 'id' => (int) $row->id ), array( '%s' ), array( '%d' ) );
	}
}

/* ------------------------------------------------------------------
 * 7. 404 MONITOR
 * ------------------------------------------------------------------ */

/**
 * Is this user agent a machine?
 *
 * Uses the view counter's list, which is kept current, plus the tools
 * that probe for broken pages and the prefetch proxies that are not
 * readers. An empty user agent is a machine: every browser sends one.
 *
 * @param string $ua User agent.
 * @return bool
 */
function livingdraft_redirects_is_bot( $ua ) {
	$ua = strtolower( (string) $ua );
	if ( '' === $ua ) {
		return true;
	}

	$signatures = function_exists( 'livingdraft_views_bot_signatures' )
		? livingdraft_views_bot_signatures()
		: array( 'bot', 'crawl', 'spider', 'slurp', 'facebookexternalhit', 'headless' );

	$signatures = array_merge(
		$signatures,
		array(
			'prefetch', 'python', 'curl', 'wget', 'go-http', 'scrapy', 'httpclient',
			'okhttp', 'axios', 'node-fetch', 'libwww', 'java/', 'masscan', 'zgrab',
			'nuclei', 'sqlmap', 'nikto', 'wpscan', 'feedfetcher', 'uptime',
			'pingdom', 'statuscake', 'scanner',
		)
	);

	/**
	 * User-agent fragments that are never logged as 404s.
	 *
	 * @since 4.8.0
	 * @param string[] $signatures Lower-case fragments.
	 */
	$signatures = (array) apply_filters( 'livingdraft_404_bot_signatures', array_unique( $signatures ) );

	foreach ( $signatures as $sig ) {
		if ( '' !== $sig && false !== strpos( $ua, strtolower( (string) $sig ) ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Paths that are never a reader's mistake.
 *
 * @param string $path Normalised path.
 * @return bool
 */
function livingdraft_redirects_skip_404_path( $path ) {
	/**
	 * Regular expressions for 404 paths that are never logged.
	 *
	 * @since 4.8.0
	 * @param string[] $patterns PCRE patterns.
	 */
	$patterns = (array) apply_filters(
		'livingdraft_404_skip_patterns',
		array(
			'#^/wp-(admin|login|json|includes)(/|\.|$)#i',  // WordPress internals.
			'#^/wp-content/(plugins|themes|uploads)/#i',     // Probes for files.
			'#^/\.well-known/#i',                            // Machine-to-machine files.
			'#/data:[a-z]+/[a-z0-9.+\-]+[;,]#i',            // Inline data: scripts read as paths.
			'#/\.(env|git|svn|hg|aws|ssh|ds_store|htaccess|htpasswd)(/|$)#i',
			'#\.(php[0-9]?|asp|aspx|jsp|cgi|sql|bak|old|swp|ini|log|zip|tar|gz|rar|7z)/?$#i',
			'#^/(cgi-bin|phpmyadmin|pma|vendor|xmlrpc)(/|$)#i',
			'#(?:^|/)([^/]+)/(?:\1/){3,}#',                  // Crawler loops: /-/-/-/-/ or /page/page/page/page/.
		)
	);

	foreach ( $patterns as $pattern ) {
		if ( @preg_match( $pattern, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a bad filtered pattern must not break the 404 page.
			return true;
		}
	}
	return false;
}

/**
 * Log a 404 from a reader.
 *
 * @param string $template Template.
 * @return string
 */
function livingdraft_redirects_log_404( $template ) {
	if ( ! empty( $GLOBALS['livingdraft_redirects_serving_gone'] ) ) {
		return $template;
	}
	if ( ! (bool) get_option( 'livingdraft_redirects_log_404', true ) ) {
		return $template;
	}

	$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
	if ( livingdraft_redirects_is_bot( $ua ) ) {
		return $template;
	}

	$path = livingdraft_redirects_normalize_path( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	if ( '' === $path || '/' === $path || livingdraft_redirects_skip_404_path( $path ) ) {
		return $template;
	}

	global $wpdb;
	$table = livingdraft_404s_table();
	$now   = current_time( 'mysql' );

	$rows = $wpdb->get_results(
		$wpdb->prepare( "SELECT id, resolved FROM $table WHERE url_path = %s ORDER BY resolved DESC, id ASC", $path ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);

	if ( $rows ) {
		$row = $rows[0];

		if ( LIVINGDRAFT_404_IGNORED === (int) $row->resolved ) {
			// Ignored stays ignored. Count it, do not resurface it.
			$wpdb->query( $wpdb->prepare( "UPDATE $table SET hits = hits + 1, last_seen = %s WHERE id = %d", $now, (int) $row->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $template;
		}

		// Open, or "fixed" but 404ing again (the redirect was removed): open.
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET hits = hits + 1, last_seen = %s, resolved = 0 WHERE id = %d", $now, (int) $row->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	} else {
		$wpdb->insert(
			$table,
			array(
				'url_path'   => $path,
				'referrer'   => mb_substr( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ?? '' ) ), 0, 2000 ),
				'user_agent' => mb_substr( $ua, 0, 500 ),
				'last_seen'  => $now,
				'first_seen' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}

	// Occasional cleanup. Ignored rows are kept so they stay ignored.
	if ( 1 === wp_rand( 1, 100 ) ) {
		$wpdb->query( "DELETE FROM $table WHERE resolved <> 2 AND id NOT IN (SELECT id FROM (SELECT id FROM $table WHERE resolved <> 2 ORDER BY last_seen DESC LIMIT 5000) tmp)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	return $template;
}
add_filter( '404_template', 'livingdraft_redirects_log_404' );

/**
 * Is a path an open 404?
 *
 * @param string $path Normalised path.
 * @return bool
 */
function livingdraft_404_is_open( $path ) {
	global $wpdb;
	$table = livingdraft_404s_table();
	return (bool) $wpdb->get_var(
		$wpdb->prepare( "SELECT id FROM $table WHERE url_path = %s AND resolved = 0 LIMIT 1", $path ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);
}

/**
 * Set the state of every open log row for a path.
 *
 * @param string $path  Normalised path.
 * @param int    $state LIVINGDRAFT_404_* constant.
 */
function livingdraft_404_mark_path( $path, $state ) {
	global $wpdb;
	$wpdb->update(
		livingdraft_404s_table(),
		array( 'resolved' => (int) $state ),
		array(
			'url_path' => $path,
			'resolved' => LIVINGDRAFT_404_OPEN,
		),
		array( '%d' ),
		array( '%s', '%d' )
	);
	delete_transient( 'ld_sug_actionable' );
}

/**
 * Set the state of one log row.
 *
 * @param int $id    Row id.
 * @param int $state LIVINGDRAFT_404_* constant.
 * @return bool
 */
function livingdraft_404_set_state( $id, $state ) {
	global $wpdb;
	$ok = (bool) $wpdb->update(
		livingdraft_404s_table(),
		array( 'resolved' => (int) $state ),
		array( 'id' => (int) $id ),
		array( '%d' ),
		array( '%d' )
	);
	delete_transient( 'ld_sug_actionable' );
	return $ok;
}

/**
 * Backward-compatible: "resolved" now means "ignored" when a person does
 * it by hand, so it never comes back.
 *
 * @param int $id Row id.
 * @return bool
 */
function livingdraft_404_mark_resolved( $id ) {
	return livingdraft_404_set_state( $id, LIVINGDRAFT_404_IGNORED );
}

/* ------------------------------------------------------------------
 * 8. LISTS FOR THE ADMIN
 * ------------------------------------------------------------------ */

/**
 * A page of redirects.
 *
 * @param array $args per_page, page, orderby, order, search, status.
 * @return array
 */
function livingdraft_redirects_list( $args = array() ) {
	global $wpdb;
	$table = livingdraft_redirects_table();

	$args = wp_parse_args(
		$args,
		array(
			'per_page' => 20,
			'page'     => 1,
			'orderby'  => 'created_at',
			'order'    => 'DESC',
			'search'   => '',
			'status'   => 'active',
		)
	);

	$offset  = max( 0, ( (int) $args['page'] - 1 ) * (int) $args['per_page'] );
	$orderby = in_array( $args['orderby'], array( 'created_at', 'updated_at', 'hits', 'last_hit', 'source_path' ), true ) ? $args['orderby'] : 'created_at';
	$order   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

	$where      = 'status = %s';
	$where_args = array( 'pending' === $args['status'] ? 'pending' : 'active' );

	if ( '' !== (string) $args['search'] ) {
		$where       .= ' AND (source_path LIKE %s OR target_url LIKE %s OR notes LIKE %s)';
		$like         = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		$where_args[] = $like;
		$where_args[] = $like;
		$where_args[] = $like;
	}

	$where_args[] = (int) $args['per_page'];
	$where_args[] = (int) $offset;

	return $wpdb->get_results(
		$wpdb->prepare( "SELECT * FROM $table WHERE $where ORDER BY $orderby $order, id DESC LIMIT %d OFFSET %d", $where_args ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);
}

/**
 * Count redirects.
 *
 * @param string $search Search text.
 * @param string $status 'active' or 'pending'.
 * @return int
 */
function livingdraft_redirects_count( $search = '', $status = 'active' ) {
	global $wpdb;
	$table  = livingdraft_redirects_table();
	$status = 'pending' === $status ? 'pending' : 'active';

	if ( '' === (string) $search ) {
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE status = %s", $status ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	$like = '%' . $wpdb->esc_like( $search ) . '%';
	return (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE status = %s AND (source_path LIKE %s OR target_url LIKE %s OR notes LIKE %s)", $status, $like, $like, $like ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);
}

/**
 * A page of the 404 log.
 *
 * @param array $args per_page, page, resolved (0 open, 2 ignored).
 * @return array
 */
function livingdraft_404s_list( $args = array() ) {
	global $wpdb;
	$table = livingdraft_404s_table();

	$args = wp_parse_args(
		$args,
		array(
			'per_page' => 30,
			'page'     => 1,
			'resolved' => LIVINGDRAFT_404_OPEN,
		)
	);

	$offset = max( 0, ( (int) $args['page'] - 1 ) * (int) $args['per_page'] );

	return $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM $table WHERE resolved = %d ORDER BY hits DESC, last_seen DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			(int) $args['resolved'],
			(int) $args['per_page'],
			(int) $offset
		)
	);
}

/**
 * Count 404 log rows in a state.
 *
 * @param int $resolved State.
 * @return int
 */
function livingdraft_404s_count( $resolved = 0 ) {
	global $wpdb;
	$table = livingdraft_404s_table();
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE resolved = %d", (int) $resolved ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/* ------------------------------------------------------------------
 * 9. ADMIN
 * ------------------------------------------------------------------ */

require_once __DIR__ . '/redirects-admin.php';
require_once __DIR__ . '/redirects-suggest.php';
