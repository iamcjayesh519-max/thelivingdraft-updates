<?php
/**
 * GSC admin page — configuration, quota status, indexing log, bulk submit.
 *
 * Setup is the tricky part for a non-developer user. Google's service
 * account flow has half a dozen steps across two consoles. The screen
 * walks through them in order with copy-friendly identifiers.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Save handler for the GSC settings form. Uses admin_init like the
 * other SEO tab handlers.
 */
function livingdraft_gsc_admin_handle_save() {
	if ( ! isset( $_POST['ld_gsc_save'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'ld_gsc_settings' );

	// Credentials JSON: only overwrite when a value was pasted. Type
	// "clear" to remove.
	if ( isset( $_POST['ld_gsc_json'] ) ) {
		$posted = trim( (string) wp_unslash( $_POST['ld_gsc_json'] ) );
		if ( 'clear' === strtolower( $posted ) ) {
			livingdraft_gsc_set_credentials( '' );
		} elseif ( '' !== $posted && '{' === substr( $posted, 0, 1 ) ) {
			$stored = livingdraft_gsc_set_credentials( $posted );
			if ( is_wp_error( $stored ) ) {
				wp_safe_redirect( add_query_arg( array( 'page' => 'livingdraft-seo', 'tab' => 'gsc', 'error' => rawurlencode( $stored->get_error_message() ) ), admin_url( 'admin.php' ) ) );
				exit;
			}
		}
	}

	$existing = livingdraft_gsc_get_settings();
	$settings = array(
		'property_url'      => isset( $_POST['property_url'] ) ? sanitize_text_field( wp_unslash( $_POST['property_url'] ) ) : $existing['property_url'],
		'analytics_enabled' => isset( $_POST['analytics_enabled'] ) ? 1 : 0,
		'indexing_enabled'  => isset( $_POST['indexing_enabled'] ) ? 1 : 0,
	);
	update_option( 'livingdraft_gsc_settings', $settings, false );

	// v3.4.0: auto-warm the analytics cache the first time everything's
	// valid. Previously, users saved the settings, went to a post to
	// look at metrics, saw "No cache yet", and had to come back here
	// to find the Sync button. Now the sync fires right after save —
	// blocking, but bounded to 30s by the underlying HTTP timeout, and
	// the meta cache surfaces any error on the next tab render.
	if ( $settings['analytics_enabled']
		&& '' !== $settings['property_url']
		&& livingdraft_gsc_configured() ) {
		$meta         = get_option( LD_GSC_CACHE_META_OPT, array() );
		$never_synced = empty( $meta['refreshed'] );
		$property_changed = ( $existing['property_url'] !== $settings['property_url'] );
		if ( $never_synced || $property_changed ) {
			livingdraft_gsc_refresh_analytics_cache();
		}
	}

	wp_safe_redirect( add_query_arg( array( 'page' => 'livingdraft-seo', 'tab' => 'gsc', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
	exit;
}
add_action( 'admin_init', 'livingdraft_gsc_admin_handle_save' );

/**
 * Test the connection with a real (small) API call so the user sees
 * within a second whether their config actually works.
 */
function livingdraft_gsc_ajax_test() {
	check_ajax_referer( 'ld_gsc_test', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'livingdraft-core' ) ) );
	}

	// A minimal Search Analytics query with a 1-row limit acts as the
	// cheapest possible auth-and-property-are-valid probe.
	$result = livingdraft_gsc_query_analytics( '', 1 );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success( array(
		'message' => __( 'Connection OK — property verified and Search Analytics readable.', 'livingdraft-core' ),
	) );
}
add_action( 'wp_ajax_ld_gsc_test', 'livingdraft_gsc_ajax_test' );

/**
 * Render the GSC tab under SEO.
 */
function livingdraft_seo_admin_render_gsc() {
	$s          = livingdraft_gsc_get_settings();
	$creds      = livingdraft_gsc_get_credentials();
	$configured = livingdraft_gsc_configured();
	$log        = get_option( LD_GSC_INDEXING_LOG, array() );
	$meta       = get_option( LD_GSC_CACHE_META_OPT, array() );

	if ( isset( $_GET['saved'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'livingdraft-core' ) . '</p></div>';
	}
	if ( isset( $_GET['error'] ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html( rawurldecode( (string) $_GET['error'] ) ) . '</p></div>';
	}
	?>

	<div class="tld-card">
		<div class="tld-card-header">
			<div>
				<span class="tld-card-eyebrow"><?php esc_html_e( 'Google Search Console', 'livingdraft-core' ); ?></span>
				<h2 class="tld-card-title"><?php esc_html_e( 'Setup', 'livingdraft-core' ); ?></h2>
			</div>
		</div>

		<p style="color:var(--tld-ink-3, #666);max-width:60ch;margin-top:0">
			<?php esc_html_e( 'Uses a Google Cloud service account rather than user OAuth. Setup is one-time but has multiple steps across two consoles. Once done, the service account talks to Google on your site\'s behalf without any refresh-token dance.', 'livingdraft-core' ); ?>
		</p>

		<details style="margin:16px 0;padding:14px 16px;background:#fafaf7;border:1px solid #e5e5e5">
			<summary style="cursor:pointer;font-family:var(--tld-mono, monospace);font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#666"><?php esc_html_e( 'Setup steps (click to expand)', 'livingdraft-core' ); ?></summary>
			<ol style="margin:12px 0 0 20px;font-size:13px;line-height:1.7">
				<li><?php echo wp_kses( __( 'Go to <a href="https://console.cloud.google.com/" target="_blank">Google Cloud Console</a> and create (or pick) a project.', 'livingdraft-core' ), array( 'a' => array( 'href' => true, 'target' => true ) ) ); ?></li>
				<li><?php echo wp_kses( __( 'Enable two APIs: <a href="https://console.cloud.google.com/apis/library/searchconsole.googleapis.com" target="_blank">Search Console API</a> and <a href="https://console.cloud.google.com/apis/library/indexing.googleapis.com" target="_blank">Indexing API</a>.', 'livingdraft-core' ), array( 'a' => array( 'href' => true, 'target' => true ) ) ); ?></li>
				<li><?php echo wp_kses( __( 'Under <strong>IAM & Admin → Service Accounts</strong>, create a new service account, then create a JSON key for it. Download the JSON.', 'livingdraft-core' ), array( 'strong' => array() ) ); ?></li>
				<li><?php echo wp_kses( __( 'In <a href="https://search.google.com/search-console/users" target="_blank">Search Console → Settings → Users and permissions</a>, add the service account\'s email address (looks like <code>name@project.iam.gserviceaccount.com</code>) as an <strong>Owner</strong>.', 'livingdraft-core' ), array( 'a' => array( 'href' => true, 'target' => true ), 'strong' => array(), 'code' => array() ) ); ?></li>
				<li><?php echo esc_html__( 'Paste the JSON key content into the form below, set your property URL, save, and click Test connection.', 'livingdraft-core' ); ?></li>
			</ol>
		</details>

		<form method="post">
			<?php wp_nonce_field( 'ld_gsc_settings' ); ?>

			<div class="tld-field">
				<label class="tld-label" for="ld_gsc_json"><?php esc_html_e( 'Service account JSON', 'livingdraft-core' ); ?></label>
				<textarea id="ld_gsc_json" name="ld_gsc_json" class="tld-input is-mono" rows="6"
					placeholder='{"type":"service_account","project_id":"...","private_key":"..."}'></textarea>
				<p class="tld-help">
					<?php if ( $creds ) : ?>
						<?php
						printf(
							/* translators: %s: service account email */
							esc_html__( 'Credentials stored. Account: %s. Paste new JSON to replace, or type "clear" to remove.', 'livingdraft-core' ),
							'<code>' . esc_html( $creds['client_email'] ) . '</code>'
						);
						?>
					<?php else : ?>
						<?php esc_html_e( 'Paste the entire contents of the JSON file you downloaded from Google Cloud.', 'livingdraft-core' ); ?>
					<?php endif; ?>
				</p>
			</div>

			<div class="tld-field">
				<label class="tld-label" for="ld_property_url"><?php esc_html_e( 'Property URL', 'livingdraft-core' ); ?></label>
				<input type="text" id="ld_property_url" name="property_url" class="tld-input is-mono"
					value="<?php echo esc_attr( $s['property_url'] ); ?>"
					placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>">
				<p class="tld-help">
					<?php esc_html_e( 'Two formats work: URL-prefix property ("https://example.com/", must match exactly incl. trailing slash) or domain property ("sc-domain:example.com"). Copy from Search Console\'s property picker.', 'livingdraft-core' ); ?>
				</p>
			</div>

			<hr style="margin:20px 0;border:0;border-top:1px solid #eee">

			<div class="tld-field">
				<label style="display:flex;align-items:flex-start;gap:10px;padding:14px;border:1px solid #d4d4d4;background:#fff;cursor:pointer">
					<input type="checkbox" name="analytics_enabled" value="1" <?php checked( $s['analytics_enabled'] ); ?> style="margin-top:2px">
					<span>
						<strong><?php esc_html_e( 'Sync analytics daily', 'livingdraft-core' ); ?></strong><br>
						<span style="color:var(--tld-ink-3, #666);font-size:13px">
							<?php esc_html_e( 'Pulls the top 1000 pages\' clicks, impressions, CTR, and average position from the last 28 days. Runs once daily via WP-Cron. Shown in every post\'s SEO metabox.', 'livingdraft-core' ); ?>
						</span>
					</span>
				</label>
			</div>

			<div class="tld-field">
				<label style="display:flex;align-items:flex-start;gap:10px;padding:14px;border:1px solid #d4d4d4;background:#fff;cursor:pointer">
					<input type="checkbox" name="indexing_enabled" value="1" <?php checked( $s['indexing_enabled'] ); ?> style="margin-top:2px">
					<span>
						<strong><?php esc_html_e( 'Auto-submit new posts to Indexing API', 'livingdraft-core' ); ?></strong><br>
						<span style="color:var(--tld-ink-3, #666);font-size:13px">
							<?php esc_html_e( 'On publish, notifies Google to crawl the URL. Daily quota is 200 URLs — enough for a typical newsroom. IMPORTANT: Google\'s Indexing API is officially for pages with JobPosting or BroadcastEvent schema. For other content types Google accepts requests and treats them as a "crawl hint" — usually honoured, sometimes ignored, no penalty. Real signals for regular content stay in the sitemap and news sitemap you already ship.', 'livingdraft-core' ); ?>
						</span>
					</span>
				</label>
			</div>

			<button type="submit" name="ld_gsc_save" class="tld-btn is-primary">
				<?php esc_html_e( 'Save', 'livingdraft-core' ); ?>
			</button>

			<?php if ( $configured ) : ?>
				<button type="button" id="ld-gsc-test-btn" class="tld-btn"
					data-ld-nonce="<?php echo esc_attr( wp_create_nonce( 'ld_gsc_test' ) ); ?>">
					<?php esc_html_e( 'Test connection', 'livingdraft-core' ); ?>
				</button>
				<span id="ld-gsc-test-result" style="margin-left:12px;font-family:var(--tld-mono, monospace);font-size:12px;color:#666"></span>
			<?php endif; ?>
		</form>
	</div>

	<?php /* v3.4.0: Diagnostics panel. Seven checks run in sequence,
	     pinpointing exactly which of the many possible auth / config
	     failures is happening. This is the single biggest quality-of-
	     life improvement for GSC setup. */ ?>
	<div class="tld-card">
		<div class="tld-card-header">
			<div>
				<span class="tld-card-eyebrow"><?php esc_html_e( 'Self-diagnostics', 'livingdraft-core' ); ?></span>
				<h2 class="tld-card-title"><?php esc_html_e( 'Why isn\'t this working?', 'livingdraft-core' ); ?></h2>
			</div>
		</div>
		<p style="color:var(--tld-ink-3, #666);max-width:60ch;margin-top:0">
			<?php esc_html_e( 'Runs seven checks that identify exactly which step is broken — openssl, credentials, property format, both API tokens, Search Console query, cache health. Each fail comes with a specific fix.', 'livingdraft-core' ); ?>
		</p>
		<button type="button" id="ld-gsc-diag-btn" class="tld-btn is-primary"
			data-ld-nonce="<?php echo esc_attr( wp_create_nonce( 'ld_gsc_diagnostics' ) ); ?>">
			<?php esc_html_e( 'Run diagnostics', 'livingdraft-core' ); ?>
		</button>
		<div id="ld-gsc-diag-results" style="margin-top:16px"></div>
	</div>

	<?php if ( $configured && $s['analytics_enabled'] ) : ?>
		<div class="tld-card">
			<div class="tld-card-header">
				<div>
					<span class="tld-card-eyebrow"><?php esc_html_e( '28-day rolling cache', 'livingdraft-core' ); ?></span>
					<h2 class="tld-card-title"><?php esc_html_e( 'Analytics', 'livingdraft-core' ); ?></h2>
				</div>
			</div>

			<?php
			$synced = isset( $meta['refreshed'] ) ? (int) $meta['refreshed'] : 0;
			$count  = isset( $meta['count'] ) ? (int) $meta['count'] : 0;
			?>

			<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
				<div style="padding:14px;background:#fafaf7;border:1px solid #e5e5e5">
					<div style="font-family:var(--tld-mono, monospace);font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:#666"><?php esc_html_e( 'Pages in cache', 'livingdraft-core' ); ?></div>
					<div style="font-family:Georgia, serif;font-size:24px;margin-top:4px"><?php echo esc_html( number_format_i18n( $count ) ); ?></div>
				</div>
				<div style="padding:14px;background:#fafaf7;border:1px solid #e5e5e5">
					<div style="font-family:var(--tld-mono, monospace);font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:#666"><?php esc_html_e( 'Last sync', 'livingdraft-core' ); ?></div>
					<div style="font-family:Georgia, serif;font-size:16px;margin-top:4px">
						<?php echo $synced ? esc_html( human_time_diff( $synced, time() ) . ' ago' ) : esc_html__( 'never', 'livingdraft-core' ); ?>
					</div>
				</div>
			</div>

			<button type="button" id="ld-gsc-sync-btn" class="tld-btn"
				data-ld-nonce="<?php echo esc_attr( wp_create_nonce( 'ld_gsc_sync' ) ); ?>">
				<?php esc_html_e( 'Sync now', 'livingdraft-core' ); ?>
			</button>
			<button type="button" id="ld-gsc-rebuild-btn" class="tld-btn"
				data-ld-nonce="<?php echo esc_attr( wp_create_nonce( 'ld_gsc_rebuild' ) ); ?>"
				title="<?php esc_attr_e( 'Re-stamp postmeta and termmeta from the existing cache. Use this if the Impr./Clicks columns on your Posts, Pages, or category / tag lists are showing dashes — it backfills the per-post index without burning a fresh API call.', 'livingdraft-core' ); ?>">
				<?php esc_html_e( 'Rebuild per-post index', 'livingdraft-core' ); ?>
			</button>
			<span id="ld-gsc-sync-status" style="margin-left:12px;font-family:var(--tld-mono, monospace);font-size:11px;color:#666"></span>
		</div>
	<?php endif; ?>

	<?php if ( $configured && $s['indexing_enabled'] ) :
		$today = livingdraft_gsc_indexing_submissions_today();
		$left  = max( 0, 200 - $today );

		// v3.6.0: parse queue filter/sort/pagination from the URL so
		// state is bookmarkable and pagination links are one-line.
		$q_sort_allowed   = array( 'longest_ago', 'never_submitted', 'newest', 'oldest', 'top_impressions', 'top_clicks', 'best_position' );
		$q_filter_allowed = array( 'all', 'never', 'stale_30', 'stale_90', 'recent_7' );
		$q_type_allowed   = array( 'any', 'post', 'page' );

		$q_sort   = isset( $_GET['q_sort'] ) && in_array( $_GET['q_sort'], $q_sort_allowed, true ) ? sanitize_key( $_GET['q_sort'] ) : 'longest_ago';
		$q_filter = isset( $_GET['q_filter'] ) && in_array( $_GET['q_filter'], $q_filter_allowed, true ) ? sanitize_key( $_GET['q_filter'] ) : 'stale_30';
		$q_type   = isset( $_GET['q_type'] ) && in_array( $_GET['q_type'], $q_type_allowed, true ) ? sanitize_key( $_GET['q_type'] ) : 'any';
		$q_page   = isset( $_GET['q_page'] ) ? max( 1, (int) $_GET['q_page'] ) : 1;

		$queue = livingdraft_gsc_queue_query( array(
			'sort'      => $q_sort,
			'filter'    => $q_filter,
			'post_type' => 'any' === $q_type ? array( 'post', 'page' ) : array( $q_type ),
			'per_page'  => 50,
			'page'      => $q_page,
		) );

		$sort_labels = array(
			'longest_ago'     => __( 'Longest since last submit (default)', 'livingdraft-core' ),
			'never_submitted' => __( 'Never submitted (newest first)', 'livingdraft-core' ),
			'newest'          => __( 'Newest published first', 'livingdraft-core' ),
			'oldest'          => __( 'Oldest published first', 'livingdraft-core' ),
			'top_impressions' => __( 'Highest impressions (28 days)', 'livingdraft-core' ),
			'top_clicks'      => __( 'Highest clicks (28 days)', 'livingdraft-core' ),
			'best_position'   => __( 'Best average position (28 days)', 'livingdraft-core' ),
		);
		$filter_labels = array(
			'all'      => __( 'All published posts', 'livingdraft-core' ),
			'never'    => __( 'Never submitted', 'livingdraft-core' ),
			'stale_30' => __( 'Not submitted in 30+ days (or never)', 'livingdraft-core' ),
			'stale_90' => __( 'Not submitted in 90+ days (or never)', 'livingdraft-core' ),
			'recent_7' => __( 'Submitted in the last 7 days', 'livingdraft-core' ),
		);
		$type_labels = array(
			'any'  => __( 'Posts + Pages', 'livingdraft-core' ),
			'post' => __( 'Posts only', 'livingdraft-core' ),
			'page' => __( 'Pages only', 'livingdraft-core' ),
		);
		?>
		<div class="tld-card">
			<div class="tld-card-header">
				<div>
					<span class="tld-card-eyebrow"><?php esc_html_e( 'Google Indexing API', 'livingdraft-core' ); ?></span>
					<h2 class="tld-card-title"><?php esc_html_e( 'Indexing queue', 'livingdraft-core' ); ?></h2>
				</div>
			</div>

			<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
				<div style="padding:14px;background:#fafaf7;border:1px solid #e5e5e5">
					<div style="font-family:var(--tld-mono, monospace);font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:#666"><?php esc_html_e( 'Submitted today', 'livingdraft-core' ); ?></div>
					<div style="font-family:Georgia, serif;font-size:24px;margin-top:4px"><?php echo esc_html( $today ); ?> / 200</div>
				</div>
				<div style="padding:14px;background:#fafaf7;border:1px solid #e5e5e5">
					<div style="font-family:var(--tld-mono, monospace);font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:#666"><?php esc_html_e( 'Quota remaining', 'livingdraft-core' ); ?></div>
					<div id="ld-gsc-quota-left" style="font-family:Georgia, serif;font-size:24px;margin-top:4px;color:<?php echo $left < 20 ? '#a32e2e' : ( $left < 50 ? '#b7791f' : '#2f7a3a' ); ?>"><?php echo esc_html( $left ); ?></div>
				</div>
			</div>

			<div style="padding:12px 14px;background:#fff;border:1px solid #e5e5e5;font-family:var(--tld-mono, monospace);font-size:11px;color:#666;margin-bottom:16px">
				<?php esc_html_e( 'Quota resets at UTC midnight. New posts auto-submit as they publish. Use the queue below to backfill or resubmit specific posts.', 'livingdraft-core' ); ?>
			</div>

			<!-- Filter / sort bar. Uses GET so state is bookmarkable
			     and pagination is trivial. -->
			<form method="get" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-bottom:14px;padding:12px;background:#fafaf7;border:1px solid #e5e5e5">
				<input type="hidden" name="page" value="livingdraft-seo">
				<input type="hidden" name="tab" value="gsc">
				<div>
					<label style="display:block;font-family:var(--tld-mono, monospace);font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:#666;margin-bottom:4px"><?php esc_html_e( 'Filter', 'livingdraft-core' ); ?></label>
					<select name="q_filter">
						<?php foreach ( $filter_labels as $val => $lab ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $q_filter, $val ); ?>><?php echo esc_html( $lab ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div>
					<label style="display:block;font-family:var(--tld-mono, monospace);font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:#666;margin-bottom:4px"><?php esc_html_e( 'Sort by', 'livingdraft-core' ); ?></label>
					<select name="q_sort">
						<?php foreach ( $sort_labels as $val => $lab ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $q_sort, $val ); ?>><?php echo esc_html( $lab ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div>
					<label style="display:block;font-family:var(--tld-mono, monospace);font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:#666;margin-bottom:4px"><?php esc_html_e( 'Post type', 'livingdraft-core' ); ?></label>
					<select name="q_type">
						<?php foreach ( $type_labels as $val => $lab ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $q_type, $val ); ?>><?php echo esc_html( $lab ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<button type="submit" class="button"><?php esc_html_e( 'Apply', 'livingdraft-core' ); ?></button>
				<div style="flex:1;text-align:right;color:#666;font-size:12px">
					<?php
					/* translators: 1: results on this page, 2: total matching results */
					echo esc_html( sprintf(
						__( 'Showing %1$d of %2$d matching posts', 'livingdraft-core' ),
						count( $queue['posts'] ),
						$queue['total']
					) );
					?>
				</div>
			</form>

			<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
				<div>
					<button type="button" id="ld-gsc-submit-selected" class="button button-primary" disabled
						data-ld-nonce="<?php echo esc_attr( wp_create_nonce( 'ld_gsc_index_batch' ) ); ?>">
						<?php esc_html_e( 'Submit selected', 'livingdraft-core' ); ?>
						(<span id="ld-gsc-selected-count">0</span>)
					</button>
					<span id="ld-gsc-batch-status" style="margin-left:10px;font-family:var(--tld-mono, monospace);font-size:11px;color:#666"></span>
				</div>
				<?php if ( $queue['pages'] > 1 ) : ?>
					<div style="font-family:var(--tld-mono, monospace);font-size:11px">
						<?php
						$base = add_query_arg( array( 'q_filter' => $q_filter, 'q_sort' => $q_sort, 'q_type' => $q_type ) );
						if ( $q_page > 1 ) {
							printf( '<a href="%s">&larr; %s</a>&nbsp;&nbsp;', esc_url( add_query_arg( 'q_page', $q_page - 1, $base ) ), esc_html__( 'Previous', 'livingdraft-core' ) );
						}
						echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'livingdraft-core' ), $q_page, $queue['pages'] ) );
						if ( $q_page < $queue['pages'] ) {
							printf( '&nbsp;&nbsp;<a href="%s">%s &rarr;</a>', esc_url( add_query_arg( 'q_page', $q_page + 1, $base ) ), esc_html__( 'Next', 'livingdraft-core' ) );
						}
						?>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( empty( $queue['posts'] ) ) : ?>
				<div style="padding:24px;background:#fafaf7;border:1px solid #e5e5e5;text-align:center;color:#666">
					<?php esc_html_e( 'No posts match this filter.', 'livingdraft-core' ); ?>
				</div>
			<?php else : ?>
				<table class="widefat striped ld-gsc-queue" style="font-size:12px">
					<thead>
						<tr>
							<th style="width:32px"><input type="checkbox" id="ld-gsc-select-all"></th>
							<th><?php esc_html_e( 'Title', 'livingdraft-core' ); ?></th>
							<th style="width:110px"><?php esc_html_e( 'Published', 'livingdraft-core' ); ?></th>
							<th style="width:130px"><?php esc_html_e( 'Last submitted', 'livingdraft-core' ); ?></th>
							<th style="width:90px;text-align:right"><?php esc_html_e( 'Impressions', 'livingdraft-core' ); ?></th>
							<th style="width:70px;text-align:right"><?php esc_html_e( 'Clicks', 'livingdraft-core' ); ?></th>
							<th style="width:90px"><?php esc_html_e( 'Actions', 'livingdraft-core' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $queue['posts'] as $qp ) :
						$indexed_at  = (int) get_post_meta( $qp->ID, '_ld_gsc_indexed_at', true );
						$impressions = (int) get_post_meta( $qp->ID, '_ld_gsc_impressions', true );
						$clicks      = (int) get_post_meta( $qp->ID, '_ld_gsc_clicks', true );
						$has_metrics = ( $impressions > 0 || $clicks > 0 );
						?>
						<tr>
							<td><input type="checkbox" class="ld-gsc-queue-select" value="<?php echo (int) $qp->ID; ?>"></td>
							<td>
								<a href="<?php echo esc_url( get_edit_post_link( $qp->ID ) ); ?>" style="text-decoration:none">
									<strong><?php echo esc_html( wp_trim_words( get_the_title( $qp ), 12 ) ); ?></strong>
								</a>
								<div style="font-family:var(--tld-mono, monospace);font-size:10px;color:#999;word-break:break-all"><?php echo esc_html( str_replace( home_url(), '', get_permalink( $qp ) ) ); ?></div>
							</td>
							<td style="color:#666"><?php echo esc_html( human_time_diff( strtotime( $qp->post_date_gmt ), time() ) . ' ago' ); ?></td>
							<td>
								<?php if ( $indexed_at > 0 ) : ?>
									<span style="color:#2f7a3a">✓</span> <?php echo esc_html( human_time_diff( $indexed_at, time() ) . ' ago' ); ?>
								<?php else : ?>
									<span style="color:#a37a1f;font-style:italic"><?php esc_html_e( 'never', 'livingdraft-core' ); ?></span>
								<?php endif; ?>
							</td>
							<td style="text-align:right;font-family:var(--tld-mono, monospace)">
								<?php echo $has_metrics ? esc_html( number_format_i18n( $impressions ) ) : '<span style="color:#ccc">—</span>'; ?>
							</td>
							<td style="text-align:right;font-family:var(--tld-mono, monospace)">
								<?php echo $has_metrics ? esc_html( number_format_i18n( $clicks ) ) : '<span style="color:#ccc">—</span>'; ?>
							</td>
							<td>
								<button type="button" class="button button-small ld-gsc-queue-submit-one"
									data-post-id="<?php echo (int) $qp->ID; ?>"
									data-nonce="<?php echo esc_attr( wp_create_nonce( 'ld_gsc_index_now' ) ); ?>">
									<?php echo $indexed_at > 0 ? esc_html__( 'Resubmit', 'livingdraft-core' ) : esc_html__( 'Submit', 'livingdraft-core' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( ! empty( $log ) ) : ?>
				<h3 style="margin-top:24px;font-family:var(--tld-serif, Georgia, serif);font-weight:400;font-size:16px">
					<?php esc_html_e( 'Recent submissions', 'livingdraft-core' ); ?>
				</h3>
				<table class="widefat striped" style="font-family:var(--tld-mono, monospace);font-size:11px">
					<thead>
						<tr>
							<th style="width:110px"><?php esc_html_e( 'When', 'livingdraft-core' ); ?></th>
							<th style="width:60px"><?php esc_html_e( 'Code', 'livingdraft-core' ); ?></th>
							<th style="width:60px"><?php esc_html_e( 'Source', 'livingdraft-core' ); ?></th>
							<th><?php esc_html_e( 'URL', 'livingdraft-core' ); ?></th>
							<th><?php esc_html_e( 'Result', 'livingdraft-core' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_slice( $log, 0, 20 ) as $row ) :
							$ok = 200 === (int) ( $row['code'] ?? 0 );
							?>
							<tr>
								<td><?php echo esc_html( human_time_diff( (int) ( $row['time'] ?? 0 ), time() ) . ' ago' ); ?></td>
								<td style="color:<?php echo $ok ? '#2f7a3a' : '#a32e2e'; ?>"><?php echo esc_html( $row['code'] ?? '-' ); ?></td>
								<td><?php echo esc_html( $row['source'] ?? '-' ); ?></td>
								<td style="word-break:break-all;font-size:10px"><?php echo esc_html( $row['url'] ?? '' ); ?></td>
								<td><?php echo esc_html( $row['message'] ?? '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<script>
	(function () {
		function bind( id, action, onSuccess ) {
			var btn = document.getElementById( id );
			if ( ! btn ) return;
			var status = document.getElementById( id.replace( '-btn', '' ) + ( id.indexOf( 'test' ) >= 0 ? '-result' : '-status' ) );
			btn.addEventListener( 'click', function () {
				var orig = btn.innerHTML;
				btn.disabled = true;
				if ( status ) { status.textContent = 'Working…'; status.style.color = '#666'; }
				var fd = new FormData();
				fd.append( 'action', action );
				fd.append( 'nonce', btn.getAttribute( 'data-ld-nonce' ) );
				return fetch( ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						if ( ! res || ! res.success ) {
							if ( status ) { status.textContent = ( res && res.data && res.data.message ) || 'Failed'; status.style.color = '#a32e2e'; }
							return null;
						}
						if ( onSuccess ) return onSuccess( res.data, status, btn, orig );
						if ( status ) { status.textContent = ( res.data && res.data.message ) || 'OK'; status.style.color = '#2f7a3a'; }
						return res.data;
					} )
					.catch( function ( err ) {
						if ( status ) { status.textContent = err.message; status.style.color = '#a32e2e'; }
					} )
					.finally( function () {
						btn.disabled = false;
						btn.innerHTML = orig;
					} );
			} );
		}

		bind( 'ld-gsc-test-btn', 'ld_gsc_test' );

		bind( 'ld-gsc-sync-btn', 'ld_gsc_sync_analytics', function ( data, status ) {
			if ( status ) {
				status.textContent = 'Synced ' + data.count + ' pages.';
				status.style.color = '#2f7a3a';
			}
			setTimeout( function () { window.location.reload(); }, 1500 );
		} );

		// v3.7.1: Rebuild per-post/term index from existing cache. No
		// Google API call — cheap and instant even for large sites.
		bind( 'ld-gsc-rebuild-btn', 'ld_gsc_rebuild_index', function ( data, status ) {
			if ( status ) {
				status.textContent = 'Stamped ' + data.posts_stamped + ' posts + ' + data.terms_stamped + ' terms from ' + data.cache_size + ' cached URLs.';
				status.style.color = '#2f7a3a';
			}
		} );

		/* v3.4.0: Diagnostics. Renders a pass/fail table inline. Each
		   check gets a colored status pill and, on fail/warn, a
		   specific "how to fix" line. */
		var diagBtn = document.getElementById( 'ld-gsc-diag-btn' );
		if ( diagBtn ) {
			var diagOut = document.getElementById( 'ld-gsc-diag-results' );
			diagBtn.addEventListener( 'click', function () {
				var orig = diagBtn.innerHTML;
				diagBtn.disabled = true;
				diagBtn.innerHTML = '<?php echo esc_js( __( 'Running…', 'livingdraft-core' ) ); ?>';
				diagOut.innerHTML = '';
				var fd = new FormData();
				fd.append( 'action', 'ld_gsc_diagnostics' );
				fd.append( 'nonce', diagBtn.getAttribute( 'data-ld-nonce' ) );
				fetch( ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						if ( ! res || ! res.success ) {
							diagOut.innerHTML = '<div style="color:#a32e2e">Diagnostics failed to run.</div>';
							return;
						}
						renderDiagnostics( res.data.checks );
					} )
					.catch( function () {
						diagOut.innerHTML = '<div style="color:#a32e2e">Network error running diagnostics.</div>';
					} )
					.finally( function () {
						diagBtn.disabled = false;
						diagBtn.innerHTML = orig;
					} );
			} );

			function renderDiagnostics( checks ) {
				var colors = { pass: '#2f7a3a', warn: '#b7791f', fail: '#a32e2e', skip: '#8a8a8a' };
				var glyphs = { pass: '✓', warn: '!', fail: '✗', skip: '·' };
				var pills = { pass: 'PASS', warn: 'WARN', fail: 'FAIL', skip: 'SKIP' };
				var html = '<table class="widefat striped" style="font-size:12px"><tbody>';
				checks.forEach( function ( c ) {
					var col = colors[ c.status ] || '#666';
					html += '<tr>';
					html += '<td style="width:40px;font-size:16px;color:' + col + ';text-align:center;font-weight:bold">' + glyphs[ c.status ] + '</td>';
					html += '<td style="width:90px"><span style="display:inline-block;padding:2px 8px;background:' + col + ';color:#fff;font-family:var(--tld-mono, monospace);font-size:10px;letter-spacing:.08em">' + pills[ c.status ] + '</span></td>';
					html += '<td><div style="font-weight:500;margin-bottom:2px">' + escapeHtml( c.name ) + '</div>';
					html += '<div style="color:#444;font-size:11px">' + escapeHtml( c.detail ) + '</div>';
					if ( c.fix ) {
						html += '<div style="margin-top:6px;padding:6px 10px;background:#fafaf7;border-left:3px solid ' + col + ';font-size:11px;color:#333"><strong>Fix:</strong> ' + escapeHtml( c.fix ) + '</div>';
					}
					html += '</td></tr>';
				} );
				html += '</tbody></table>';
				diagOut.innerHTML = html;
			}

			function escapeHtml( s ) {
				return String( s == null ? '' : s )
					.replace( /&/g, '&amp;' )
					.replace( /</g, '&lt;' )
					.replace( />/g, '&gt;' )
					.replace( /"/g, '&quot;' );
			}
		}

		/* v3.6.0: Queue table interactions.
		   - Select-all checkbox toggles all row checkboxes on the current page.
		   - Row checkbox change updates the selected-count + button state.
		   - Per-row "Submit" button calls the single-URL endpoint.
		   - "Submit selected" button chunks the checked IDs into batches
		     of 10 and calls the batch endpoint sequentially, updating
		     status and quota-left between each chunk. */
		var selectAll = document.getElementById( 'ld-gsc-select-all' );
		var rowChecks = document.querySelectorAll( '.ld-gsc-queue-select' );
		var submitBtn = document.getElementById( 'ld-gsc-submit-selected' );
		var countEl   = document.getElementById( 'ld-gsc-selected-count' );
		var batchStat = document.getElementById( 'ld-gsc-batch-status' );
		var quotaEl   = document.getElementById( 'ld-gsc-quota-left' );

		function refreshSelection() {
			var selected = getSelectedIds();
			if ( countEl ) countEl.textContent = selected.length;
			if ( submitBtn ) submitBtn.disabled = ( 0 === selected.length );
			if ( selectAll ) {
				// Reflect indeterminate state — some but not all checked.
				var totalOnPage = rowChecks.length;
				var checkedOnPage = 0;
				rowChecks.forEach( function ( c ) { if ( c.checked ) checkedOnPage++; } );
				selectAll.indeterminate = ( checkedOnPage > 0 && checkedOnPage < totalOnPage );
				selectAll.checked       = ( checkedOnPage > 0 && checkedOnPage === totalOnPage );
			}
		}
		function getSelectedIds() {
			var out = [];
			rowChecks.forEach( function ( c ) { if ( c.checked ) out.push( c.value ); } );
			return out;
		}
		if ( selectAll ) {
			selectAll.addEventListener( 'change', function () {
				rowChecks.forEach( function ( c ) { c.checked = selectAll.checked; } );
				refreshSelection();
			} );
		}
		rowChecks.forEach( function ( c ) {
			c.addEventListener( 'change', refreshSelection );
		} );

		// Per-row single submit — reuses the existing ld_gsc_index_now endpoint.
		document.querySelectorAll( '.ld-gsc-queue-submit-one' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var origLabel = btn.textContent;
				btn.disabled = true;
				btn.textContent = 'Submitting…';
				var fd = new FormData();
				fd.append( 'action', 'ld_gsc_index_now' );
				fd.append( 'nonce', btn.getAttribute( 'data-nonce' ) );
				fd.append( 'post_id', btn.getAttribute( 'data-post-id' ) );
				fetch( ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						if ( res && res.success ) {
							btn.textContent = '✓';
							btn.style.color = '#2f7a3a';
							if ( quotaEl && res.data && typeof res.data.quota_left !== 'undefined' ) {
								quotaEl.textContent = res.data.quota_left;
							}
						} else {
							btn.textContent = '✗';
							btn.style.color = '#a32e2e';
							btn.title = ( res && res.data && res.data.message ) || 'Failed';
							setTimeout( function () { btn.textContent = origLabel; btn.style.color = ''; btn.disabled = false; }, 3000 );
						}
					} )
					.catch( function () {
						btn.textContent = 'Network error';
						setTimeout( function () { btn.textContent = origLabel; btn.disabled = false; }, 2000 );
					} );
			} );
		} );

		// Bulk-submit — chunk the selected IDs into batches of 10 and
		// dispatch sequentially, updating status between chunks.
		if ( submitBtn ) {
			submitBtn.addEventListener( 'click', function () {
				var all = getSelectedIds();
				if ( ! all.length ) return;
				if ( ! window.confirm( 'Submit ' + all.length + ' post(s) to the Indexing API? Each counts against the 200/day quota.' ) ) {
					return;
				}
				submitBtn.disabled = true;
				var totalSelected = all.length;
				var totalOk = 0, totalFail = 0, totalSkipped = 0;

				function processNext() {
					if ( all.length === 0 ) {
						batchStat.textContent = totalOk + ' submitted · ' + totalFail + ' failed · ' + totalSkipped + ' skipped · quota left ' + ( quotaEl ? quotaEl.textContent : '?' );
						batchStat.style.color = totalFail > 0 ? '#a37a1f' : '#2f7a3a';
						setTimeout( function () { window.location.reload(); }, 2500 );
						return;
					}
					var chunk = all.splice( 0, 10 );
					batchStat.textContent = 'Submitting… (' + ( totalSelected - all.length ) + ' / ' + totalSelected + ')';
					batchStat.style.color = '#666';
					var fd = new FormData();
					fd.append( 'action', 'ld_gsc_index_batch' );
					fd.append( 'nonce', submitBtn.getAttribute( 'data-ld-nonce' ) );
					chunk.forEach( function ( id ) { fd.append( 'ids[]', id ); } );
					fetch( ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( res ) {
							if ( ! res || ! res.success ) {
								batchStat.textContent = ( res && res.data && res.data.message ) || 'Failed';
								batchStat.style.color = '#a32e2e';
								submitBtn.disabled = false;
								return;
							}
							totalOk      += res.data.submitted || 0;
							totalFail    += res.data.failed || 0;
							totalSkipped += res.data.skipped || 0;
							if ( quotaEl && typeof res.data.quota_left !== 'undefined' ) {
								quotaEl.textContent = res.data.quota_left;
							}
							if ( res.data.quota_hit ) {
								batchStat.textContent = 'Daily quota reached — submitted ' + totalOk + ' of ' + totalSelected + '. Resumes at UTC midnight.';
								batchStat.style.color = '#a37a1f';
								setTimeout( function () { window.location.reload(); }, 2500 );
								return;
							}
							setTimeout( processNext, 800 );
						} )
						.catch( function ( err ) {
							batchStat.textContent = 'Network error: ' + err.message;
							batchStat.style.color = '#a32e2e';
							submitBtn.disabled = false;
						} );
				}
				processNext();
			} );
		}

		refreshSelection();
	})();
	</script>
	<?php
}
