<?php
/**
 * AI key points — a short "In brief" box at the top of an article.
 *
 * === WHY IT EXISTS ===
 *
 * Readers on a phone decide in about three seconds whether to keep
 * reading. Three plain bullets that say what happened lift engaged time
 * and scroll depth (both measured on the Analytics screen, so the effect
 * can be checked rather than assumed). Search engines and AI answer
 * engines also pick up a clean factual summary near the top of a page.
 *
 * === THE RULES IT KEEPS ===
 *
 *   - It never publishes itself unseen. The bullets appear in the editor
 *     sidebar, where a person can rewrite or delete any line, and they
 *     can be switched off per article.
 *   - Auto-generate on publish is off by default. When switched on it runs
 *     in the background a minute after publishing, so pressing Publish is
 *     never slowed down by an AI call.
 *   - If the article is edited after the bullets were written, the
 *     sidebar says so. A summary that no longer matches the story is the
 *     kind of error this site publishes corrections for.
 *
 * The theme draws the box (template-parts/ai-summary.php). Any other theme
 * gets it prepended to the article content, so nothing is lost on a
 * theme change.
 *
 * @package LivingDraftCore
 * @since   4.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LIVINGDRAFT_SUMMARY_META = '_ld_ai_summary';
const LIVINGDRAFT_SUMMARY_HASH = '_ld_ai_summary_hash';
const LIVINGDRAFT_SUMMARY_OFF  = '_ld_ai_summary_off';

/**
 * Hash of what the summary was written from.
 *
 * @param WP_Post $post Post.
 * @return string
 */
function livingdraft_summary_content_hash( $post ) {
	return md5( $post->post_title . "\n" . $post->post_content );
}

/**
 * The bullets to show for an article, or an empty array.
 *
 * Themes call this. Returns nothing when the article has none, when they
 * are switched off for that article, or when the feature is off.
 *
 * @param int|null $post_id Post.
 * @return string[]
 */
function livingdraft_ai_summary( $post_id = null ) {
	$post_id = $post_id ? (int) $post_id : (int) get_the_ID();

	if ( ! $post_id || get_post_meta( $post_id, LIVINGDRAFT_SUMMARY_OFF, true ) ) {
		return array();
	}

	$items = get_post_meta( $post_id, LIVINGDRAFT_SUMMARY_META, true );
	$items = is_array( $items ) ? array_values( array_filter( array_map( 'trim', $items ) ) ) : array();

	return (array) apply_filters( 'livingdraft_ai_summary', $items, $post_id );
}

/**
 * Ask the model.
 *
 * @param WP_Post $post  Post.
 * @param array   $extra Extra args (provider/model override, bypass_rate_limit).
 * @return string[]|WP_Error
 */
function livingdraft_summary_generate( $post, $extra = array() ) {
	if ( ! function_exists( 'livingdraft_ai_complete' ) ) {
		return new WP_Error( 'ld_no_ai', __( 'The AI layer is not loaded.', 'livingdraft-core' ) );
	}

	$text = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
	$text = preg_replace( '/\s+/', ' ', $text );
	$text = function_exists( 'mb_substr' ) ? mb_substr( (string) $text, 0, 12000 ) : substr( (string) $text, 0, 12000 );

	if ( str_word_count( $text ) < 80 ) {
		return new WP_Error( 'ld_summary_short', __( 'The article is too short to summarise (under 80 words).', 'livingdraft-core' ) );
	}

	$count = (int) apply_filters( 'livingdraft_ai_summary_count', 3 );

	$prompt = sprintf(
		"Summarise this news article as exactly %d bullet points for a reader deciding whether to read on.\n"
		. "Rules: each bullet is one plain sentence under 25 words; state facts from the article only; the first bullet says what happened; "
		. "keep names, numbers and dates exact; no opinion, no hype, no questions, no emojis; match the article's language.\n"
		. "Return only the bullets, one per line, each starting with \"- \".\n\nTITLE: %s\n\nARTICLE: %s",
		$count,
		$post->post_title,
		$text
	);

	$out = livingdraft_ai_complete(
		$prompt,
		array_merge(
			array(
				'task'        => 'article_summary',
				'system'      => 'You are a careful news sub-editor. Accuracy matters more than style.',
				'max_tokens'  => 400,
				'temperature' => 0.2,
			),
			$extra
		)
	);

	if ( is_wp_error( $out ) ) {
		return $out;
	}

	$items = array();
	foreach ( preg_split( '/\r?\n/', (string) $out ) as $line ) {
		$line = trim( preg_replace( '/^\s*(?:[-*•]|\d+[.)])\s*/u', '', $line ) );
		$line = trim( $line, " \t\"'" );
		if ( '' !== $line ) {
			$items[] = sanitize_text_field( $line );
		}
	}

	$items = array_slice( $items, 0, max( 1, $count + 1 ) );

	if ( empty( $items ) ) {
		return new WP_Error( 'ld_summary_empty', __( 'The model returned nothing usable. Try again or pick another model.', 'livingdraft-core' ) );
	}

	return $items;
}

/* ==================================================================
 * EDITOR BOX
 * ================================================================== */

function livingdraft_summary_box_register() {
	add_meta_box( 'livingdraft_ai_summary', __( 'Key points (AI)', 'livingdraft-core' ), 'livingdraft_summary_box', 'post', 'side', 'high' );
}
add_action( 'add_meta_boxes', 'livingdraft_summary_box_register' );

/**
 * @param WP_Post $post Post.
 */
function livingdraft_summary_box( $post ) {
	$items = get_post_meta( $post->ID, LIVINGDRAFT_SUMMARY_META, true );
	$items = is_array( $items ) ? $items : array();
	$off   = (bool) get_post_meta( $post->ID, LIVINGDRAFT_SUMMARY_OFF, true );
	$hash  = (string) get_post_meta( $post->ID, LIVINGDRAFT_SUMMARY_HASH, true );
	$stale = $items && $hash && livingdraft_summary_content_hash( $post ) !== $hash;

	wp_nonce_field( 'ld_summary_save', 'ld_summary_nonce' );
	?>
	<p style="margin-top:0;color:#646970"><?php esc_html_e( 'Shown as "In brief" above the article. One point per line — edit freely.', 'livingdraft-core' ); ?></p>

	<?php if ( $stale ) : ?>
		<p style="color:#b7791f;margin:0 0 8px"><strong><?php esc_html_e( 'The article changed after these were written.', 'livingdraft-core' ); ?></strong> <?php esc_html_e( 'Check them, or regenerate.', 'livingdraft-core' ); ?></p>
	<?php endif; ?>

	<textarea name="ld_summary_items" id="ld-summary-items" rows="6" style="width:100%;font-size:13px"><?php echo esc_textarea( implode( "\n", $items ) ); ?></textarea>

	<p style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:8px 0">
		<?php
		if ( function_exists( 'livingdraft_ai_render_model_picker' ) ) {
			livingdraft_ai_render_model_picker( array( 'storage_key' => 'livingdraft.ai.summary' ) );
		}
		?>
	</p>
	<p style="display:flex;gap:8px;align-items:center;margin:0">
		<button type="button" class="button" id="ld-summary-gen" data-post="<?php echo esc_attr( $post->ID ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'ld_summary_gen' ) ); ?>">
			<?php echo $items ? esc_html__( 'Regenerate', 'livingdraft-core' ) : esc_html__( 'Generate with AI', 'livingdraft-core' ); ?>
		</button>
		<span id="ld-summary-status" style="color:#646970"></span>
	</p>

	<p style="margin:10px 0 0">
		<label><input type="checkbox" name="ld_summary_off" value="1" <?php checked( $off ); ?>> <?php esc_html_e( 'Hide on this article', 'livingdraft-core' ); ?></label>
	</p>
	<p style="margin:6px 0 0;color:#646970;font-size:12px"><?php esc_html_e( 'Uses the saved article text — save the draft first if you just changed it.', 'livingdraft-core' ); ?></p>

	<script>
	(function () {
		var b = document.getElementById('ld-summary-gen');
		var st = document.getElementById('ld-summary-status');
		var ta = document.getElementById('ld-summary-items');
		if (!b) { return; }
		b.addEventListener('click', function () {
			b.disabled = true; st.textContent = '<?php echo esc_js( __( 'Writing…', 'livingdraft-core' ) ); ?>';
			var fd = new FormData();
			fd.append('action', 'ld_summary_gen');
			fd.append('nonce', b.dataset.nonce);
			fd.append('post', b.dataset.post);
			if (window.livingdraftAiCurrentModel) { fd.append('ai_model', window.livingdraftAiCurrentModel('livingdraft.ai.summary')); }
			fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (res && res.success) { ta.value = res.data.items.join('\n'); st.textContent = '<?php echo esc_js( __( 'Done — review, then save the post.', 'livingdraft-core' ) ); ?>'; }
					else { st.textContent = (res && res.data && res.data.message) || 'Failed.'; }
				})
				.catch(function (e) { st.textContent = e.message; })
				.finally(function () { b.disabled = false; });
		});
	})();
	</script>
	<?php
}

/**
 * Save the box.
 *
 * @param int $post_id Post.
 */
function livingdraft_summary_save( $post_id ) {
	if ( ! isset( $_POST['ld_summary_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ld_summary_nonce'] ) ), 'ld_summary_save' ) ) {
		return;
	}
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$raw   = isset( $_POST['ld_summary_items'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ld_summary_items'] ) ) : '';
	$items = array_values( array_filter( array_map( 'trim', preg_split( '/\r?\n/', $raw ) ) ) );
	$prev  = get_post_meta( $post_id, LIVINGDRAFT_SUMMARY_META, true );

	if ( $items ) {
		update_post_meta( $post_id, LIVINGDRAFT_SUMMARY_META, array_slice( $items, 0, 8 ) );
		// A person saved them: they now vouch for this version of the text.
		if ( $items !== $prev ) {
			$post = get_post( $post_id );
			update_post_meta( $post_id, LIVINGDRAFT_SUMMARY_HASH, livingdraft_summary_content_hash( $post ) );
		}
	} else {
		delete_post_meta( $post_id, LIVINGDRAFT_SUMMARY_META );
		delete_post_meta( $post_id, LIVINGDRAFT_SUMMARY_HASH );
	}

	if ( ! empty( $_POST['ld_summary_off'] ) ) {
		update_post_meta( $post_id, LIVINGDRAFT_SUMMARY_OFF, 1 );
	} else {
		delete_post_meta( $post_id, LIVINGDRAFT_SUMMARY_OFF );
	}
}
add_action( 'save_post_post', 'livingdraft_summary_save' );

/**
 * AJAX generate (does not save — the editor reviews first).
 */
function livingdraft_summary_ajax() {
	check_ajax_referer( 'ld_summary_gen', 'nonce' );

	$post_id = absint( $_POST['post'] ?? 0 );
	$post    = $post_id ? get_post( $post_id ) : null;

	if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permission.', 'livingdraft-core' ) ) );
	}

	$extra = function_exists( 'livingdraft_ai_read_request_override' ) ? livingdraft_ai_read_request_override() : array();
	$items = livingdraft_summary_generate( $post, $extra );

	if ( is_wp_error( $items ) ) {
		wp_send_json_error( array( 'message' => $items->get_error_message() ) );
	}

	wp_send_json_success( array( 'items' => $items ) );
}
add_action( 'wp_ajax_ld_summary_gen', 'livingdraft_summary_ajax' );

/* ==================================================================
 * AUTO-GENERATE ON FIRST PUBLISH (opt-in)
 * ================================================================== */

/**
 * @return bool
 */
function livingdraft_summary_auto_enabled() {
	return (bool) get_option( 'livingdraft_ai_summary_auto', false );
}

/**
 * Queue a background job when a post is first published without bullets.
 *
 * @param string  $new  New status.
 * @param string  $old  Old status.
 * @param WP_Post $post Post.
 */
function livingdraft_summary_on_publish( $new, $old, $post ) {
	if ( 'publish' !== $new || 'publish' === $old || 'post' !== $post->post_type || ! livingdraft_summary_auto_enabled() ) {
		return;
	}
	if ( get_post_meta( $post->ID, LIVINGDRAFT_SUMMARY_META, true ) ) {
		return;
	}
	if ( ! wp_next_scheduled( 'livingdraft_summary_job', array( (int) $post->ID ) ) ) {
		wp_schedule_single_event( time() + 60, 'livingdraft_summary_job', array( (int) $post->ID ) );
	}
}
add_action( 'transition_post_status', 'livingdraft_summary_on_publish', 10, 3 );

/**
 * The background job.
 *
 * @param int $post_id Post.
 */
function livingdraft_summary_job( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post || 'publish' !== $post->post_status || get_post_meta( $post_id, LIVINGDRAFT_SUMMARY_META, true ) ) {
		return;
	}

	// Trusted server path: the editor switched this on in Settings. There is
	// no logged-in user in cron, so the per-user UI rate limit cannot apply.
	$items = livingdraft_summary_generate( $post, array( 'bypass_rate_limit' => true ) );

	if ( is_wp_error( $items ) ) {
		update_post_meta( $post_id, '_ld_ai_summary_error', $items->get_error_message() );
		return;
	}

	update_post_meta( $post_id, LIVINGDRAFT_SUMMARY_META, $items );
	update_post_meta( $post_id, LIVINGDRAFT_SUMMARY_HASH, livingdraft_summary_content_hash( $post ) );
	delete_post_meta( $post_id, '_ld_ai_summary_error' );

	// The article page is probably cached without the box; refresh it.
	clean_post_cache( $post_id );
	do_action( 'litespeed_purge_post', $post_id );
	if ( function_exists( 'rocket_clean_post' ) ) {
		rocket_clean_post( $post_id );
	}
}
add_action( 'livingdraft_summary_job', 'livingdraft_summary_job' );

/* ==================================================================
 * FRONT END — for themes that do not draw the box themselves
 * ================================================================== */

/**
 * @param string $content Content.
 * @return string
 */
function livingdraft_summary_fallback( $content ) {
	if ( current_theme_supports( 'livingdraft-ai-summary' ) || ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	$items = livingdraft_ai_summary();
	if ( ! $items ) {
		return $content;
	}

	$html = '<aside class="ld-summary" aria-label="' . esc_attr__( 'In brief', 'livingdraft-core' ) . '"><p class="ld-summary-title">' . esc_html__( 'In brief', 'livingdraft-core' ) . '</p><ul>';
	foreach ( $items as $item ) {
		$html .= '<li>' . esc_html( $item ) . '</li>';
	}
	$html .= '</ul></aside>';

	return $html . $content;
}
add_filter( 'the_content', 'livingdraft_summary_fallback', 8 );

/* ==================================================================
 * SETTINGS — one switch, on the AI screen
 * ================================================================== */

function livingdraft_summary_handle_save() {
	if ( ! isset( $_POST['ld_summary_settings_save'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'ld_summary_settings' );
	update_option( 'livingdraft_ai_summary_auto', ! empty( $_POST['ld_summary_auto'] ), false );
	wp_safe_redirect( admin_url( 'admin.php?page=livingdraft-settings&section=ai_summary&saved=1' ) );
	exit;
}
add_action( 'admin_init', 'livingdraft_summary_handle_save' );

function livingdraft_summary_render_settings() {
	if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="tld-notice"><p>' . esc_html__( 'Saved.', 'livingdraft-core' ) . '</p></div>';
	}
	?>
	<div class="tld-card">
		<div class="tld-section-rule"><h2><?php esc_html_e( 'Key points (AI)', 'livingdraft-core' ); ?></h2></div>
		<p class="tld-help"><?php esc_html_e( 'Three-line "In brief" summaries above articles. Written with your AI provider, reviewed and edited in the post sidebar, switchable per article.', 'livingdraft-core' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'ld_summary_settings' ); ?>
			<div class="tld-field">
				<label class="tld-check">
					<input type="checkbox" name="ld_summary_auto" value="1" <?php checked( livingdraft_summary_auto_enabled() ); ?>>
					<span><?php esc_html_e( 'Write key points automatically when a story is first published', 'livingdraft-core' ); ?></span>
				</label>
				<p class="tld-help"><?php esc_html_e( 'Runs in the background about a minute after publishing. Only for articles that have none yet. Off by default: an unreviewed AI line on a news story is a correction waiting to happen.', 'livingdraft-core' ); ?></p>
			</div>
			<div class="tld-btn-row"><button class="tld-btn is-primary" type="submit" name="ld_summary_settings_save" value="1"><?php esc_html_e( 'Save', 'livingdraft-core' ); ?></button></div>
		</form>
	</div>
	<?php
}

/**
 * @param array $sections Sections.
 * @return array
 */
function livingdraft_summary_register_settings( $sections ) {
	$sections['ai_summary'] = array(
		'label'  => __( 'Key points (AI)', 'livingdraft-core' ),
		'desc'   => __( 'AI "In brief" summaries above articles.', 'livingdraft-core' ),
		'render' => 'livingdraft_summary_render_settings',
		'cap'    => 'manage_options',
	);
	return $sections;
}
add_filter( 'livingdraft_settings_panels', 'livingdraft_summary_register_settings', 11 );
