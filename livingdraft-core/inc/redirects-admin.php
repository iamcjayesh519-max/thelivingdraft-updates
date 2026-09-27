<?php
/**
 * Redirections — the wp-admin interface.
 *
 * Everything you see when you go to Tools → Redirections lives here.
 * Kept as a single file with tabbed views (?tab=redirects|404s|settings|import)
 * rather than a WP_List_Table subclass, because the table is simple enough
 * that WP_List_Table's abstraction is overkill.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------
 * MENU + PAGE REGISTRATION
 * ------------------------------------------------------------------ */

function livingdraft_redirects_admin_menu() {
	add_management_page(
		__( 'Redirections', 'livingdraft-core' ),
		__( 'Redirections', 'livingdraft-core' ),
		'manage_options',
		'livingdraft-redirects',
		'livingdraft_redirects_admin_render'
	);
}
add_action( 'admin_menu', 'livingdraft_redirects_admin_menu' );

/* ------------------------------------------------------------------
 * FORM HANDLERS — run on admin_init, before the page renders
 * ------------------------------------------------------------------ */

function livingdraft_redirects_handle_actions() {
	// Only fire on our own page.
	if ( ! isset( $_GET['page'] ) || 'livingdraft-redirects' !== $_GET['page'] ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Add or update a redirect from the Add form.
	if ( isset( $_POST['ld_redirect_save'] ) ) {
		check_admin_referer( 'livingdraft_redirect_save' );

		$source = isset( $_POST['source_path'] ) ? sanitize_text_field( wp_unslash( $_POST['source_path'] ) ) : '';
		$target = isset( $_POST['target_url'] ) ? esc_url_raw( wp_unslash( $_POST['target_url'] ) ) : '';
		$type   = isset( $_POST['redirect_type'] ) ? (int) $_POST['redirect_type'] : 301;
		$notes  = isset( $_POST['notes'] ) ? sanitize_text_field( wp_unslash( $_POST['notes'] ) ) : '';

		$id = livingdraft_redirects_add( $source, $target, $type, $notes, false );

		if ( $id ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'livingdraft-redirects', 'added' => 1 ), admin_url( 'admin.php' ) ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'livingdraft-redirects', 'error' => 'save' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	// Delete a redirect via link.
	if ( isset( $_GET['delete'] ) ) {
		$id = (int) $_GET['delete'];
		check_admin_referer( 'livingdraft_redirect_delete_' . $id );
		livingdraft_redirects_delete( $id );
		wp_safe_redirect( add_query_arg( array( 'page' => 'livingdraft-redirects', 'deleted' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	// Mark a 404 as resolved.
	if ( isset( $_GET['resolve'] ) ) {
		$id = (int) $_GET['resolve'];
		check_admin_referer( 'livingdraft_404_resolve_' . $id );
		livingdraft_404_mark_resolved( $id );
		wp_safe_redirect( add_query_arg( array( 'page' => 'livingdraft-redirects', 'tab' => '404s', 'resolved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	// Save settings tab.
	if ( isset( $_POST['ld_redirects_settings'] ) ) {
		check_admin_referer( 'livingdraft_redirects_settings' );

		update_option( 'livingdraft_redirects_auto_slug', isset( $_POST['auto_slug'] ) ? 1 : 0 );
		update_option( 'livingdraft_redirects_log_404', isset( $_POST['log_404'] ) ? 1 : 0 );

		wp_safe_redirect( add_query_arg( array( 'page' => 'livingdraft-redirects', 'tab' => 'settings', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	// Import CSV.
	if ( isset( $_POST['ld_redirects_import'] ) && ! empty( $_FILES['csv_file']['tmp_name'] ) ) {
		check_admin_referer( 'livingdraft_redirects_import' );

		$imported = livingdraft_redirects_import_csv( $_FILES['csv_file']['tmp_name'] );
		wp_safe_redirect( add_query_arg( array( 'page' => 'livingdraft-redirects', 'tab' => 'import', 'imported' => (int) $imported ), admin_url( 'admin.php' ) ) );
		exit;
	}

	// Export CSV.
	if ( isset( $_GET['export'] ) && 'csv' === $_GET['export'] ) {
		check_admin_referer( 'livingdraft_redirects_export' );
		livingdraft_redirects_export_csv();
		exit; // export_csv() sends headers and dies.
	}
}
add_action( 'admin_init', 'livingdraft_redirects_handle_actions' );

/* ------------------------------------------------------------------
 * PAGE RENDER — dispatches based on ?tab=
 * ------------------------------------------------------------------ */

function livingdraft_redirects_admin_render() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'livingdraft-core' ) );
	}

	$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'redirects';
	$tab = in_array( $tab, array( 'redirects', '404s', 'suggest', 'settings', 'import' ), true ) ? $tab : 'redirects';

	// The tab-specific descriptions and header actions vary by which sub-view we're on.
	$header_args = array(
		'eyebrow' => __( 'The Living Draft Core · Redirections', 'livingdraft-core' ),
	);

	switch ( $tab ) {
		case '404s':
			$header_args['title'] = __( '404 Log', 'livingdraft-core' );
			$header_args['desc']  = __( 'URLs readers tried to visit that returned "Not Found". Convert real reader mistakes into working redirects.', 'livingdraft-core' );
			break;
		case 'suggest':
			$header_args['title'] = __( 'Suggested redirects', 'livingdraft-core' );
			$header_args['desc']  = __( 'For each broken URL, likely targets picked from your post history and content. Approve one and the redirect is created for you.', 'livingdraft-core' );
			break;
		case 'settings':
			$header_args['title'] = __( 'Redirections Settings', 'livingdraft-core' );
			$header_args['desc']  = __( 'Automatic behaviours: slug-change redirects, 404 logging.', 'livingdraft-core' );
			break;
		case 'import':
			$header_args['title'] = __( 'Import & Export', 'livingdraft-core' );
			$header_args['desc']  = __( 'CSV round-trip. Useful for migrating from Rank Math or backing up your redirects.', 'livingdraft-core' );
			break;
		default:
			$header_args['title']   = __( 'Redirections', 'livingdraft-core' );
			$header_args['desc']    = __( 'Send old URLs to their new home. Auto-creates 301s when you edit headlines.', 'livingdraft-core' );
			$header_args['actions'] = array(
				array(
					'label' => __( '+ Add redirect', 'livingdraft-core' ),
					'url'   => '#add-new',
					'class' => 'is-primary',
				),
			);
	}

	// If the shared header renderer exists (v1.5.0+), use it. Otherwise
	// fall back to the old <div class="wrap"> layout so the page still
	// works if inc/admin/menu.php failed to load for any reason.
	$has_new_shell = function_exists( 'livingdraft_admin_render_header' );

	if ( $has_new_shell ) {
		livingdraft_admin_render_header( $header_args );
	} else {
		echo '<div class="wrap"><h1>' . esc_html( $header_args['title'] ) . '</h1>';
	}

	// Sub-nav within this module. v3.8.0: uses the shared
	// livingdraft_admin_render_subtabs() helper instead of inline styles.
	if ( $has_new_shell && function_exists( 'livingdraft_admin_render_subtabs' ) ) {
		$sub_tabs = array(
			'redirects' => array(
				'label' => __( 'Redirects', 'livingdraft-core' ),
				'count' => livingdraft_redirects_count(),
			),
			'404s'      => array(
				'label' => __( '404 Log', 'livingdraft-core' ),
				'count' => livingdraft_404s_count( 0 ),
			),
			'suggest'   => array(
				'label' => __( 'Suggestions', 'livingdraft-core' ),
				'count' => function_exists( 'livingdraft_suggest_actionable_count' )
					? livingdraft_suggest_actionable_count()
					: null,
			),
			'settings'  => array( 'label' => __( 'Settings', 'livingdraft-core' ) ),
			'import'    => array( 'label' => __( 'Import / Export', 'livingdraft-core' ) ),
		);
		livingdraft_admin_render_subtabs( $sub_tabs, $tab, 'livingdraft-redirects' );
	}

	livingdraft_redirects_admin_notices();

	switch ( $tab ) {
		case '404s':
			livingdraft_redirects_render_404s_tab();
			break;
		case 'suggest':
			if ( function_exists( 'livingdraft_suggest_render_tab' ) ) {
				livingdraft_suggest_render_tab();
			}
			break;
		case 'settings':
			livingdraft_redirects_render_settings_tab();
			break;
		case 'import':
			livingdraft_redirects_render_import_tab();
			break;
		case 'redirects':
		default:
			livingdraft_redirects_render_list_tab();
			break;
	}

	if ( $has_new_shell ) {
		livingdraft_admin_render_footer();
	} else {
		echo '</div>';
	}
}

/* ------------------------------------------------------------------
 * SUCCESS/ERROR NOTICES
 * ------------------------------------------------------------------ */

function livingdraft_redirects_admin_notices() {
	// v3.8.0: uses .tld-notice inside the plugin shell so messages sit
	// inside the editorial design system instead of the WP-core one.
	// Falls back to .notice when the new shell isn't loaded.
	$new_shell = function_exists( 'livingdraft_admin_render_header' );
	$ok        = $new_shell ? '<div class="tld-notice">%s</div>' : '<div class="notice notice-success is-dismissible"><p>%s</p></div>';
	$bad       = $new_shell ? '<div class="tld-notice is-bad">%s</div>' : '<div class="notice notice-error is-dismissible"><p>%s</p></div>';

	if ( isset( $_GET['added'] ) ) {
		printf( $ok, esc_html__( 'Redirect saved.', 'livingdraft-core' ) );
	}
	if ( isset( $_GET['deleted'] ) ) {
		printf( $ok, esc_html__( 'Redirect deleted.', 'livingdraft-core' ) );
	}
	if ( isset( $_GET['resolved'] ) ) {
		printf( $ok, esc_html__( '404 marked as resolved.', 'livingdraft-core' ) );
	}
	if ( isset( $_GET['saved'] ) ) {
		printf( $ok, esc_html__( 'Settings saved.', 'livingdraft-core' ) );
	}
	if ( isset( $_GET['imported'] ) ) {
		printf( $ok, esc_html( sprintf( __( 'Imported %d redirects.', 'livingdraft-core' ), (int) $_GET['imported'] ) ) );
	}
	if ( isset( $_GET['error'] ) ) {
		printf( $bad, esc_html__( 'Something went wrong. Check the source path and target URL are both filled.', 'livingdraft-core' ) );
	}
}

/* ------------------------------------------------------------------
 * TAB: REDIRECTS LIST + ADD FORM
 * ------------------------------------------------------------------ */

function livingdraft_redirects_render_list_tab() {
	$page      = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
	$per_page  = 20;
	$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$redirects = livingdraft_redirects_list( array( 'page' => $page, 'per_page' => $per_page, 'search' => $search ) );
	$total     = livingdraft_redirects_count( $search );
	$pages     = (int) ceil( $total / $per_page );

	?>
	<form method="get" style="margin-bottom:12px">
		<input type="hidden" name="page" value="livingdraft-redirects">
		<p class="search-box">
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search paths or URLs…', 'livingdraft-core' ); ?>" style="min-width:280px">
			<?php submit_button( __( 'Search', 'livingdraft-core' ), '', '', false ); ?>
		</p>
	</form>

	<table class="tld-table">
		<thead>
			<tr>
				<th style="width:35%"><?php esc_html_e( 'From (path)', 'livingdraft-core' ); ?></th>
				<th style="width:35%"><?php esc_html_e( 'To (URL)', 'livingdraft-core' ); ?></th>
				<th style="width:8%"><?php esc_html_e( 'Type', 'livingdraft-core' ); ?></th>
				<th style="width:8%"><?php esc_html_e( 'Hits', 'livingdraft-core' ); ?></th>
				<th style="width:14%"><?php esc_html_e( 'Actions', 'livingdraft-core' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $redirects ) ) : ?>
				<tr><td colspan="5" style="text-align:center;padding:24px;color:#666">
					<?php esc_html_e( 'No redirects yet. Use the form below to add your first one.', 'livingdraft-core' ); ?>
				</td></tr>
			<?php else : ?>
				<?php foreach ( $redirects as $r ) : ?>
					<tr>
						<td>
							<code style="word-break:break-all"><?php echo esc_html( $r->source_path ); ?></code>
							<?php if ( $r->auto_generated ) : ?>
								<span style="display:inline-block;padding:1px 6px;background:#f0f6fc;color:#0969da;border-radius:3px;font-size:11px;margin-left:4px">auto</span>
							<?php endif; ?>
							<?php if ( $r->notes ) : ?>
								<div class="tld-cell-meta"><?php echo esc_html( $r->notes ); ?></div>
							<?php endif; ?>
						</td>
						<td><a href="<?php echo esc_url( $r->target_url ); ?>" target="_blank"><?php echo esc_html( wp_trim_words( $r->target_url, 8, '…' ) ); ?></a></td>
						<td><?php echo esc_html( $r->redirect_type ); ?></td>
						<td>
							<?php echo esc_html( number_format_i18n( (int) $r->hits ) ); ?>
							<?php if ( $r->last_hit ) : ?>
								<div class="tld-cell-meta"><?php echo esc_html( human_time_diff( strtotime( $r->last_hit ), current_time( 'timestamp' ) ) ); ?> <?php esc_html_e( 'ago', 'livingdraft-core' ); ?></div>
							<?php endif; ?>
						</td>
						<td>
							<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'livingdraft-redirects', 'delete' => $r->id ), admin_url( 'admin.php' ) ), 'livingdraft_redirect_delete_' . $r->id ) ); ?>" onclick="return confirm('<?php esc_attr_e( 'Delete this redirect?', 'livingdraft-core' ); ?>');" style="color:#a00"><?php esc_html_e( 'Delete', 'livingdraft-core' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<?php if ( $pages > 1 ) : ?>
		<div class="tablenav" style="margin-top:12px">
			<div class="tablenav-pages">
				<?php
				echo paginate_links( array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'current'   => $page,
					'total'     => $pages,
					'prev_text' => '‹',
					'next_text' => '›',
				) );
				?>
			</div>
		</div>
	<?php endif; ?>

	<h2 id="add-new"><?php esc_html_e( 'Add new redirect', 'livingdraft-core' ); ?></h2>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=livingdraft-redirects' ) ); ?>" class="tld-card">
		<?php wp_nonce_field( 'livingdraft_redirect_save' ); ?>
		<div class="tld-fields">
			<div class="tld-field">
				<label class="tld-label" for="source_path"><?php esc_html_e( 'From (path)', 'livingdraft-core' ); ?></label>
					<input type="text" id="source_path" name="source_path" class="tld-input is-mono" placeholder="/old-headline-here/" required >
					<p class="tld-help"><?php esc_html_e( 'The path part only, starting with / — no domain. Example: /old-headline/', 'livingdraft-core' ); ?></p>
			</div>
			<div class="tld-field">
				<label class="tld-label" for="target_url"><?php esc_html_e( 'To (URL)', 'livingdraft-core' ); ?></label>
					<input type="url" id="target_url" name="target_url" class="tld-input is-mono" placeholder="https://thelivingdraft.com/new-headline/" required >
					<p class="tld-help"><?php esc_html_e( 'Full URL where the reader should end up. Can be your own site or external.', 'livingdraft-core' ); ?></p>
			</div>
			<div class="tld-field">
				<label class="tld-label" for="redirect_type"><?php esc_html_e( 'Type', 'livingdraft-core' ); ?></label>
					<select id="redirect_type" name="redirect_type" class="tld-select">
						<option value="301">301 — <?php esc_html_e( 'Permanent (recommended for slug changes)', 'livingdraft-core' ); ?></option>
						<option value="302">302 — <?php esc_html_e( 'Temporary (for A/B tests, seasonal content)', 'livingdraft-core' ); ?></option>
						<option value="307">307 — <?php esc_html_e( 'Temporary (preserves POST data)', 'livingdraft-core' ); ?></option>
					</select>
			</div>
			<div class="tld-field">
				<label class="tld-label" for="notes"><?php esc_html_e( 'Notes (optional)', 'livingdraft-core' ); ?></label>
					<input type="text" id="notes" name="notes" class="tld-input" placeholder="<?php esc_attr_e( 'Why you added this — for your own reference', 'livingdraft-core' ); ?>"  maxlength="255">
			</div>
		</div>
		<p><button type="submit" name="ld_redirect_save" class="tld-btn is-primary"><?php esc_html_e( 'Save redirect', 'livingdraft-core' ); ?></button></p>
	</form>
	<?php
}

/* ------------------------------------------------------------------
 * TAB: 404 LOG
 * ------------------------------------------------------------------ */

function livingdraft_redirects_render_404s_tab() {
	$page  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
	$logs  = livingdraft_404s_list( array( 'page' => $page, 'per_page' => 30 ) );
	$total = livingdraft_404s_count( 0 );

	?>
	<p style="max-width:640px;color:#555">
		<?php esc_html_e( 'URLs readers tried to visit that returned "Not Found". Click "Create redirect" to send those readers to a working page. Bot traffic is filtered out automatically.', 'livingdraft-core' ); ?>
	</p>

	<table class="tld-table">
		<thead>
			<tr>
				<th style="width:45%"><?php esc_html_e( 'Broken URL', 'livingdraft-core' ); ?></th>
				<th style="width:10%"><?php esc_html_e( 'Hits', 'livingdraft-core' ); ?></th>
				<th style="width:20%"><?php esc_html_e( 'Referrer', 'livingdraft-core' ); ?></th>
				<th style="width:15%"><?php esc_html_e( 'Last seen', 'livingdraft-core' ); ?></th>
				<th style="width:10%"><?php esc_html_e( 'Actions', 'livingdraft-core' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $logs ) ) : ?>
				<tr><td colspan="5" style="text-align:center;padding:24px;color:#666">
					<?php esc_html_e( 'No unresolved 404s. Good sign — nothing is currently broken.', 'livingdraft-core' ); ?>
				</td></tr>
			<?php else : ?>
				<?php foreach ( $logs as $log ) : ?>
					<tr>
						<td><code style="word-break:break-all"><?php echo esc_html( $log->url_path ); ?></code></td>
						<td><?php echo esc_html( number_format_i18n( (int) $log->hits ) ); ?></td>
						<td style="font-size:12px;color:#666">
							<?php if ( $log->referrer ) : ?>
								<?php echo esc_html( parse_url( $log->referrer, PHP_URL_HOST ) ?: $log->referrer ); ?>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td style="font-size:12px"><?php echo esc_html( human_time_diff( strtotime( $log->last_seen ), current_time( 'timestamp' ) ) ); ?> <?php esc_html_e( 'ago', 'livingdraft-core' ); ?></td>
						<td>
							<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'livingdraft-redirects', 'tab' => 'suggest', 'url_path' => rawurlencode( $log->url_path ) ), admin_url( 'admin.php' ) ) ); ?>" style="color:#2f7a3a;font-weight:500"><?php esc_html_e( 'Suggest', 'livingdraft-core' ); ?></a>
							&nbsp;·&nbsp;
							<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'livingdraft-redirects', 'prefill_source' => rawurlencode( $log->url_path ) ), admin_url( 'admin.php' ) ) ); ?>#add-new" style="color:#0969da"><?php esc_html_e( 'Redirect', 'livingdraft-core' ); ?></a>
							&nbsp;·&nbsp;
							<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'livingdraft-redirects', 'resolve' => $log->id ), admin_url( 'admin.php' ) ), 'livingdraft_404_resolve_' . $log->id ) ); ?>" style="color:#666"><?php esc_html_e( 'Ignore', 'livingdraft-core' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<?php if ( isset( $_GET['prefill_source'] ) ) : ?>
		<script>
			document.addEventListener( 'DOMContentLoaded', function () {
				var src = document.getElementById( 'source_path' );
				if ( src ) src.value = <?php echo wp_json_encode( urldecode( wp_unslash( $_GET['prefill_source'] ) ) ); ?>;
			} );
		</script>
	<?php endif; ?>
	<?php
}

/* ------------------------------------------------------------------
 * TAB: SETTINGS
 * ------------------------------------------------------------------ */

function livingdraft_redirects_render_settings_tab() {
	$auto_slug = (bool) get_option( 'livingdraft_redirects_auto_slug', true );
	$log_404   = (bool) get_option( 'livingdraft_redirects_log_404', true );
	?>
	<form method="post" style="background:#fff;padding:16px;border:1px solid #c3c4c7;max-width:700px">
		<?php wp_nonce_field( 'livingdraft_redirects_settings' ); ?>

		<h3><?php esc_html_e( 'Automatic behaviour', 'livingdraft-core' ); ?></h3>
		<div class="tld-fields">
			<div class="tld-field">
				<span class="tld-label"><?php esc_html_e( 'Slug changes', 'livingdraft-core' ); ?></span>
					<label>
						<input type="checkbox" name="auto_slug" value="1" <?php checked( $auto_slug ); ?>>
						<?php esc_html_e( 'Auto-create a 301 when a published post\'s URL slug changes', 'livingdraft-core' ); ?>
					</label>
					<p class="tld-help"><?php esc_html_e( 'Prevents broken links when you edit headlines after publishing. Strongly recommended for editorial sites.', 'livingdraft-core' ); ?></p>
			</div>
			<div class="tld-field">
				<span class="tld-label"><?php esc_html_e( '404 logging', 'livingdraft-core' ); ?></span>
					<label>
						<input type="checkbox" name="log_404" value="1" <?php checked( $log_404 ); ?>>
						<?php esc_html_e( 'Log URLs that return 404 so you can create redirects for them', 'livingdraft-core' ); ?>
					</label>
					<p class="tld-help"><?php esc_html_e( 'Bot traffic is filtered out. Log is capped at 5000 rows to prevent runaway growth.', 'livingdraft-core' ); ?></p>
			</div>
		</div>

		<p><button type="submit" name="ld_redirects_settings" class="tld-btn is-primary"><?php esc_html_e( 'Save settings', 'livingdraft-core' ); ?></button></p>
	</form>
	<?php
}

/* ------------------------------------------------------------------
 * TAB: IMPORT / EXPORT (CSV)
 * ------------------------------------------------------------------ */

function livingdraft_redirects_render_import_tab() {
	// v3.5.0: import from Rank Math / Yoast Premium / Redirection plugin.
	// Rendered above the CSV panel because it's more common than a
	// hand-curated CSV file.
	if ( function_exists( 'livingdraft_redirects_import_render_sources' ) ) {
		livingdraft_redirects_import_render_sources();
	}
	?>
	<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:900px">

		<div style="background:#fff;padding:16px;border:1px solid #c3c4c7">
			<h3><?php esc_html_e( 'Export', 'livingdraft-core' ); ?></h3>
			<p><?php esc_html_e( 'Download all your redirects as a CSV file. Useful for backup or migration.', 'livingdraft-core' ); ?></p>
			<p>
				<a class="tld-btn" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'livingdraft-redirects', 'export' => 'csv' ), admin_url( 'admin.php' ) ), 'livingdraft_redirects_export' ) ); ?>">
					<?php esc_html_e( 'Download CSV', 'livingdraft-core' ); ?>
				</a>
			</p>
		</div>

		<div style="background:#fff;padding:16px;border:1px solid #c3c4c7">
			<h3><?php esc_html_e( 'Import from CSV', 'livingdraft-core' ); ?></h3>
			<p><?php esc_html_e( 'Upload a CSV to add many redirects at once. Format:', 'livingdraft-core' ); ?></p>
			<p><code style="display:block;padding:8px;background:#f6f7f7">source_path,target_url,type,notes<br>/old-1/,https://example.com/new-1/,301,<br>/old-2/,https://example.com/new-2/,301,Migrated</code></p>
			<p><?php esc_html_e( 'Existing redirects with the same source path will be overwritten.', 'livingdraft-core' ); ?></p>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'livingdraft_redirects_import' ); ?>
				<p><input type="file" name="csv_file" accept=".csv" required></p>
				<p><button type="submit" name="ld_redirects_import" class="tld-btn is-primary"><?php esc_html_e( 'Upload and import', 'livingdraft-core' ); ?></button></p>
			</form>
		</div>

	</div>
	<?php
}

/* ------------------------------------------------------------------
 * CSV IMPORT/EXPORT LOGIC
 * ------------------------------------------------------------------ */

/**
 * Parse an uploaded CSV file and add each row as a redirect.
 * Uses fgetcsv() so quoted values and escaped delimiters work.
 *
 * @param string $file Path to the uploaded temp file.
 * @return int Number of redirects successfully added.
 */
function livingdraft_redirects_import_csv( $file ) {
	$handle = fopen( $file, 'r' );
	if ( ! $handle ) {
		return 0;
	}

	// Skip header row. See the note on $escape below.
	fgetcsv( $handle, 0, ',', '"', '' );

	$imported = 0;
	while ( ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) !== false ) {
		if ( count( $row ) < 2 ) {
			continue;
		}

		$source = trim( $row[0] );
		$target = trim( $row[1] );
		$type   = isset( $row[2] ) ? (int) $row[2] : 301;
		$notes  = isset( $row[3] ) ? trim( $row[3] ) : '';

		if ( '' === $source || '' === $target ) {
			continue;
		}

		if ( livingdraft_redirects_add( $source, $target, $type, $notes, false ) ) {
			$imported++;
		}
	}

	fclose( $handle );
	return $imported;
}

/**
 * Stream all redirects as a CSV download.
 * Sets the headers, writes rows, exits — no return.
 *
 * CSV formula-injection guard: any target_url starting with =, +, -, or @
 * gets a single-quote prefix, so Excel does not evaluate it. Same guard
 * we added to the newsletter subscriber export earlier.
 */
function livingdraft_redirects_export_csv() {
	global $wpdb;
	$table = $wpdb->prefix . 'livingdraft_redirects';

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=livingdraft-redirects-' . gmdate( 'Y-m-d' ) . '.csv' );

	$out = fopen( 'php://output', 'w' );

	/*
	 * The $escape argument is passed explicitly. PHP 8.4 deprecates relying
	 * on its default, and PHP 8.5 will change the default outright, so an
	 * export that works today would start emitting notices onto the top of
	 * the downloaded file. Empty string is also the correct value: it turns
	 * off PHP's non-standard backslash escaping and produces CSV that matches
	 * RFC 4180, which is what Excel and Google Sheets actually expect.
	 */
	fputcsv( $out, array( 'source_path', 'target_url', 'type', 'notes', 'hits', 'auto_generated', 'created_at' ), ',', '"', '' );

	$rows = $wpdb->get_results( "SELECT source_path, target_url, redirect_type, notes, hits, auto_generated, created_at FROM $table ORDER BY id ASC" );
	foreach ( $rows as $r ) {
		fputcsv( $out, array(
			livingdraft_csv_safe( $r->source_path ),
			livingdraft_csv_safe( $r->target_url ),
			(int) $r->redirect_type,
			livingdraft_csv_safe( (string) $r->notes ),
			(int) $r->hits,
			(int) $r->auto_generated,
			$r->created_at,
		), ',', '"', '' );
	}

	fclose( $out );
	exit;
}

/**
 * Prefix a value with ' if it starts with =, +, -, @ so Excel does not
 * interpret it as a formula. Same helper the newsletter module uses.
 */
if ( ! function_exists( 'livingdraft_csv_safe' ) ) {
	function livingdraft_csv_safe( $value ) {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@' ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}
}
