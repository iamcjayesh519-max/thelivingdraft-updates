<?php
/**
 * SEO Desk — coverage dashboard, term list columns, and bulk
 * term-meta generator.
 *
 * Three related capabilities in one module because they share the
 * same "field coverage" concept: which posts / pages / categories /
 * tags have their SEO title, meta description, and focus keyword
 * filled in — and, when they're not, an AI-powered way to fill them
 * in at scale rather than one at a time.
 *
 * ---------------------------------------------------------------
 * WHY ONE MODULE
 *
 * The overview dashboard reads coverage counts from the same
 * postmeta / termmeta the bulk generator writes to, and both use
 * the term list column data to display per-item status. Splitting
 * across files would fragment shared query logic without any
 * offsetting benefit.
 *
 * ---------------------------------------------------------------
 * WHY THE BULK GENERATOR USES A JSON BUNDLE
 *
 * The existing per-field AI (livingdraft_seo_term_ajax_ai) makes
 * one API call per field: three calls per term to fill title +
 * description + keyword. For a newsroom with 200 categories all
 * missing all three, that's 600 API calls. The bulk path uses a
 * new `term_meta_bundle` prompt (in ai-provider.php) that returns
 * all three fields as one JSON object per call — 3x cheaper and
 * 3x faster.
 *
 * @package LivingDraftCore
 * @since   3.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ==================================================================
 * 1. TERM LIST COLUMNS
 *
 * On the Categories / Tags / custom-taxonomy list screens, add:
 *   - SEO status pill (all three filled / partial / none)
 *   - GSC impressions
 *   - GSC clicks
 *
 * Matches the pattern used on the Posts list from
 * inc/seo/post-list-columns.php so users see the same shape of
 * information on both.
 * ================================================================== */

function livingdraft_seo_desk_term_taxonomies() {
	// Reuse the same taxonomy set the term-meta module allows SEO
	// fields on, so columns only appear where the fields exist.
	if ( function_exists( 'livingdraft_seo_term_taxonomies' ) ) {
		return livingdraft_seo_term_taxonomies();
	}
	$excluded = array( 'nav_menu', 'link_category', 'post_format' );
	$out      = array();
	foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
		if ( ! in_array( $tax->name, $excluded, true ) ) {
			$out[] = $tax->name;
		}
	}
	return $out;
}

function livingdraft_seo_desk_register_term_columns() {
	if ( ! is_admin() ) {
		return;
	}
	$gsc_on = function_exists( 'livingdraft_gsc_configured' ) && livingdraft_gsc_configured();
	foreach ( livingdraft_seo_desk_term_taxonomies() as $tax ) {
		add_filter( "manage_edit-{$tax}_columns", function ( $columns ) use ( $gsc_on ) {
			$columns['ld_seo_status'] = __( 'SEO', 'livingdraft-core' );
			if ( $gsc_on ) {
				$columns['ld_gsc_impressions'] = __( 'Impr.', 'livingdraft-core' );
				$columns['ld_gsc_clicks']      = __( 'Clicks', 'livingdraft-core' );
			}
			return $columns;
		} );
		add_filter( "manage_{$tax}_custom_column", 'livingdraft_seo_desk_render_term_column', 10, 3 );
	}
}
add_action( 'admin_init', 'livingdraft_seo_desk_register_term_columns' );

function livingdraft_seo_desk_render_term_column( $content, $column, $term_id ) {
	if ( 'ld_seo_status' === $column ) {
		return livingdraft_seo_desk_term_status_pill( (int) $term_id );
	}
	if ( 'ld_gsc_impressions' === $column ) {
		$v = get_term_meta( (int) $term_id, '_ld_gsc_impressions', true );
		if ( '' === $v || null === $v ) {
			return '<span style="color:#ccc">—</span>';
		}
		return '<span style="font-family:var(--tld-mono, monospace);font-size:12px">' . esc_html( number_format_i18n( (int) $v ) ) . '</span>';
	}
	if ( 'ld_gsc_clicks' === $column ) {
		$v = get_term_meta( (int) $term_id, '_ld_gsc_clicks', true );
		if ( '' === $v || null === $v ) {
			return '<span style="color:#ccc">—</span>';
		}
		$n = (int) $v;
		$col = $n > 0 ? '#2f7a3a' : '#999';
		return sprintf(
			'<span style="font-family:var(--tld-mono, monospace);font-size:12px;color:%s;font-weight:%s">%s</span>',
			esc_attr( $col ),
			$n > 0 ? '600' : '400',
			esc_html( number_format_i18n( $n ) )
		);
	}
	return $content;
}

/**
 * Render the SEO status pill for one term.
 * Three-state: all three fields filled (green), partial (amber),
 * nothing filled (red).
 */
function livingdraft_seo_desk_term_status_pill( $term_id ) {
	if ( ! function_exists( 'livingdraft_seo_term_get' ) ) {
		return '';
	}
	$title = trim( (string) livingdraft_seo_term_get( 'title', $term_id ) );
	$desc  = trim( (string) livingdraft_seo_term_get( 'description', $term_id ) );
	$kw    = trim( (string) livingdraft_seo_term_get( 'focus_keyword', $term_id ) );

	$filled = 0;
	if ( '' !== $title ) $filled++;
	if ( '' !== $desc )  $filled++;
	if ( '' !== $kw )    $filled++;

	if ( 3 === $filled ) {
		return '<span style="display:inline-block;padding:3px 8px;background:#e7f3e8;color:#2f7a3a;font-size:11px;font-family:var(--tld-mono, monospace);letter-spacing:.05em">' . esc_html__( 'COMPLETE', 'livingdraft-core' ) . '</span>';
	}
	if ( 0 === $filled ) {
		return '<span style="display:inline-block;padding:3px 8px;background:#fdecec;color:#a32e2e;font-size:11px;font-family:var(--tld-mono, monospace);letter-spacing:.05em">' . esc_html__( 'NONE', 'livingdraft-core' ) . '</span>';
	}
	return sprintf(
		'<span style="display:inline-block;padding:3px 8px;background:#fdf5e6;color:#b7791f;font-size:11px;font-family:var(--tld-mono, monospace);letter-spacing:.05em" title="%s">%s (%d/3)</span>',
		esc_attr( sprintf( __( '%d of 3 SEO fields filled', 'livingdraft-core' ), $filled ) ),
		esc_html__( 'PARTIAL', 'livingdraft-core' ),
		$filled
	);
}

/* ==================================================================
 * 2. BULK TERM META GENERATOR — QUERY + AJAX
 * ================================================================== */

/**
 * Return term IDs across all SEO-enabled taxonomies that are missing
 * at least one of the three SEO fields (title, description, keyword).
 *
 * Uses a single DB query rather than iterating every term — for a
 * site with thousands of terms this matters. The query joins termmeta
 * to find terms that have no `_ld_seo_title`, `_ld_seo_description`,
 * or `_ld_seo_focus_keyword` entry, then unions those IDs.
 */
function livingdraft_seo_desk_pending_term_ids( $limit = 500, $taxonomy_filter = '' ) {
	global $wpdb;

	$taxonomies = livingdraft_seo_desk_term_taxonomies();
	if ( '' !== $taxonomy_filter && in_array( $taxonomy_filter, $taxonomies, true ) ) {
		$taxonomies = array( $taxonomy_filter );
	}
	if ( empty( $taxonomies ) ) {
		return array();
	}

	$in = "'" . implode( "','", array_map( 'esc_sql', $taxonomies ) ) . "'";

	// A term is "pending" when it lacks ALL THREE meta rows or any
	// value is empty. We check for the specific "missing all three"
	// case — partial fills are treated as done to respect user work.
	// Users who want to force regeneration can delete termmeta first.
	$sql = "
		SELECT DISTINCT t.term_id, tt.taxonomy
		FROM {$wpdb->terms} t
		INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
		LEFT JOIN {$wpdb->termmeta} title_m ON title_m.term_id = t.term_id AND title_m.meta_key = '_ld_seo_title'
		LEFT JOIN {$wpdb->termmeta} desc_m  ON desc_m.term_id  = t.term_id AND desc_m.meta_key  = '_ld_seo_description'
		LEFT JOIN {$wpdb->termmeta} kw_m    ON kw_m.term_id    = t.term_id AND kw_m.meta_key    = '_ld_seo_focus_keyword'
		WHERE tt.taxonomy IN ({$in})
		  AND (
			title_m.meta_id IS NULL OR title_m.meta_value = ''
			OR desc_m.meta_id IS NULL OR desc_m.meta_value = ''
			OR kw_m.meta_id IS NULL OR kw_m.meta_value = ''
		  )
		ORDER BY t.term_id ASC
		LIMIT " . (int) $limit;

	$rows = $wpdb->get_results( $sql );
	$out  = array();
	foreach ( (array) $rows as $row ) {
		$out[] = array(
			'term_id'  => (int) $row->term_id,
			'taxonomy' => (string) $row->taxonomy,
		);
	}
	return $out;
}

/**
 * Count-of-pending helper for the dashboard "N terms need attention".
 */
function livingdraft_seo_desk_pending_term_count() {
	global $wpdb;
	$taxonomies = livingdraft_seo_desk_term_taxonomies();
	if ( empty( $taxonomies ) ) {
		return 0;
	}
	$in = "'" . implode( "','", array_map( 'esc_sql', $taxonomies ) ) . "'";
	return (int) $wpdb->get_var( "
		SELECT COUNT(DISTINCT t.term_id)
		FROM {$wpdb->terms} t
		INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
		LEFT JOIN {$wpdb->termmeta} title_m ON title_m.term_id = t.term_id AND title_m.meta_key = '_ld_seo_title'
		LEFT JOIN {$wpdb->termmeta} desc_m  ON desc_m.term_id  = t.term_id AND desc_m.meta_key  = '_ld_seo_description'
		LEFT JOIN {$wpdb->termmeta} kw_m    ON kw_m.term_id    = t.term_id AND kw_m.meta_key    = '_ld_seo_focus_keyword'
		WHERE tt.taxonomy IN ({$in})
		  AND (
			title_m.meta_id IS NULL OR title_m.meta_value = ''
			OR desc_m.meta_id IS NULL OR desc_m.meta_value = ''
			OR kw_m.meta_id IS NULL OR kw_m.meta_value = ''
		  )
	" );
}

/**
 * AJAX endpoint: generate all three SEO fields for ONE term via a
 * single JSON-bundle AI call. Client-side loops term-by-term with
 * a progress bar.
 *
 * We don't process multiple terms server-side because:
 *   - One term = one AI call = a few seconds. Multiple would push
 *     the request over WP's default 30s timeout easily.
 *   - Progress-per-term is more useful UX than progress-per-batch.
 */
function livingdraft_seo_desk_ajax_generate_term() {
	check_ajax_referer( 'ld_seo_desk_generate', 'nonce' );
	if ( ! current_user_can( 'manage_categories' ) ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'livingdraft-core' ) ) );
	}
	if ( ! function_exists( 'livingdraft_seo_ai_ready' ) || ! livingdraft_seo_ai_ready() ) {
		wp_send_json_error( array( 'message' => __( 'AI provider not configured. Add an API key under Settings → AI first.', 'livingdraft-core' ) ) );
	}

	$term_id  = isset( $_POST['term_id'] ) ? (int) $_POST['term_id'] : 0;
	$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
	$overwrite = isset( $_POST['overwrite'] ) && '1' === (string) $_POST['overwrite'];
	if ( ! $term_id || '' === $taxonomy ) {
		wp_send_json_error( array( 'message' => __( 'Missing term or taxonomy.', 'livingdraft-core' ) ) );
	}

	$term = get_term( $term_id, $taxonomy );
	if ( ! $term || is_wp_error( $term ) ) {
		wp_send_json_error( array( 'message' => __( 'Term not found.', 'livingdraft-core' ) ) );
	}

	// Skip terms already fully covered (unless caller asked for
	// overwrite). Cheaper than making the AI call and discarding.
	$existing_title = trim( (string) livingdraft_seo_term_get( 'title', $term_id ) );
	$existing_desc  = trim( (string) livingdraft_seo_term_get( 'description', $term_id ) );
	$existing_kw    = trim( (string) livingdraft_seo_term_get( 'focus_keyword', $term_id ) );
	if ( ! $overwrite && '' !== $existing_title && '' !== $existing_desc && '' !== $existing_kw ) {
		wp_send_json_success( array(
			'term_id'   => $term_id,
			'skipped'   => true,
			'reason'    => 'already-complete',
			'written'   => array(),
		) );
	}

	// Compose the archive context — same as the per-field endpoint,
	// but shared here since the JSON bundle needs it once.
	$context_lines = array();
	$term_desc     = trim( wp_strip_all_tags( (string) $term->description ) );
	if ( '' !== $term_desc ) {
		$context_lines[] = $term_desc;
	}
	$recent_posts = get_posts( array(
		'posts_per_page' => 10,
		'post_status'    => 'publish',
		'orderby'        => 'date',
		'order'          => 'DESC',
		'tax_query'      => array(
			array(
				'taxonomy' => $taxonomy,
				'field'    => 'term_id',
				'terms'    => $term_id,
			),
		),
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
		'fields'                 => 'ids',
	) );
	if ( ! empty( $recent_posts ) ) {
		$context_lines[] = 'Recent articles in this archive:';
		foreach ( $recent_posts as $pid ) {
			$context_lines[] = '- ' . get_the_title( $pid );
		}
	}

	$taxonomy_obj = get_taxonomy( $taxonomy );
	$tax_label    = $taxonomy_obj && $taxonomy_obj->labels && $taxonomy_obj->labels->singular_name
		? strtolower( $taxonomy_obj->labels->singular_name )
		: 'category';

	$context = array(
		'title'          => $term->name,
		'excerpt'        => implode( "\n", $context_lines ),
		'taxonomy_label' => $tax_label,
	);

	$prompt = livingdraft_ai_build_prompt( 'term_meta_bundle', $context );
	if ( '' === $prompt ) {
		wp_send_json_error( array( 'message' => __( 'Unknown AI task.', 'livingdraft-core' ) ) );
	}

	$override = livingdraft_ai_read_request_override();
	$result   = livingdraft_ai_complete(
		$prompt,
		array_merge(
			array(
				'task'        => 'term_meta_bundle',
				'max_tokens'  => 350,
				'temperature' => 0.6,
				// v3.7.2: bypass the per-user 30/5min interactive rate
				// limit. The user explicitly clicked "Start bulk
				// generation" — this loop is intentional, not a runaway
				// UI. Provider-side rate limits still apply (OpenAI /
				// Gemini / OpenRouter each enforce their own per-minute
				// caps at the account level).
				'bypass_rate_limit' => true,
			),
			$override
		)
	);
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array(
			'message' => $result->get_error_message(),
			'term_id' => $term_id,
			'term'    => $term->name,
		) );
	}

	// Strip markdown fences some models add despite "return only JSON".
	$json = trim( $result );
	$json = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $json );
	$data = json_decode( $json, true );
	if ( ! is_array( $data ) ) {
		wp_send_json_error( array(
			'message' => __( 'AI returned unparseable JSON. Try again.', 'livingdraft-core' ),
			'raw'     => mb_substr( $result, 0, 300 ),
			'term_id' => $term_id,
			'term'    => $term->name,
		) );
	}

	$written = array();
	if ( isset( $data['seo_title'] ) && '' !== trim( (string) $data['seo_title'] ) ) {
		if ( '' === $existing_title || $overwrite ) {
			update_term_meta( $term_id, '_ld_seo_title', sanitize_text_field( $data['seo_title'] ) );
			$written[] = 'title';
		}
	}
	if ( isset( $data['meta_description'] ) && '' !== trim( (string) $data['meta_description'] ) ) {
		if ( '' === $existing_desc || $overwrite ) {
			update_term_meta( $term_id, '_ld_seo_description', sanitize_text_field( $data['meta_description'] ) );
			$written[] = 'description';
		}
	}
	if ( isset( $data['focus_keyword'] ) && '' !== trim( (string) $data['focus_keyword'] ) ) {
		if ( '' === $existing_kw || $overwrite ) {
			$kw = sanitize_text_field( $data['focus_keyword'] );
			$kw = rtrim( trim( $kw, " \t\n\r\0\x0B\"'`" ), '.' );
			update_term_meta( $term_id, '_ld_seo_focus_keyword', $kw );
			$written[] = 'focus_keyword';
		}
	}

	wp_send_json_success( array(
		'term_id' => $term_id,
		'term'    => $term->name,
		'written' => $written,
		'skipped' => empty( $written ),
	) );
}
add_action( 'wp_ajax_ld_seo_desk_generate_term', 'livingdraft_seo_desk_ajax_generate_term' );

/**
 * AJAX endpoint: return the next batch of pending term IDs. The
 * client fetches these upfront and iterates through them itself,
 * so a stopped/refreshed page loses only progress, not state.
 */
function livingdraft_seo_desk_ajax_pending_terms() {
	check_ajax_referer( 'ld_seo_desk_generate', 'nonce' );
	if ( ! current_user_can( 'manage_categories' ) ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'livingdraft-core' ) ) );
	}
	$tax = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
	wp_send_json_success( array( 'pending' => livingdraft_seo_desk_pending_term_ids( 500, $tax ) ) );
}
add_action( 'wp_ajax_ld_seo_desk_pending_terms', 'livingdraft_seo_desk_ajax_pending_terms' );

/* ==================================================================
 * 3. ADMIN PAGE — dashboard + bulk generator
 * ================================================================== */

function livingdraft_seo_desk_admin_menu() {
	add_submenu_page(
		'livingdraft-seo',
		__( 'SEO Desk', 'livingdraft-core' ),
		__( 'SEO Desk', 'livingdraft-core' ),
		'manage_options',
		'livingdraft-seo-desk',
		'livingdraft_seo_desk_render_page'
	);
}
add_action( 'admin_menu', 'livingdraft_seo_desk_admin_menu', 20 );

function livingdraft_seo_desk_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'livingdraft-core' ) );
	}

	$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview';
	if ( ! in_array( $tab, array( 'overview', 'bulk' ), true ) ) {
		$tab = 'overview';
	}

	// Header render — reuse the shared header if available.
	if ( function_exists( 'livingdraft_admin_render_header' ) ) {
		livingdraft_admin_render_header( array(
			'eyebrow' => __( 'The Living Draft Core · SEO', 'livingdraft-core' ),
			'title'   => 'overview' === $tab ? __( 'SEO Desk — Overview', 'livingdraft-core' ) : __( 'SEO Desk — Bulk term generator', 'livingdraft-core' ),
			'desc'    => 'overview' === $tab
				? __( 'Coverage status for SEO metadata across posts, pages, categories, and tags.', 'livingdraft-core' )
				: __( 'Fill in missing SEO title, description, and focus keyword for every category and tag using AI. One click, batched safely.', 'livingdraft-core' ),
			'active'  => 'livingdraft-seo',
		) );
	} else {
		echo '<div class="wrap"><h1>' . esc_html__( 'SEO Desk', 'livingdraft-core' ) . '</h1>';
	}

	// Sub-nav. v3.8.0: uses the shared render_subtabs helper.
	if ( function_exists( 'livingdraft_admin_render_subtabs' ) ) {
		livingdraft_admin_render_subtabs(
			array(
				'overview' => array( 'label' => __( 'Overview', 'livingdraft-core' ) ),
				'bulk'     => array( 'label' => __( 'Bulk term generator', 'livingdraft-core' ) ),
			),
			$tab,
			'livingdraft-seo-desk'
		);
	}

	if ( 'overview' === $tab ) {
		livingdraft_seo_desk_render_overview();
	} else {
		livingdraft_seo_desk_render_bulk();
	}

	if ( function_exists( 'livingdraft_admin_render_footer' ) ) {
		livingdraft_admin_render_footer();
	} else {
		echo '</div>';
	}
}

/**
 * Overview tab: coverage matrix. One row per content type, columns
 * for total / missing title / missing description / missing keyword.
 */
function livingdraft_seo_desk_render_overview() {
	global $wpdb;

	// Post coverage — one query joining postmeta three times.
	$post_types = array( 'post', 'page' );
	$rows       = array();
	foreach ( $post_types as $pt ) {
		$total   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", $pt ) );
		if ( 0 === $total ) {
			continue;
		}
		$missing_title = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(p.ID) FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_ld_seo_title'
			 WHERE p.post_type = %s AND p.post_status = 'publish'
			   AND (m.meta_id IS NULL OR m.meta_value = '')",
			$pt
		) );
		$missing_desc = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(p.ID) FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_ld_seo_description'
			 WHERE p.post_type = %s AND p.post_status = 'publish'
			   AND (m.meta_id IS NULL OR m.meta_value = '')",
			$pt
		) );
		$missing_kw = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(p.ID) FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_ld_seo_focus_keyword'
			 WHERE p.post_type = %s AND p.post_status = 'publish'
			   AND (m.meta_id IS NULL OR m.meta_value = '')",
			$pt
		) );
		$rows[] = array(
			'label' => ucfirst( $pt ) . 's',
			'kind'  => 'post_type',
			'name'  => $pt,
			'total' => $total,
			'mt'    => $missing_title,
			'md'    => $missing_desc,
			'mk'    => $missing_kw,
			'link'  => admin_url( 'edit.php?post_type=' . $pt ),
		);
	}

	// Term coverage — one row per SEO-enabled taxonomy.
	$taxonomies = livingdraft_seo_desk_term_taxonomies();
	foreach ( $taxonomies as $tax ) {
		$tax_obj = get_taxonomy( $tax );
		$label   = $tax_obj && $tax_obj->labels ? $tax_obj->labels->name : $tax;
		$total   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $tax ) );
		if ( 0 === $total ) {
			continue;
		}
		$missing_title = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(tt.term_id) FROM {$wpdb->term_taxonomy} tt
			 LEFT JOIN {$wpdb->termmeta} m ON m.term_id = tt.term_id AND m.meta_key = '_ld_seo_title'
			 WHERE tt.taxonomy = %s
			   AND (m.meta_id IS NULL OR m.meta_value = '')",
			$tax
		) );
		$missing_desc = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(tt.term_id) FROM {$wpdb->term_taxonomy} tt
			 LEFT JOIN {$wpdb->termmeta} m ON m.term_id = tt.term_id AND m.meta_key = '_ld_seo_description'
			 WHERE tt.taxonomy = %s
			   AND (m.meta_id IS NULL OR m.meta_value = '')",
			$tax
		) );
		$missing_kw = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(tt.term_id) FROM {$wpdb->term_taxonomy} tt
			 LEFT JOIN {$wpdb->termmeta} m ON m.term_id = tt.term_id AND m.meta_key = '_ld_seo_focus_keyword'
			 WHERE tt.taxonomy = %s
			   AND (m.meta_id IS NULL OR m.meta_value = '')",
			$tax
		) );
		$rows[] = array(
			'label' => $label,
			'kind'  => 'taxonomy',
			'name'  => $tax,
			'total' => $total,
			'mt'    => $missing_title,
			'md'    => $missing_desc,
			'mk'    => $missing_kw,
			'link'  => admin_url( 'edit-tags.php?taxonomy=' . $tax ),
		);
	}
	?>
	<div class="tld-card">
		<div class="tld-card-header">
			<div>
				<span class="tld-card-eyebrow"><?php esc_html_e( 'SEO metadata coverage', 'livingdraft-core' ); ?></span>
				<h2 class="tld-card-title"><?php esc_html_e( 'How well is your metadata filled in?', 'livingdraft-core' ); ?></h2>
			</div>
		</div>
		<p style="color:var(--tld-ink-3, #666);max-width:60ch;margin-top:0"><?php esc_html_e( 'Each row shows how many items are missing at least one SEO field. Click any count to open a filtered list. Use the Bulk term generator tab to fill category and tag gaps with AI.', 'livingdraft-core' ); ?></p>

		<table class="widefat striped" style="margin-top:12px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Content type', 'livingdraft-core' ); ?></th>
					<th style="width:100px;text-align:right"><?php esc_html_e( 'Total', 'livingdraft-core' ); ?></th>
					<th style="width:130px;text-align:right"><?php esc_html_e( 'Missing title', 'livingdraft-core' ); ?></th>
					<th style="width:150px;text-align:right"><?php esc_html_e( 'Missing description', 'livingdraft-core' ); ?></th>
					<th style="width:150px;text-align:right"><?php esc_html_e( 'Missing focus keyword', 'livingdraft-core' ); ?></th>
					<th style="width:120px"><?php esc_html_e( 'Coverage', 'livingdraft-core' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $r ) :
				// Coverage bar: percent of (title+desc+kw) filled across all items.
				$total_fields = $r['total'] * 3;
				$missing      = $r['mt'] + $r['md'] + $r['mk'];
				$pct          = $total_fields > 0 ? round( 100 * ( $total_fields - $missing ) / $total_fields ) : 0;
				$bar_color    = $pct >= 80 ? '#2f7a3a' : ( $pct >= 50 ? '#b7791f' : '#a32e2e' );
				?>
				<tr>
					<td>
						<strong><a href="<?php echo esc_url( $r['link'] ); ?>" style="text-decoration:none"><?php echo esc_html( $r['label'] ); ?></a></strong>
						<div style="font-size:11px;color:#999"><?php echo esc_html( 'taxonomy' === $r['kind'] ? $r['name'] : $r['name'] ); ?></div>
					</td>
					<td style="text-align:right;font-family:var(--tld-mono, monospace)"><?php echo esc_html( number_format_i18n( $r['total'] ) ); ?></td>
					<td style="text-align:right;font-family:var(--tld-mono, monospace);color:<?php echo $r['mt'] > 0 ? '#a32e2e' : '#2f7a3a'; ?>"><?php echo esc_html( number_format_i18n( $r['mt'] ) ); ?></td>
					<td style="text-align:right;font-family:var(--tld-mono, monospace);color:<?php echo $r['md'] > 0 ? '#a32e2e' : '#2f7a3a'; ?>"><?php echo esc_html( number_format_i18n( $r['md'] ) ); ?></td>
					<td style="text-align:right;font-family:var(--tld-mono, monospace);color:<?php echo $r['mk'] > 0 ? '#a32e2e' : '#2f7a3a'; ?>"><?php echo esc_html( number_format_i18n( $r['mk'] ) ); ?></td>
					<td>
						<div style="background:#eee;height:12px;position:relative;overflow:hidden">
							<div style="background:<?php echo esc_attr( $bar_color ); ?>;height:100%;width:<?php echo (int) $pct; ?>%"></div>
						</div>
						<div style="font-family:var(--tld-mono, monospace);font-size:10px;margin-top:2px;color:<?php echo esc_attr( $bar_color ); ?>"><?php echo (int) $pct; ?>%</div>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/**
 * Bulk generator tab: single button + progress display.
 * All the heavy lifting is client-side JS looping the AJAX endpoint
 * term by term, so the user sees real-time progress and can stop
 * at any point by closing the tab (server state remains consistent
 * because each call is atomic).
 */
function livingdraft_seo_desk_render_bulk() {
	$ai_ready       = function_exists( 'livingdraft_seo_ai_ready' ) && livingdraft_seo_ai_ready();
	$pending_count  = livingdraft_seo_desk_pending_term_count();
	$nonce          = wp_create_nonce( 'ld_seo_desk_generate' );

	// Per-taxonomy pending counts so the user knows what they're
	// about to work on.
	global $wpdb;
	$tax_counts = array();
	foreach ( livingdraft_seo_desk_term_taxonomies() as $tax ) {
		$in  = "'" . esc_sql( $tax ) . "'";
		$tax_counts[ $tax ] = (int) $wpdb->get_var( "
			SELECT COUNT(DISTINCT t.term_id)
			FROM {$wpdb->terms} t
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
			LEFT JOIN {$wpdb->termmeta} title_m ON title_m.term_id = t.term_id AND title_m.meta_key = '_ld_seo_title'
			LEFT JOIN {$wpdb->termmeta} desc_m  ON desc_m.term_id  = t.term_id AND desc_m.meta_key  = '_ld_seo_description'
			LEFT JOIN {$wpdb->termmeta} kw_m    ON kw_m.term_id    = t.term_id AND kw_m.meta_key    = '_ld_seo_focus_keyword'
			WHERE tt.taxonomy IN ({$in})
			  AND (
				title_m.meta_id IS NULL OR title_m.meta_value = ''
				OR desc_m.meta_id IS NULL OR desc_m.meta_value = ''
				OR kw_m.meta_id IS NULL OR kw_m.meta_value = ''
			  )
		" );
	}
	?>

	<?php if ( ! $ai_ready ) : ?>
		<div class="notice notice-warning" style="padding:14px;margin-bottom:16px">
			<p><strong><?php esc_html_e( 'No AI provider configured.', 'livingdraft-core' ); ?></strong>
			<?php
			printf(
				/* translators: %s: link to the AI settings tab */
				esc_html__( 'Add an OpenAI, Google Gemini, or OpenRouter API key %s before running bulk generation.', 'livingdraft-core' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=livingdraft-seo&tab=ai' ) ) . '">' . esc_html__( 'here', 'livingdraft-core' ) . '</a>'
			);
			?>
			</p>
		</div>
	<?php endif; ?>

	<div class="tld-card">
		<div class="tld-card-header">
			<div>
				<span class="tld-card-eyebrow"><?php esc_html_e( 'Bulk AI', 'livingdraft-core' ); ?></span>
				<h2 class="tld-card-title"><?php esc_html_e( 'Generate missing SEO metadata for terms', 'livingdraft-core' ); ?></h2>
			</div>
		</div>

		<p style="color:var(--tld-ink-3, #666);max-width:60ch;margin-top:0"><?php esc_html_e( 'For every category / tag that\'s missing at least one of SEO title, meta description, or focus keyword, this makes ONE AI call per term that fills in all three at once from a JSON prompt (3x cheaper than filling one field at a time). Existing values on your side are never overwritten. Safe to stop and resume — each call is atomic.', 'livingdraft-core' ); ?></p>

		<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:14px 0">
			<div style="padding:14px;background:#fafaf7;border:1px solid #e5e5e5">
				<div style="font-family:var(--tld-mono, monospace);font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:#666"><?php esc_html_e( 'Terms needing attention', 'livingdraft-core' ); ?></div>
				<div id="ld-desk-pending-count" style="font-family:Georgia, serif;font-size:24px;margin-top:4px;color:<?php echo $pending_count > 0 ? '#b7791f' : '#2f7a3a'; ?>"><?php echo esc_html( number_format_i18n( $pending_count ) ); ?></div>
			</div>
			<div style="padding:14px;background:#fafaf7;border:1px solid #e5e5e5">
				<div style="font-family:var(--tld-mono, monospace);font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:#666"><?php esc_html_e( 'Estimated cost (rough)', 'livingdraft-core' ); ?></div>
				<div style="font-family:Georgia, serif;font-size:16px;margin-top:4px">
					<?php
					$est_cost = $pending_count * 0.002; // ~$0.002 per call on cheap models
					printf( '$%.2f – $%.2f', $est_cost * 0.5, $est_cost * 2 );
					?>
				</div>
				<div style="font-size:10px;color:#999;margin-top:2px"><?php esc_html_e( 'Depends on your chosen model. GPT-4o mini / Gemini Flash are the cheap end.', 'livingdraft-core' ); ?></div>
			</div>
		</div>

		<?php if ( ! empty( $tax_counts ) ) : ?>
			<div style="margin:14px 0;padding:12px;background:#fff;border:1px solid #e5e5e5">
				<div style="font-family:var(--tld-mono, monospace);font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:#666;margin-bottom:6px"><?php esc_html_e( 'By taxonomy', 'livingdraft-core' ); ?></div>
				<?php foreach ( $tax_counts as $tax => $n ) :
					$tax_obj = get_taxonomy( $tax );
					$label   = $tax_obj && $tax_obj->labels ? $tax_obj->labels->name : $tax;
					?>
					<div style="display:flex;justify-content:space-between;padding:4px 0;font-size:13px">
						<span><?php echo esc_html( $label ); ?></span>
						<span style="font-family:var(--tld-mono, monospace);color:<?php echo $n > 0 ? '#b7791f' : '#2f7a3a'; ?>"><?php echo esc_html( sprintf( _n( '%d needs generation', '%d need generation', $n, 'livingdraft-core' ), $n ) ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( function_exists( 'livingdraft_ai_render_model_picker' ) && $ai_ready ) : ?>
			<div style="margin-bottom:14px">
				<?php livingdraft_ai_render_model_picker( array(
					'label'       => __( 'Model used for bulk generation', 'livingdraft-core' ),
					'storage_key' => 'livingdraft.ai.model.desk_bulk',
				) ); ?>
			</div>
		<?php endif; ?>

		<button type="button" id="ld-desk-start" class="button button-primary" <?php disabled( ! $ai_ready || 0 === $pending_count ); ?>>
			<?php esc_html_e( 'Start bulk generation', 'livingdraft-core' ); ?>
		</button>
		<button type="button" id="ld-desk-stop" class="button" style="display:none;margin-left:8px">
			<?php esc_html_e( 'Stop', 'livingdraft-core' ); ?>
		</button>

		<div id="ld-desk-progress" style="display:none;margin-top:20px">
			<div style="background:#eee;height:16px;position:relative;overflow:hidden;border:1px solid #ccc">
				<div id="ld-desk-bar" style="background:linear-gradient(135deg, #8b3a2c, #6d2e23);height:100%;width:0%;transition:width 250ms ease"></div>
			</div>
			<div id="ld-desk-status" style="margin-top:8px;font-family:var(--tld-mono, monospace);font-size:12px;color:#666"></div>
			<div id="ld-desk-log" style="margin-top:12px;max-height:280px;overflow-y:auto;font-family:var(--tld-mono, monospace);font-size:11px;background:#fafaf7;border:1px solid #e5e5e5;padding:10px;line-height:1.6"></div>
		</div>
	</div>

	<script>
	(function () {
		'use strict';
		var startBtn = document.getElementById( 'ld-desk-start' );
		var stopBtn  = document.getElementById( 'ld-desk-stop' );
		var progress = document.getElementById( 'ld-desk-progress' );
		var bar      = document.getElementById( 'ld-desk-bar' );
		var status   = document.getElementById( 'ld-desk-status' );
		var logEl    = document.getElementById( 'ld-desk-log' );
		var countEl  = document.getElementById( 'ld-desk-pending-count' );

		var ctx = {
			ajaxUrl:      <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
			nonce:        <?php echo wp_json_encode( $nonce ); ?>,
			modelStorage: 'livingdraft.ai.model.desk_bulk'
		};

		var state = { stopped: false, pending: [], done: 0, ok: 0, fail: 0 };

		function log( msg, kind ) {
			var color = kind === 'ok' ? '#2f7a3a' : kind === 'fail' ? '#a32e2e' : '#666';
			logEl.innerHTML += '<div style="color:' + color + '">' + msg + '</div>';
			logEl.scrollTop = logEl.scrollHeight;
		}

		if ( ! startBtn ) return;

		startBtn.addEventListener( 'click', function () {
			if ( ! confirm( '<?php echo esc_js( __( "This will make one AI call per term. Continue?", 'livingdraft-core' ) ); ?>' ) ) return;
			startBtn.disabled = true;
			stopBtn.style.display = 'inline-block';
			progress.style.display = 'block';
			logEl.innerHTML = '';
			state = { stopped: false, pending: [], done: 0, ok: 0, fail: 0 };
			status.textContent = '<?php echo esc_js( __( 'Fetching pending terms…', 'livingdraft-core' ) ); ?>';

			var fd = new FormData();
			fd.append( 'action', 'ld_seo_desk_pending_terms' );
			fd.append( 'nonce', ctx.nonce );
			fetch( ctx.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( ! res || ! res.success ) throw new Error( 'Failed to fetch list' );
					state.pending = res.data.pending || [];
					if ( ! state.pending.length ) {
						status.textContent = '<?php echo esc_js( __( 'Nothing to do — all terms are already covered.', 'livingdraft-core' ) ); ?>';
						startBtn.disabled = false;
						stopBtn.style.display = 'none';
						return;
					}
					status.textContent = 'Processing ' + state.pending.length + ' terms…';
					processNext();
				} )
				.catch( function ( err ) {
					status.textContent = 'Failed: ' + err.message;
					startBtn.disabled = false;
					stopBtn.style.display = 'none';
				} );
		} );

		stopBtn.addEventListener( 'click', function () {
			state.stopped = true;
			status.textContent = '<?php echo esc_js( __( 'Stopping after current term…', 'livingdraft-core' ) ); ?>';
		} );

		function processNext() {
			if ( state.stopped ) {
				finish( true );
				return;
			}
			if ( state.pending.length === 0 ) {
				finish( false );
				return;
			}
			var next = state.pending.shift();
			var total = state.done + state.pending.length + 1;
			var pct   = Math.round( 100 * state.done / total );
			bar.style.width = pct + '%';
			status.textContent = 'Term ' + ( state.done + 1 ) + ' of ' + total + ' · ' + state.ok + ' ok · ' + state.fail + ' failed';

			var fd = new FormData();
			fd.append( 'action', 'ld_seo_desk_generate_term' );
			fd.append( 'nonce', ctx.nonce );
			fd.append( 'term_id', next.term_id );
			fd.append( 'taxonomy', next.taxonomy );
			try {
				var m = window.localStorage.getItem( ctx.modelStorage );
				if ( m ) fd.append( 'ai_model', m );
			} catch ( e ) {}

			fetch( ctx.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					state.done++;
					if ( res && res.success ) {
						state.ok++;
						var written = ( res.data && res.data.written ) || [];
						if ( written.length ) {
							log( '✓ ' + ( res.data.term || '#' + next.term_id ) + ' — wrote: ' + written.join( ', ' ), 'ok' );
						} else {
							log( '· ' + ( res.data.term || '#' + next.term_id ) + ' — skipped (already covered)', 'skip' );
						}
					} else {
						state.fail++;
						var msg = ( res && res.data && res.data.message ) || 'Unknown error';
						log( '✗ Term #' + next.term_id + ' — ' + msg, 'fail' );
					}
					// Small delay between calls to be polite to the AI provider.
					setTimeout( processNext, 250 );
				} )
				.catch( function ( err ) {
					state.done++;
					state.fail++;
					log( '✗ Term #' + next.term_id + ' — network error: ' + err.message, 'fail' );
					setTimeout( processNext, 500 );
				} );
		}

		function finish( wasStopped ) {
			bar.style.width = '100%';
			status.textContent = ( wasStopped ? 'Stopped. ' : 'Complete. ' ) + state.ok + ' terms written, ' + state.fail + ' failed.';
			status.style.color = state.fail > 0 ? '#a37a1f' : '#2f7a3a';
			startBtn.disabled = false;
			stopBtn.style.display = 'none';
			if ( countEl ) {
				var remaining = Math.max( 0, parseInt( countEl.textContent.replace( /,/g, '' ), 10 ) - state.ok );
				countEl.textContent = remaining.toLocaleString();
			}
		}
	})();
	</script>
	<?php
}
