<?php
/**
 * The Living Draft → SEO admin page.
 *
 * Three tabs:
 *   General   — the output on/off master switch, title separator.
 *   AI        — provider selection + keys (from ai-admin.php).
 *   Migration — read Rank Math meta count and offer to migrate.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the submenu item.
 */
function livingdraft_seo_admin_menu() {
	add_submenu_page(
		'livingdraft-overview',
		__( 'SEO', 'livingdraft-core' ),
		__( 'SEO', 'livingdraft-core' ),
		'manage_options',
		'livingdraft-seo',
		'livingdraft_seo_admin_render'
	);
}
add_action( 'admin_menu', 'livingdraft_seo_admin_menu', 20 );

/**
 * Send old SEO → AI bookmarks to the new location.
 *
 * @since 4.4.0
 * @return void
 */
function livingdraft_seo_admin_legacy_ai_redirect() {
	if ( ! is_admin() ) {
		return;
	}

	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( 'livingdraft-seo' === $page && 'ai' === $tab ) {
		wp_safe_redirect( admin_url( 'admin.php?page=livingdraft-settings&section=ai' ) );
		exit;
	}
}
add_action( 'admin_init', 'livingdraft_seo_admin_legacy_ai_redirect' );

/**
 * Save the General tab (output toggle + separator). AI settings save is
 * in ai-admin.php; migration doesn't have a form.
 */
function livingdraft_seo_admin_handle_save() {
	if ( ! isset( $_POST['ld_seo_general_save'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'ld_seo_general' );

	update_option( 'livingdraft_seo_output_enabled', isset( $_POST['output_enabled'] ) ? 1 : 0, false );
	update_option(
		'livingdraft_seo_title_separator',
		isset( $_POST['title_separator'] ) ? sanitize_text_field( wp_unslash( $_POST['title_separator'] ) ) : '|',
		false
	);

	wp_safe_redirect( add_query_arg( array( 'page' => 'livingdraft-seo', 'tab' => 'general', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
	exit;
}
add_action( 'admin_init', 'livingdraft_seo_admin_handle_save' );

/**
 * Migration action: copy Rank Math OR Yoast meta into our namespace.
 *
 * v3.3.0: added Yoast as a migration source. The form posts a `source`
 * parameter — 'rank_math' (default, unchanged) or 'yoast'. The Yoast
 * path also reconstructs our combined `_ld_seo_robots` array from
 * Yoast's per-directive rows.
 */
function livingdraft_seo_admin_handle_migrate() {
	if ( ! isset( $_POST['ld_seo_migrate'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'ld_seo_migrate' );

	$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : 'rank_math';
	if ( ! in_array( $source, array( 'rank_math', 'yoast' ), true ) ) {
		$source = 'rank_math';
	}

	global $wpdb;

	$map    = ( 'yoast' === $source ) ? livingdraft_seo_yoast_meta_map() : livingdraft_seo_meta_map();
	$copied = 0;

	foreach ( $map as $our_key => $src_key ) {
		// Never touch the robots key here — Yoast splits it across
		// multiple rows, and the Rank Math single-array copy is
		// already handled below by the string path. Instead we do a
		// separate reconstruction pass afterwards.
		if ( '_ld_seo_robots' === $our_key ) {
			continue;
		}

		// Find all post_ids that have the source key set AND don't
		// already have our own key set (we never overwrite existing).
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT src.post_id, src.meta_value
				 FROM {$wpdb->postmeta} src
				 LEFT JOIN {$wpdb->postmeta} ld
				   ON ld.post_id = src.post_id AND ld.meta_key = %s
				 WHERE src.meta_key = %s
				   AND src.meta_value != ''
				   AND ld.meta_id IS NULL",
				$our_key,
				$src_key
			)
		);

		foreach ( $rows as $row ) {
			$value = maybe_unserialize( $row->meta_value );
			update_post_meta( (int) $row->post_id, $our_key, $value );
			$copied++;
		}
	}

	// Rank Math ships robots as a single serialized array; copy that
	// straight over.
	if ( 'rank_math' === $source ) {
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT src.post_id, src.meta_value
				 FROM {$wpdb->postmeta} src
				 LEFT JOIN {$wpdb->postmeta} ld
				   ON ld.post_id = src.post_id AND ld.meta_key = %s
				 WHERE src.meta_key = %s
				   AND ld.meta_id IS NULL",
				'_ld_seo_robots',
				'rank_math_robots'
			)
		);
		foreach ( $rows as $row ) {
			$value = maybe_unserialize( $row->meta_value );
			if ( is_array( $value ) && ! empty( $value ) ) {
				update_post_meta( (int) $row->post_id, '_ld_seo_robots', $value );
				$copied++;
			}
		}
	}

	// Yoast splits robots into per-directive rows. Walk every post
	// that has any of the four Yoast robots keys set, reconstruct
	// our combined array, and store it.
	if ( 'yoast' === $source ) {
		$candidate_ids = $wpdb->get_col(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
			 WHERE meta_key IN ('_yoast_wpseo_meta-robots-noindex',
			                    '_yoast_wpseo_meta-robots-nofollow',
			                    '_yoast_wpseo_meta-robots-adv')"
		);
		foreach ( (array) $candidate_ids as $pid ) {
			$pid = (int) $pid;
			if ( '' !== (string) get_post_meta( $pid, '_ld_seo_robots', true ) ) {
				continue;
			}
			$reconstructed = array();
			if ( '1' === (string) get_post_meta( $pid, '_yoast_wpseo_meta-robots-noindex', true ) ) {
				$reconstructed[] = 'noindex';
			}
			if ( '1' === (string) get_post_meta( $pid, '_yoast_wpseo_meta-robots-nofollow', true ) ) {
				$reconstructed[] = 'nofollow';
			}
			$adv = (string) get_post_meta( $pid, '_yoast_wpseo_meta-robots-adv', true );
			if ( '' !== $adv ) {
				foreach ( explode( ',', $adv ) as $d ) {
					$d = sanitize_key( trim( $d ) );
					if ( in_array( $d, array( 'noarchive', 'noimageindex', 'nosnippet' ), true ) ) {
						$reconstructed[] = $d;
					}
				}
			}
			if ( ! empty( $reconstructed ) ) {
				update_post_meta( $pid, '_ld_seo_robots', array_values( array_unique( $reconstructed ) ) );
				$copied++;
			}
		}
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'     => 'livingdraft-seo',
				'tab'      => 'migration',
				'migrated' => (int) $copied,
				'source'   => $source,
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}
add_action( 'admin_init', 'livingdraft_seo_admin_handle_migrate' );

/**
 * Render.
 */
function livingdraft_seo_admin_render() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'livingdraft-core' ) );
	}

	$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
	// 'ai' is no longer a tab here — it moved to Settings → AI in 4.4.0.
	// The redirect below catches bookmarks; this line stops it rendering
	// an empty screen if one slips through.
	$tab = in_array( $tab, array( 'general', 'sitemap', 'schema', 'brief', 'links', 'gsc', 'webmasters', 'migration' ), true ) ? $tab : 'general';

	$titles = array(
		'general'    => __( 'General', 'livingdraft-core' ),
		'sitemap'    => __( 'Sitemap', 'livingdraft-core' ),
		'schema'     => __( 'Schema', 'livingdraft-core' ),
		'brief'      => __( 'Brief', 'livingdraft-core' ),
		'links'      => __( 'Links', 'livingdraft-core' ),
		'gsc'        => __( 'Search Console', 'livingdraft-core' ),
		'webmasters' => __( 'Webmasters', 'livingdraft-core' ),
		'migration'  => __( 'Migration', 'livingdraft-core' ),
	);
	$descs = array(
		'general'    => __( 'Turn front-end output on when you\'re ready. Do this only after disabling Rank Math or Yoast.', 'livingdraft-core' ),
		'sitemap'    => __( 'XML sitemaps for Google and a News sitemap for the Top Stories carousel, plus IndexNow pings to Bing and Yandex on publish.', 'livingdraft-core' ),
		'schema'     => __( 'NewsArticle, Organization, WebSite, and BreadcrumbList structured data. Feeds Google\'s Top Stories carousel, sitelinks search box, and article rich results.', 'livingdraft-core' ),
		'brief'      => __( 'A structured research brief for any keyword — title options, meta description, target length, outline, entities, questions, semantic terms. Optionally spins up a draft post with the outline pre-filled.', 'livingdraft-core' ),
		'links'      => __( 'Related-post suggestions in every editor sidebar, powered by embeddings. Writers see the five most semantically similar existing posts and can copy a Markdown link to any of them.', 'livingdraft-core' ),
		'gsc'        => __( 'Search Console analytics per post (clicks, impressions, CTR, average position) plus auto-submit new URLs to Google\'s Indexing API. One service account setup, then everything runs itself.', 'livingdraft-core' ),
		'webmasters' => __( 'Verification meta tags for Search Console, Bing, Yandex, Pinterest, Baidu, Facebook. Attachment page redirect. RSS feed footer for canonical attribution.', 'livingdraft-core' ),
		'migration'  => __( 'Copy Rank Math or Yoast post meta into this plugin\'s namespace so nothing is lost when you switch.', 'livingdraft-core' ),
	);

	livingdraft_admin_render_header(
		array(
			'eyebrow' => __( 'The Living Draft Core · SEO', 'livingdraft-core' ),
			'title'   => $titles[ $tab ],
			'desc'    => $descs[ $tab ],
			'active'  => 'livingdraft-seo',
		)
	);

	// Sub-nav. v3.8.0: uses the shared render_subtabs helper instead of
	// inline styles.
	if ( function_exists( 'livingdraft_admin_render_subtabs' ) ) {
		livingdraft_admin_render_subtabs(
			array(
				'general'    => array( 'label' => __( 'General', 'livingdraft-core' ) ),
				'sitemap'    => array( 'label' => __( 'Sitemap', 'livingdraft-core' ) ),
				'schema'     => array( 'label' => __( 'Schema', 'livingdraft-core' ) ),
				'brief'      => array( 'label' => __( 'Brief', 'livingdraft-core' ) ),
				'links'      => array( 'label' => __( 'Links', 'livingdraft-core' ) ),
				'gsc'        => array( 'label' => __( 'Search Console', 'livingdraft-core' ) ),
				'webmasters' => array( 'label' => __( 'Webmasters', 'livingdraft-core' ) ),
				'migration'  => array( 'label' => __( 'Migration', 'livingdraft-core' ) ),
			),
			$tab,
			'livingdraft-seo'
		);
	}

	// Notices.
	if ( isset( $_GET['saved'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'livingdraft-core' ) . '</p></div>';
	}
	if ( isset( $_GET['migrated'] ) ) {
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( sprintf( __( '%d SEO field values imported from Rank Math.', 'livingdraft-core' ), (int) $_GET['migrated'] ) )
		);
	}

	// Conflict banner.
	$conflicts = livingdraft_seo_conflicts();
	if ( ! empty( $conflicts ) && livingdraft_seo_output_enabled() ) {
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Duplicate SEO tags likely.', 'livingdraft-core' ),
			esc_html( sprintf(
				__( '%s is active AND this plugin\'s SEO output is on. Deactivate the other plugin first.', 'livingdraft-core' ),
				implode( ', ', $conflicts )
			) )
		);
	} elseif ( ! empty( $conflicts ) ) {
		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			esc_html( sprintf(
				__( '%s is active. Finish your migration and disable it before turning on this plugin\'s SEO output.', 'livingdraft-core' ),
				implode( ', ', $conflicts )
			) )
		);
	}

	switch ( $tab ) {
		case 'sitemap':
			livingdraft_seo_admin_render_sitemap();
			break;
		case 'schema':
			livingdraft_seo_admin_render_schema();
			break;
		case 'brief':
			livingdraft_content_brief_render_tab();
			break;
		case 'links':
			livingdraft_seo_admin_render_links();
			break;
		case 'gsc':
			livingdraft_seo_admin_render_gsc();
			break;
		case 'webmasters':
			livingdraft_seo_admin_render_webmasters();
			break;
		case 'migration':
			livingdraft_seo_admin_render_migration();
			break;
		default:
			livingdraft_seo_admin_render_general();
	}

	livingdraft_admin_render_footer();
}

/**
 * General tab body.
 */
function livingdraft_seo_admin_render_general() {
	$enabled = (bool) get_option( 'livingdraft_seo_output_enabled', false );
	$sep     = (string) get_option( 'livingdraft_seo_title_separator', '|' );
	?>
	<div class="tld-card">
		<form method="post">
			<?php wp_nonce_field( 'ld_seo_general' ); ?>

			<div class="tld-field">
				<label class="tld-label"><?php esc_html_e( 'Front-end SEO output', 'livingdraft-core' ); ?></label>
				<label style="display:flex;align-items:flex-start;gap:10px;padding:14px;border:1px solid #d4d4d4;background:#fff;cursor:pointer">
					<input type="checkbox" name="output_enabled" value="1" <?php checked( $enabled ); ?> style="margin-top:2px">
					<span>
						<strong><?php esc_html_e( 'Emit meta tags, Open Graph, Twitter Card, robots, canonical', 'livingdraft-core' ); ?></strong><br>
						<span style="color:var(--tld-ink-3, #666);font-size:13px">
							<?php esc_html_e( 'Turn this on only after disabling Rank Math or Yoast, or you\'ll get duplicate tags.', 'livingdraft-core' ); ?>
						</span>
					</span>
				</label>
			</div>

			<div class="tld-field">
				<label class="tld-label" for="ld_seo_sep"><?php esc_html_e( 'Title separator', 'livingdraft-core' ); ?></label>
				<input type="text" id="ld_seo_sep" name="title_separator" class="tld-input" style="max-width:120px"
					value="<?php echo esc_attr( $sep ); ?>" maxlength="4">
				<p class="tld-help"><?php esc_html_e( 'The character between the post title and the site name in the browser tab. Common choices: | · — –', 'livingdraft-core' ); ?></p>
			</div>

			<button type="submit" name="ld_seo_general_save" class="tld-btn is-primary">
				<?php esc_html_e( 'Save', 'livingdraft-core' ); ?>
			</button>
		</form>
	</div>
	<?php
}

/**
 * Migration tab body.
 */
function livingdraft_seo_admin_render_migration() {
	global $wpdb;

	$map       = livingdraft_seo_meta_map();
	$yoast_map = livingdraft_seo_yoast_meta_map();

	$counts_rm    = array();
	$counts_yoast = array();

	foreach ( $map as $our => $rm ) {
		$counts_rm[ $our ] = array(
			'ours'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''", $our ) ),
			'theirs' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''", $rm ) ),
			'src_key' => $rm,
		);
	}
	foreach ( $yoast_map as $our => $ykey ) {
		$counts_yoast[ $our ] = array(
			'ours'   => isset( $counts_rm[ $our ] ) ? $counts_rm[ $our ]['ours'] : (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''", $our ) ),
			'theirs' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''", $ykey ) ),
			'src_key' => $ykey,
		);
	}

	$total_rm    = array_sum( array_column( $counts_rm, 'theirs' ) );
	$total_yoast = array_sum( array_column( $counts_yoast, 'theirs' ) );

	// One card per source, side-by-side conceptually. Empty sources
	// still render the card so it's clear the plugin can migrate from
	// them if the data appears later.
	?>
	<div class="tld-card" style="margin-bottom:20px">
		<div class="tld-card-header">
			<div>
				<span class="tld-card-eyebrow"><?php esc_html_e( 'One-time copy', 'livingdraft-core' ); ?></span>
				<h2 class="tld-card-title"><?php esc_html_e( 'Import from Rank Math', 'livingdraft-core' ); ?></h2>
			</div>
		</div>
		<p style="color:var(--tld-ink-3, #666);max-width:60ch">
			<?php esc_html_e( 'Copies Rank Math\'s per-post SEO data into this plugin\'s meta keys. Existing values on our side are never overwritten. Rank Math\'s original meta is left intact.', 'livingdraft-core' ); ?>
		</p>
		<table class="widefat striped" style="margin-top:12px">
			<thead><tr>
				<th><?php esc_html_e( 'Field', 'livingdraft-core' ); ?></th>
				<th style="width:100px"><?php esc_html_e( 'In Rank Math', 'livingdraft-core' ); ?></th>
				<th style="width:100px"><?php esc_html_e( 'In this plugin', 'livingdraft-core' ); ?></th>
				<th style="width:120px"><?php esc_html_e( 'Would copy', 'livingdraft-core' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $counts_rm as $our => $c ) : ?>
				<tr>
					<td><code style="font-size:11px"><?php echo esc_html( str_replace( '_ld_seo_', '', $our ) ); ?></code></td>
					<td><?php echo esc_html( number_format_i18n( $c['theirs'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $c['ours'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( max( 0, $c['theirs'] - $c['ours'] ) ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( 0 === $total_rm ) : ?>
			<p style="margin-top:16px;color:#666"><em><?php esc_html_e( 'No Rank Math data found.', 'livingdraft-core' ); ?></em></p>
		<?php else : ?>
			<form method="post" style="margin-top:16px">
				<?php wp_nonce_field( 'ld_seo_migrate' ); ?>
				<input type="hidden" name="source" value="rank_math">
				<button type="submit" name="ld_seo_migrate" class="tld-btn is-primary"
					onclick="return confirm('<?php echo esc_js( __( 'Copy Rank Math data into this plugin\'s namespace? Existing values here will NOT be overwritten.', 'livingdraft-core' ) ); ?>');">
					<?php esc_html_e( 'Copy Rank Math data now', 'livingdraft-core' ); ?>
				</button>
			</form>
		<?php endif; ?>
	</div>

	<div class="tld-card">
		<div class="tld-card-header">
			<div>
				<span class="tld-card-eyebrow"><?php esc_html_e( 'One-time copy', 'livingdraft-core' ); ?></span>
				<h2 class="tld-card-title"><?php esc_html_e( 'Import from Yoast SEO', 'livingdraft-core' ); ?></h2>
			</div>
		</div>
		<p style="color:var(--tld-ink-3, #666);max-width:60ch">
			<?php esc_html_e( 'Copies Yoast\'s per-post SEO data into this plugin\'s meta keys. Robots directives (Yoast splits noindex / nofollow / noarchive across multiple rows) are recombined into our single array. Yoast\'s original meta is left intact.', 'livingdraft-core' ); ?>
		</p>
		<table class="widefat striped" style="margin-top:12px">
			<thead><tr>
				<th><?php esc_html_e( 'Field', 'livingdraft-core' ); ?></th>
				<th style="width:100px"><?php esc_html_e( 'In Yoast', 'livingdraft-core' ); ?></th>
				<th style="width:100px"><?php esc_html_e( 'In this plugin', 'livingdraft-core' ); ?></th>
				<th style="width:120px"><?php esc_html_e( 'Would copy', 'livingdraft-core' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $counts_yoast as $our => $c ) : ?>
				<tr>
					<td><code style="font-size:11px"><?php echo esc_html( str_replace( '_ld_seo_', '', $our ) ); ?></code></td>
					<td><?php echo esc_html( number_format_i18n( $c['theirs'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $c['ours'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( max( 0, $c['theirs'] - $c['ours'] ) ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( 0 === $total_yoast ) : ?>
			<p style="margin-top:16px;color:#666"><em><?php esc_html_e( 'No Yoast SEO data found.', 'livingdraft-core' ); ?></em></p>
		<?php else : ?>
			<form method="post" style="margin-top:16px">
				<?php wp_nonce_field( 'ld_seo_migrate' ); ?>
				<input type="hidden" name="source" value="yoast">
				<button type="submit" name="ld_seo_migrate" class="tld-btn is-primary"
					onclick="return confirm('<?php echo esc_js( __( 'Copy Yoast data into this plugin\'s namespace? Existing values here will NOT be overwritten.', 'livingdraft-core' ) ); ?>');">
					<?php esc_html_e( 'Copy Yoast data now', 'livingdraft-core' ); ?>
				</button>
			</form>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Save the Sitemap tab settings.
 */
function livingdraft_seo_admin_handle_sitemap_save() {
	if ( ! isset( $_POST['ld_seo_sitemap_save'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'ld_seo_sitemap' );

	update_option( 'livingdraft_sitemap_enabled', isset( $_POST['sitemap_enabled'] ) ? 1 : 0, false );
	update_option( 'livingdraft_sitemap_news_enabled', isset( $_POST['sitemap_news_enabled'] ) ? 1 : 0, false );
	update_option( 'livingdraft_sitemap_authors', isset( $_POST['sitemap_authors'] ) ? 1 : 0, false );
	update_option( 'livingdraft_indexnow_enabled', isset( $_POST['indexnow_enabled'] ) ? 1 : 0, false );

	if ( isset( $_POST['sitemap_pub_name'] ) ) {
		update_option(
			'livingdraft_sitemap_pub_name',
			sanitize_text_field( wp_unslash( $_POST['sitemap_pub_name'] ) ),
			false
		);
	}

	// Force a rewrite flush so new rules take effect immediately after
	// toggling the sitemap on for the first time — otherwise the URLs
	// return 404 until the user visits Permalinks.
	delete_option( 'livingdraft_sitemap_rewrites_ver' );

	wp_safe_redirect( add_query_arg( array( 'page' => 'livingdraft-seo', 'tab' => 'sitemap', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
	exit;
}
add_action( 'admin_init', 'livingdraft_seo_admin_handle_sitemap_save' );

/**
 * Handle a manual IndexNow test ping.
 */
function livingdraft_seo_admin_handle_indexnow_test() {
	if ( ! isset( $_POST['ld_seo_indexnow_test'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'ld_seo_indexnow_test' );

	livingdraft_indexnow_submit( array( home_url( '/' ) ) );

	wp_safe_redirect( add_query_arg( array( 'page' => 'livingdraft-seo', 'tab' => 'sitemap', 'pinged' => 1 ), admin_url( 'admin.php' ) ) );
	exit;
}
add_action( 'admin_init', 'livingdraft_seo_admin_handle_indexnow_test' );

/**
 * Render the Sitemap tab body.
 */
function livingdraft_seo_admin_render_sitemap() {
	$enabled       = livingdraft_sitemap_enabled();
	$news_enabled  = livingdraft_sitemap_news_enabled();
	$authors       = (bool) get_option( 'livingdraft_sitemap_authors', false );
	$indexnow      = livingdraft_indexnow_enabled();
	$pub_name      = get_option( 'livingdraft_sitemap_pub_name', get_bloginfo( 'name' ) );

	if ( isset( $_GET['pinged'] ) ) {
		echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'IndexNow test ping sent. See the log below.', 'livingdraft-core' ) . '</p></div>';
	}
	?>

	<div class="tld-card">
		<div class="tld-card-header">
			<div>
				<span class="tld-card-eyebrow"><?php esc_html_e( 'Discovery layer', 'livingdraft-core' ); ?></span>
				<h2 class="tld-card-title"><?php esc_html_e( 'XML sitemaps', 'livingdraft-core' ); ?></h2>
			</div>
		</div>

		<form method="post">
			<?php wp_nonce_field( 'ld_seo_sitemap' ); ?>

			<div class="tld-field">
				<label style="display:flex;align-items:flex-start;gap:10px;padding:14px;border:1px solid #d4d4d4;background:#fff;cursor:pointer">
					<input type="checkbox" name="sitemap_enabled" value="1" <?php checked( $enabled ); ?> style="margin-top:2px">
					<span>
						<strong><?php esc_html_e( 'Serve /sitemap.xml (and index aliases)', 'livingdraft-core' ); ?></strong><br>
						<span style="color:var(--tld-ink-3, #666);font-size:13px">
							<?php esc_html_e( 'Also serves /sitemap_index.xml and /wp-sitemap.xml as aliases so existing Search Console submissions from Rank Math or Yoast keep working during migration.', 'livingdraft-core' ); ?>
						</span>
					</span>
				</label>
			</div>

			<div class="tld-field">
				<label style="display:flex;align-items:flex-start;gap:10px;padding:14px;border:1px solid #d4d4d4;background:#fff;cursor:pointer">
					<input type="checkbox" name="sitemap_news_enabled" value="1" <?php checked( $news_enabled ); ?> style="margin-top:2px">
					<span>
						<strong><?php esc_html_e( 'Serve /news-sitemap.xml', 'livingdraft-core' ); ?></strong><br>
						<span style="color:var(--tld-ink-3, #666);font-size:13px">
							<?php esc_html_e( 'Google News format: last 48 hours only, up to 1000 URLs. Required to be eligible for the Top Stories carousel.', 'livingdraft-core' ); ?>
						</span>
					</span>
				</label>
			</div>

			<div class="tld-field">
				<label class="tld-label" for="ld_pub_name"><?php esc_html_e( 'Publication name (for news sitemap)', 'livingdraft-core' ); ?></label>
				<input type="text" id="ld_pub_name" name="sitemap_pub_name" class="tld-input" value="<?php echo esc_attr( $pub_name ); ?>">
				<p class="tld-help"><?php esc_html_e( 'The name Google will show for your publication. Defaults to the site name.', 'livingdraft-core' ); ?></p>
			</div>

			<div class="tld-field">
				<label style="display:flex;align-items:flex-start;gap:10px;padding:14px;border:1px solid #d4d4d4;background:#fff;cursor:pointer">
					<input type="checkbox" name="sitemap_authors" value="1" <?php checked( $authors ); ?> style="margin-top:2px">
					<span>
						<strong><?php esc_html_e( 'Include author archives', 'livingdraft-core' ); ?></strong><br>
						<span style="color:var(--tld-ink-3, #666);font-size:13px">
							<?php esc_html_e( 'Off by default — most sites\' author pages are thin content and better left out.', 'livingdraft-core' ); ?>
						</span>
					</span>
				</label>
			</div>

			<?php if ( $enabled ) : ?>
				<div style="margin:16px 0;padding:12px 14px;background:#fafaf7;border:1px solid #e5e5e5">
					<div style="font-family:var(--tld-mono, monospace);font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:#666;margin-bottom:6px">
						<?php esc_html_e( 'Live URLs', 'livingdraft-core' ); ?>
					</div>
					<div style="font-family:var(--tld-mono, monospace);font-size:12px;line-height:1.8">
						<a href="<?php echo esc_url( home_url( '/sitemap.xml' ) ); ?>" target="_blank"><?php echo esc_html( home_url( '/sitemap.xml' ) ); ?></a><br>
						<?php if ( $news_enabled ) : ?>
							<a href="<?php echo esc_url( home_url( '/news-sitemap.xml' ) ); ?>" target="_blank"><?php echo esc_html( home_url( '/news-sitemap.xml' ) ); ?></a>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>

			<hr style="margin:24px 0;border:0;border-top:1px solid #eee">

			<div class="tld-card-header" style="border:0;padding:0">
				<div>
					<span class="tld-card-eyebrow"><?php esc_html_e( 'Instant indexing', 'livingdraft-core' ); ?></span>
					<h2 class="tld-card-title" style="font-size:18px"><?php esc_html_e( 'IndexNow', 'livingdraft-core' ); ?></h2>
				</div>
			</div>

			<p style="color:var(--tld-ink-3, #666);max-width:60ch;margin-top:0">
				<?php esc_html_e( 'Pings Bing and Yandex the moment a post is published or meaningfully updated. Google is not part of IndexNow — Google discovery relies on the sitemap.', 'livingdraft-core' ); ?>
			</p>

			<div class="tld-field">
				<label style="display:flex;align-items:flex-start;gap:10px;padding:14px;border:1px solid #d4d4d4;background:#fff;cursor:pointer">
					<input type="checkbox" name="indexnow_enabled" value="1" <?php checked( $indexnow ); ?> style="margin-top:2px">
					<span>
						<strong><?php esc_html_e( 'Enable IndexNow pings on publish', 'livingdraft-core' ); ?></strong><br>
						<span style="color:var(--tld-ink-3, #666);font-size:13px">
							<?php esc_html_e( 'A verification key file is generated and served automatically at your site root. No manual setup required.', 'livingdraft-core' ); ?>
						</span>
					</span>
				</label>
			</div>

			<?php if ( $indexnow ) :
				$key      = livingdraft_indexnow_key();
				$key_file = home_url( '/' . $key . '.txt' );
				?>
				<div style="margin:16px 0;padding:12px 14px;background:#fafaf7;border:1px solid #e5e5e5;font-family:var(--tld-mono, monospace);font-size:12px">
					<div style="color:#666;letter-spacing:.12em;text-transform:uppercase;font-size:10px;margin-bottom:6px"><?php esc_html_e( 'Verification key file', 'livingdraft-core' ); ?></div>
					<a href="<?php echo esc_url( $key_file ); ?>" target="_blank"><?php echo esc_html( $key_file ); ?></a>
				</div>
			<?php endif; ?>

			<button type="submit" name="ld_seo_sitemap_save" class="tld-btn is-primary">
				<?php esc_html_e( 'Save', 'livingdraft-core' ); ?>
			</button>
		</form>

		<?php if ( $indexnow ) : ?>
			<hr style="margin:24px 0;border:0;border-top:1px solid #eee">

			<form method="post" style="display:inline-block">
				<?php wp_nonce_field( 'ld_seo_indexnow_test' ); ?>
				<button type="submit" name="ld_seo_indexnow_test" class="tld-btn">
					<?php esc_html_e( 'Test ping (submits homepage)', 'livingdraft-core' ); ?>
				</button>
			</form>

			<?php
			$log = livingdraft_indexnow_get_log();
			if ( ! empty( $log ) ) :
				?>
				<h3 style="margin-top:20px;font-family:var(--tld-serif, Georgia, serif);font-weight:400;font-size:16px"><?php esc_html_e( 'Recent submissions', 'livingdraft-core' ); ?></h3>
				<table class="widefat striped" style="font-family:var(--tld-mono, monospace);font-size:11px">
					<thead>
						<tr>
							<th style="width:140px"><?php esc_html_e( 'When', 'livingdraft-core' ); ?></th>
							<th style="width:70px"><?php esc_html_e( 'Code', 'livingdraft-core' ); ?></th>
							<th><?php esc_html_e( 'URL(s)', 'livingdraft-core' ); ?></th>
							<th><?php esc_html_e( 'Result', 'livingdraft-core' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_slice( $log, 0, 15 ) as $row ) :
							$ok = in_array( (int) $row['code'], array( 200, 202 ), true );
							?>
							<tr>
								<td><?php echo esc_html( human_time_diff( (int) $row['time'], time() ) . ' ago' ); ?></td>
								<td style="color:<?php echo $ok ? '#2f7a3a' : '#a32e2e'; ?>"><?php echo esc_html( $row['code'] ); ?></td>
								<td><?php echo esc_html( implode( "\n", (array) ( $row['urls'] ?? array() ) ) ); ?></td>
								<td><?php echo esc_html( $row['message'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Save the Schema tab settings.
 */
function livingdraft_seo_admin_handle_schema_save() {
	if ( ! isset( $_POST['ld_seo_schema_save'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'ld_seo_schema' );

	update_option( 'livingdraft_schema_enabled', isset( $_POST['schema_enabled'] ) ? 1 : 0, false );

	$posted   = isset( $_POST['ld_schema'] ) ? (array) wp_unslash( $_POST['ld_schema'] ) : array();
	$existing = livingdraft_schema_settings();

	$article_type = isset( $posted['article_type'] ) ? sanitize_key( $posted['article_type'] ) : $existing['article_type'];
	if ( ! in_array( $article_type, array( 'NewsArticle', 'Article', 'BlogPosting' ), true ) ) {
		$article_type = 'NewsArticle';
	}

	$organization = isset( $posted['organization'] ) ? sanitize_key( $posted['organization'] ) : 'Organization';
	if ( ! in_array( $organization, array( 'Organization', 'Person' ), true ) ) {
		$organization = 'Organization';
	}

	$settings = array(
		'article_type'    => $article_type,
		'organization'    => $organization,
		'publisher_name'  => isset( $posted['publisher_name'] ) ? sanitize_text_field( $posted['publisher_name'] ) : $existing['publisher_name'],
		'publisher_logo'  => isset( $posted['publisher_logo'] ) ? esc_url_raw( $posted['publisher_logo'] ) : $existing['publisher_logo'],
		'social_profiles' => isset( $posted['social_profiles'] ) ? sanitize_textarea_field( $posted['social_profiles'] ) : $existing['social_profiles'],
	);

	update_option( 'livingdraft_schema_settings', $settings, false );

	wp_safe_redirect( add_query_arg( array( 'page' => 'livingdraft-seo', 'tab' => 'schema', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
	exit;
}
add_action( 'admin_init', 'livingdraft_seo_admin_handle_schema_save' );

/**
 * Render the Schema tab.
 */
function livingdraft_seo_admin_render_schema() {
	$enabled = livingdraft_schema_enabled();
	$s       = livingdraft_schema_settings();

	// Pick the most recent published post to link to Google's Rich
	// Results Test — the user can see what our output actually looks
	// like to Google without leaving the admin.
	$latest = get_posts(
		array(
			'post_type'      => 'post',
			'posts_per_page' => 1,
			'post_status'    => 'publish',
			'no_found_rows'  => true,
			'fields'         => 'ids',
		)
	);
	$test_url = ! empty( $latest ) ? get_permalink( $latest[0] ) : home_url( '/' );
	?>

	<div class="tld-card">
		<div class="tld-card-header">
			<div>
				<span class="tld-card-eyebrow"><?php esc_html_e( 'Structured data', 'livingdraft-core' ); ?></span>
				<h2 class="tld-card-title"><?php esc_html_e( 'Schema', 'livingdraft-core' ); ?></h2>
			</div>
		</div>

		<form method="post">
			<?php wp_nonce_field( 'ld_seo_schema' ); ?>

			<div class="tld-field">
				<label style="display:flex;align-items:flex-start;gap:10px;padding:14px;border:1px solid #d4d4d4;background:#fff;cursor:pointer">
					<input type="checkbox" name="schema_enabled" value="1" <?php checked( $enabled ); ?> style="margin-top:2px">
					<span>
						<strong><?php esc_html_e( 'Emit JSON-LD schema in <head>', 'livingdraft-core' ); ?></strong><br>
						<span style="color:var(--tld-ink-3, #666);font-size:13px">
							<?php esc_html_e( 'One @graph per page: Organization + WebSite site-wide, NewsArticle + BreadcrumbList on posts, WebPage + BreadcrumbList on pages. FAQ and Rating blocks continue emitting their own schema.', 'livingdraft-core' ); ?>
						</span>
					</span>
				</label>
			</div>

			<hr style="margin:20px 0;border:0;border-top:1px solid #eee">

			<div class="tld-field">
				<label class="tld-label"><?php esc_html_e( 'Article type for posts', 'livingdraft-core' ); ?></label>
				<div style="display:flex;gap:8px;flex-wrap:wrap">
					<?php foreach ( array(
						'NewsArticle' => __( 'NewsArticle — for news publishers, eligible for Top Stories', 'livingdraft-core' ),
						'Article'     => __( 'Article — generic', 'livingdraft-core' ),
						'BlogPosting' => __( 'BlogPosting — for personal / opinion blogs', 'livingdraft-core' ),
					) as $val => $label ) :
						$checked = $val === $s['article_type'];
						?>
						<label style="display:flex;align-items:center;gap:6px;padding:8px 14px;border:1px solid <?php echo $checked ? 'var(--tld-mark,#8b3a2c)' : '#d4d4d4'; ?>;cursor:pointer;background:<?php echo $checked ? '#faf6f5' : '#fff'; ?>">
							<input type="radio" name="ld_schema[article_type]" value="<?php echo esc_attr( $val ); ?>" <?php checked( $checked ); ?>>
							<span><?php echo esc_html( $label ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>

			<hr style="margin:20px 0;border:0;border-top:1px solid #eee">

			<div class="tld-field">
				<label class="tld-label"><?php esc_html_e( 'Publisher type', 'livingdraft-core' ); ?></label>
				<div style="display:flex;gap:8px">
					<?php foreach ( array( 'Organization' => __( 'Organization', 'livingdraft-core' ), 'Person' => __( 'Person', 'livingdraft-core' ) ) as $val => $label ) :
						$checked = $val === $s['organization'];
						?>
						<label style="display:flex;align-items:center;gap:6px;padding:6px 12px;border:1px solid <?php echo $checked ? 'var(--tld-mark,#8b3a2c)' : '#d4d4d4'; ?>;cursor:pointer;background:<?php echo $checked ? '#faf6f5' : '#fff'; ?>">
							<input type="radio" name="ld_schema[organization]" value="<?php echo esc_attr( $val ); ?>" <?php checked( $checked ); ?>>
							<span><?php echo esc_html( $label ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="tld-field">
				<label class="tld-label" for="ld_publisher_name"><?php esc_html_e( 'Publisher name', 'livingdraft-core' ); ?></label>
				<input type="text" id="ld_publisher_name" name="ld_schema[publisher_name]" class="tld-input"
					value="<?php echo esc_attr( $s['publisher_name'] ); ?>">
				<p class="tld-help"><?php esc_html_e( 'The name that appears next to your byline in Google. Defaults to the site name.', 'livingdraft-core' ); ?></p>
			</div>

			<div class="tld-field">
				<label class="tld-label" for="ld_publisher_logo"><?php esc_html_e( 'Publisher logo URL', 'livingdraft-core' ); ?></label>
				<input type="url" id="ld_publisher_logo" name="ld_schema[publisher_logo]" class="tld-input"
					value="<?php echo esc_attr( $s['publisher_logo'] ); ?>"
					placeholder="https://example.com/logo.png">
				<p class="tld-help">
					<?php esc_html_e( 'Google\'s guidance: at least 112px on the shortest side, ideally rectangular (max width 600px, max height 60px). Required for Article schema to be eligible for rich results. Defaults to the site icon when empty.', 'livingdraft-core' ); ?>
				</p>
				<?php if ( '' !== $s['publisher_logo'] ) : ?>
					<div style="margin-top:8px;padding:10px;background:#fff;border:1px solid #e5e5e5;display:inline-block">
						<img src="<?php echo esc_url( $s['publisher_logo'] ); ?>" alt="" style="max-height:40px;max-width:250px;display:block">
					</div>
				<?php endif; ?>
			</div>

			<div class="tld-field">
				<label class="tld-label" for="ld_social_profiles"><?php esc_html_e( 'Social profiles', 'livingdraft-core' ); ?></label>
				<textarea id="ld_social_profiles" name="ld_schema[social_profiles]" class="tld-input" rows="4"
					placeholder="https://twitter.com/example
https://www.facebook.com/example
https://www.linkedin.com/company/example"><?php echo esc_textarea( $s['social_profiles'] ); ?></textarea>
				<p class="tld-help"><?php esc_html_e( 'One URL per line. Feeds Organization.sameAs so Google knows these profiles belong to the same entity.', 'livingdraft-core' ); ?></p>
			</div>

			<button type="submit" name="ld_seo_schema_save" class="tld-btn is-primary">
				<?php esc_html_e( 'Save', 'livingdraft-core' ); ?>
			</button>

			<?php if ( $enabled ) : ?>
				<a class="tld-btn"
					target="_blank" rel="noopener"
					href="<?php echo esc_url( 'https://search.google.com/test/rich-results?url=' . rawurlencode( $test_url ) ); ?>">
					<?php esc_html_e( 'Test in Google Rich Results →', 'livingdraft-core' ); ?>
				</a>
			<?php endif; ?>
		</form>

		<?php if ( $enabled ) : ?>
			<hr style="margin:24px 0;border:0;border-top:1px solid #eee">

			<h3 style="margin-top:0;font-family:var(--tld-serif, Georgia, serif);font-weight:400;font-size:16px">
				<?php esc_html_e( 'What we emit', 'livingdraft-core' ); ?>
			</h3>
			<ul style="font-size:13px;color:#333;line-height:1.9;padding-left:18px;margin:0">
				<li><strong>Every page:</strong> Organization + WebSite (with SearchAction for the sitelinks search box)</li>
				<li><strong>Singular posts:</strong> <?php echo esc_html( $s['article_type'] ); ?> + BreadcrumbList</li>
				<li><strong>Singular pages:</strong> WebPage + BreadcrumbList</li>
				<li><strong>Elsewhere:</strong> FAQ and Rating blocks continue emitting their own JSON-LD blocks (Google reads both — this isn't a problem)</li>
			</ul>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Save the Links tab settings.
 */
function livingdraft_seo_admin_handle_links_save() {
	if ( ! isset( $_POST['ld_seo_links_save'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'ld_seo_links' );

	update_option( 'livingdraft_links_enabled', isset( $_POST['links_enabled'] ) ? 1 : 0, false );

	wp_safe_redirect( add_query_arg( array( 'page' => 'livingdraft-seo', 'tab' => 'links', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
	exit;
}
add_action( 'admin_init', 'livingdraft_seo_admin_handle_links_save' );

/**
 * Render the Links tab.
 */
function livingdraft_seo_admin_render_links() {
	global $wpdb;
	$enabled = livingdraft_links_enabled();

	$post_types = livingdraft_links_post_types();
	$in         = "'" . implode( "','", array_map( 'esc_sql', $post_types ) ) . "'";

	$total = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts}
		 WHERE post_status = 'publish' AND post_password = ''
		   AND post_type IN ({$in})"
	); // phpcs:ignore

	// "Indexed" means: has an embedding row whose provider+model matches
	// the currently-active embedding config. Cross-provider embeddings
	// exist in the DB but are unusable for the current search, so they
	// count as unindexed (and the batch runner will reindex them).
	$s        = livingdraft_ai_get_settings();
	$provider = 'openrouter' === $s['provider'] ? 'openai' : $s['provider'];
	$model    = (string) ( $s[ 'embedding_model_' . $provider ] ?? '' );

	$embedding_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT pm.meta_value
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = %s
			   AND p.post_status = 'publish'
			   AND p.post_type IN ({$in})",
			LD_LINKS_META_KEY
		)
	); // phpcs:ignore
	$indexed = 0;
	foreach ( $embedding_rows as $r ) {
		$stored = maybe_unserialize( $r->meta_value );
		if ( is_array( $stored )
			&& ( $stored['provider'] ?? '' ) === $provider
			&& ( $stored['model'] ?? '' ) === $model ) {
			$indexed++;
		}
	}
	$remaining = max( 0, $total - $indexed );

	$ai_ready = livingdraft_seo_ai_ready();
	?>

	<div class="tld-card">
		<div class="tld-card-header">
			<div>
				<span class="tld-card-eyebrow"><?php esc_html_e( 'Related-post suggestions', 'livingdraft-core' ); ?></span>
				<h2 class="tld-card-title"><?php esc_html_e( 'Internal links', 'livingdraft-core' ); ?></h2>
			</div>
		</div>

		<form method="post">
			<?php wp_nonce_field( 'ld_seo_links' ); ?>

			<div class="tld-field">
				<label style="display:flex;align-items:flex-start;gap:10px;padding:14px;border:1px solid #d4d4d4;background:#fff;cursor:pointer">
					<input type="checkbox" name="links_enabled" value="1" <?php checked( $enabled ); ?> style="margin-top:2px">
					<span>
						<strong><?php esc_html_e( 'Enable internal link suggestions', 'livingdraft-core' ); ?></strong><br>
						<span style="color:var(--tld-ink-3, #666);font-size:13px">
							<?php esc_html_e( 'Adds an "Internal links" strip to every SEO metabox. Each published post gets an embedding on save so related-post suggestions are ready when the next writer sits down.', 'livingdraft-core' ); ?>
						</span>
					</span>
				</label>
			</div>

			<?php if ( ! $ai_ready ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Configure an OpenAI or Gemini key first — OpenRouter doesn\'t provide embeddings.', 'livingdraft-core' ); ?></p></div>
			<?php endif; ?>

			<button type="submit" name="ld_seo_links_save" class="tld-btn is-primary">
				<?php esc_html_e( 'Save', 'livingdraft-core' ); ?>
			</button>
		</form>

		<?php if ( $enabled && $ai_ready ) : ?>
			<hr style="margin:24px 0;border:0;border-top:1px solid #eee">

			<h3 style="margin-top:0;font-family:var(--tld-serif, Georgia, serif);font-weight:400;font-size:16px">
				<?php esc_html_e( 'Index status', 'livingdraft-core' ); ?>
			</h3>

			<div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:12px;margin-bottom:16px">
				<div style="padding:14px;background:#fafaf7;border:1px solid #e5e5e5">
					<div style="font-family:var(--tld-mono, monospace);font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:#666"><?php esc_html_e( 'Total posts', 'livingdraft-core' ); ?></div>
					<div style="font-family:Georgia, serif;font-size:24px;margin-top:4px"><?php echo esc_html( number_format_i18n( $total ) ); ?></div>
				</div>
				<div style="padding:14px;background:#fafaf7;border:1px solid #e5e5e5">
					<div style="font-family:var(--tld-mono, monospace);font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:#666"><?php esc_html_e( 'Indexed', 'livingdraft-core' ); ?></div>
					<div style="font-family:Georgia, serif;font-size:24px;margin-top:4px;color:var(--tld-good, #2f7a3a)" data-ld-indexed><?php echo esc_html( number_format_i18n( $indexed ) ); ?></div>
				</div>
				<div style="padding:14px;background:#fafaf7;border:1px solid #e5e5e5">
					<div style="font-family:var(--tld-mono, monospace);font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:#666"><?php esc_html_e( 'Remaining', 'livingdraft-core' ); ?></div>
					<div style="font-family:Georgia, serif;font-size:24px;margin-top:4px;color:var(--tld-mark, #8b3a2c)" data-ld-remaining><?php echo esc_html( number_format_i18n( $remaining ) ); ?></div>
				</div>
			</div>

			<div style="padding:12px 14px;background:#fff;border:1px solid #e5e5e5;font-family:var(--tld-mono, monospace);font-size:11px;color:#666">
				<?php
				printf(
					/* translators: 1: provider, 2: model */
					esc_html__( 'Embedding via %1$s %2$s. Estimated cost to fully index: %3$s.', 'livingdraft-core' ),
					esc_html( $provider ),
					'<strong>' . esc_html( $model ) . '</strong>',
					esc_html( livingdraft_seo_admin_estimated_cost( $remaining, $provider ) )
				);
				?>
			</div>

			<?php if ( $remaining > 0 ) : ?>
				<div style="margin-top:16px">
					<button type="button" class="tld-btn is-primary" id="ld-links-batch-btn"
						data-ld-nonce="<?php echo esc_attr( wp_create_nonce( 'ld_links_batch' ) ); ?>">
						<span class="ld-seo-ai-glyph">✦</span>
						<?php esc_html_e( 'Index remaining posts', 'livingdraft-core' ); ?>
					</button>
					<span id="ld-links-batch-status" style="margin-left:12px;font-family:var(--tld-mono, monospace);font-size:11px;color:#666"></span>
				</div>
			<?php else : ?>
				<div style="margin-top:16px;color:var(--tld-good, #2f7a3a);font-family:var(--tld-mono, monospace);font-size:11px">
					✓ <?php esc_html_e( 'ALL POSTS INDEXED', 'livingdraft-core' ); ?>
				</div>
			<?php endif; ?>

			<script>
			(function () {
				var btn = document.getElementById( 'ld-links-batch-btn' );
				if ( ! btn ) return;
				var status    = document.getElementById( 'ld-links-batch-status' );
				var indexed   = document.querySelector( '[data-ld-indexed]' );
				var remaining = document.querySelector( '[data-ld-remaining]' );
				var nonce     = btn.getAttribute( 'data-ld-nonce' );

				btn.addEventListener( 'click', function () {
					if ( btn.disabled ) return;
					btn.disabled = true;
					run();
				} );

				function run() {
					status.textContent = 'Working…';
					status.style.color = '#666';
					var fd = new FormData();
					fd.append( 'action', 'ld_links_batch_index' );
					fd.append( 'nonce', nonce );
					fd.append( 'batch', '15' );

					fetch( ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( res ) {
							if ( ! res || ! res.success ) {
								status.textContent = 'Error: ' + ( ( res && res.data && res.data.message ) || 'failed' );
								status.style.color = '#a32e2e';
								btn.disabled = false;
								return;
							}
							if ( indexed ) indexed.textContent = ( parseInt( indexed.textContent.replace( /,/g, '' ), 10 ) || 0 ) + res.data.done;
							if ( remaining ) remaining.textContent = res.data.remaining;
							status.textContent = res.data.done + ' indexed, ' + res.data.remaining + ' left' + ( res.data.failed ? ' (' + res.data.failed + ' failed)' : '' );

							if ( res.data.remaining > 0 ) {
								setTimeout( run, 800 );
							} else {
								status.textContent = '✓ All done.';
								status.style.color = '#2f7a3a';
								btn.disabled = false;
								btn.textContent = '✓ Complete';
							}
						} )
						.catch( function ( err ) {
							status.textContent = err.message;
							status.style.color = '#a32e2e';
							btn.disabled = false;
						} );
				}
			})();
			</script>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Rough cost estimate for indexing N posts. Uses average tokens per
 * post × current price per million tokens. Ballpark, not billing-grade.
 */
function livingdraft_seo_admin_estimated_cost( $count, $provider ) {
	if ( $count < 1 ) {
		return '$0.00';
	}
	// About 2600 tokens per typical 2000-word post.
	$tokens = $count * 2600;
	$prices_per_million = array(
		'openai' => 0.02, // text-embedding-3-small
		'gemini' => 0.00, // Generous free tier at time of writing.
	);
	$per_m = isset( $prices_per_million[ $provider ] ) ? $prices_per_million[ $provider ] : 0.02;
	$dollars = ( $tokens / 1000000 ) * $per_m;
	if ( $dollars < 0.01 && $dollars > 0 ) {
		return '< $0.01';
	}
	if ( 0.0 === $dollars ) {
		return __( 'free tier', 'livingdraft-core' );
	}
	return '$' . number_format( $dollars, 2 );
}

/* ==================================================================
 * v3.3.0 — WEBMASTERS TAB
 *
 * Three sections in one tab: site-verification meta tags, attachment
 * page redirect toggle, and RSS feed footer template. Each section
 * has its own <form> so partial saves don't blow away neighbouring
 * fields, and each posts to its own handler in the module file (see
 * inc/seo/verification.php, attachment-redirect.php, rss-footer.php).
 * ================================================================== */

function livingdraft_seo_admin_render_webmasters() {
	$verification_current = get_option( 'livingdraft_seo_verification', array() );
	$providers            = livingdraft_seo_verification_providers();
	$attachment_on        = livingdraft_seo_attachment_redirect_enabled();
	$rss_template         = livingdraft_seo_rss_footer_template();

	// Recalc-scores utility handler — one-shot, sync, safe on small
	// sites, warns on big ones. Piggy-backs this tab because it's the
	// natural place for "housekeeping" utilities that don't have their
	// own home yet.
	if ( isset( $_POST['ld_seo_recalc_scores'] ) && current_user_can( 'manage_options' ) ) {
		check_admin_referer( 'ld_seo_recalc_scores' );
		$n = function_exists( 'livingdraft_seo_recalc_all_scores' )
			? livingdraft_seo_recalc_all_scores( 500 )
			: 0;
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( 'Recomputed SEO scores for %d posts.', 'livingdraft-core' ), $n ) ) . '</p></div>';
	}
	?>

	<div class="tld-card" style="margin-bottom:20px">
		<h3 style="margin-top:0"><?php esc_html_e( 'Site verification', 'livingdraft-core' ); ?></h3>
		<p class="tld-help" style="margin-top:0;margin-bottom:16px">
			<?php esc_html_e( 'Paste the verification code (not the whole meta tag) from each service that asks you to prove you own this site. Empty fields emit nothing.', 'livingdraft-core' ); ?>
		</p>
		<form method="post">
			<?php wp_nonce_field( 'ld_seo_verification' ); ?>
			<?php foreach ( $providers as $slug => $spec ) : ?>
				<div class="tld-field">
					<label class="tld-label" for="ld_ver_<?php echo esc_attr( $slug ); ?>">
						<?php echo esc_html( $spec['label'] ); ?>
					</label>
					<input type="text"
						id="ld_ver_<?php echo esc_attr( $slug ); ?>"
						name="verification[<?php echo esc_attr( $slug ); ?>]"
						class="tld-input"
						style="max-width:520px;font-family:var(--tld-mono, monospace);font-size:12px"
						value="<?php echo esc_attr( isset( $verification_current[ $slug ] ) ? $verification_current[ $slug ] : '' ); ?>"
						placeholder="<?php echo esc_attr( $spec['placeholder'] ); ?>">
					<p class="tld-help"><?php echo esc_html( $spec['help'] ); ?>
						<code style="font-size:11px;color:var(--tld-ink-3, #666)">&lt;meta name="<?php echo esc_html( $spec['meta_name'] ); ?>" ...&gt;</code>
					</p>
				</div>
			<?php endforeach; ?>
			<button type="submit" name="ld_seo_verification_save" class="tld-btn is-primary">
				<?php esc_html_e( 'Save verification tags', 'livingdraft-core' ); ?>
			</button>
		</form>
	</div>

	<div class="tld-card" style="margin-bottom:20px">
		<h3 style="margin-top:0"><?php esc_html_e( 'Attachment page redirect', 'livingdraft-core' ); ?></h3>
		<p class="tld-help" style="margin-top:0;margin-bottom:16px">
			<?php esc_html_e( 'By default WordPress gives every uploaded file its own URL (/?attachment_id=…). Those pages compete with the actual post for search rankings. Redirecting them to the parent post kills that duplicate content problem and passes link equity to the post.', 'livingdraft-core' ); ?>
		</p>
		<form method="post">
			<?php wp_nonce_field( 'ld_seo_attachment' ); ?>
			<label style="display:flex;align-items:flex-start;gap:10px;padding:14px;border:1px solid #d4d4d4;background:#fff;cursor:pointer">
				<input type="checkbox" name="attachment_redirect" value="1" <?php checked( $attachment_on ); ?> style="margin-top:2px">
				<span>
					<strong><?php esc_html_e( '301 redirect attachment pages to their parent post (or the file itself if orphaned)', 'livingdraft-core' ); ?></strong><br>
					<span style="color:var(--tld-ink-3, #666);font-size:13px">
						<?php esc_html_e( 'Recommended for almost every site. Turn off only if your theme has a legitimate attachment gallery template.', 'livingdraft-core' ); ?>
					</span>
				</span>
			</label>
			<div style="margin-top:14px">
				<button type="submit" name="ld_seo_attachment_save" class="tld-btn is-primary">
					<?php esc_html_e( 'Save', 'livingdraft-core' ); ?>
				</button>
			</div>
		</form>
	</div>

	<div class="tld-card" style="margin-bottom:20px">
		<h3 style="margin-top:0"><?php esc_html_e( 'RSS feed footer', 'livingdraft-core' ); ?></h3>
		<p class="tld-help" style="margin-top:0;margin-bottom:16px">
			<?php esc_html_e( 'Text appended to every RSS item. Combats content scrapers by including a canonical link back — Google treats that link as a strong signal and keeps the original ranking authority with you, not the scraper. Available variables:', 'livingdraft-core' ); ?>
			<code style="font-size:11px">%%link%%</code>
			<code style="font-size:11px">%%title%%</code>
			<code style="font-size:11px">%%sitename%%</code>
			<code style="font-size:11px">%%siteurl%%</code>
			<code style="font-size:11px">%%author%%</code>
			<code style="font-size:11px">%%year%%</code>
		</p>
		<form method="post">
			<?php wp_nonce_field( 'ld_seo_rss_footer' ); ?>
			<textarea name="rss_footer" rows="4" class="tld-input" style="font-family:var(--tld-mono, monospace);font-size:12px;width:100%;max-width:700px"><?php echo esc_textarea( $rss_template ); ?></textarea>
			<p class="tld-help">
				<?php esc_html_e( 'Leave empty to disable the footer entirely.', 'livingdraft-core' ); ?>
			</p>
			<button type="submit" name="ld_seo_rss_footer_save" class="tld-btn is-primary">
				<?php esc_html_e( 'Save RSS footer', 'livingdraft-core' ); ?>
			</button>
		</form>
	</div>

	<div class="tld-card">
		<h3 style="margin-top:0"><?php esc_html_e( 'Housekeeping', 'livingdraft-core' ); ?></h3>
		<p class="tld-help" style="margin-top:0;margin-bottom:16px">
			<?php esc_html_e( 'Post-list SEO scores are cached per post and updated on save. Legacy posts that predate v3.3.0 will show a dash until you edit and save them, or run this tool once to backfill.', 'livingdraft-core' ); ?>
		</p>
		<form method="post">
			<?php wp_nonce_field( 'ld_seo_recalc_scores' ); ?>
			<button type="submit" name="ld_seo_recalc_scores" class="tld-btn">
				<?php esc_html_e( 'Recompute SEO scores for up to 500 posts', 'livingdraft-core' ); ?>
			</button>
		</form>
	</div>
	<?php
}
