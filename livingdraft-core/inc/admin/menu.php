<?php
/**
 * The Living Draft Core — Admin infrastructure.
 *
 * This file owns the top-level "The Living Draft" menu in wp-admin and
 * exposes helpers every admin page uses to render its header, tabs, and
 * shell markup.
 *
 * === WHY A SEPARATE FILE ===
 *
 * Every page in this plugin (Overview, Redirections, Subscribers,
 * Settings, etc.) needs the same header strip, the same page title
 * treatment, the same tab navigation. Putting that in one place means
 * a design tweak lands everywhere at once.
 *
 * === HOW TO ADD A NEW PAGE ===
 *
 * 1. Add a livingdraft_admin_register_page( ... ) call inside the
 *    'admin_menu' hook wherever your module is registered.
 * 2. Your callback renders the inside of `<div class="tld-main">`. Header
 *    and tabs are added automatically via the callback wrapper.
 * 3. Add your page to $LIVINGDRAFT_ADMIN_TABS below so it appears in the
 *    top-level nav.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------
 * 1. TOP-LEVEL MENU + SUBMENU ITEMS
 * ------------------------------------------------------------------ */

/**
 * The Living Draft top-level menu.
 *
 * Uses `dashicons-edit` (pencil) as the icon — matches the editorial
 * identity better than a generic admin cog. Position 30 places it near
 * Posts/Media, where editors already look.
 */
function livingdraft_admin_menu() {
	add_menu_page(
		__( 'The Living Draft', 'livingdraft-core' ),
		__( 'The Living Draft', 'livingdraft-core' ),
		'edit_posts', // Reachable by editors, not just admins.
		'livingdraft-overview',
		'livingdraft_admin_render_overview',
		'dashicons-edit',
		30
	);

	// Overview (same slug as parent — replaces the auto-generated "The Living
	// Draft" duplicate submenu that WordPress adds by default).
	add_submenu_page(
		'livingdraft-overview',
		__( 'Overview', 'livingdraft-core' ),
		__( 'Overview', 'livingdraft-core' ),
		'edit_posts',
		'livingdraft-overview',
		'livingdraft_admin_render_overview'
	);

	// Redirections — page callback lives in redirects-admin.php.
	add_submenu_page(
		'livingdraft-overview',
		__( 'Redirections', 'livingdraft-core' ),
		__( 'Redirections', 'livingdraft-core' ),
		'manage_options',
		'livingdraft-redirects',
		'livingdraft_redirects_admin_render'
	);

	// Settings — was Settings > The Living Draft. Now under our menu.
	add_submenu_page(
		'livingdraft-overview',
		__( 'Settings', 'livingdraft-core' ),
		__( 'Settings', 'livingdraft-core' ),
		'manage_options',
		'livingdraft-settings',
		'livingdraft_admin_render_settings'
	);
}
add_action( 'admin_menu', 'livingdraft_admin_menu' );

/**
 * Tab registry — retired in 4.4.0.
 *
 * Every Core page used to print a horizontal strip of links to Overview,
 * Redirections, Timelines, Mail, SEO and Settings, directly beneath the page
 * title. WordPress's own sidebar lists exactly the same six destinations,
 * three inches to the left, at the same moment. Two navigations pointing at
 * the same places is not redundancy that helps — it is a second thing to
 * scan, a second thing to keep in sync, and roughly sixty pixels of vertical
 * space taken from every screen in the plugin.
 *
 * The sidebar wins because it is always visible, it is where WordPress users
 * already look, and it survives the plugin being deactivated.
 *
 * The function is kept, returning an empty array, because modules registered
 * onto the `livingdraft_admin_tabs` filter and a third-party or child
 * customisation may still do so. The filter fires; nothing renders.
 *
 * @deprecated 4.4.0 Use the WordPress submenu.
 * @return array Always empty.
 */
function livingdraft_admin_tabs() {
	return (array) apply_filters( 'livingdraft_admin_tabs', array() );
}

/**
 * Settings sections.
 *
 * === WHY THERE IS A REGISTRY AND NOT FIVE SCREENS ===
 *
 * Configuration for this plugin used to live in four unrelated places: AI
 * keys under SEO → AI, the update channel on its own page, Listen bolted to
 * the bottom of Settings, and mail settings on a sub-tab of Mail. Four
 * mental models for "configure the plugin", and no way to answer "where do I
 * set X" except by remembering.
 *
 * One screen, one rail down the left, one section per module. A module
 * registers a label and a callback and does not care how it is presented;
 * adding a section is four lines and it lands in the same place as every
 * other one.
 *
 * @since 4.4.0
 * @return array
 */
function livingdraft_admin_settings_sections() {
	/**
	 * Register a settings section.
	 *
	 * Each entry: label (string), desc (string), render (callable),
	 * cap (string), group (string, for the rail eyebrow).
	 *
	 * @since 4.4.0
	 * @param array $sections Registered sections.
	 */
	$sections = (array) apply_filters( 'livingdraft_settings_panels', array() );

	return array_filter(
		$sections,
		function ( $section ) {
			return is_callable( $section['render'] ?? null )
				&& current_user_can( $section['cap'] ?? 'manage_options' );
		}
	);
}

/* ------------------------------------------------------------------
 * 2. ASSET LOADING — only on Core plugin pages
 * ------------------------------------------------------------------ */

/**
 * Enqueue admin.css on Core plugin admin screens.
 *
 * Detected by page slug starting with "livingdraft-". Anywhere else in
 * wp-admin, we don't load anything — no risk of colliding with other
 * plugins' styles.
 */
function livingdraft_admin_enqueue_assets( $hook_suffix ) {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

	if ( 0 !== strpos( $page, 'livingdraft-' ) ) {
		return;
	}

	$rel = 'assets/css/admin.css';
	$abs = LIVINGDRAFT_CORE_DIR . $rel;

	if ( ! file_exists( $abs ) ) {
		return;
	}

	wp_enqueue_style(
		'livingdraft-core-admin',
		LIVINGDRAFT_CORE_URL . $rel,
		array(),
		(string) filemtime( $abs )
	);

	// The .tld-admin class lives on the wrapper <div> opened by
	// livingdraft_admin_render_header(). It used to also be pushed onto
	// <html> here so the token block on :root.tld-admin would take effect —
	// but that meant the layout rule ".tld-admin { margin: -10px -20px ... }"
	// applied to <html> as well, producing a horizontal scrollbar on every
	// plugin page. The tokens now live on ".tld-admin" and inherit down
	// through the wrapper div, so the class on <html> is no longer needed.
}
add_action( 'admin_enqueue_scripts', 'livingdraft_admin_enqueue_assets' );

/* ------------------------------------------------------------------
 * 3. SHARED PAGE SHELL — header, tabs, wrapper markup
 * ------------------------------------------------------------------ */

/**
 * Render the header + tabs at the top of every Core admin page.
 * Every page callback calls this once before rendering its body.
 *
 * @param array $args {
 *   @type string $eyebrow  Small mono text above title. e.g. "Redirections"
 *   @type string $title    Page title in serif. Required.
 *   @type string $desc     Subtitle description.
 *   @type array  $actions  List of buttons for top-right, each an array of
 *                          [label, url, class]. class defaults to 'is-ghost'.
 *   @type string $active   Which tab slug is active. Defaults to current ?page.
 * }
 */
function livingdraft_admin_render_header( $args = array() ) {
	$defaults = array(
		'eyebrow' => __( 'The Living Draft Core', 'livingdraft-core' ),
		'title'   => '',
		'desc'    => '',
		'actions' => array(),
		'active'  => isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '',
	);
	$args = wp_parse_args( $args, $defaults );
	?>
	<div class="tld-admin" style="margin-top:0">
		<div class="tld-header">
			<div class="tld-header-strip"><?php echo esc_html( $args['eyebrow'] ); ?></div>
			<div class="tld-header-row">
				<div>
					<h1 class="tld-header-title"><?php echo esc_html( $args['title'] ); ?></h1>
					<?php if ( ! empty( $args['desc'] ) ) : ?>
						<p class="tld-header-desc"><?php echo esc_html( $args['desc'] ); ?></p>
					<?php endif; ?>
				</div>
				<?php if ( ! empty( $args['actions'] ) ) : ?>
					<div class="tld-header-actions">
						<?php foreach ( $args['actions'] as $action ) : ?>
							<a href="<?php echo esc_url( $action['url'] ); ?>" class="tld-btn <?php echo esc_attr( $action['class'] ?? 'is-ghost' ); ?>">
								<?php echo esc_html( $action['label'] ); ?>
							</a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<div class="tld-main">
	<?php
}

/**
 * Close the wrapper opened by livingdraft_admin_render_header().
 * Every page callback calls this at the end.
 *
 * v3.8.0: prints an editor's-note strip before closing, mirroring the
 * footer strapline on the frontend site. Purely decorative; adds a
 * signature to every plugin page without occupying real estate.
 */
function livingdraft_admin_render_footer() {
	?>
	<div class="tld-editor-note">
		<span class="ten-quote"><?php esc_html_e( 'Every story lives beyond the headline.', 'livingdraft-core' ); ?></span>
		<span class="ten-copy"><?php
			printf(
				/* translators: %s: current year */
				esc_html__( '© %s · Corrections are published, not quietly made.', 'livingdraft-core' ),
				esc_html( date_i18n( 'Y' ) )
			);
		?></span>
	</div>
	<?php
	echo "</div></div>\n"; // Close .tld-main and .tld-admin
}

/**
 * Render a module-level sub-nav.
 *
 * v3.8.0: replaces three copies of inline-styled sub-nav HTML that
 * used to live in redirects-admin.php, seo-admin.php, and seo-desk.php.
 * One helper, one component, one place to fix a design tweak.
 *
 * @param array  $tabs   Slug => label (string) OR slug => array( 'label' => …, 'count' => int|null, 'cap' => string|null ).
 * @param string $active Slug of the currently active tab.
 * @param string $page   Parent page slug used to build the URL (?page=…&tab=…).
 */
function livingdraft_admin_render_subtabs( $tabs, $active, $page ) {
	if ( empty( $tabs ) ) {
		return;
	}
	echo '<nav class="tld-subtabs">';
	foreach ( $tabs as $slug => $tab ) {
		$label = is_array( $tab ) ? ( $tab['label'] ?? $slug ) : (string) $tab;
		$count = is_array( $tab ) && isset( $tab['count'] ) ? $tab['count'] : null;
		if ( is_array( $tab ) && ! empty( $tab['cap'] ) && ! current_user_can( $tab['cap'] ) ) {
			continue;
		}
		$url       = add_query_arg(
			array( 'page' => $page, 'tab' => $slug ),
			admin_url( 'admin.php' )
		);
		$is_active = ( $slug === $active );
		printf(
			'<a href="%s" class="%s">%s%s</a>',
			esc_url( $url ),
			$is_active ? 'is-active' : '',
			esc_html( $label ),
			( null !== $count )
				? '<span class="tld-tab-count">' . esc_html( number_format_i18n( (int) $count ) ) . '</span>'
				: ''
		);
	}
	echo '</nav>';
}

/* ------------------------------------------------------------------
 * 4. THE OVERVIEW / DASHBOARD PAGE — new landing page
 * ------------------------------------------------------------------ */

/**
 * Compute plugin health stats from real data sources.
 * Anything we can't measure (features not yet built) is deliberately
 * omitted — no placeholder or fake data.
 */
function livingdraft_admin_get_overview_stats() {
	global $wpdb;
	$stats = array();

	// Newsletter subscribers count (from the newsletter module's table).
	$subscribers_table = $wpdb->prefix . 'livingdraft_subscribers';
	if ( $wpdb->get_var( "SHOW TABLES LIKE '$subscribers_table'" ) === $subscribers_table ) {
		$stats['subscribers'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $subscribers_table WHERE unsubscribed_at IS NULL" );
	}

	// Corrections logged (via post meta).
	$corrections_count = (int) $wpdb->get_var(
		"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_livingdraft_updatelog'"
	);
	$stats['corrections'] = $corrections_count;

	// Redirects (from the redirects module).
	if ( function_exists( 'livingdraft_redirects_count' ) ) {
		$stats['redirects']       = livingdraft_redirects_count();
		$stats['redirects_auto']  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}livingdraft_redirects WHERE auto_generated = 1" );
		$stats['redirects_manual'] = $stats['redirects'] - $stats['redirects_auto'];
	}

	// Unresolved 404s.
	if ( function_exists( 'livingdraft_404s_count' ) ) {
		$stats['unresolved_404s'] = livingdraft_404s_count( 0 );
	}

	// Update channel status.
	$update_url = get_option( 'livingdraft_update_url', '' );
	$stats['update_channel_connected'] = ! empty( $update_url );

	return $stats;
}

/**
 * Recent activity feed. Draws from real sources — no fabricated entries.
 * If we don't have anything to show, the panel stays empty rather than
 * inventing filler.
 */
function livingdraft_admin_get_recent_activity( $limit = 8 ) {
	global $wpdb;
	$items = array();

	// Recent auto-redirects (slug changes).
	if ( function_exists( 'livingdraft_redirects_count' ) ) {
		$rows = $wpdb->get_results(
			"SELECT source_path, target_url, created_at, notes FROM {$wpdb->prefix}livingdraft_redirects
			 WHERE auto_generated = 1
			 ORDER BY created_at DESC LIMIT 3"
		);
		foreach ( $rows as $r ) {
			$items[] = array(
				'time' => $r->created_at,
				'html' => sprintf(
					'<strong>%s</strong> — old URL <code>%s</code> now redirects to the new headline',
					esc_html__( 'Auto-redirect created', 'livingdraft-core' ),
					esc_html( wp_trim_words( $r->source_path, 6, '…' ) )
				),
			);
		}
	}

	// Recent 404 hits.
	if ( function_exists( 'livingdraft_404s_list' ) ) {
		$rows = $wpdb->get_results(
			"SELECT url_path, hits, last_seen FROM {$wpdb->prefix}livingdraft_404s
			 WHERE resolved = 0 AND hits >= 3
			 ORDER BY last_seen DESC LIMIT 2"
		);
		foreach ( $rows as $r ) {
			$items[] = array(
				'time' => $r->last_seen,
				'html' => sprintf(
					'<strong>%s</strong> — <code>%s</code> hit %d times · <a href="%s">create redirect?</a>',
					esc_html__( '404 spike', 'livingdraft-core' ),
					esc_html( wp_trim_words( $r->url_path, 6, '…' ) ),
					(int) $r->hits,
					esc_url( admin_url( 'admin.php?page=livingdraft-redirects&tab=404s' ) )
				),
			);
		}
	}

	// Recent corrections logged.
	$rows = $wpdb->get_results(
		"SELECT p.ID, p.post_title, p.post_modified FROM {$wpdb->postmeta} pm
		 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		 WHERE pm.meta_key = '_livingdraft_updatelog'
		 ORDER BY p.post_modified DESC LIMIT 3"
	);
	foreach ( $rows as $r ) {
		$items[] = array(
			'time' => $r->post_modified,
			'html' => sprintf(
				'<strong>%s</strong> on <a href="%s">%s</a>',
				esc_html__( 'Correction logged', 'livingdraft-core' ),
				esc_url( get_edit_post_link( $r->ID ) ),
				esc_html( wp_trim_words( $r->post_title, 8, '…' ) )
			),
		);
	}

	// Sort by time descending, cap at $limit.
	usort( $items, function ( $a, $b ) {
		return strtotime( $b['time'] ) - strtotime( $a['time'] );
	} );

	return array_slice( $items, 0, $limit );
}

/**
 * Overview / dashboard page renderer.
 */
function livingdraft_admin_render_overview() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'livingdraft-core' ) );
	}

	$stats    = livingdraft_admin_get_overview_stats();
	$activity = livingdraft_admin_get_recent_activity();

	livingdraft_admin_render_header( array(
		'eyebrow' => __( 'The Living Draft Core · Overview', 'livingdraft-core' ),
		'title'   => __( 'Dashboard', 'livingdraft-core' ),
		'desc'    => __( 'The state of your newsroom infrastructure — subscribers, corrections, redirects, and 404s captured.', 'livingdraft-core' ),
		'actions' => array(
			array(
				'label' => __( 'Settings', 'livingdraft-core' ),
				'url'   => admin_url( 'admin.php?page=livingdraft-settings' ),
				'class' => 'is-primary',
			),
		),
	) );

	?>

	<!-- THE NUMBERS — ledger-style stat row (v3.8.0 "The Desk") -->
	<div class="tld-section-rule">
		<h2><?php esc_html_e( 'The numbers', 'livingdraft-core' ); ?></h2>
		<span class="tld-section-eyebrow"><?php esc_html_e( 'Newsroom infrastructure at a glance', 'livingdraft-core' ); ?></span>
	</div>
	<div class="tld-numbers">
		<?php if ( isset( $stats['subscribers'] ) ) : ?>
			<div class="tld-num">
				<p class="tld-num-label"><?php esc_html_e( 'Newsletter subscribers', 'livingdraft-core' ); ?></p>
				<p class="tld-num-value"><?php echo esc_html( number_format_i18n( $stats['subscribers'] ) ); ?></p>
				<p class="tld-num-delta"><?php esc_html_e( 'Active, opted-in', 'livingdraft-core' ); ?></p>
			</div>
		<?php endif; ?>

		<div class="tld-num">
			<p class="tld-num-label"><?php esc_html_e( 'Corrections logged', 'livingdraft-core' ); ?></p>
			<p class="tld-num-value"><?php echo esc_html( number_format_i18n( $stats['corrections'] ) ); ?></p>
			<p class="tld-num-delta"><?php esc_html_e( 'Across all posts', 'livingdraft-core' ); ?></p>
		</div>

		<?php if ( isset( $stats['redirects'] ) ) : ?>
			<div class="tld-num">
				<p class="tld-num-label"><?php esc_html_e( 'Active redirects', 'livingdraft-core' ); ?></p>
				<p class="tld-num-value"><?php echo esc_html( number_format_i18n( $stats['redirects'] ) ); ?></p>
				<p class="tld-num-delta">
					<?php echo esc_html( sprintf( '%d %s · %d %s',
						(int) ( $stats['redirects_auto'] ?? 0 ),
						__( 'auto', 'livingdraft-core' ),
						(int) ( $stats['redirects_manual'] ?? 0 ),
						__( 'manual', 'livingdraft-core' )
					) ); ?>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( isset( $stats['unresolved_404s'] ) ) : ?>
			<div class="tld-num">
				<p class="tld-num-label"><?php esc_html_e( 'Unresolved 404s', 'livingdraft-core' ); ?></p>
				<p class="tld-num-value"><?php echo esc_html( number_format_i18n( $stats['unresolved_404s'] ) ); ?></p>
				<p class="tld-num-delta <?php echo $stats['unresolved_404s'] > 5 ? 'is-warn' : ''; ?>">
					<?php echo $stats['unresolved_404s'] > 0
						? esc_html__( 'Needs attention', 'livingdraft-core' )
						: esc_html__( 'Nothing broken', 'livingdraft-core' ); ?>
				</p>
			</div>
		<?php endif; ?>
	</div>

	<!-- TWO COLUMN: Activity + Quick actions -->
	<div class="tld-two-col">

		<!-- Activity feed -->
		<div class="tld-card">
			<div class="tld-card-header">
				<div>
					<span class="tld-card-eyebrow"><?php esc_html_e( 'Newsroom log', 'livingdraft-core' ); ?></span>
					<h2 class="tld-card-title"><?php esc_html_e( 'Recent activity', 'livingdraft-core' ); ?></h2>
				</div>
			</div>

			<?php if ( empty( $activity ) ) : ?>
				<div class="tld-empty">
					<p class="tld-empty-title"><?php esc_html_e( 'No activity yet', 'livingdraft-core' ); ?></p>
					<p class="tld-empty-desc"><?php esc_html_e( 'Activity appears here as you log corrections, edit headlines, or your readers hit broken links.', 'livingdraft-core' ); ?></p>
				</div>
			<?php else : ?>
				<ul class="tld-activity">
					<?php foreach ( $activity as $item ) : ?>
						<li>
							<span class="tld-activity-time"><?php echo esc_html( human_time_diff( strtotime( $item['time'] ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'livingdraft-core' ) ); ?></span>
							<span class="tld-activity-text"><?php echo wp_kses_post( $item['html'] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<!-- Quick actions + health -->
		<div>
			<div class="tld-card" style="margin-bottom:16px">
				<div class="tld-card-header" style="border:0;padding-bottom:0;margin-bottom:12px">
					<div>
						<span class="tld-card-eyebrow"><?php esc_html_e( 'Common tasks', 'livingdraft-core' ); ?></span>
						<h2 class="tld-card-title"><?php esc_html_e( 'Quick actions', 'livingdraft-core' ); ?></h2>
					</div>
				</div>
				<div style="display:flex;flex-direction:column;gap:8px">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=livingdraft-redirects#add-new' ) ); ?>" class="tld-btn is-primary" style="justify-content:center"><?php esc_html_e( '+ Add redirect', 'livingdraft-core' ); ?></a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=livingdraft-redirects&tab=404s' ) ); ?>" class="tld-btn" style="justify-content:center">
						<?php
						$unresolved = $stats['unresolved_404s'] ?? 0;
						echo esc_html( sprintf( __( 'View 404 log (%d unresolved)', 'livingdraft-core' ), $unresolved ) );
						?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=livingdraft-settings' ) ); ?>" class="tld-btn" style="justify-content:center"><?php esc_html_e( 'Plugin settings', 'livingdraft-core' ); ?></a>
				</div>
			</div>

			<div class="tld-card">
				<div class="tld-card-header" style="border:0;padding-bottom:0;margin-bottom:12px">
					<div>
						<span class="tld-card-eyebrow"><?php esc_html_e( 'Plugin status', 'livingdraft-core' ); ?></span>
						<h2 class="tld-card-title"><?php esc_html_e( 'Health', 'livingdraft-core' ); ?></h2>
					</div>
				</div>
				<ul class="tld-checklist">
					<li>
						<span class="tld-check-icon <?php echo $stats['update_channel_connected'] ? 'is-good' : 'is-warn'; ?>"><?php echo $stats['update_channel_connected'] ? '&check;' : '!'; ?></span>
						<div class="tld-check-text">
							<?php echo $stats['update_channel_connected']
								? esc_html__( 'Update channel connected', 'livingdraft-core' )
								: esc_html__( 'Update channel not configured', 'livingdraft-core' ); ?>
							<div class="tld-check-detail">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=livingdraft-settings' ) ); ?>"><?php esc_html_e( 'Settings', 'livingdraft-core' ); ?></a>
							</div>
						</div>
					</li>
					<?php if ( isset( $stats['unresolved_404s'] ) && $stats['unresolved_404s'] > 0 ) : ?>
						<li>
							<span class="tld-check-icon is-warn">!</span>
							<div class="tld-check-text">
								<?php echo esc_html( sprintf( _n( '%d unresolved 404', '%d unresolved 404s', $stats['unresolved_404s'], 'livingdraft-core' ), $stats['unresolved_404s'] ) ); ?>
								<div class="tld-check-detail">
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=livingdraft-redirects&tab=404s' ) ); ?>"><?php esc_html_e( 'Review and create redirects', 'livingdraft-core' ); ?></a>
								</div>
							</div>
						</li>
					<?php else : ?>
						<li>
							<span class="tld-check-icon is-good">&check;</span>
							<div class="tld-check-text">
								<?php esc_html_e( 'No unresolved 404s', 'livingdraft-core' ); ?>
								<div class="tld-check-detail"><?php esc_html_e( 'Nothing currently broken', 'livingdraft-core' ); ?></div>
							</div>
						</li>
					<?php endif; ?>
				</ul>
			</div>
		</div>
	</div>

	<?php
	livingdraft_admin_render_footer();
}

/* ------------------------------------------------------------------
 * 5. SETTINGS PAGE — replaces the old Settings > The Living Draft page
 * ------------------------------------------------------------------ */

/**
 * Render the Settings page. Delegates to the existing update-channel
 * settings renderer but wraps it in the new design shell.
 */
function livingdraft_admin_render_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'livingdraft-core' ) );
	}

	$sections = livingdraft_admin_settings_sections();

	if ( empty( $sections ) ) {
		livingdraft_admin_render_header(
			array(
				'eyebrow' => __( 'The Living Draft Core · Settings', 'livingdraft-core' ),
				'title'   => __( 'Settings', 'livingdraft-core' ),
			)
		);
		echo '<div class="tld-card"><p>' . esc_html__( 'No settings modules are loaded.', 'livingdraft-core' ) . '</p></div>';
		livingdraft_admin_render_footer();
		return;
	}

	$requested = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$active    = isset( $sections[ $requested ] ) ? $requested : (string) array_key_first( $sections );
	$current   = $sections[ $active ];

	livingdraft_admin_render_header(
		array(
			'eyebrow' => __( 'The Living Draft Core · Settings', 'livingdraft-core' ),
			'title'   => $current['label'],
			'desc'    => $current['desc'] ?? '',
		)
	);
	?>
	<div class="tld-layout">
		<div class="tld-sidebar">
			<ul class="tld-sidebar-nav">
				<li class="tld-nav-eyebrow"><?php esc_html_e( 'Settings', 'livingdraft-core' ); ?></li>
				<?php foreach ( $sections as $slug => $section ) : ?>
					<li>
						<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'livingdraft-settings', 'section' => $slug ), admin_url( 'admin.php' ) ) ); ?>"
							class="<?php echo $slug === $active ? 'is-active' : ''; ?>">
							<?php echo esc_html( $section['label'] ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>

		<div class="tld-settings-body">
			<?php call_user_func( $current['render'] ); ?>
		</div>
	</div>
	<?php

	livingdraft_admin_render_footer();
}

/**
 * The update-channel section.
 *
 * @since 4.4.0
 * @return void
 */
function livingdraft_admin_render_updates_section() {
	echo '<div class="tld-card">';

	if ( function_exists( 'livingdraft_update_channel_render_form' ) ) {
		livingdraft_update_channel_render_form();
	} else {
		echo '<p>' . esc_html__( 'Update channel module not loaded.', 'livingdraft-core' ) . '</p>';
	}

	echo '</div>';
}

/**
 * Register the built-in sections.
 *
 * Priority 90 so module sections — which are the ones an editor actually
 * opens — sort above the update channel.
 *
 * @since 4.4.0
 * @param array $sections Registered sections.
 * @return array
 */
function livingdraft_admin_register_core_sections( $sections ) {
	$sections['updates'] = array(
		'label'  => __( 'Updates', 'livingdraft-core' ),
		'desc'   => __( 'Which channel this plugin takes new versions from.', 'livingdraft-core' ),
		'render' => 'livingdraft_admin_render_updates_section',
		'cap'    => 'manage_options',
	);

	return $sections;
}
add_filter( 'livingdraft_settings_panels', 'livingdraft_admin_register_core_sections', 90 );

/* ------------------------------------------------------------------
 * 6. LEGACY REDIRECTS — preserve old bookmarks
 * ------------------------------------------------------------------ */

/**
 * The plugin used to live at:
 *   Settings > The Living Draft   (options-general.php?page=livingdraft-updates)
 *   Tools > Redirections          (tools.php?page=livingdraft-redirects)
 *
 * v1.5.0 moves everything under our own top-level menu:
 *   The Living Draft > Settings    (admin.php?page=livingdraft-settings)
 *   The Living Draft > Redirections (admin.php?page=livingdraft-redirects)
 *
 * If someone hits an old URL from a bookmark, browser history, or an
 * external link, redirect them to the new location instead of showing
 * "You do not have permission to access this page."
 */
function livingdraft_admin_legacy_redirects() {
	if ( ! is_admin() ) {
		return;
	}

	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

	// Old update-channel slug.
	if ( 'livingdraft-updates' === $page && strpos( $_SERVER['REQUEST_URI'] ?? '', 'options-general.php' ) !== false ) {
		wp_safe_redirect( admin_url( 'admin.php?page=livingdraft-settings' ) );
		exit;
	}

	// Old redirects slug was under Tools — same slug, different parent.
	// Tools > Redirections still works because we didn't remove the
	// add_management_page call from redirects-admin.php in this phase;
	// both entry points render the same callback. Older bookmarks continue
	// to work exactly as before.
}
add_action( 'admin_init', 'livingdraft_admin_legacy_redirects' );
