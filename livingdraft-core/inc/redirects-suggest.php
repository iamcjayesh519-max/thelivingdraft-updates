<?php
/**
 * Redirect target suggestions for logged 404s.
 *
 * === WHY THIS EXISTS ===
 *
 * The 404 log tells you WHICH URLs are broken. It does not tell you where
 * those readers were probably trying to go. That guess — the target of the
 * redirect — is the actual editorial decision, and doing it by hand for
 * hundreds of 404s after a migration is the reason most redirect tools
 * stay unused.
 *
 * This module proposes a shortlist of likely targets for each broken URL,
 * so the editor's job shrinks to "pick one and click accept".
 *
 * === HOW MATCHING WORKS ===
 *
 * Four strategies run in parallel; results are merged, deduplicated by
 * post, scored 0–100 and sorted:
 *
 *   1. OLD SLUG (score 100). WordPress keeps a "_wp_old_slug" post-meta
 *      entry whenever a post's slug changes. If the broken URL's slug
 *      matches one of those, we know exactly which post moved.
 *
 *   2. CURRENT SLUG (score 90). The last URL segment matches a published
 *      post's current slug. Handles the case where a category prefix
 *      changed but the slug did not.
 *
 *   3. FUZZY SLUG (score 55–90). similar_text() percentage against
 *      published slugs whose first few characters match — catches typos,
 *      transliteration drift, "&" vs "and", trailing "-2".
 *
 *   4. FUZZY TITLE (score 40–80). The slug turned back into words,
 *      matched against post titles — catches cases where the URL was
 *      guessed from the headline (bookmarks copied from screenshots,
 *      hand-typed URLs).
 *
 * === DECISIONS THE EDITOR STILL MAKES ===
 *
 * Nothing is auto-redirected. The shortlist is a suggestion; the human
 * approves. That is deliberate — a wrong auto-redirect looks like an
 * editorial mistake to a reader, and it also loses the option of
 * choosing a better destination (a category, a tag archive, the home
 * page for a truly retired story).
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------
 * 1. THE MATCHER
 * ------------------------------------------------------------------ */

/**
 * Post types considered as redirect targets. Filterable so custom types
 * (a Reviews CPT, a Recipes CPT) can be added without editing this file.
 *
 * @return string[]
 */
function livingdraft_suggest_post_types() {
	return (array) apply_filters(
		'livingdraft_suggest_post_types',
		array( 'post', 'page' )
	);
}

/**
 * Minimum score a candidate must reach to be shown at all. Below this,
 * the noise-to-signal ratio starts hurting more than it helps — you spend
 * longer dismissing bad matches than you save on the good ones.
 */
function livingdraft_suggest_min_score() {
	return (int) apply_filters( 'livingdraft_suggest_min_score', 40 );
}

/**
 * Suggest redirect targets for a broken URL path.
 *
 * Cached per path via a transient. The cache key includes a version
 * number stored in an option; bumping that option (done automatically on
 * post save/update) invalidates every cached suggestion at once, without
 * having to enumerate them.
 *
 * @param string $url_path e.g. '/old-story-headline/' or '/category/thing/'
 * @param int    $limit    How many candidates to return.
 * @return array[] List of { post_id, title, url, score, reason }.
 */
function livingdraft_suggest_targets_for_path( $url_path, $limit = 5 ) {
	$url_path = '/' . ltrim( (string) $url_path, '/' );

	$version = (int) get_option( 'livingdraft_suggest_cache_version', 1 );
	$cache_key = 'ld_sug_' . $version . '_' . md5( $url_path );

	$cached = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return array_slice( $cached, 0, $limit );
	}

	global $wpdb;

	$types   = livingdraft_suggest_post_types();
	$types_in = "'" . implode( "','", array_map( 'esc_sql', $types ) ) . "'";

	// Extract the last non-empty URL segment — that is nearly always the
	// slug part, whether the permalink structure is /%postname%/ or
	// /category/%postname%/ or /%year%/%monthnum%/%postname%/.
	$segments = array_values( array_filter( explode( '/', trim( $url_path, '/' ) ) ) );
	$slug     = end( $segments );
	$slug     = is_string( $slug ) ? sanitize_title( urldecode( $slug ) ) : '';

	/**
	 * Candidates: post_id => { score, reason, post }. Duplicates from
	 * different strategies collapse to the highest-scoring one.
	 */
	$candidates = array();

	// -------- Strategy 1: _wp_old_slug (post history) -----------------
	if ( '' !== $slug ) {
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = '_wp_old_slug' AND meta_value = %s
				 LIMIT 10",
				$slug
			)
		);

		foreach ( $ids as $pid ) {
			$post = get_post( (int) $pid );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}
			if ( ! in_array( $post->post_type, $types, true ) ) {
				continue;
			}
			$candidates[ (int) $pid ] = array(
				'score'  => 100,
				'reason' => __( 'Was this post\'s previous slug', 'livingdraft-core' ),
				'post'   => $post,
			);
		}
	}

	// -------- Strategy 2: current slug exact match --------------------
	if ( '' !== $slug ) {
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_name = %s AND post_status = 'publish'
				 AND post_type IN ($types_in)
				 LIMIT 1",
				$slug
			)
		);
		if ( $row ) {
			$pid = (int) $row->ID;
			if ( ! isset( $candidates[ $pid ] ) ) {
				$post = get_post( $pid );
				if ( $post ) {
					$candidates[ $pid ] = array(
						'score'  => 90,
						'reason' => __( 'Same slug on a published post', 'livingdraft-core' ),
						'post'   => $post,
					);
				}
			}
		}
	}

	// -------- Strategy 3: fuzzy slug via similar_text -----------------
	//
	// LIKE '<prefix>%' first, to keep the candidate set small. Then rank
	// in PHP with similar_text(), which handles substitutions/typos far
	// better than SQL LIKE alone.
	if ( strlen( $slug ) >= 4 ) {
		$prefix_len = max( 2, min( 4, (int) floor( strlen( $slug ) / 3 ) ) );
		$prefix     = substr( $slug, 0, $prefix_len );
		$like       = $wpdb->esc_like( $prefix ) . '%';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_name FROM {$wpdb->posts}
				 WHERE post_status = 'publish' AND post_type IN ($types_in)
				 AND post_name LIKE %s
				 LIMIT 60",
				$like
			)
		);

		foreach ( $rows as $row ) {
			$pid = (int) $row->ID;
			if ( isset( $candidates[ $pid ] ) ) {
				continue;
			}

			similar_text( $slug, $row->post_name, $percent );
			$percent = (int) round( $percent );
			if ( $percent < 55 ) {
				continue;
			}

			$post = get_post( $pid );
			if ( ! $post ) {
				continue;
			}

			$candidates[ $pid ] = array(
				'score'  => $percent,
				'reason' => sprintf(
					/* translators: %d: similarity percentage. */
					__( '%d%% similar slug', 'livingdraft-core' ),
					$percent
				),
				'post'   => $post,
			);
		}
	}

	// -------- Strategy 4: fuzzy title match ---------------------------
	//
	// Turn the slug back into readable words and search post_title for
	// the longest word. Slugs are slightly more trusted than titles, so
	// the score for a title match is discounted by 10%.
	if ( '' !== $slug ) {
		$words = array_values(
			array_filter(
				explode( ' ', str_replace( array( '-', '_' ), ' ', $slug ) ),
				function ( $w ) {
					return strlen( $w ) >= 4;
				}
			)
		);

		if ( ! empty( $words ) ) {
			// Use the longest word — most distinctive, best filter.
			usort( $words, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
			$needle = $words[0];
			$like   = '%' . $wpdb->esc_like( $needle ) . '%';

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title FROM {$wpdb->posts}
					 WHERE post_status = 'publish' AND post_type IN ($types_in)
					 AND post_title LIKE %s
					 LIMIT 40",
					$like
				)
			);

			$readable = str_replace( array( '-', '_' ), ' ', $slug );

			foreach ( $rows as $row ) {
				$pid = (int) $row->ID;
				if ( isset( $candidates[ $pid ] ) ) {
					continue;
				}

				similar_text(
					strtolower( $readable ),
					strtolower( wp_strip_all_tags( $row->post_title ) ),
					$percent
				);
				$percent = (int) round( $percent );
				if ( $percent < 50 ) {
					continue;
				}

				$score = (int) round( $percent * 0.9 );
				$post  = get_post( $pid );
				if ( ! $post ) {
					continue;
				}

				$candidates[ $pid ] = array(
					'score'  => $score,
					'reason' => sprintf(
						/* translators: %d: similarity percentage. */
						__( '%d%% similar title', 'livingdraft-core' ),
						$percent
					),
					'post'   => $post,
				);
			}
		}
	}

	// -------- Rank and format -----------------------------------------
	uasort(
		$candidates,
		function ( $a, $b ) {
			return $b['score'] - $a['score'];
		}
	);

	$min = livingdraft_suggest_min_score();
	$out = array();

	foreach ( $candidates as $pid => $c ) {
		if ( $c['score'] < $min ) {
			continue;
		}
		$out[] = array(
			'post_id' => $pid,
			'title'   => get_the_title( $c['post'] ),
			'url'     => get_permalink( $c['post'] ),
			'score'   => $c['score'],
			'reason'  => $c['reason'],
		);
	}

	/**
	 * Give integrators a chance to add or reweight candidates.
	 *
	 * @param array  $out       Ranked candidate list.
	 * @param string $url_path  The broken URL path being matched.
	 */
	$out = (array) apply_filters( 'livingdraft_suggest_targets', $out, $url_path );

	set_transient( $cache_key, $out, 12 * HOUR_IN_SECONDS );

	return array_slice( $out, 0, $limit );
}

/**
 * Invalidate every cached suggestion when the corpus changes.
 *
 * Bumping the version number that is embedded in cache keys is cheaper
 * and more reliable than deleting hundreds of individual transients —
 * we do not have to enumerate them, and the old ones expire naturally.
 */
function livingdraft_suggest_bump_cache_version() {
	$version = (int) get_option( 'livingdraft_suggest_cache_version', 1 );
	update_option( 'livingdraft_suggest_cache_version', $version + 1, false );
}
add_action( 'save_post', 'livingdraft_suggest_bump_cache_version' );
add_action( 'deleted_post', 'livingdraft_suggest_bump_cache_version' );

/**
 * Count of unresolved 404s that have at least one strong (score >= 60)
 * suggestion. Used for the sub-tab badge.
 *
 * Capped at the first 200 unresolved rows so it stays fast on sites with
 * a large 404 log — the badge is informational, not exact.
 */
function livingdraft_suggest_actionable_count() {
	$cache = get_transient( 'ld_sug_actionable' );
	if ( false !== $cache ) {
		return (int) $cache;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'livingdraft_404s';
	$rows  = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT url_path FROM {$table} WHERE resolved = %d ORDER BY hits DESC LIMIT 200",
			0
		)
	);

	$count = 0;
	foreach ( $rows as $path ) {
		$hits = livingdraft_suggest_targets_for_path( $path, 1 );
		if ( ! empty( $hits ) && (int) $hits[0]['score'] >= 60 ) {
			$count++;
		}
	}

	set_transient( 'ld_sug_actionable', $count, HOUR_IN_SECONDS );
	return $count;
}

/* ------------------------------------------------------------------
 * 2. ACTION HANDLERS — accept / dismiss
 * ------------------------------------------------------------------ */

/**
 * Accepting a suggestion does two things at once: create the redirect,
 * then mark the 404 resolved. If either fails the other is not attempted,
 * so the log stays consistent.
 */
function livingdraft_suggest_handle_actions() {
	if ( ! isset( $_GET['page'] ) || 'livingdraft-redirects' !== $_GET['page'] ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Accept a specific suggestion for a specific 404 row.
	if ( isset( $_GET['ld_accept_suggestion'] ) ) {
		$log_id = (int) $_GET['ld_accept_suggestion'];
		check_admin_referer( 'livingdraft_accept_suggestion_' . $log_id );

		$target = isset( $_GET['target'] ) ? esc_url_raw( wp_unslash( $_GET['target'] ) ) : '';
		$type   = isset( $_GET['type'] ) ? (int) $_GET['type'] : 301;

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT url_path FROM {$wpdb->prefix}livingdraft_404s WHERE id = %d LIMIT 1",
				$log_id
			)
		);

		if ( ! $row || '' === $target ) {
			wp_safe_redirect(
				add_query_arg(
					array( 'page' => 'livingdraft-redirects', 'tab' => 'suggest', 'error' => 'accept' ),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$rid = livingdraft_redirects_add(
			$row->url_path,
			$target,
			$type,
			__( 'Accepted from 404 suggestion', 'livingdraft-core' ),
			false
		);

		if ( $rid ) {
			livingdraft_404_mark_resolved( $log_id );
		}

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => 'livingdraft-redirects', 'tab' => 'suggest', 'accepted' => (int) (bool) $rid ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// Dismiss = mark the 404 resolved without creating a redirect.
	// Distinct from the "Ignore" link on the 404 log tab only in that it
	// returns you to the suggest tab, keeping the review flow intact.
	if ( isset( $_GET['ld_dismiss_suggestion'] ) ) {
		$log_id = (int) $_GET['ld_dismiss_suggestion'];
		check_admin_referer( 'livingdraft_dismiss_suggestion_' . $log_id );

		livingdraft_404_mark_resolved( $log_id );

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => 'livingdraft-redirects', 'tab' => 'suggest', 'dismissed' => 1 ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
add_action( 'admin_init', 'livingdraft_suggest_handle_actions' );

/* ------------------------------------------------------------------
 * 3. THE SUGGESTIONS TAB
 * ------------------------------------------------------------------ */

/**
 * Render the Suggestions tab. Two modes:
 *
 *   - Focused (?url_path=X): show suggestions for one specific 404.
 *     Reached from the "Suggest" link on a row in the 404 log.
 *
 *   - Overview (default): the paginated list of unresolved 404s, each
 *     with its top three candidates as one-click Accept buttons.
 */
function livingdraft_suggest_render_tab() {
	if ( isset( $_GET['accepted'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' .
			esc_html__( 'Redirect created and 404 resolved.', 'livingdraft-core' ) .
			'</p></div>';
	}
	if ( isset( $_GET['dismissed'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' .
			esc_html__( '404 dismissed.', 'livingdraft-core' ) .
			'</p></div>';
	}
	if ( isset( $_GET['error'] ) && 'accept' === $_GET['error'] ) {
		echo '<div class="notice notice-error is-dismissible"><p>' .
			esc_html__( 'Could not save that redirect. The target URL may be invalid or point back to itself.', 'livingdraft-core' ) .
			'</p></div>';
	}

	$focused_path = isset( $_GET['url_path'] ) ? sanitize_text_field( wp_unslash( $_GET['url_path'] ) ) : '';

	if ( '' !== $focused_path ) {
		livingdraft_suggest_render_focused( $focused_path );
		return;
	}

	livingdraft_suggest_render_overview();
}

/**
 * The overview list: one row per unresolved 404, top 3 candidates each.
 *
 * Limited to 15 rows per page because each row triggers a matcher run
 * (four SQL queries + similar_text over up to 100 candidates). Cached,
 * so pages viewed twice cost almost nothing.
 */
function livingdraft_suggest_render_overview() {
	$per_page = 15;
	$page     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

	$logs  = livingdraft_404s_list( array( 'page' => $page, 'per_page' => $per_page ) );
	$total = livingdraft_404s_count( 0 );

	?>
	<p style="max-width:640px;color:var(--tld-ink-3, #666);margin-top:0">
		<?php esc_html_e( 'For each broken URL, the plugin proposes likely redirect targets. Pick one and it creates the 301 and marks the 404 resolved in one step.', 'livingdraft-core' ); ?>
	</p>

	<?php if ( empty( $logs ) ) : ?>
		<div class="tld-card">
			<p style="text-align:center;padding:24px;color:#666;margin:0">
				<?php esc_html_e( 'No unresolved 404s to suggest for. Nothing is currently broken.', 'livingdraft-core' ); ?>
			</p>
		</div>
		<?php return; ?>
	<?php endif; ?>

	<?php foreach ( $logs as $log ) : ?>
		<?php
		$suggestions = livingdraft_suggest_targets_for_path( $log->url_path, 3 );
		?>
		<div class="tld-card" style="margin-bottom:12px">
			<div style="display:flex;align-items:baseline;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:12px">
				<div style="flex:1;min-width:280px">
					<code style="word-break:break-all;font-size:13px"><?php echo esc_html( $log->url_path ); ?></code>
					<div style="font-size:12px;color:#666;margin-top:4px">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: hit count, 2: relative time. */
								_n( '%1$s hit · last seen %2$s ago', '%1$s hits · last seen %2$s ago', (int) $log->hits, 'livingdraft-core' ),
								number_format_i18n( (int) $log->hits ),
								human_time_diff( strtotime( $log->last_seen ), current_time( 'timestamp' ) )
							)
						);
						?>
						<?php if ( $log->referrer ) : ?>
							· <?php echo esc_html__( 'from', 'livingdraft-core' ) . ' ' . esc_html( wp_parse_url( $log->referrer, PHP_URL_HOST ) ?: $log->referrer ); ?>
						<?php endif; ?>
					</div>
				</div>
				<div>
					<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'livingdraft-redirects', 'tab' => 'suggest', 'ld_dismiss_suggestion' => (int) $log->id ), admin_url( 'admin.php' ) ), 'livingdraft_dismiss_suggestion_' . $log->id ) ); ?>"
					   class="tld-btn is-ghost is-small"
					   style="padding:4px 10px;font-size:10px"
					   onclick="return confirm('<?php echo esc_js( __( 'Dismiss this 404 without creating a redirect?', 'livingdraft-core' ) ); ?>');">
						<?php esc_html_e( 'Dismiss', 'livingdraft-core' ); ?>
					</a>
				</div>
			</div>

			<?php if ( empty( $suggestions ) ) : ?>
				<p style="color:#888;font-size:13px;margin:0">
					<?php esc_html_e( 'No confident matches found. You can add a redirect manually.', 'livingdraft-core' ); ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'livingdraft-redirects', 'prefill_source' => rawurlencode( $log->url_path ) ), admin_url( 'admin.php' ) ) ); ?>#add-new">
						<?php esc_html_e( 'Add manually →', 'livingdraft-core' ); ?>
					</a>
				</p>
			<?php else : ?>
				<div style="display:flex;flex-direction:column;gap:6px">
					<?php foreach ( $suggestions as $s ) : ?>
						<?php
						$accept_url = wp_nonce_url(
							add_query_arg(
								array(
									'page'                    => 'livingdraft-redirects',
									'tab'                     => 'suggest',
									'ld_accept_suggestion'    => (int) $log->id,
									'target'                  => rawurlencode( $s['url'] ),
									'type'                    => 301,
								),
								admin_url( 'admin.php' )
							),
							'livingdraft_accept_suggestion_' . $log->id
						);
						$score = (int) $s['score'];
						$badge_bg = $score >= 85 ? '#eaf5ec' : ( $score >= 65 ? '#fdf4e3' : '#f0f0f0' );
						$badge_fg = $score >= 85 ? '#2f7a3a' : ( $score >= 65 ? '#b7791f' : '#666' );
						?>
						<div style="display:flex;align-items:center;gap:12px;padding:8px 10px;border:1px solid #e5e5e5;background:#fafafa">
							<span style="display:inline-block;min-width:44px;text-align:center;padding:2px 6px;font-family:var(--tld-mono, monospace);font-size:10px;letter-spacing:.08em;background:<?php echo esc_attr( $badge_bg ); ?>;color:<?php echo esc_attr( $badge_fg ); ?>">
								<?php echo esc_html( $score ); ?>
							</span>
							<div style="flex:1;min-width:0">
								<div style="font-size:14px;color:#111;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
									<?php echo esc_html( $s['title'] ); ?>
								</div>
								<div style="font-size:11px;color:#888;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
									<?php echo esc_html( wp_parse_url( $s['url'], PHP_URL_PATH ) ); ?>
									<span style="margin-left:8px;font-style:italic"><?php echo esc_html( $s['reason'] ); ?></span>
								</div>
							</div>
							<a href="<?php echo esc_url( $accept_url ); ?>" class="tld-btn is-primary is-small" style="padding:4px 12px;font-size:10px">
								<?php esc_html_e( 'Redirect here', 'livingdraft-core' ); ?>
							</a>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>

	<?php
	// Pagination.
	$pages = (int) ceil( $total / $per_page );
	if ( $pages > 1 ) {
		echo '<div style="margin-top:16px;text-align:center">';
		echo paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'current'   => $page,
				'total'     => $pages,
				'prev_text' => '‹',
				'next_text' => '›',
			)
		);
		echo '</div>';
	}
}

/**
 * The focused view: one broken URL, its full shortlist, plus a shortcut
 * to add a manual redirect if none of the candidates look right.
 *
 * @param string $url_path Broken URL path.
 */
function livingdraft_suggest_render_focused( $url_path ) {
	global $wpdb;
	$log = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}livingdraft_404s WHERE url_path = %s AND resolved = 0 LIMIT 1",
			$url_path
		)
	);

	if ( ! $log ) {
		?>
		<div class="tld-card">
			<p style="margin:0"><?php esc_html_e( 'That URL is not in the unresolved 404 log — it may already have been redirected or dismissed.', 'livingdraft-core' ); ?></p>
			<p style="margin:12px 0 0">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=livingdraft-redirects&tab=suggest' ) ); ?>" class="tld-btn">
					<?php esc_html_e( '← Back to suggestions', 'livingdraft-core' ); ?>
				</a>
			</p>
		</div>
		<?php
		return;
	}

	$suggestions = livingdraft_suggest_targets_for_path( $log->url_path, 5 );
	?>
	<p style="margin-top:0">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=livingdraft-redirects&tab=suggest' ) ); ?>"
		   style="color:var(--tld-ink-3, #666);text-decoration:none;font-size:13px">
			← <?php esc_html_e( 'All suggestions', 'livingdraft-core' ); ?>
		</a>
	</p>

	<div class="tld-card">
		<div class="tld-card-header">
			<div>
				<span class="tld-card-eyebrow"><?php esc_html_e( 'Broken URL', 'livingdraft-core' ); ?></span>
				<h2 class="tld-card-title" style="font-family:var(--tld-mono, monospace);font-size:16px;word-break:break-all">
					<?php echo esc_html( $log->url_path ); ?>
				</h2>
			</div>
		</div>

		<p style="color:#666;font-size:13px">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: hit count, 2: relative time since last hit, 3: first-seen date. */
					__( '%1$s hits · last seen %2$s ago · first seen %3$s', 'livingdraft-core' ),
					number_format_i18n( (int) $log->hits ),
					human_time_diff( strtotime( $log->last_seen ), current_time( 'timestamp' ) ),
					mysql2date( get_option( 'date_format' ), $log->first_seen )
				)
			);
			?>
		</p>

		<?php if ( empty( $suggestions ) ) : ?>
			<p style="padding:16px;background:#fafafa;border:1px solid #eee;color:#666">
				<?php esc_html_e( 'No confident matches for this URL. Try adding a redirect manually with a target you choose.', 'livingdraft-core' ); ?>
			</p>
		<?php else : ?>
			<p style="font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:#666;margin:16px 0 8px">
				<?php esc_html_e( 'Suggested targets', 'livingdraft-core' ); ?>
			</p>
			<div style="display:flex;flex-direction:column;gap:8px">
				<?php foreach ( $suggestions as $s ) : ?>
					<?php
					$accept_url = wp_nonce_url(
						add_query_arg(
							array(
								'page'                 => 'livingdraft-redirects',
								'tab'                  => 'suggest',
								'ld_accept_suggestion' => (int) $log->id,
								'target'               => rawurlencode( $s['url'] ),
								'type'                 => 301,
							),
							admin_url( 'admin.php' )
						),
						'livingdraft_accept_suggestion_' . $log->id
					);
					$score    = (int) $s['score'];
					$badge_bg = $score >= 85 ? '#eaf5ec' : ( $score >= 65 ? '#fdf4e3' : '#f0f0f0' );
					$badge_fg = $score >= 85 ? '#2f7a3a' : ( $score >= 65 ? '#b7791f' : '#666' );
					?>
					<div style="display:flex;align-items:center;gap:12px;padding:12px;border:1px solid #e5e5e5;background:#fafafa">
						<span style="display:inline-block;min-width:44px;text-align:center;padding:3px 6px;font-family:var(--tld-mono, monospace);font-size:11px;letter-spacing:.08em;background:<?php echo esc_attr( $badge_bg ); ?>;color:<?php echo esc_attr( $badge_fg ); ?>">
							<?php echo esc_html( $score ); ?>
						</span>
						<div style="flex:1;min-width:0">
							<div style="font-size:15px;color:#111"><?php echo esc_html( $s['title'] ); ?></div>
							<div style="font-size:12px;color:#888;margin-top:2px">
								<?php echo esc_html( wp_parse_url( $s['url'], PHP_URL_PATH ) ); ?>
								<span style="margin-left:10px;font-style:italic"><?php echo esc_html( $s['reason'] ); ?></span>
							</div>
						</div>
						<a href="<?php echo esc_url( $accept_url ); ?>" class="tld-btn is-primary">
							<?php esc_html_e( 'Create 301 →', 'livingdraft-core' ); ?>
						</a>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<hr style="margin:24px 0 16px;border:0;border-top:1px solid #eee">

		<p style="margin:0;font-size:13px;color:#666">
			<?php esc_html_e( 'None of these look right?', 'livingdraft-core' ); ?>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'livingdraft-redirects', 'prefill_source' => rawurlencode( $log->url_path ) ), admin_url( 'admin.php' ) ) ); ?>#add-new">
				<?php esc_html_e( 'Add a redirect manually', 'livingdraft-core' ); ?>
			</a>
			·
			<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'livingdraft-redirects', 'tab' => 'suggest', 'ld_dismiss_suggestion' => (int) $log->id ), admin_url( 'admin.php' ) ), 'livingdraft_dismiss_suggestion_' . $log->id ) ); ?>"
			   onclick="return confirm('<?php echo esc_js( __( 'Dismiss this 404?', 'livingdraft-core' ) ); ?>');">
				<?php esc_html_e( 'Dismiss this URL', 'livingdraft-core' ); ?>
			</a>
		</p>
	</div>
	<?php
}
