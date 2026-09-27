<?php
/**
 * Post-list SEO column.
 *
 * Adds an "SEO" column to the Posts and Pages admin lists showing:
 *   - a colored dot for the score (good / warn / bad / none)
 *   - the numeric score /100
 *   - the focus keyword, if set
 *
 * The column is sortable — click the header to order the list by
 * SEO score ascending / descending, which is how an editor finds the
 * ten posts that need the most attention.
 *
 * ---------------------------------------------------------------
 * WHY WE CACHE THE SCORE
 *
 * `livingdraft_seo_analyze()` reads the post body, runs 13 checks,
 * and computes a weighted score. Doing that inline for every row in
 * a 100-row post list would run 100 analyses per page load. Instead,
 * we cache the score in postmeta under `_ld_seo_score` and update it
 * on `save_post`. The column reads the cached value.
 *
 * Legacy posts that pre-date this feature won't have a cache entry
 * yet; those show a "—" dash. When the editor opens and saves the
 * post (or hits the "Recalculate scores" tool in Settings), the
 * cache populates.
 *
 * @package LivingDraftCore
 * @since   3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post types that get the SEO column. Post + Page by default;
 * filterable so a site with custom post types can opt them in.
 */
function livingdraft_seo_column_post_types() {
	return (array) apply_filters( 'livingdraft_seo_column_post_types', array( 'post', 'page' ) );
}

/* ------------------------------------------------------------------
 * 1. REGISTER THE COLUMN
 * ------------------------------------------------------------------ */

/**
 * Add the SEO column + v3.7 GSC columns (impressions, clicks) to the
 * columns array. Placed after the title column when possible (which
 * is where the eye naturally lands after reading the post title).
 *
 * GSC columns only show up when GSC integration is configured — no
 * point wasting screen real-estate on always-empty columns.
 */
function livingdraft_seo_add_list_column( $columns ) {
	$new_cols = array( 'ld_seo' => __( 'SEO', 'livingdraft-core' ) );

	// Only add GSC columns when the integration is actually set up.
	// The check is cheap (one option read) and avoids empty columns
	// on sites that don't use GSC at all.
	if ( function_exists( 'livingdraft_gsc_configured' ) && livingdraft_gsc_configured() ) {
		$new_cols['ld_gsc_impressions'] = __( 'Impr.', 'livingdraft-core' );
		$new_cols['ld_gsc_clicks']      = __( 'Clicks', 'livingdraft-core' );
		// v4.0.0: average position. Last of the three because it is the one
		// you act on rather than scan — impressions and clicks tell you a
		// page is being shown and used, position tells you what to do next.
		$new_cols['ld_gsc_position']    = __( 'Pos.', 'livingdraft-core' );
	}

	if ( ! isset( $columns['title'] ) ) {
		return array_merge( $columns, $new_cols );
	}

	$out = array();
	foreach ( $columns as $key => $label ) {
		$out[ $key ] = $label;
		if ( 'title' === $key ) {
			foreach ( $new_cols as $k => $v ) {
				$out[ $k ] = $v;
			}
		}
	}
	return $out;
}

/**
 * Hook the column register onto every post type that opts in.
 * Runs on admin_init so custom post types registered by plugins
 * loading later have already declared themselves.
 */
function livingdraft_seo_register_list_columns() {
	if ( ! is_admin() ) {
		return;
	}
	foreach ( livingdraft_seo_column_post_types() as $pt ) {
		add_filter( "manage_{$pt}_posts_columns", 'livingdraft_seo_add_list_column' );
		add_action( "manage_{$pt}_posts_custom_column", 'livingdraft_seo_render_list_column', 10, 2 );
		add_filter( "manage_edit-{$pt}_sortable_columns", 'livingdraft_seo_make_list_column_sortable' );
	}
}
add_action( 'admin_init', 'livingdraft_seo_register_list_columns' );

/**
 * Declare our columns sortable. WP wires each to `?orderby=<slug>`,
 * which we translate to a meta_value_num sort in the pre_get_posts
 * handler below.
 */
function livingdraft_seo_make_list_column_sortable( $columns ) {
	$columns['ld_seo']             = 'ld_seo';
	$columns['ld_gsc_impressions'] = 'ld_gsc_impressions';
	$columns['ld_gsc_clicks']      = 'ld_gsc_clicks';
	$columns['ld_gsc_position']    = 'ld_gsc_position';
	return $columns;
}

/**
 * When the user clicks a sortable column header, translate the
 * orderby into a meta_value_num sort on the cached postmeta.
 *
 * Posts with no cached value sort as 0 — for DESC (most common,
 * "show me my best posts") they land at the bottom; for ASC they
 * land at the top ("show me what needs attention").
 *
 * === POSITION IS THE EXCEPTION (v4.0.0) ===
 *
 * For every other column, more is better and a missing value sorting as 0
 * is harmless. Position runs the other way: 1 is the best result on the
 * page and a large number is a bad one, so the useful sort is ASCENDING.
 *
 * That makes "no data" actively wrong. An article Google has never shown
 * has no position at all, but it is stored as 0 — which is numerically
 * better than first place. Sorted ascending, every unranked article on the
 * site would pile up above the genuine top ten and the column would be
 * useless for exactly the job it exists to do.
 *
 * So sorting by position also filters to articles that actually have one.
 * The list becomes "my ranked pages, best first", which is the question
 * being asked. The unranked ones are still there under any other sort.
 */
function livingdraft_seo_column_sort_query( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}
	$orderby = $query->get( 'orderby' );

	if ( 'ld_gsc_position' === $orderby ) {
		$query->set(
			'meta_query', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			array(
				array(
					'key'     => '_ld_gsc_position',
					'value'   => 0,
					'compare' => '>',
					'type'    => 'DECIMAL(10,2)',
				),
			)
		);
		$query->set( 'orderby', 'meta_value_num' );
		$query->set( 'meta_key', '_ld_gsc_position' ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		return;
	}

	$map = array(
		'ld_seo'             => '_ld_seo_score',
		'ld_gsc_impressions' => '_ld_gsc_impressions',
		'ld_gsc_clicks'      => '_ld_gsc_clicks',
	);
	if ( isset( $map[ $orderby ] ) ) {
		$query->set( 'meta_key', $map[ $orderby ] );
		$query->set( 'orderby', 'meta_value_num' );
	}
}
add_action( 'pre_get_posts', 'livingdraft_seo_column_sort_query' );

/* ------------------------------------------------------------------
 * 2. RENDER THE CELL
 * ------------------------------------------------------------------ */

/**
 * The clicks/impressions figure for one row.
 *
 * Reads the denormalised postmeta first, because that is what the sortable
 * column headers order by and it costs nothing. If the meta is absent it
 * falls back to the same live cache lookup the editor sidebar uses, and
 * writes the answer back so the next page load and the sort both have it.
 *
 * Why the fallback exists: before 4.0 these columns showed a dash on sites
 * where the editor sidebar showed real numbers. The two read the cache from
 * opposite directions, and the list's direction (url_to_postid) fails on
 * nested-category permalinks. The sync in gsc-hooks.php now fixes that at
 * source; this is the belt to that braces, so a site that has not re-synced
 * since upgrading still sees its numbers immediately.
 *
 * @since 4.0.0
 * @param int    $post_id Post.
 * @param string $which   'clicks', 'impressions' or 'position'.
 * @return int|float|null Null when Google genuinely has no data.
 */
function livingdraft_seo_column_gsc_value( $post_id, $which ) {
	$meta_key = '_ld_gsc_' . $which;
	$stored   = get_post_meta( $post_id, $meta_key, true );

	if ( '' !== $stored && null !== $stored ) {
		return 'position' === $which ? (float) $stored : (int) $stored;
	}

	if ( ! function_exists( 'livingdraft_gsc_analytics_for' ) ) {
		return null;
	}

	$data = livingdraft_gsc_analytics_for( get_permalink( $post_id ) );

	if ( ! is_array( $data ) ) {
		return null;
	}

	// Stamp all three at once — we already paid for the lookup.
	update_post_meta( $post_id, '_ld_gsc_clicks', (int) ( $data['clicks'] ?? 0 ) );
	update_post_meta( $post_id, '_ld_gsc_impressions', (int) ( $data['impressions'] ?? 0 ) );
	update_post_meta( $post_id, '_ld_gsc_position', (float) ( $data['position'] ?? 0 ) );

	return 'position' === $which
		? (float) ( $data['position'] ?? 0 )
		: (int) ( $data[ $which ] ?? 0 );
}

/**
 * Render one cell of the SEO column.
 *
 * The three states:
 *   - score cached and > 0  → colored dot + score + keyword
 *   - score cached and = 0  → grey dot + "0" + "no keyword" hint
 *   - no cache entry yet    → dash + "—" (legacy / never analyzed)
 */
function livingdraft_seo_render_list_column( $column, $post_id ) {
	// v3.7.0: GSC columns first — cheaper to render than the SEO score.
	if ( 'ld_gsc_impressions' === $column ) {
		$val = livingdraft_seo_column_gsc_value( $post_id, 'impressions' );
		if ( null === $val ) {
			echo '<span style="color:#ccc" title="' . esc_attr__( 'No GSC data', 'livingdraft-core' ) . '">—</span>';
			return;
		}
		printf(
			'<span style="font-family:var(--tld-mono, monospace);font-size:12px;color:#333">%s</span>',
			esc_html( number_format_i18n( (int) $val ) )
		);
		return;
	}
	if ( 'ld_gsc_clicks' === $column ) {
		$val = livingdraft_seo_column_gsc_value( $post_id, 'clicks' );
		if ( null === $val ) {
			echo '<span style="color:#ccc" title="' . esc_attr__( 'No GSC data', 'livingdraft-core' ) . '">—</span>';
			return;
		}
		$n = (int) $val;
		// Clicks get a subtle green tint when non-zero so a scanning
		// eye can quickly find the posts actually earning traffic.
		$col = $n > 0 ? '#2f7a3a' : '#999';
		printf(
			'<span style="font-family:var(--tld-mono, monospace);font-size:12px;color:%s;font-weight:%s">%s</span>',
			esc_attr( $col ),
			$n > 0 ? '600' : '400',
			esc_html( number_format_i18n( $n ) )
		);
		return;
	}

	if ( 'ld_gsc_position' === $column ) {
		$val = livingdraft_seo_column_gsc_value( $post_id, 'position' );

		// 0 is not "first place", it is "Google has never shown this".
		if ( null === $val || (float) $val <= 0 ) {
			echo '<span style="color:#ccc" title="' . esc_attr__( 'Not appearing in search results yet', 'livingdraft-core' ) . '">&mdash;</span>';
			return;
		}

		$pos = (float) $val;

		/*
		 * The bands are chosen to answer one question: what should I work on
		 * next? Position 11 to 20 is the answer almost every time. Those
		 * articles are on page two, where they earn a fraction of the clicks
		 * of page one, and they are close enough that a better title or a
		 * stronger opening can move them over. An article at 40 needs a
		 * rewrite; an article at 3 needs nothing. Page two is the band worth
		 * flagging, so it is the one that gets the loud colour.
		 */
		if ( $pos <= 3 ) {
			$colour = '#2f7a3a';
			$note   = __( 'Top three', 'livingdraft-core' );
		} elseif ( $pos <= 10 ) {
			$colour = '#4a8c56';
			$note   = __( 'Page one', 'livingdraft-core' );
		} elseif ( $pos <= 20 ) {
			$colour = '#a3271f';
			$note   = __( 'Page two — closest to a win. Worth a better title or opening.', 'livingdraft-core' );
		} else {
			$colour = '#999';
			$note   = __( 'Page three or beyond', 'livingdraft-core' );
		}

		printf(
			'<span style="font-family:var(--tld-mono, monospace);font-size:12px;color:%s;font-weight:%s;font-variant-numeric:tabular-nums" title="%s">%s</span>',
			esc_attr( $colour ),
			$pos <= 20 ? '600' : '400',
			esc_attr( $note ),
			esc_html( number_format_i18n( $pos, 1 ) )
		);

		// The one band an editor should act on says so in words, not just
		// in colour — colour alone fails for anyone who cannot see it.
		if ( $pos > 10 && $pos <= 20 ) {
			printf(
				'<div style="font-size:11px;color:#a3271f;margin-top:2px;font-style:italic">%s</div>',
				esc_html__( 'page two', 'livingdraft-core' )
			);
		}

		return;
	}

	if ( 'ld_seo' !== $column ) {
		return;
	}

	$score_raw = get_post_meta( $post_id, '_ld_seo_score', true );
	$keyword   = livingdraft_seo_get( 'focus_keyword', $post_id );

	// Legacy post — cache never populated.
	if ( '' === $score_raw || null === $score_raw ) {
		echo '<span style="color:#999;font-family:var(--tld-mono, monospace);font-size:12px" title="' . esc_attr__( 'Open the post to compute its SEO score.', 'livingdraft-core' ) . '">—</span>';
		return;
	}

	$score = (int) $score_raw;

	// Same grade thresholds the metabox uses. Kept inline here so this
	// module has no runtime dependency on content-analysis.php beyond
	// the (already-run) score.
	if ( $score >= 80 ) {
		$grade_color = '#2f7a3a'; // good
		$grade_label = __( 'Good', 'livingdraft-core' );
	} elseif ( $score >= 50 ) {
		$grade_color = '#b7791f'; // warn
		$grade_label = __( 'Needs work', 'livingdraft-core' );
	} else {
		$grade_color = '#a32e2e'; // bad
		$grade_label = __( 'Poor', 'livingdraft-core' );
	}

	printf(
		'<div style="display:flex;align-items:center;gap:6px;line-height:1.3">'
			. '<span style="display:inline-block;width:10px;height:10px;border-radius:50%%;background:%1$s;box-shadow:0 0 0 3px %1$s22" title="%2$s"></span>'
			. '<span style="font-family:var(--tld-mono, monospace);font-size:12px;color:%1$s;font-weight:600">%3$d</span>'
			. '</div>',
		esc_attr( $grade_color ),
		esc_attr( $grade_label ),
		$score
	);

	if ( '' !== $keyword ) {
		printf(
			'<div style="font-size:11px;color:var(--tld-ink-3, #666);margin-top:2px;font-style:italic;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="%s">%s</div>',
			esc_attr__( 'Focus keyword', 'livingdraft-core' ),
			esc_html( $keyword )
		);
	} else {
		printf(
			'<div style="font-size:11px;color:#c99;margin-top:2px;font-style:italic">%s</div>',
			esc_html__( 'no focus keyword', 'livingdraft-core' )
		);
	}
}

/* ------------------------------------------------------------------
 * 3. CACHE THE SCORE ON SAVE
 * ------------------------------------------------------------------ */

/**
 * Recompute and cache the SEO score whenever a post is saved.
 *
 * We deliberately DON'T short-circuit on autosave / revisions here:
 * `livingdraft_seo_analyze()` is fast (~5ms) and the score should
 * update as the writer edits, including via Gutenberg autosaves so
 * the metabox score circle stays in sync. Revisions themselves are
 * still skipped because they're not the parent post.
 */
function livingdraft_seo_cache_score_on_save( $post_id, $post = null ) {
	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( ! $post ) {
		$post = get_post( $post_id );
	}
	if ( ! $post ) {
		return;
	}
	if ( ! in_array( $post->post_type, livingdraft_seo_column_post_types(), true ) ) {
		return;
	}

	// The analyzer function is defined in content-analysis.php.
	if ( ! function_exists( 'livingdraft_seo_analyze' ) ) {
		return;
	}
	$result = livingdraft_seo_analyze( $post_id );
	$score  = isset( $result['score'] ) ? (int) $result['score'] : 0;

	update_post_meta( $post_id, '_ld_seo_score', $score );
}
add_action( 'save_post', 'livingdraft_seo_cache_score_on_save', 20, 2 );

/**
 * REST hook: same idea, but for Gutenberg saves that go through the
 * REST API. WP fires `rest_after_insert_{post_type}` after every
 * REST-driven save; hooking that alongside save_post keeps the
 * cache warm no matter which UI the writer uses.
 */
function livingdraft_seo_cache_score_rest( $post ) {
	if ( $post && isset( $post->ID ) ) {
		livingdraft_seo_cache_score_on_save( (int) $post->ID, $post );
	}
}
foreach ( array( 'post', 'page' ) as $pt ) {
	add_action( "rest_after_insert_{$pt}", 'livingdraft_seo_cache_score_rest' );
}

/**
 * Bulk recalc utility — call to warm the cache for all posts of
 * the enabled post types. Exposed via a Settings page button.
 * Returns the number of posts scored.
 */
function livingdraft_seo_recalc_all_scores( $limit = 500 ) {
	if ( ! function_exists( 'livingdraft_seo_analyze' ) ) {
		return 0;
	}
	$ids = get_posts( array(
		'post_type'              => livingdraft_seo_column_post_types(),
		'post_status'            => array( 'publish', 'future', 'draft', 'pending', 'private' ),
		'posts_per_page'         => (int) $limit,
		'fields'                 => 'ids',
		'orderby'                => 'ID',
		'order'                  => 'ASC',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	) );
	$n = 0;
	foreach ( $ids as $id ) {
		$r = livingdraft_seo_analyze( $id );
		update_post_meta( $id, '_ld_seo_score', isset( $r['score'] ) ? (int) $r['score'] : 0 );
		$n++;
	}
	return $n;
}
