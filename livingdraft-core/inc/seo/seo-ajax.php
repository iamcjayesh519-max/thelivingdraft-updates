<?php
/**
 * AJAX bridge between the meta box and the server-side analyzer / AI.
 *
 * Two endpoints:
 *
 *   POST admin-ajax.php?action=ld_seo_analyze
 *     Body: post_id, nonce, and current form values (title, description,
 *     keyword). Returns the score + checks so the metabox can rerender
 *     without a save.
 *
 *   POST admin-ajax.php?action=ld_seo_ai
 *     Body: post_id, task, nonce, and current form values. Returns the
 *     generated text.
 *
 * Both are POST because they carry the unsaved-form draft state, which
 * is properly a request body, not a query string.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run the analyzer against unsaved-form state.
 */
function livingdraft_seo_ajax_analyze() {
	check_ajax_referer( 'ld_seo_analyze', 'nonce' );

	$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
	if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'livingdraft-core' ) ) );
	}

	$overrides = array(
		'title'       => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
		'description' => isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '',
		'keyword'     => isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '',
	);

	wp_send_json_success( livingdraft_seo_analyze( $post_id, $overrides ) );
}
add_action( 'wp_ajax_ld_seo_analyze', 'livingdraft_seo_ajax_analyze' );

/**
 * Run an AI task against the current post + form draft, return the text.
 *
 * Handles both the generic "Generate" buttons on fields (seo_title,
 * meta_description, focus_keyword) and the per-check "Fix with AI"
 * flows (suggest_intro, suggest_density, suggest_headings,
 * suggest_expansion). The suggest_* tasks need extra context — word
 * counts, mention counts, first paragraph — which we compute here
 * rather than making the JS carry it.
 */
function livingdraft_seo_ajax_ai() {
	check_ajax_referer( 'ld_seo_ai', 'nonce' );

	$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
	if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'livingdraft-core' ) ) );
	}

	$allowed_tasks = array(
		'meta_description',
		'seo_title',
		'seo_slug',
		'focus_keyword',
		'suggest_intro',
		'suggest_density',
		'suggest_headings',
		'suggest_expansion',
	);

	$task = isset( $_POST['task'] ) ? sanitize_key( $_POST['task'] ) : '';
	if ( ! in_array( $task, $allowed_tasks, true ) ) {
		wp_send_json_error( array( 'message' => __( 'Unknown AI task.', 'livingdraft-core' ) ) );
	}

	$post = get_post( $post_id );

	$context = array(
		'title'    => isset( $_POST['title'] ) && '' !== $_POST['title']
			? sanitize_text_field( wp_unslash( $_POST['title'] ) )
			: get_the_title( $post ),
		'excerpt'  => (string) $post->post_content,
		'keyword'  => isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '',
		'existing' => isset( $_POST['existing'] ) ? sanitize_text_field( wp_unslash( $_POST['existing'] ) ) : '',
	);

	// Enrich context for the tasks that need it.
	if ( 'suggest_intro' === $task ) {
		$context['intro'] = livingdraft_seo_first_paragraph( $post );
	}
	if ( in_array( $task, array( 'suggest_density', 'suggest_expansion' ), true ) ) {
		$plain           = trim( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) );
		$context['words'] = str_word_count( $plain );
		if ( 'suggest_density' === $task && '' !== $context['keyword'] ) {
			$context['mentions'] = substr_count( strtolower( $plain ), strtolower( $context['keyword'] ) );
		}
	}

	$prompt = livingdraft_ai_build_prompt( $task, $context );
	if ( '' === $prompt ) {
		wp_send_json_error( array( 'message' => __( 'Unknown task.', 'livingdraft-core' ) ) );
	}

	// Token budgets tuned per task: titles are short, meta descriptions
	// medium, multi-bullet suggestions need room to breathe.
	$budgets = array(
		'seo_title'         => 60,
		'seo_slug'          => 40,
		'focus_keyword'     => 30,
		'meta_description'  => 200,
		'suggest_intro'     => 250,
		'suggest_density'   => 250,
		'suggest_headings'  => 200,
		'suggest_expansion' => 400,
	);
	$max_tokens = isset( $budgets[ $task ] ) ? $budgets[ $task ] : 200;

	// Temperature: keywords should be deterministic; titles/descriptions
	// benefit from a little variation; suggestions want a bit more.
	$temperature = 0.7;
	if ( 'focus_keyword' === $task ) {
		$temperature = 0.3;
	} elseif ( 0 === strpos( $task, 'suggest_' ) ) {
		$temperature = 0.8;
	}

	$override = livingdraft_ai_read_request_override();

	$result = livingdraft_ai_complete(
		$prompt,
		array_merge(
			array(
				'task'        => $task,
				'max_tokens'  => $max_tokens,
				'temperature' => $temperature,
			),
			$override
		)
	);

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	// Clean up model artifacts: wrapping quotes, trailing periods on
	// short outputs. Suggest tasks may include bullet markers, keep those.
	$clean = trim( $result, " \t\n\r\0\x0B\"'`" );
	if ( 'focus_keyword' === $task ) {
		$clean = rtrim( $clean, '.' );
	}
	// Slug: force through sanitize_title so nothing the model returned
	// can smuggle a slash, quote, unicode punctuation, or space into
	// post_name / URL. If the model returned the slug in an unexpected
	// form ("URL: my-post-slug"), grab the last plausible-looking token.
	if ( 'seo_slug' === $task ) {
		// If the model emitted a whole line like "https://.../foo-bar/",
		// pull out the last non-empty path segment before sanitising.
		if ( false !== strpos( $clean, '/' ) ) {
			$segments = array_values( array_filter( explode( '/', $clean ) ) );
			if ( ! empty( $segments ) ) {
				$clean = end( $segments );
			}
		}
		$clean = sanitize_title( $clean );
	}

	wp_send_json_success( array( 'text' => $clean, 'task' => $task ) );
}
add_action( 'wp_ajax_ld_seo_ai', 'livingdraft_seo_ajax_ai' );

/* ==============================================================
 * v3.2.1 — TERM AI ENDPOINT
 *
 * Same idea as `ld_seo_ai` above, but operating on a term
 * (category / tag / custom taxonomy) rather than a post.
 *
 * The AI's "context" for a term is deliberately different from a
 * post's: a term has no long body copy, so we build the excerpt
 * from the term description PLUS the titles of the ten most recent
 * posts filed in that term. That way the model knows the archive
 * actually covers "Mumbai monsoon flooding, IMD warnings, BMC
 * response…" instead of just seeing an empty term description and
 * guessing.
 *
 * A separate action name keeps this endpoint isolated — nothing
 * about the existing post AI flow changes.
 * ============================================================== */

function livingdraft_seo_term_ajax_ai() {
	check_ajax_referer( 'ld_seo_term_ai', 'nonce' );

	$term_id  = isset( $_POST['term_id'] ) ? (int) $_POST['term_id'] : 0;
	$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';

	if ( ! $term_id || '' === $taxonomy ) {
		wp_send_json_error( array( 'message' => __( 'Missing term or taxonomy.', 'livingdraft-core' ) ) );
	}
	if ( ! current_user_can( 'manage_categories' ) ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'livingdraft-core' ) ) );
	}

	$term = get_term( $term_id, $taxonomy );
	if ( ! $term || is_wp_error( $term ) ) {
		wp_send_json_error( array( 'message' => __( 'Term not found.', 'livingdraft-core' ) ) );
	}

	// Only the four tasks that make sense on an archive page. Post-only
	// tasks (suggest_intro, suggest_density, etc.) don't apply here —
	// there is no article body to rewrite.
	$allowed_tasks = array( 'meta_description', 'seo_title', 'seo_slug', 'focus_keyword' );
	$task = isset( $_POST['task'] ) ? sanitize_key( $_POST['task'] ) : '';
	if ( ! in_array( $task, $allowed_tasks, true ) ) {
		wp_send_json_error( array( 'message' => __( 'Unknown AI task.', 'livingdraft-core' ) ) );
	}

	// Build the archive's "excerpt" for the model: the term description
	// (if any) followed by the titles of the 10 most recent posts in the
	// term. If nothing has been filed in the term yet we send just the
	// term name — the model will produce something generic, which is
	// still better than the operator staring at a blank field.
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
		'fields'                 => 'ids', // We only need titles; skip loading full post objects.
	) );
	if ( ! empty( $recent_posts ) ) {
		$context_lines[] = "Recent articles in this archive:";
		foreach ( $recent_posts as $pid ) {
			$context_lines[] = '- ' . get_the_title( $pid );
		}
	}

	$context = array(
		'title'    => isset( $_POST['title'] ) && '' !== $_POST['title']
			? sanitize_text_field( wp_unslash( $_POST['title'] ) )
			: $term->name,
		'excerpt'  => implode( "\n", $context_lines ),
		'keyword'  => isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '',
		'existing' => isset( $_POST['existing'] ) ? sanitize_text_field( wp_unslash( $_POST['existing'] ) ) : '',
	);

	$prompt = livingdraft_ai_build_prompt( $task, $context );
	if ( '' === $prompt ) {
		wp_send_json_error( array( 'message' => __( 'Unknown task.', 'livingdraft-core' ) ) );
	}

	$budgets = array(
		'seo_title'        => 60,
		'seo_slug'         => 40,
		'focus_keyword'    => 30,
		'meta_description' => 200,
	);
	$max_tokens = $budgets[ $task ] ?? 200;

	$temperature = ( 'focus_keyword' === $task || 'seo_slug' === $task ) ? 0.3 : 0.7;

	$override = livingdraft_ai_read_request_override();

	$result = livingdraft_ai_complete(
		$prompt,
		array_merge(
			array(
				'task'        => 'term_' . $task,  // Distinct label in any provider-side logs.
				'max_tokens'  => $max_tokens,
				'temperature' => $temperature,
			),
			$override
		)
	);

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	// Trim wrapping quotes / trailing periods on short outputs — same
	// cleanup the post endpoint does.
	$clean = trim( $result, " \t\n\r\0\x0B\"'`" );
	if ( 'focus_keyword' === $task ) {
		$clean = rtrim( $clean, '.' );
	}
	if ( 'seo_slug' === $task ) {
		// Same "model returned a whole URL, extract the last segment"
		// defense as the post endpoint. Then force through sanitize_title.
		if ( false !== strpos( $clean, '/' ) ) {
			$segments = array_values( array_filter( explode( '/', $clean ) ) );
			if ( ! empty( $segments ) ) {
				$clean = end( $segments );
			}
		}
		$clean = sanitize_title( $clean );
	}

	wp_send_json_success( array( 'text' => $clean, 'task' => $task ) );
}
add_action( 'wp_ajax_ld_seo_term_ai', 'livingdraft_seo_term_ajax_ai' );
