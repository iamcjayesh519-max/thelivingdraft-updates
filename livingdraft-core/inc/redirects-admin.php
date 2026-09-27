<?php
/**
 * Redirections — the wp-admin screens.
 *
 * Tabs: Redirects · Awaiting approval · 404 Log · Suggestions · Settings ·
 * Import / Export. Every write goes through livingdraft_redirects_save(),
 * so the screens only collect input and report what happened.
 *
 * Messages survive the redirect-after-POST through a short per-user
 * transient ("flash"), which also carries the form values back when a
 * save is refused, so nothing typed is lost.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------
 * MENU
 * ------------------------------------------------------------------ */

/**
 * Menu label with the waiting-approval bubble WordPress uses elsewhere.
 *
 * @return string
 */
function livingdraft_redirects_menu_label() {
	$label   = __( 'Redirections', 'livingdraft-core' );
	$pending = function_exists( 'livingdraft_redirects_pending_count' ) ? livingdraft_redirects_pending_count() : 0;
	if ( $pending > 0 ) {
		$label .= sprintf(
			' <span class="awaiting-mod count-%1$d"><span class="pending-count">%2$s</span></span>',
			$pending,
			esc_html( number_format_i18n( $pending ) )
		);
	}
	return $label;
}

function livingdraft_redirects_admin_menu() {
	add_management_page(
		__( 'Redirections', 'livingdraft-core' ),
		livingdraft_redirects_menu_label(),
		'manage_options',
		'livingdraft-redirects',
		'livingdraft_redirects_admin_render'
	);
}
add_action( 'admin_menu', 'livingdraft_redirects_admin_menu' );

/* ------------------------------------------------------------------
 * HELPERS
 * ------------------------------------------------------------------ */

/**
 * URL of this screen.
 *
 * @param array $args Query args.
 * @return string
 */
function livingdraft_redirects_admin_url( $args = array() ) {
	return add_query_arg( array_merge( array( 'page' => 'livingdraft-redirects' ), $args ), admin_url( 'admin.php' ) );
}

/**
 * Store a message for the next page load.
 *
 * @param string $type    good|bad|warn|info.
 * @param string $message Main line.
 * @param array  $details Extra lines.
 * @param array  $form    Form values to restore.
 */
function livingdraft_redirects_flash( $type, $message, $details = array(), $form = array() ) {
	set_transient(
		'ld_redir_flash_' . get_current_user_id(),
		array(
			'type'    => $type,
			'message' => $message,
			'details' => array_values( array_filter( (array) $details ) ),
			'form'    => $form,
		),
		5 * MINUTE_IN_SECONDS
	);
}

/**
 * Read and clear the stored message.
 *
 * @return array|null
 */
function livingdraft_redirects_take_flash() {
	static $flash = false;
	if ( false === $flash ) {
		$key   = 'ld_redir_flash_' . get_current_user_id();
		$flash = get_transient( $key );
		delete_transient( $key );
		$flash = is_array( $flash ) ? $flash : null;
	}
	return $flash;
}

/**
 * Redirect and stop.
 *
 * @param array  $args     Query args.
 * @param string $fragment Optional #fragment.
 */
function livingdraft_redirects_go( $args = array(), $fragment = '' ) {
	wp_safe_redirect( livingdraft_redirects_admin_url( $args ) . ( $fragment ? '#' . $fragment : '' ) );
	exit;
}

/**
 * Human label for a type.
 *
 * @param int $type Code.
 * @return string
 */
function livingdraft_redirects_type_label( $type ) {
	$labels = array(
		301 => __( '301 Permanent', 'livingdraft-core' ),
		302 => __( '302 Temporary', 'livingdraft-core' ),
		307 => __( '307 Temporary', 'livingdraft-core' ),
		308 => __( '308 Permanent', 'livingdraft-core' ),
		410 => __( '410 Gone', 'livingdraft-core' ),
	);
	return $labels[ (int) $type ] ?? (string) (int) $type;
}

/**
 * Raw text from a request field: unslashed, trimmed, no tags. Deliberately
 * not sanitize_text_field(), which deletes %20-style sequences from paths.
 *
 * @param array  $source $_POST or $_GET.
 * @param string $key    Field.
 * @return string
 */
function livingdraft_redirects_field( $source, $key ) {
	if ( ! isset( $source[ $key ] ) || is_array( $source[ $key ] ) ) {
		return '';
	}
	$value = wp_check_invalid_utf8( wp_unslash( (string) $source[ $key ] ) );
	return trim( wp_strip_all_tags( $value ) );
}

/* ------------------------------------------------------------------
 * ACTIONS
 * ------------------------------------------------------------------ */

function livingdraft_redirects_handle_actions() {
	// phpcs:disable WordPress.Security.NonceVerification -- each branch verifies its own nonce.
	if ( ! isset( $_GET['page'] ) || 'livingdraft-redirects' !== $_GET['page'] ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Add or edit.
	if ( isset( $_POST['ld_redirect_save'] ) || isset( $_POST['ld_redirect_save_approve'] ) ) {
		check_admin_referer( 'livingdraft_redirect_save' );

		$id      = isset( $_POST['redirect_id'] ) ? absint( $_POST['redirect_id'] ) : 0;
		$current = $id ? livingdraft_redirects_get( $id ) : null;
		$form    = array(
			'id'         => $id,
			'source'     => livingdraft_redirects_field( $_POST, 'source_path' ),
			'target'     => livingdraft_redirects_field( $_POST, 'target_url' ),
			'type'       => isset( $_POST['redirect_type'] ) ? (int) $_POST['redirect_type'] : 301,
			'notes'      => sanitize_text_field( wp_unslash( $_POST['notes'] ?? '' ) ),
			'allow_live' => ! empty( $_POST['allow_live'] ),
		);

		$status = 'active';
		if ( $current && 'pending' === $current->status && ! isset( $_POST['ld_redirect_save_approve'] ) ) {
			$status = 'pending';
		}

		$result = livingdraft_redirects_save(
			array(
				'id'         => $id,
				'source'     => $form['source'],
				'target'     => $form['target'],
				'type'       => $form['type'],
				'notes'      => $form['notes'],
				'status'     => $status,
				'auto'       => $current ? (bool) $current->auto_generated : false,
				'post_id'    => $current ? (int) $current->post_id : 0,
				'allow_live' => $form['allow_live'],
			)
		);

		if ( ! $result['ok'] ) {
			livingdraft_redirects_flash( 'bad', $result['message'], array(), $form );
			$back = array( 'tab' => 'redirects' );
			if ( $id ) {
				$back['edit'] = $id;
			}
			livingdraft_redirects_go( $back, 'add-new' );
		}

		if ( 'pending' === $status ) {
			livingdraft_redirects_flash( 'good', __( 'Proposal updated. It is still waiting for approval.', 'livingdraft-core' ), $result['warnings'] );
			livingdraft_redirects_go( array( 'tab' => 'pending' ) );
		}

		livingdraft_redirects_flash(
			$result['warnings'] ? 'warn' : 'good',
			$id ? __( 'Redirect updated. It is live.', 'livingdraft-core' ) : __( 'Redirect saved. It is live.', 'livingdraft-core' ),
			$result['warnings']
		);
		livingdraft_redirects_go( array( 'tab' => 'redirects' ) );
	}

	// Delete.
	if ( isset( $_GET['delete'] ) ) {
		$id = absint( $_GET['delete'] );
		check_admin_referer( 'livingdraft_redirect_delete_' . $id );
		livingdraft_redirects_delete( $id );
		livingdraft_redirects_flash( 'good', __( 'Redirect deleted.', 'livingdraft-core' ) );
		livingdraft_redirects_go( array( 'tab' => 'redirects' ) );
	}

	// Approve one proposal.
	if ( isset( $_GET['approve'] ) ) {
		$id = absint( $_GET['approve'] );
		check_admin_referer( 'livingdraft_redirect_approve_' . $id );
		$result = livingdraft_redirects_approve( $id );
		if ( $result['ok'] ) {
			livingdraft_redirects_flash( $result['warnings'] ? 'warn' : 'good', __( 'Approved. The redirect is live.', 'livingdraft-core' ), $result['warnings'] );
		} else {
			livingdraft_redirects_flash( 'bad', __( 'Not approved.', 'livingdraft-core' ) . ' ' . $result['message'] );
		}
		livingdraft_redirects_go( array( 'tab' => 'pending' ) );
	}

	// Reject one proposal.
	if ( isset( $_GET['reject'] ) ) {
		$id = absint( $_GET['reject'] );
		check_admin_referer( 'livingdraft_redirect_reject_' . $id );
		$row = livingdraft_redirects_get( $id );
		if ( $row && 'pending' === $row->status ) {
			livingdraft_redirects_delete( $id );
			livingdraft_redirects_flash( 'good', __( 'Proposal rejected and removed.', 'livingdraft-core' ) );
		}
		livingdraft_redirects_go( array( 'tab' => 'pending' ) );
	}

	// Approve every proposal.
	if ( isset( $_POST['ld_redirect_approve_all'] ) ) {
		check_admin_referer( 'livingdraft_redirect_approve_all' );
		$ok     = 0;
		$failed = array();
		foreach ( livingdraft_redirects_list( array( 'status' => 'pending', 'per_page' => 500 ) ) as $row ) {
			$result = livingdraft_redirects_approve( (int) $row->id );
			if ( $result['ok'] ) {
				$ok++;
			} else {
				$failed[] = $row->source_path . ' — ' . $result['message'];
			}
		}
		livingdraft_redirects_flash(
			$failed ? 'warn' : 'good',
			sprintf(
				/* translators: 1: approved count, 2: left waiting count. */
				__( '%1$d approved. %2$d left waiting — see why below.', 'livingdraft-core' ),
				$ok,
				count( $failed )
			),
			$failed
		);
		livingdraft_redirects_go( array( 'tab' => 'pending' ) );
	}

	// Re-check every redirect for chains and loops.
	if ( isset( $_GET['repair'] ) ) {
		check_admin_referer( 'livingdraft_redirects_repair' );
		$out = livingdraft_redirects_repair_all();
		livingdraft_redirects_flash(
			$out['loops'] ? 'warn' : 'good',
			sprintf(
				/* translators: 1: chains shortened, 2: loops found. */
				__( 'Check complete. %1$d chains shortened to one hop, %2$d loops moved to Awaiting approval.', 'livingdraft-core' ),
				$out['flattened'],
				$out['loops']
			)
		);
		livingdraft_redirects_go( array( 'tab' => 'redirects' ) );
	}

	// 404: ignore for good (the old "resolve" link does the same).
	foreach ( array( 'ignore404', 'resolve' ) as $param ) {
		if ( isset( $_GET[ $param ] ) ) {
			$id = absint( $_GET[ $param ] );
			check_admin_referer( 'ignore404' === $param ? 'livingdraft_404_ignore_' . $id : 'livingdraft_404_resolve_' . $id );
			livingdraft_404_set_state( $id, LIVINGDRAFT_404_IGNORED );
			livingdraft_redirects_flash( 'good', __( 'Ignored. This URL will not come back to the log.', 'livingdraft-core' ) );
			livingdraft_redirects_go( array( 'tab' => '404s' ) );
		}
	}

	// 404: restore an ignored row.
	if ( isset( $_GET['unignore404'] ) ) {
		$id = absint( $_GET['unignore404'] );
		check_admin_referer( 'livingdraft_404_unignore_' . $id );
		livingdraft_404_set_state( $id, LIVINGDRAFT_404_OPEN );
		livingdraft_redirects_flash( 'good', __( 'Moved back to the open log.', 'livingdraft-core' ) );
		livingdraft_redirects_go( array( 'tab' => '404s', 'view' => 'ignored' ) );
	}

	// 404: answer 410 Gone.
	if ( isset( $_GET['gone404'] ) ) {
		$id = absint( $_GET['gone404'] );
		check_admin_referer( 'livingdraft_404_gone_' . $id );
		global $wpdb;
		$path = $wpdb->get_var( $wpdb->prepare( 'SELECT url_path FROM ' . livingdraft_404s_table() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $path ) {
			$result = livingdraft_redirects_save(
				array(
					'source'      => $path,
					'type'        => 410,
					'notes'       => __( 'Marked gone from the 404 log', 'livingdraft-core' ),
					'on_existing' => 'error',
				)
			);
			if ( $result['ok'] ) {
				livingdraft_redirects_flash( 'good', __( 'Marked as Gone (410). Search engines will drop it.', 'livingdraft-core' ) );
			} else {
				livingdraft_redirects_flash( 'bad', $result['message'] );
			}
		}
		livingdraft_redirects_go( array( 'tab' => '404s' ) );
	}

	// Settings.
	if ( isset( $_POST['ld_redirects_settings'] ) ) {
		check_admin_referer( 'livingdraft_redirects_settings' );
		update_option( 'livingdraft_redirects_auto_slug', empty( $_POST['auto_slug'] ) ? 0 : 1 );
		update_option( 'livingdraft_redirects_core_fallback', empty( $_POST['core_fallback'] ) ? 0 : 1 );
		update_option( 'livingdraft_redirects_preserve_query', empty( $_POST['preserve_query'] ) ? 0 : 1 );
		update_option( 'livingdraft_redirects_log_404', empty( $_POST['log_404'] ) ? 0 : 1 );
		update_option( 'livingdraft_traffic_advice', empty( $_POST['traffic_advice'] ) ? 0 : 1 );
		livingdraft_redirects_flash( 'good', __( 'Settings saved.', 'livingdraft-core' ) );
		livingdraft_redirects_go( array( 'tab' => 'settings' ) );
	}

	// CSV import.
	if ( isset( $_POST['ld_redirects_import'] ) ) {
		check_admin_referer( 'livingdraft_redirects_import' );
		if ( empty( $_FILES['csv_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			livingdraft_redirects_flash( 'bad', __( 'Choose a CSV file first.', 'livingdraft-core' ) );
			livingdraft_redirects_go( array( 'tab' => 'import' ) );
		}
		$counts = livingdraft_redirects_import_csv( $_FILES['csv_file']['tmp_name'], ! empty( $_POST['overwrite'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		livingdraft_redirects_flash(
			$counts['failed'] ? 'warn' : 'good',
			sprintf(
				/* translators: 1: imported, 2: already existed, 3: refused. */
				__( 'CSV import: %1$d added, %2$d already existed and were left alone, %3$d refused.', 'livingdraft-core' ),
				$counts['imported'],
				$counts['existing'],
				$counts['failed']
			),
			array_slice( $counts['errors'], 0, 20 )
		);
		livingdraft_redirects_go( array( 'tab' => 'import' ) );
	}

	// CSV export.
	if ( isset( $_GET['export'] ) && 'csv' === $_GET['export'] ) {
		check_admin_referer( 'livingdraft_redirects_export' );
		livingdraft_redirects_export_csv();
		exit;
	}
	// phpcs:enable
}
add_action( 'admin_init', 'livingdraft_redirects_handle_actions' );

/* ------------------------------------------------------------------
 * PAGE
 * ------------------------------------------------------------------ */

function livingdraft_redirects_admin_render() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'livingdraft-core' ) );
	}

	$tabs = array( 'redirects', 'pending', '404s', 'suggest', 'settings', 'import' );
	$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'redirects'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$tab  = in_array( $tab, $tabs, true ) ? $tab : 'redirects';

	$header_args = array( 'eyebrow' => __( 'The Living Draft Core · Redirections', 'livingdraft-core' ) );

	switch ( $tab ) {
		case 'pending':
			$header_args['title'] = __( 'Awaiting approval', 'livingdraft-core' );
			$header_args['desc']  = __( 'Redirects the site proposed when a story changed address or was unpublished. Nothing here is live until you approve it.', 'livingdraft-core' );
			break;
		case '404s':
			$header_args['title'] = __( '404 Log', 'livingdraft-core' );
			$header_args['desc']  = __( 'Addresses readers tried that do not exist. Send them somewhere useful, mark them gone, or ignore them for good.', 'livingdraft-core' );
			break;
		case 'suggest':
			$header_args['title'] = __( 'Suggested redirects', 'livingdraft-core' );
			$header_args['desc']  = __( 'For each broken address, likely destinations from your post history, sections and content. Pick one and the redirect is created.', 'livingdraft-core' );
			break;
		case 'settings':
			$header_args['title'] = __( 'Redirections settings', 'livingdraft-core' );
			$header_args['desc']  = __( 'What the site proposes on its own, and how redirects behave.', 'livingdraft-core' );
			break;
		case 'import':
			$header_args['title'] = __( 'Import & Export', 'livingdraft-core' );
			$header_args['desc']  = __( 'Bring redirects in from other plugins or a CSV file, or download a backup.', 'livingdraft-core' );
			break;
		default:
			$header_args['title']   = __( 'Redirections', 'livingdraft-core' );
			$header_args['desc']    = __( 'Live redirects: old addresses and where they send readers.', 'livingdraft-core' );
			$header_args['actions'] = array(
				array(
					'label' => __( 'Add redirect', 'livingdraft-core' ),
					'url'   => '#add-new',
					'class' => 'is-primary',
				),
			);
	}

	$has_new_shell = function_exists( 'livingdraft_admin_render_header' );

	if ( $has_new_shell ) {
		livingdraft_admin_render_header( $header_args );
	} else {
		echo '<div class="wrap"><h1>' . esc_html( $header_args['title'] ) . '</h1>';
	}

	if ( $has_new_shell && function_exists( 'livingdraft_admin_render_subtabs' ) ) {
		livingdraft_admin_render_subtabs(
			array(
				'redirects' => array(
					'label' => __( 'Redirects', 'livingdraft-core' ),
					'count' => livingdraft_redirects_count(),
				),
				'pending'   => array(
					'label' => __( 'Awaiting approval', 'livingdraft-core' ),
					'count' => livingdraft_redirects_pending_count(),
				),
				'404s'      => array(
					'label' => __( '404 Log', 'livingdraft-core' ),
					'count' => livingdraft_404s_count( LIVINGDRAFT_404_OPEN ),
				),
				'suggest'   => array(
					'label' => __( 'Suggestions', 'livingdraft-core' ),
					'count' => function_exists( 'livingdraft_suggest_actionable_count' ) ? livingdraft_suggest_actionable_count() : null,
				),
				'settings'  => array( 'label' => __( 'Settings', 'livingdraft-core' ) ),
				'import'    => array( 'label' => __( 'Import / Export', 'livingdraft-core' ) ),
			),
			$tab,
			'livingdraft-redirects'
		);
	}

	livingdraft_redirects_admin_notices();

	switch ( $tab ) {
		case 'pending':
			livingdraft_redirects_render_pending_tab();
			break;
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
		default:
			livingdraft_redirects_render_list_tab();
	}

	if ( $has_new_shell && function_exists( 'livingdraft_admin_render_footer' ) ) {
		livingdraft_admin_render_footer();
	} else {
		echo '</div>';
	}
}

/**
 * Print one notice.
 *
 * @param string $type    good|bad|warn|info.
 * @param string $message Main line.
 * @param array  $details Extra lines.
 */
function livingdraft_redirects_print_notice( $type, $message, $details = array() ) {
	$class = array(
		'good' => 'tld-notice',
		'bad'  => 'tld-notice is-bad',
		'warn' => 'tld-notice is-warn',
		'info' => 'tld-notice is-info',
	);
	echo '<div class="' . esc_attr( $class[ $type ] ?? 'tld-notice' ) . '"><p style="margin:0">' . esc_html( $message ) . '</p>';
	if ( $details ) {
		echo '<ul style="margin:8px 0 0 18px;list-style:disc">';
		foreach ( $details as $line ) {
			echo '<li style="word-break:break-word">' . esc_html( (string) $line ) . '</li>';
		}
		echo '</ul>';
	}
	echo '</div>';
}

function livingdraft_redirects_admin_notices() {
	$report = get_option( 'livingdraft_redirects_migration_report' );
	if ( is_array( $report ) ) {
		delete_option( 'livingdraft_redirects_migration_report' );
		livingdraft_redirects_print_notice(
			'info',
			__( 'Version 4.8.0 checked your existing redirects:', 'livingdraft-core' ),
			array(
				/* translators: %d: count. */
				sprintf( __( '%d "From" paths rewritten so they can match (full URLs, missing slashes, encoded characters).', 'livingdraft-core' ), (int) $report['normalised'] ),
				/* translators: %d: count. */
				sprintf( __( '%d duplicates merged.', 'livingdraft-core' ), (int) $report['merged'] ),
				/* translators: %d: count. */
				sprintf( __( '%d automatic redirects removed because they were hiding a live story.', 'livingdraft-core' ), (int) $report['unhidden'] ),
				/* translators: %d: count. */
				sprintf( __( '%d chains shortened to a single hop.', 'livingdraft-core' ), (int) $report['flattened'] ),
				/* translators: %d: count. */
				sprintf( __( '%d loops moved to Awaiting approval for you to review.', 'livingdraft-core' ), (int) $report['loops'] ),
			)
		);
	}

	$flash = livingdraft_redirects_take_flash();
	// A refused save is reported next to the form it came from.
	if ( $flash && empty( $flash['form'] ) ) {
		livingdraft_redirects_print_notice( $flash['type'], $flash['message'], $flash['details'] );
	}
}

/* ------------------------------------------------------------------
 * TAB: REDIRECTS (live) + ADD / EDIT FORM
 * ------------------------------------------------------------------ */

function livingdraft_redirects_render_list_tab() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$page      = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
	$per_page  = 20;
	$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	// phpcs:enable
	$redirects = livingdraft_redirects_list( array( 'page' => $page, 'per_page' => $per_page, 'search' => $search ) );
	$total     = livingdraft_redirects_count( $search );
	$pages     = (int) ceil( $total / $per_page );
	?>
	<form method="get" style="margin-bottom:12px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
		<input type="hidden" name="page" value="livingdraft-redirects">
		<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" class="tld-input" placeholder="<?php esc_attr_e( 'Search paths, URLs or notes', 'livingdraft-core' ); ?>" style="min-width:280px;max-width:360px">
		<button type="submit" class="tld-btn"><?php esc_html_e( 'Search', 'livingdraft-core' ); ?></button>
		<a class="tld-btn is-ghost" href="<?php echo esc_url( wp_nonce_url( livingdraft_redirects_admin_url( array( 'repair' => 1 ) ), 'livingdraft_redirects_repair' ) ); ?>"><?php esc_html_e( 'Check all redirects', 'livingdraft-core' ); ?></a>
	</form>

	<table class="tld-table">
		<thead>
			<tr>
				<th style="width:34%"><?php esc_html_e( 'From', 'livingdraft-core' ); ?></th>
				<th style="width:34%"><?php esc_html_e( 'To', 'livingdraft-core' ); ?></th>
				<th style="width:10%"><?php esc_html_e( 'Type', 'livingdraft-core' ); ?></th>
				<th style="width:8%"><?php esc_html_e( 'Hits', 'livingdraft-core' ); ?></th>
				<th style="width:14%"><?php esc_html_e( 'Actions', 'livingdraft-core' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $redirects ) ) : ?>
				<tr><td colspan="5" style="text-align:center;padding:24px;color:#666">
					<?php echo $search ? esc_html__( 'No live redirects match that search.', 'livingdraft-core' ) : esc_html__( 'No live redirects yet. Add one below, or approve a waiting proposal.', 'livingdraft-core' ); ?>
				</td></tr>
			<?php else : ?>
				<?php foreach ( $redirects as $r ) : ?>
					<tr>
						<td>
							<code style="word-break:break-all"><?php echo esc_html( $r->source_path ); ?></code>
							<?php if ( (int) $r->auto_generated ) : ?>
								<span class="tld-badge is-info" style="margin-left:4px"><?php esc_html_e( 'automatic', 'livingdraft-core' ); ?></span>
							<?php endif; ?>
							<?php if ( $r->notes ) : ?>
								<div class="tld-cell-meta"><?php echo esc_html( $r->notes ); ?></div>
							<?php endif; ?>
						</td>
						<td style="word-break:break-all">
							<?php if ( 410 === (int) $r->redirect_type ) : ?>
								<span class="tld-cell-meta"><?php esc_html_e( 'Readers see a "removed" page', 'livingdraft-core' ); ?></span>
							<?php else : ?>
								<a href="<?php echo esc_url( $r->target_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $r->target_url ); ?></a>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( livingdraft_redirects_type_label( $r->redirect_type ) ); ?></td>
						<td>
							<?php echo esc_html( number_format_i18n( (int) $r->hits ) ); ?>
							<?php if ( $r->last_hit ) : ?>
								<div class="tld-cell-meta">
									<?php
									/* translators: %s: time difference. */
									echo esc_html( sprintf( __( '%s ago', 'livingdraft-core' ), human_time_diff( strtotime( $r->last_hit ), current_time( 'timestamp' ) ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
									?>
								</div>
							<?php endif; ?>
						</td>
						<td>
							<a href="<?php echo esc_url( livingdraft_redirects_admin_url( array( 'edit' => (int) $r->id ) ) ); ?>#add-new"><?php esc_html_e( 'Edit', 'livingdraft-core' ); ?></a>
							&nbsp;·&nbsp;
							<a href="<?php echo esc_url( wp_nonce_url( livingdraft_redirects_admin_url( array( 'delete' => (int) $r->id ) ), 'livingdraft_redirect_delete_' . $r->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this redirect?', 'livingdraft-core' ) ); ?>');" style="color:#a00"><?php esc_html_e( 'Delete', 'livingdraft-core' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<?php livingdraft_redirects_render_pagination( $page, $pages ); ?>

	<?php
	livingdraft_redirects_render_form();
}

/**
 * Simple pagination.
 *
 * @param int $page  Current page.
 * @param int $pages Total pages.
 */
function livingdraft_redirects_render_pagination( $page, $pages ) {
	if ( $pages < 2 ) {
		return;
	}
	echo '<div class="tablenav" style="margin-top:12px"><div class="tablenav-pages">';
	echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		array(
			'base'      => add_query_arg( 'paged', '%#%' ),
			'format'    => '',
			'current'   => $page,
			'total'     => $pages,
			'prev_text' => '‹',
			'next_text' => '›',
		)
	);
	echo '</div></div>';
}

/**
 * The add / edit form.
 */
function livingdraft_redirects_render_form() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$flash   = livingdraft_redirects_take_flash();
	$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
	$row     = $edit_id ? livingdraft_redirects_get( $edit_id ) : null;

	$values = array(
		'id'         => $row ? (int) $row->id : 0,
		'source'     => $row ? $row->source_path : '',
		'target'     => $row ? $row->target_url : '',
		'type'       => $row ? (int) $row->redirect_type : 301,
		'notes'      => $row ? (string) $row->notes : '',
		'allow_live' => false,
	);

	if ( ! $row && isset( $_GET['prefill_source'] ) ) {
		$values['source'] = livingdraft_redirects_normalize_path( rawurldecode( wp_unslash( (string) $_GET['prefill_source'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}
	if ( ! $row && isset( $_GET['prefill_type'] ) ) {
		$values['type'] = (int) $_GET['prefill_type'];
	}
	// phpcs:enable

	if ( $flash && ! empty( $flash['form'] ) && (int) ( $flash['form']['id'] ?? 0 ) === $values['id'] ) {
		$values = array_merge( $values, $flash['form'] );
	}

	$is_pending = $row && 'pending' === $row->status;

	if ( $row && $is_pending ) {
		$heading = __( 'Edit waiting proposal', 'livingdraft-core' );
	} elseif ( $row ) {
		$heading = __( 'Edit redirect', 'livingdraft-core' );
	} else {
		$heading = __( 'Add a redirect', 'livingdraft-core' );
	}
	?>
	<h2 id="add-new" style="margin-top:32px"><?php echo esc_html( $heading ); ?></h2>
	<?php if ( $edit_id && ! $row ) : ?>
		<?php livingdraft_redirects_print_notice( 'warn', __( 'That redirect no longer exists. You can add a new one below.', 'livingdraft-core' ) ); ?>
	<?php endif; ?>

	<?php if ( $flash && ! empty( $flash['form'] ) ) : ?>
		<?php livingdraft_redirects_print_notice( $flash['type'], $flash['message'], $flash['details'] ); ?>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( livingdraft_redirects_admin_url() ); ?>" class="tld-card" style="max-width:900px">
		<?php wp_nonce_field( 'livingdraft_redirect_save' ); ?>
		<input type="hidden" name="redirect_id" value="<?php echo esc_attr( (string) $values['id'] ); ?>">
		<div class="tld-fields">
			<div class="tld-field">
				<label class="tld-label" for="source_path"><?php esc_html_e( 'From', 'livingdraft-core' ); ?></label>
				<input type="text" id="source_path" name="source_path" class="tld-input is-mono" value="<?php echo esc_attr( $values['source'] ); ?>" placeholder="/old-headline/" required>
				<p class="tld-help"><?php esc_html_e( 'The old address. A path like /old-headline/ or a full URL from this site — both work.', 'livingdraft-core' ); ?></p>
			</div>
			<div class="tld-field" id="ld-target-field">
				<label class="tld-label" for="target_url"><?php esc_html_e( 'To', 'livingdraft-core' ); ?></label>
				<input type="text" id="target_url" name="target_url" class="tld-input is-mono" value="<?php echo esc_attr( $values['target'] ); ?>" placeholder="/new-headline/ or https://…">
				<p class="tld-help"><?php esc_html_e( 'Where readers should land. A path on this site or a full URL anywhere.', 'livingdraft-core' ); ?></p>
			</div>
			<div class="tld-field">
				<label class="tld-label" for="redirect_type"><?php esc_html_e( 'Type', 'livingdraft-core' ); ?></label>
				<select id="redirect_type" name="redirect_type" class="tld-select">
					<?php
					$options = array(
						301 => __( '301 — Permanent (moved stories, renamed headlines)', 'livingdraft-core' ),
						302 => __( '302 — Temporary', 'livingdraft-core' ),
						307 => __( '307 — Temporary, keeps form data', 'livingdraft-core' ),
						308 => __( '308 — Permanent, keeps form data', 'livingdraft-core' ),
						410 => __( '410 — Gone (removed on purpose; no destination)', 'livingdraft-core' ),
					);
					foreach ( $options as $code => $label ) {
						printf( '<option value="%1$d" %2$s>%3$s</option>', (int) $code, selected( (int) $values['type'], $code, false ), esc_html( $label ) );
					}
					?>
				</select>
			</div>
			<div class="tld-field">
				<label class="tld-label" for="notes"><?php esc_html_e( 'Notes', 'livingdraft-core' ); ?></label>
				<input type="text" id="notes" name="notes" class="tld-input" value="<?php echo esc_attr( $values['notes'] ); ?>" maxlength="255" placeholder="<?php esc_attr_e( 'Why this redirect exists — for your own records', 'livingdraft-core' ); ?>">
			</div>
			<div class="tld-field">
				<label>
					<input type="checkbox" name="allow_live" value="1" <?php checked( ! empty( $values['allow_live'] ) ); ?>>
					<?php esc_html_e( 'Redirect even though a story is live here', 'livingdraft-core' ); ?>
				</label>
				<p class="tld-help"><?php esc_html_e( 'Leave unticked. The save is refused if the "From" address is a published story, so a story cannot disappear by accident.', 'livingdraft-core' ); ?></p>
			</div>
		</div>
		<p style="display:flex;gap:10px;flex-wrap:wrap">
			<?php if ( $is_pending ) : ?>
				<button type="submit" name="ld_redirect_save_approve" class="tld-btn is-primary"><?php esc_html_e( 'Save and approve', 'livingdraft-core' ); ?></button>
				<button type="submit" name="ld_redirect_save" class="tld-btn"><?php esc_html_e( 'Save, keep waiting', 'livingdraft-core' ); ?></button>
			<?php else : ?>
				<button type="submit" name="ld_redirect_save" class="tld-btn is-primary"><?php echo $row ? esc_html__( 'Update redirect', 'livingdraft-core' ) : esc_html__( 'Save redirect', 'livingdraft-core' ); ?></button>
			<?php endif; ?>
			<?php if ( $row ) : ?>
				<a class="tld-btn is-ghost" href="<?php echo esc_url( livingdraft_redirects_admin_url( array( 'tab' => $is_pending ? 'pending' : 'redirects' ) ) ); ?>"><?php esc_html_e( 'Cancel', 'livingdraft-core' ); ?></a>
			<?php endif; ?>
		</p>
	</form>
	<script>
		( function () {
			var type = document.getElementById( 'redirect_type' );
			var field = document.getElementById( 'ld-target-field' );
			if ( ! type || ! field ) { return; }
			function sync() { field.style.display = '410' === type.value ? 'none' : ''; }
			type.addEventListener( 'change', sync );
			sync();
		}() );
	</script>
	<?php
}

/* ------------------------------------------------------------------
 * TAB: AWAITING APPROVAL
 * ------------------------------------------------------------------ */

function livingdraft_redirects_render_pending_tab() {
	$page  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$rows  = livingdraft_redirects_list( array( 'status' => 'pending', 'page' => $page, 'per_page' => 30, 'orderby' => 'updated_at' ) );
	$total = livingdraft_redirects_count( '', 'pending' );
	?>
	<p style="max-width:720px;color:#555">
		<?php
		if ( (bool) get_option( 'livingdraft_redirects_core_fallback', true ) ) {
			esc_html_e( 'While a renamed story waits here, WordPress still sends readers from its old address to the new one, so nobody hits a dead end. Approving makes the redirect permanent and lets you choose a different destination.', 'livingdraft-core' );
		} else {
			esc_html_e( 'WordPress\'s own fallback is switched off in Settings, so old addresses listed here return "Not Found" until you approve them.', 'livingdraft-core' );
		}
		?>
	</p>

	<?php if ( $total > 0 ) : ?>
		<form method="post" action="<?php echo esc_url( livingdraft_redirects_admin_url() ); ?>" style="margin:0 0 12px">
			<?php wp_nonce_field( 'livingdraft_redirect_approve_all' ); ?>
			<button type="submit" name="ld_redirect_approve_all" class="tld-btn" onclick="return confirm('<?php echo esc_js( __( 'Approve every waiting proposal? Any that fail a check stay here with the reason.', 'livingdraft-core' ) ); ?>');">
				<?php
				/* translators: %d: count. */
				echo esc_html( sprintf( __( 'Approve all %d', 'livingdraft-core' ), $total ) );
				?>
			</button>
		</form>
	<?php endif; ?>

	<table class="tld-table">
		<thead>
			<tr>
				<th style="width:30%"><?php esc_html_e( 'Old address', 'livingdraft-core' ); ?></th>
				<th style="width:30%"><?php esc_html_e( 'Proposed', 'livingdraft-core' ); ?></th>
				<th style="width:22%"><?php esc_html_e( 'Why', 'livingdraft-core' ); ?></th>
				<th style="width:18%"><?php esc_html_e( 'Actions', 'livingdraft-core' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="4" style="text-align:center;padding:24px;color:#666">
					<?php esc_html_e( 'Nothing is waiting. When a published story changes address or is unpublished, the proposed redirect appears here.', 'livingdraft-core' ); ?>
				</td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><code style="word-break:break-all"><?php echo esc_html( $r->source_path ); ?></code></td>
						<td style="word-break:break-all">
							<?php if ( 410 === (int) $r->redirect_type ) : ?>
								<?php esc_html_e( 'Gone (410) — no destination', 'livingdraft-core' ); ?>
							<?php else : ?>
								<a href="<?php echo esc_url( $r->target_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $r->target_url ); ?></a>
								<div class="tld-cell-meta"><?php echo esc_html( livingdraft_redirects_type_label( $r->redirect_type ) ); ?></div>
							<?php endif; ?>
						</td>
						<td>
							<?php echo esc_html( (string) $r->notes ); ?>
							<div class="tld-cell-meta">
								<?php
								$when = $r->updated_at ? $r->updated_at : $r->created_at;
								/* translators: %s: time difference. */
								echo esc_html( sprintf( __( '%s ago', 'livingdraft-core' ), human_time_diff( strtotime( $when ), current_time( 'timestamp' ) ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
								?>
							</div>
						</td>
						<td>
							<a href="<?php echo esc_url( wp_nonce_url( livingdraft_redirects_admin_url( array( 'approve' => (int) $r->id ) ), 'livingdraft_redirect_approve_' . $r->id ) ); ?>" style="font-weight:600"><?php esc_html_e( 'Approve', 'livingdraft-core' ); ?></a>
							&nbsp;·&nbsp;
							<a href="<?php echo esc_url( livingdraft_redirects_admin_url( array( 'tab' => 'redirects', 'edit' => (int) $r->id ) ) ); ?>#add-new"><?php esc_html_e( 'Edit', 'livingdraft-core' ); ?></a>
							&nbsp;·&nbsp;
							<a href="<?php echo esc_url( wp_nonce_url( livingdraft_redirects_admin_url( array( 'reject' => (int) $r->id ) ), 'livingdraft_redirect_reject_' . $r->id ) ); ?>" style="color:#a00"><?php esc_html_e( 'Reject', 'livingdraft-core' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
	<?php
	livingdraft_redirects_render_pagination( $page, (int) ceil( $total / 30 ) );
}

/* ------------------------------------------------------------------
 * TAB: 404 LOG
 * ------------------------------------------------------------------ */

function livingdraft_redirects_render_404s_tab() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$view  = isset( $_GET['view'] ) && 'ignored' === $_GET['view'] ? 'ignored' : 'open';
	$page  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
	// phpcs:enable
	$state = 'ignored' === $view ? LIVINGDRAFT_404_IGNORED : LIVINGDRAFT_404_OPEN;
	$logs  = livingdraft_404s_list( array( 'page' => $page, 'per_page' => 30, 'resolved' => $state ) );
	$total = livingdraft_404s_count( $state );
	?>
	<p style="display:flex;gap:16px;margin:0 0 12px">
		<?php
		foreach ( array( 'open' => __( 'Open', 'livingdraft-core' ), 'ignored' => __( 'Ignored', 'livingdraft-core' ) ) as $key => $label ) {
			$count = livingdraft_404s_count( 'ignored' === $key ? LIVINGDRAFT_404_IGNORED : LIVINGDRAFT_404_OPEN );
			printf(
				'<a href="%1$s" style="%2$s">%3$s (%4$s)</a>',
				esc_url( livingdraft_redirects_admin_url( array( 'tab' => '404s', 'view' => $key ) ) ),
				$key === $view ? 'font-weight:600;color:inherit;text-decoration:none' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $count ) )
			);
		}
		?>
	</p>
	<p style="max-width:720px;color:#555">
		<?php esc_html_e( 'Only real readers are logged: crawlers, scanners, prefetch checks and probes for server files are filtered out. Ignored addresses stay ignored even if they are hit again.', 'livingdraft-core' ); ?>
	</p>

	<table class="tld-table">
		<thead>
			<tr>
				<th style="width:42%"><?php esc_html_e( 'Broken address', 'livingdraft-core' ); ?></th>
				<th style="width:8%"><?php esc_html_e( 'Hits', 'livingdraft-core' ); ?></th>
				<th style="width:16%"><?php esc_html_e( 'Came from', 'livingdraft-core' ); ?></th>
				<th style="width:12%"><?php esc_html_e( 'Last seen', 'livingdraft-core' ); ?></th>
				<th style="width:22%"><?php esc_html_e( 'Actions', 'livingdraft-core' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $logs ) ) : ?>
				<tr><td colspan="5" style="text-align:center;padding:24px;color:#666">
					<?php echo 'ignored' === $view ? esc_html__( 'Nothing ignored.', 'livingdraft-core' ) : esc_html__( 'No open 404s. Nothing readers tried is currently broken.', 'livingdraft-core' ); ?>
				</td></tr>
			<?php else : ?>
				<?php foreach ( $logs as $log ) : ?>
					<tr>
						<td>
							<code style="word-break:break-all"><?php echo esc_html( $log->url_path ); ?></code>
							<?php if ( $log->user_agent ) : ?>
								<div class="tld-cell-meta" style="word-break:break-all" title="<?php echo esc_attr( $log->user_agent ); ?>"><?php echo esc_html( mb_strimwidth( $log->user_agent, 0, 90, '…' ) ); ?></div>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( number_format_i18n( (int) $log->hits ) ); ?></td>
						<td style="font-size:12px;color:#666;word-break:break-all">
							<?php
							if ( $log->referrer ) {
								$host = wp_parse_url( $log->referrer, PHP_URL_HOST );
								echo esc_html( $host ? $host : $log->referrer );
							} else {
								esc_html_e( 'Direct / app', 'livingdraft-core' );
							}
							?>
						</td>
						<td style="font-size:12px">
							<?php
							/* translators: %s: time difference. */
							echo esc_html( sprintf( __( '%s ago', 'livingdraft-core' ), human_time_diff( strtotime( $log->last_seen ), current_time( 'timestamp' ) ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
							?>
						</td>
						<td>
							<?php if ( 'ignored' === $view ) : ?>
								<a href="<?php echo esc_url( wp_nonce_url( livingdraft_redirects_admin_url( array( 'unignore404' => (int) $log->id ) ), 'livingdraft_404_unignore_' . $log->id ) ); ?>"><?php esc_html_e( 'Restore', 'livingdraft-core' ); ?></a>
							<?php else : ?>
								<a href="<?php echo esc_url( livingdraft_redirects_admin_url( array( 'tab' => 'suggest', 'url_path' => rawurlencode( $log->url_path ) ) ) ); ?>" style="color:#2f7a3a;font-weight:500"><?php esc_html_e( 'Suggest', 'livingdraft-core' ); ?></a>
								&nbsp;·&nbsp;
								<a href="<?php echo esc_url( livingdraft_redirects_admin_url( array( 'tab' => 'redirects', 'prefill_source' => rawurlencode( $log->url_path ) ) ) ); ?>#add-new"><?php esc_html_e( 'Redirect', 'livingdraft-core' ); ?></a>
								&nbsp;·&nbsp;
								<a href="<?php echo esc_url( wp_nonce_url( livingdraft_redirects_admin_url( array( 'gone404' => (int) $log->id ) ), 'livingdraft_404_gone_' . $log->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Answer 410 Gone for this address? Search engines will drop it.', 'livingdraft-core' ) ); ?>');"><?php esc_html_e( 'Mark gone', 'livingdraft-core' ); ?></a>
								&nbsp;·&nbsp;
								<a href="<?php echo esc_url( wp_nonce_url( livingdraft_redirects_admin_url( array( 'ignore404' => (int) $log->id ) ), 'livingdraft_404_ignore_' . $log->id ) ); ?>" style="color:#666"><?php esc_html_e( 'Ignore', 'livingdraft-core' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
	<?php
	livingdraft_redirects_render_pagination( $page, (int) ceil( $total / 30 ) );
}

/* ------------------------------------------------------------------
 * TAB: SETTINGS
 * ------------------------------------------------------------------ */

function livingdraft_redirects_render_settings_tab() {
	$settings = array(
		'auto_slug'      => array(
			'option'  => 'livingdraft_redirects_auto_slug',
			'title'   => __( 'Proposals', 'livingdraft-core' ),
			'label'   => __( 'Propose a redirect when a published story changes address, is unpublished or is deleted', 'livingdraft-core' ),
			'help'    => __( 'Proposals wait under Awaiting approval. Nothing goes live until you approve it.', 'livingdraft-core' ),
		),
		'core_fallback'  => array(
			'option'  => 'livingdraft_redirects_core_fallback',
			'title'   => __( 'While waiting', 'livingdraft-core' ),
			'label'   => __( 'Let WordPress send readers from a renamed story\'s old address to its current one', 'livingdraft-core' ),
			'help'    => __( 'Recommended. WordPress does this on its own for renamed posts. Switch it off only if no reader should be redirected before you approve. That also stops WordPress guessing a story from a broken address, so readers see "Not Found" until you approve.', 'livingdraft-core' ),
		),
		'preserve_query' => array(
			'option'  => 'livingdraft_redirects_preserve_query',
			'title'   => __( 'Tracking', 'livingdraft-core' ),
			'label'   => __( 'Keep ?utm_source= and other parameters when redirecting', 'livingdraft-core' ),
			'help'    => __( 'So clicks from newsletters, WhatsApp and ads still show up correctly in analytics.', 'livingdraft-core' ),
		),
		'log_404'        => array(
			'option'  => 'livingdraft_redirects_log_404',
			'title'   => __( '404 log', 'livingdraft-core' ),
			'label'   => __( 'Log addresses readers tried that do not exist', 'livingdraft-core' ),
			'help'    => __( 'Machines are filtered out. The log keeps the most recent 5,000 addresses, plus everything you ignored.', 'livingdraft-core' ),
		),
		'traffic_advice' => array(
			'option'  => 'livingdraft_traffic_advice',
			'title'   => __( 'Prefetch', 'livingdraft-core' ),
			'label'   => __( 'Allow Chrome to prefetch stories from Google results', 'livingdraft-core' ),
			'help'    => __( 'Answers /.well-known/traffic-advice. Makes search clicks open faster. If your host serves /.well-known/ itself, this may have no effect.', 'livingdraft-core' ),
		),
	);
	?>
	<form method="post" action="<?php echo esc_url( livingdraft_redirects_admin_url() ); ?>" class="tld-card" style="max-width:760px">
		<?php wp_nonce_field( 'livingdraft_redirects_settings' ); ?>
		<div class="tld-fields">
			<?php foreach ( $settings as $name => $s ) : ?>
				<div class="tld-field">
					<span class="tld-label"><?php echo esc_html( $s['title'] ); ?></span>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( (bool) get_option( $s['option'], true ) ); ?>>
						<?php echo esc_html( $s['label'] ); ?>
					</label>
					<p class="tld-help"><?php echo esc_html( $s['help'] ); ?></p>
				</div>
			<?php endforeach; ?>
		</div>
		<p><button type="submit" name="ld_redirects_settings" class="tld-btn is-primary"><?php esc_html_e( 'Save settings', 'livingdraft-core' ); ?></button></p>
	</form>
	<?php
}

/* ------------------------------------------------------------------
 * TAB: IMPORT / EXPORT
 * ------------------------------------------------------------------ */

function livingdraft_redirects_render_import_tab() {
	if ( function_exists( 'livingdraft_redirects_import_render_sources' ) ) {
		livingdraft_redirects_import_render_sources();
	}
	?>
	<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;max-width:900px">
		<div class="tld-card">
			<h3><?php esc_html_e( 'Export', 'livingdraft-core' ); ?></h3>
			<p><?php esc_html_e( 'Every redirect, live and waiting, as a CSV file you can open in a spreadsheet or import again later.', 'livingdraft-core' ); ?></p>
			<p>
				<a class="tld-btn" href="<?php echo esc_url( wp_nonce_url( livingdraft_redirects_admin_url( array( 'export' => 'csv' ) ), 'livingdraft_redirects_export' ) ); ?>"><?php esc_html_e( 'Download CSV', 'livingdraft-core' ); ?></a>
			</p>
		</div>

		<div class="tld-card">
			<h3><?php esc_html_e( 'Import from CSV', 'livingdraft-core' ); ?></h3>
			<p><?php esc_html_e( 'First row is the header. Commas or semicolons both work. Leave the target empty for 410.', 'livingdraft-core' ); ?></p>
			<p><code style="display:block;padding:8px;background:#f6f7f7;white-space:pre">source_path,target_url,type,notes
/old-1/,https://example.com/new-1/,301,
/retracted-story/,,410,Legal</code></p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( livingdraft_redirects_admin_url() ); ?>">
				<?php wp_nonce_field( 'livingdraft_redirects_import' ); ?>
				<p><input type="file" name="csv_file" accept=".csv,text/csv" required></p>
				<p><label><input type="checkbox" name="overwrite" value="1"> <?php esc_html_e( 'Replace redirects that already exist for the same address', 'livingdraft-core' ); ?></label></p>
				<p><button type="submit" name="ld_redirects_import" class="tld-btn is-primary"><?php esc_html_e( 'Upload and import', 'livingdraft-core' ); ?></button></p>
			</form>
		</div>
	</div>
	<?php
}

/* ------------------------------------------------------------------
 * CSV
 * ------------------------------------------------------------------ */

/**
 * Import a CSV through the same rules as the form.
 *
 * Columns are found by header name (source_path, target_url, type, notes,
 * status), falling back to position. The apostrophe the exporter adds in
 * front of cells that start with = + - @ is removed again, so an export
 * imports cleanly.
 *
 * @param string $file      Uploaded temp file.
 * @param bool   $overwrite Replace existing rows.
 * @return array{imported:int,existing:int,failed:int,errors:string[]}
 */
function livingdraft_redirects_import_csv( $file, $overwrite = false ) {
	$out = array( 'imported' => 0, 'existing' => 0, 'failed' => 0, 'errors' => array() );

	$handle = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	if ( ! $handle ) {
		$out['errors'][] = __( 'The file could not be opened.', 'livingdraft-core' );
		return $out;
	}

	$first = (string) fgets( $handle );
	$first = preg_replace( '/^\xEF\xBB\xBF/', '', $first ); // BOM.
	$delim = substr_count( $first, ';' ) > substr_count( $first, ',' ) ? ';' : ',';
	$head  = array_map( 'strtolower', array_map( 'trim', str_getcsv( $first, $delim, '"', '' ) ) );

	$col = array( 'source' => 0, 'target' => 1, 'type' => 2, 'notes' => 3, 'status' => -1 );
	$map = array(
		'source' => array( 'source_path', 'source', 'from', 'old' ),
		'target' => array( 'target_url', 'target', 'to', 'new', 'destination' ),
		'type'   => array( 'type', 'redirect_type', 'code', 'status_code' ),
		'notes'  => array( 'notes', 'note', 'comment' ),
		'status' => array( 'status' ),
	);
	$has_header = false;
	foreach ( $map as $key => $names ) {
		foreach ( $names as $name ) {
			$pos = array_search( $name, $head, true );
			if ( false !== $pos ) {
				$col[ $key ] = (int) $pos;
				$has_header  = true;
				break;
			}
		}
	}
	$looks_like_path = static function ( $v ) {
		$v = ltrim( (string) $v, "' \t" );
		return '' !== $v && ( '/' === $v[0] || 1 === preg_match( '#^https?://#i', $v ) );
	};

	if ( ! $has_header ) {
		// No recognised header: accept the file only if its first line is
		// already a redirect, so a wrong file (an HTML page, a sitemap)
		// cannot fill the table with junk.
		$first_cells = str_getcsv( $first, $delim, '"', '' );
		if ( ! $looks_like_path( $first_cells[0] ?? '' ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$out['failed']   = 1;
			$out['errors'][] = __( 'This file does not look like a redirects CSV. The first row should be a header (source_path,target_url,type,notes) or a redirect starting with / or https://.', 'livingdraft-core' );
			return $out;
		}
		rewind( $handle ); // The first line is data.
	}

	$unguard = static function ( $v ) {
		$v = trim( (string) $v );
		if ( strlen( $v ) > 1 && "'" === $v[0] && in_array( $v[1], array( '=', '+', '-', '@' ), true ) ) {
			$v = substr( $v, 1 );
		}
		return $v;
	};

	$line = $has_header ? 1 : 0;
	while ( ( $row = fgetcsv( $handle, 0, $delim, '"', '' ) ) !== false ) {
		$line++;
		if ( ! is_array( $row ) || ( 1 === count( $row ) && null === $row[0] ) ) {
			continue;
		}

		$source = $unguard( $row[ $col['source'] ] ?? '' );
		$target = $unguard( $row[ $col['target'] ] ?? '' );
		$type   = isset( $row[ $col['type'] ] ) && '' !== trim( (string) $row[ $col['type'] ] ) ? (int) $row[ $col['type'] ] : 301;
		$notes  = sanitize_text_field( $unguard( $row[ $col['notes'] ] ?? '' ) );
		$status = ( $col['status'] >= 0 && 'pending' === strtolower( trim( (string) ( $row[ $col['status'] ] ?? '' ) ) ) ) ? 'pending' : 'active';

		if ( '' === $source ) {
			continue;
		}
		if ( ! $looks_like_path( $source ) ) {
			$out['failed']++;
			/* translators: 1: line number, 2: cell value. */
			$out['errors'][] = sprintf( __( 'Line %1$d (%2$s): the old address must start with / or https://.', 'livingdraft-core' ), $line, mb_strimwidth( $source, 0, 60, '…' ) );
			continue;
		}

		$result = livingdraft_redirects_save(
			array(
				'source'      => $source,
				'target'      => $target,
				'type'        => $type,
				'notes'       => $notes,
				'status'      => $status,
				'on_existing' => $overwrite ? 'replace' : 'skip',
			)
		);

		if ( $result['ok'] ) {
			$out['imported']++;
		} elseif ( 'existing' === $result['code'] ) {
			$out['existing']++;
		} else {
			$out['failed']++;
			/* translators: 1: line number, 2: path, 3: reason. */
			$out['errors'][] = sprintf( __( 'Line %1$d (%2$s): %3$s', 'livingdraft-core' ), $line, $source, $result['message'] );
		}
	}

	fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	return $out;
}

/**
 * Stream every redirect as CSV.
 */
function livingdraft_redirects_export_csv() {
	global $wpdb;
	$table = livingdraft_redirects_table();

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=livingdraft-redirects-' . gmdate( 'Y-m-d' ) . '.csv' );

	$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

	// Explicit $escape: PHP 8.4 deprecates relying on the default.
	fputcsv( $out, array( 'source_path', 'target_url', 'type', 'notes', 'status', 'hits', 'auto_generated', 'created_at' ), ',', '"', '' );

	$rows = $wpdb->get_results( "SELECT source_path, target_url, redirect_type, notes, status, hits, auto_generated, created_at FROM $table ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( (array) $rows as $r ) {
		fputcsv(
			$out,
			array(
				livingdraft_csv_safe( $r->source_path ),
				livingdraft_csv_safe( $r->target_url ),
				(int) $r->redirect_type,
				livingdraft_csv_safe( (string) $r->notes ),
				$r->status,
				(int) $r->hits,
				(int) $r->auto_generated,
				$r->created_at,
			),
			',',
			'"',
			''
		);
	}

	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	exit;
}

/**
 * Prefix a value with ' if it starts with =, +, -, @ so spreadsheets do
 * not run it as a formula. The importer strips it again.
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
