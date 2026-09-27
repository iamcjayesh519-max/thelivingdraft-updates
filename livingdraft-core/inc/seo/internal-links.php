<?php
/**
 * Internal link suggestions via embeddings.
 *
 * When a writer is editing a post, we show the top N semantically
 * related posts on the site so they can link to them without hunting
 * through their own archive. Powered by whatever embedding model the
 * user configured (OpenAI or Gemini — OpenRouter doesn't broker
 * embeddings).
 *
 * === HOW IT WORKS ===
 *
 * On save_post for a published post, we compute an embedding of the
 * post's title + meta description + a truncated body, and store it as
 * post meta. The embedding is tagged with the provider + model that
 * produced it so we never compare vectors across different embedding
 * spaces (an OpenAI vector and a Gemini vector are unrelated data).
 *
 * When the SEO metabox renders, we compute similarity between the
 * current post's vector and every other post's vector using cosine
 * similarity in pure PHP. For sites under about 5k posts this is
 * fine on-request; larger sites should use a vector DB, which this
 * module deliberately doesn't ship — WordPress hosting environments
 * vary too much.
 *
 * === RE-INDEXING ===
 *
 * A dedicated admin page under SEO → Links shows how many posts have
 * a current embedding, which model they use, and a button to reindex
 * the whole library in batches of 20 via AJAX. Useful when switching
 * embedding models or after a big import.
 *
 * === COSTS ===
 *
 * OpenAI text-embedding-3-small is $0.02 per million tokens. A typical
 * 2000-word post is about 2600 tokens = $0.00005. Indexing a 1000-post
 * site costs about $0.05. Gemini's embedding endpoint has a generous
 * free tier that covers most sites at no cost at all.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------
 * 1. TOGGLE + META KEY
 * ------------------------------------------------------------------ */

const LD_LINKS_META_KEY = '_ld_embedding';

function livingdraft_links_enabled() {
	return (bool) apply_filters( 'livingdraft_links_enabled', (bool) get_option( 'livingdraft_links_enabled', false ) );
}

/**
 * Which post types get indexed and offered as link targets. Filterable
 * for sites that want to include CPTs (custom post types).
 */
function livingdraft_links_post_types() {
	return (array) apply_filters( 'livingdraft_links_post_types', array( 'post' ) );
}

/* ------------------------------------------------------------------
 * 2. AUTO-INDEXING ON SAVE
 *
 * Uses wp_after_insert_post so meta is fully written before we snapshot
 * the text for embedding. Skips revisions, autosaves, and posts we
 * wouldn't include in the sitemap anyway (noindexed, password, etc.)
 * so the index only holds public discoverable content.
 * ------------------------------------------------------------------ */

function livingdraft_links_maybe_index( $post_id, $post, $update, $post_before ) {
	if ( ! livingdraft_links_enabled() ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( ! in_array( $post->post_type, livingdraft_links_post_types(), true ) ) {
		return;
	}
	if ( 'publish' !== $post->post_status ) {
		delete_post_meta( $post_id, LD_LINKS_META_KEY );
		return;
	}
	if ( function_exists( 'livingdraft_sitemap_should_include_post' ) && ! livingdraft_sitemap_should_include_post( $post ) ) {
		delete_post_meta( $post_id, LD_LINKS_META_KEY );
		return;
	}

	// Skip if the content hash hasn't changed since the last embedding —
	// avoids burning API calls on metadata-only saves.
	$text = livingdraft_links_text_for( $post );
	$hash = md5( $text );

	$existing = get_post_meta( $post_id, LD_LINKS_META_KEY, true );
	if ( is_array( $existing ) && isset( $existing['text_hash'] ) && $existing['text_hash'] === $hash ) {
		return;
	}

	livingdraft_links_index_post( $post_id, $text, $hash );
}
add_action( 'wp_after_insert_post', 'livingdraft_links_maybe_index', 30, 4 );

/**
 * Compute + store the embedding for a single post. Returns
 * WP_Error on failure so batch runners can distinguish transient
 * failures from success.
 */
function livingdraft_links_index_post( $post_id, $text = null, $hash = null ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'ld_links_no_post', 'Post not found.' );
	}
	if ( null === $text ) {
		$text = livingdraft_links_text_for( $post );
		$hash = md5( $text );
	}

	$result = livingdraft_ai_embed( $text );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	update_post_meta(
		$post_id,
		LD_LINKS_META_KEY,
		array(
			'provider'  => $result['provider'],
			'model'     => $result['model'],
			'vector'    => $result['vector'],
			'text_hash' => $hash,
			'created'   => time(),
		)
	);

	return true;
}

/**
 * The text we feed the embedding model. Title carries a lot of
 * signal so it goes first and gets weight through repetition. Meta
 * description if set. Then the plain-text body, truncated so we
 * stay under the model's token limit.
 */
function livingdraft_links_text_for( $post ) {
	$parts = array();

	$title = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
	$parts[] = $title;

	if ( function_exists( 'livingdraft_seo_get' ) ) {
		$desc = livingdraft_seo_get( 'description', $post->ID );
		if ( '' !== $desc ) {
			$parts[] = $desc;
		}
	}

	$body = trim( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) );
	if ( '' !== $body ) {
		// Roughly the first ~1500 words is plenty for topical signal.
		$parts[] = wp_trim_words( $body, 1500, '' );
	}

	return implode( "\n\n", $parts );
}

/* ------------------------------------------------------------------
 * 3. QUERY: find the top N related posts for a given post
 *
 * Loads all candidate embeddings, filters to ones from the same
 * provider+model as the source (comparing across embedding spaces
 * would return random rubbish), sorts by cosine similarity.
 * ------------------------------------------------------------------ */

function livingdraft_links_related( $post_id, $limit = 5 ) {
	$source_meta = get_post_meta( $post_id, LD_LINKS_META_KEY, true );
	if ( ! is_array( $source_meta ) || empty( $source_meta['vector'] ) ) {
		return array();
	}

	$source_vec  = $source_meta['vector'];
	$source_prov = $source_meta['provider'];
	$source_mod  = $source_meta['model'];

	global $wpdb;
	// Pull every embedding row for candidate post types in one query.
	$post_types = livingdraft_links_post_types();
	$in         = "'" . implode( "','", array_map( 'esc_sql', $post_types ) ) . "'";

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT pm.post_id, pm.meta_value
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = %s
			   AND p.post_status = 'publish'
			   AND p.post_type IN ({$in})
			   AND pm.post_id != %d",
			LD_LINKS_META_KEY,
			(int) $post_id
		)
	); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$scores = array();
	foreach ( $rows as $row ) {
		$candidate = maybe_unserialize( $row->meta_value );
		if ( ! is_array( $candidate ) || empty( $candidate['vector'] ) ) {
			continue;
		}
		// Same-space check — mixing providers gives junk similarity.
		if ( $candidate['provider'] !== $source_prov || $candidate['model'] !== $source_mod ) {
			continue;
		}
		$sim = livingdraft_links_cosine( $source_vec, $candidate['vector'] );
		if ( $sim > 0 ) {
			$scores[ (int) $row->post_id ] = $sim;
		}
	}

	arsort( $scores );
	$top_ids = array_slice( array_keys( $scores ), 0, (int) $limit, true );

	$out = array();
	foreach ( $top_ids as $id ) {
		$post = get_post( $id );
		if ( ! $post ) {
			continue;
		}
		$out[] = array(
			'id'    => (int) $id,
			'title' => get_the_title( $post ),
			'url'   => get_permalink( $post ),
			'score' => round( $scores[ $id ], 3 ),
			'date'  => get_the_date( '', $post ),
		);
	}
	return $out;
}

/**
 * Cosine similarity between two equal-length numeric arrays. Pure PHP,
 * no math extension needed. For ~1500-dim vectors this runs in about
 * 0.1ms — comfortably fast for the ~1000-vector scan we do here.
 */
function livingdraft_links_cosine( $a, $b ) {
	$n = min( count( $a ), count( $b ) );
	if ( 0 === $n ) {
		return 0;
	}
	$dot = 0.0;
	$na  = 0.0;
	$nb  = 0.0;
	for ( $i = 0; $i < $n; $i++ ) {
		$av   = (float) $a[ $i ];
		$bv   = (float) $b[ $i ];
		$dot += $av * $bv;
		$na  += $av * $av;
		$nb  += $bv * $bv;
	}
	if ( 0.0 === $na || 0.0 === $nb ) {
		return 0;
	}
	return $dot / ( sqrt( $na ) * sqrt( $nb ) );
}

/* ------------------------------------------------------------------
 * 4. METABOX STRIP
 * ------------------------------------------------------------------ */

function livingdraft_links_metabox_strip( $post ) {
	if ( ! livingdraft_links_enabled() ) {
		return;
	}
	if ( ! in_array( $post->post_type, livingdraft_links_post_types(), true ) ) {
		return;
	}
	if ( ! livingdraft_seo_ai_ready() ) {
		return;
	}

	$has_embedding = (bool) get_post_meta( $post->ID, LD_LINKS_META_KEY, true );
	?>
	<div class="ld-links-strip"
		data-ld-post-id="<?php echo (int) $post->ID; ?>"
		data-ld-nonce="<?php echo esc_attr( wp_create_nonce( 'ld_links_' . $post->ID ) ); ?>">
		<div class="ld-links-head">
			<div>
				<div class="ld-links-eyebrow"><?php esc_html_e( 'Related posts on your site', 'livingdraft-core' ); ?></div>
				<div class="ld-links-title"><?php esc_html_e( 'Internal links', 'livingdraft-core' ); ?></div>
			</div>
			<button type="button" class="ld-links-refresh" data-ld-refresh>
				<?php if ( $has_embedding ) : ?>
					<?php esc_html_e( 'Refresh', 'livingdraft-core' ); ?>
				<?php else : ?>
					<span class="ld-seo-ai-glyph">✦</span> <?php esc_html_e( 'Find related', 'livingdraft-core' ); ?>
				<?php endif; ?>
			</button>
		</div>
		<div class="ld-links-body" data-ld-links-body>
			<?php if ( ! $has_embedding ) : ?>
				<div style="color:#666;font-size:12px;padding:8px 0">
					<?php esc_html_e( 'This post hasn\'t been indexed yet. Click "Find related" once — it will index the post and pull suggestions in one step.', 'livingdraft-core' ); ?>
				</div>
			<?php else :
				$related = livingdraft_links_related( $post->ID, 5 );
				livingdraft_links_render_list( $related );
			endif; ?>
		</div>
	</div>
	<?php
}
add_action( 'livingdraft_seo_metabox_after', 'livingdraft_links_metabox_strip' );

/**
 * Render a related-posts list. Called on initial render (server-side)
 * and again from JS after an on-demand refresh.
 */
function livingdraft_links_render_list( $items ) {
	if ( empty( $items ) ) {
		echo '<div style="color:#666;font-size:12px;padding:8px 0">' . esc_html__( 'No related posts found yet. If this is a new site, index more posts first (SEO → Links).', 'livingdraft-core' ) . '</div>';
		return;
	}
	echo '<ul class="ld-links-list">';
	foreach ( $items as $item ) {
		printf(
			'<li>' .
				'<a href="%s" target="_blank" rel="noopener" class="ld-links-title-link">%s</a>' .
				'<div class="ld-links-meta">' .
					'<span class="ld-links-score">%s</span>' .
					'<span class="ld-links-date">%s</span>' .
					'<button type="button" class="ld-links-copy" data-ld-copy-md="%s">%s</button>' .
				'</div>' .
			'</li>',
			esc_url( $item['url'] ),
			esc_html( $item['title'] ),
			esc_html( sprintf( '%.0f%%', $item['score'] * 100 ) ),
			esc_html( $item['date'] ),
			esc_attr( sprintf( '[%s](%s)', $item['title'], $item['url'] ) ),
			esc_html__( 'Copy link', 'livingdraft-core' )
		);
	}
	echo '</ul>';
	echo '<script>(function(){
		document.querySelectorAll(".ld-links-copy").forEach(function(b){
			if(b._w)return; b._w=true;
			b.addEventListener("click",function(){
				var text=b.getAttribute("data-ld-copy-md");
				if(navigator.clipboard){navigator.clipboard.writeText(text).then(f);}else{f();}
				function f(){var o=b.textContent;b.textContent="Copied";b.classList.add("is-copied");setTimeout(function(){b.textContent=o;b.classList.remove("is-copied");},1200);}
			});
		});
	})();</script>';
}

/* ------------------------------------------------------------------
 * 5. AJAX — on-demand index + fetch
 *
 * Combined into a single endpoint: hitting Refresh indexes the current
 * post (if it needs it) and returns the top related. Two round trips
 * from the sidebar would waste time; the writer wants one click.
 * ------------------------------------------------------------------ */

function livingdraft_links_ajax_refresh() {
	$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
	if ( ! $post_id ) {
		wp_send_json_error( array( 'message' => __( 'Missing post.', 'livingdraft-core' ) ) );
	}
	check_ajax_referer( 'ld_links_' . $post_id, 'nonce' );

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'livingdraft-core' ) ) );
	}

	$indexed = livingdraft_links_index_post( $post_id );
	if ( is_wp_error( $indexed ) ) {
		wp_send_json_error( array( 'message' => $indexed->get_error_message() ) );
	}

	$related = livingdraft_links_related( $post_id, 5 );

	ob_start();
	livingdraft_links_render_list( $related );
	$html = ob_get_clean();

	wp_send_json_success( array( 'html' => $html, 'count' => count( $related ) ) );
}
add_action( 'wp_ajax_ld_links_refresh', 'livingdraft_links_ajax_refresh' );

/**
 * Batch indexer for the admin sweep. Processes one page of unindexed
 * posts and returns a progress payload the JS uses to schedule the
 * next batch. Batches are small so a stuck provider doesn't time out
 * the whole run.
 */
function livingdraft_links_ajax_batch_index() {
	check_ajax_referer( 'ld_links_batch', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'livingdraft-core' ) ) );
	}

	$batch = min( 20, max( 1, isset( $_POST['batch'] ) ? (int) $_POST['batch'] : 10 ) );

	global $wpdb;
	$post_types = livingdraft_links_post_types();
	$in         = "'" . implode( "','", array_map( 'esc_sql', $post_types ) ) . "'";

	// Find posts that need (re)indexing under the currently-configured
	// embedding provider+model. Two cases:
	//   (a) No embedding row at all
	//   (b) Row exists but its provider/model tag doesn't match the
	//       currently active one — cross-provider comparisons return
	//       junk similarity, so these are effectively unindexed.
	//
	// We can't cheaply filter (b) in SQL because meta_value is
	// serialized PHP. So we pull ID + meta_value, then filter in PHP.
	$s        = livingdraft_ai_get_settings();
	$provider = 'openrouter' === $s['provider'] ? 'openai' : $s['provider']; // OR has no embed API.
	$model    = (string) ( $s[ 'embedding_model_' . $provider ] ?? '' );

	// Over-fetch to account for filter drop. Cap at 5x batch to bound work.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID, pm.meta_value
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
			 WHERE p.post_status = 'publish'
			   AND p.post_type IN ({$in})
			   AND p.post_password = ''
			 ORDER BY p.post_date DESC
			 LIMIT %d",
			LD_LINKS_META_KEY,
			$batch * 5
		)
	); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$ids_to_index = array();
	foreach ( $rows as $row ) {
		if ( null === $row->meta_value ) {
			// Case (a): no embedding at all.
			$ids_to_index[] = (int) $row->ID;
		} else {
			// Case (b): check provider/model match.
			$stored = maybe_unserialize( $row->meta_value );
			if ( ! is_array( $stored )
				|| ( $stored['provider'] ?? '' ) !== $provider
				|| ( $stored['model'] ?? '' ) !== $model ) {
				$ids_to_index[] = (int) $row->ID;
			}
		}
		if ( count( $ids_to_index ) >= $batch ) {
			break;
		}
	}

	$done = 0;
	$fail = 0;
	foreach ( $ids_to_index as $id ) {
		$r = livingdraft_links_index_post( $id );
		if ( is_wp_error( $r ) ) {
			$fail++;
		} else {
			$done++;
		}
	}

	// Remaining count — same filter, unbounded pass.
	$remaining_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT pm.meta_value
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
			 WHERE p.post_status = 'publish'
			   AND p.post_type IN ({$in})
			   AND p.post_password = ''",
			LD_LINKS_META_KEY
		)
	); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$remaining = 0;
	foreach ( $remaining_rows as $r ) {
		if ( null === $r->meta_value ) {
			$remaining++;
			continue;
		}
		$stored = maybe_unserialize( $r->meta_value );
		if ( ! is_array( $stored )
			|| ( $stored['provider'] ?? '' ) !== $provider
			|| ( $stored['model'] ?? '' ) !== $model ) {
			$remaining++;
		}
	}

	wp_send_json_success( array(
		'done'      => $done,
		'failed'    => $fail,
		'remaining' => $remaining,
	) );
}
add_action( 'wp_ajax_ld_links_batch_index', 'livingdraft_links_ajax_batch_index' );

/* ------------------------------------------------------------------
 * 6. CSS for the metabox strip
 * ------------------------------------------------------------------ */

add_action(
	'admin_head-post.php',
	'livingdraft_links_metabox_css'
);
add_action(
	'admin_head-post-new.php',
	'livingdraft_links_metabox_css'
);
function livingdraft_links_metabox_css() {
	if ( ! livingdraft_links_enabled() ) {
		return;
	}
	?>
	<style>
		.ld-links-strip {
			margin-top: 16px;
			padding: 14px 16px;
			background: #fff;
			border: 1px solid #e5e5e5;
		}
		.ld-links-head {
			display: flex;
			justify-content: space-between;
			align-items: baseline;
			margin-bottom: 8px;
		}
		.ld-links-eyebrow {
			font-family: var(--tld-mono, "IBM Plex Mono", monospace);
			font-size: 10px;
			letter-spacing: 0.12em;
			text-transform: uppercase;
			color: #999;
		}
		.ld-links-title {
			font-family: var(--tld-serif, Georgia, serif);
			font-size: 15px;
			color: #1a1a1a;
			margin-top: 2px;
		}
		.ld-links-refresh {
			padding: 4px 10px;
			background: transparent;
			color: #8b3a2c;
			border: 1px solid #d4c4bf;
			font-family: var(--tld-mono, monospace);
			font-size: 10px;
			letter-spacing: 0.08em;
			text-transform: uppercase;
			cursor: pointer;
		}
		.ld-links-refresh:hover:not(:disabled) { background: #faf6f5; }
		.ld-links-refresh:disabled { opacity: 0.5; cursor: wait; }

		.ld-links-list {
			list-style: none;
			padding: 0;
			margin: 8px 0 0;
		}
		.ld-links-list li {
			padding: 10px 0;
			border-bottom: 1px solid #eee;
		}
		.ld-links-list li:last-child { border-bottom: 0; }

		.ld-links-title-link {
			display: block;
			font-family: Georgia, serif;
			font-size: 14px;
			color: #1a0dab;
			text-decoration: none;
			margin-bottom: 3px;
			line-height: 1.3;
		}
		.ld-links-title-link:hover { text-decoration: underline; }

		.ld-links-meta {
			display: flex;
			align-items: center;
			gap: 10px;
			font-family: var(--tld-mono, monospace);
			font-size: 10px;
			color: #999;
		}
		.ld-links-score {
			color: var(--tld-mark, #8b3a2c);
			font-weight: 500;
		}
		.ld-links-copy {
			margin-left: auto;
			padding: 3px 8px;
			background: transparent;
			color: #333;
			border: 1px solid #d4d4d4;
			font-family: var(--tld-mono, monospace);
			font-size: 9px;
			letter-spacing: 0.08em;
			text-transform: uppercase;
			cursor: pointer;
		}
		.ld-links-copy:hover { background: #f5f5f5; }
		.ld-links-copy.is-copied { background: #2f7a3a !important; color: #fff !important; border-color: #2f7a3a !important; }
	</style>
	<script>
	// Wire the Refresh button for the metabox. Kept inline to avoid a
	// separate enqueue for something this small.
	document.addEventListener( 'click', function ( ev ) {
		var btn = ev.target.closest ? ev.target.closest( '[data-ld-refresh]' ) : null;
		if ( ! btn ) return;
		ev.preventDefault();

		var strip = btn.closest( '.ld-links-strip' );
		var body  = strip && strip.querySelector( '[data-ld-links-body]' );
		if ( ! strip || ! body ) return;

		var pid   = strip.getAttribute( 'data-ld-post-id' );
		var nonce = strip.getAttribute( 'data-ld-nonce' );
		var orig  = btn.innerHTML;

		btn.disabled = true;
		btn.textContent = 'Working…';
		body.style.opacity = '0.5';

		var fd = new FormData();
		fd.append( 'action', 'ld_links_refresh' );
		fd.append( 'post_id', pid );
		fd.append( 'nonce', nonce );

		fetch( ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( res && res.success ) {
					body.innerHTML = res.data.html;
				} else {
					body.innerHTML = '<div style="color:#a32e2e;font-size:12px;padding:8px 0">' + ( ( res && res.data && res.data.message ) || 'Failed.' ) + '</div>';
				}
			} )
			.catch( function ( err ) {
				body.innerHTML = '<div style="color:#a32e2e;font-size:12px;padding:8px 0">' + err.message + '</div>';
			} )
			.finally( function () {
				btn.disabled = false;
				btn.innerHTML = orig;
				body.style.opacity = '1';
			} );
	} );
	</script>
	<?php
}
