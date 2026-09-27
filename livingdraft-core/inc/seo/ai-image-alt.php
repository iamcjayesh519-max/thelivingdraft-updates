<?php
/**
 * Vision AI: alt text and captions from the pixels.
 *
 * Rank Math's Image SEO module auto-fills alt from the image filename.
 * That's a lower bar than a working accessibility signal — a filename
 * like `IMG_4437.jpg` produces alt text that helps nobody. This module
 * sends the actual image bytes to whichever vision-capable model the
 * user configured (OpenAI GPT-4o, Gemini 2.x, or an OpenRouter route)
 * and gets back real descriptive alt.
 *
 * === WHERE THE BUTTONS APPEAR ===
 *
 *   Media Library — attachment edit modal + grid detail view.
 *     Added via `attachment_fields_to_edit` so it renders inside
 *     every attachment form WordPress already ships.
 *
 *   SEO metabox on the post editor — a "Fill missing alts" button
 *     that iterates over image attachments used in the current post
 *     and calls the same AJAX endpoint for each. Progress shown inline.
 *
 * === WHY WE SEND BASE64, NOT URLS ===
 *
 * OpenAI and OpenRouter accept image URLs, but on a staging or local
 * dev site the model can't reach them. Base64 encoded from the local
 * file works everywhere — one HTTP request instead of two. Gemini
 * requires inline_data anyway for anything that isn't uploaded via
 * their Files API.
 *
 * === WHICH IMAGE SIZE WE SEND ===
 *
 * The 'large' size (WP's default 1024px) is what goes to the model.
 * Full-size 4MB uploads waste tokens for no accuracy gain — vision
 * models resize internally to about 1024px on the long edge before
 * looking at anything.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------
 * 1. ATTACHMENT FORM UI
 * ------------------------------------------------------------------ */

/**
 * Add a "Generate alt with AI" row to every attachment edit form. Runs
 * inside the media modal, the Media Library grid detail view, and the
 * legacy list-view detail — same filter drives all three.
 */
function livingdraft_ai_image_alt_attachment_field( $form_fields, $post ) {
	if ( ! $post || 0 !== strpos( (string) $post->post_mime_type, 'image/' ) ) {
		return $form_fields;
	}
	if ( ! current_user_can( 'edit_post', $post->ID ) ) {
		return $form_fields;
	}
	if ( ! function_exists( 'livingdraft_ai_get_settings' ) ) {
		return $form_fields;
	}

	$ai_ready = livingdraft_seo_ai_ready();

	$nonce      = wp_create_nonce( 'ld_ai_image_alt_' . $post->ID );
	$attach_id  = (int) $post->ID;

	if ( $ai_ready ) {
		$html = sprintf(
			'<button type="button" class="button ld-ai-alt-btn" data-ld-attachment="%d" data-ld-nonce="%s">'
			. '<span class="ld-ai-alt-glyph">✦</span> %s'
			. '</button>'
			. ' <span class="ld-ai-alt-status" data-ld-status-for="%d"></span>',
			$attach_id,
			esc_attr( $nonce ),
			esc_html__( 'Generate with AI', 'livingdraft-core' ),
			$attach_id
		);
		$helps = esc_html__( 'Looks at the image itself, not the filename. Result goes into the Alt Text field above.', 'livingdraft-core' );
	} else {
		$html  = '<em style="color:#666">' . esc_html__( 'Configure an AI provider under The Living Draft → SEO → AI to enable.', 'livingdraft-core' ) . '</em>';
		$helps = '';
	}

	$form_fields['ld_ai_alt'] = array(
		'label' => __( 'Alt from AI', 'livingdraft-core' ),
		'input' => 'html',
		'html'  => $html,
		'helps' => $helps,
	);

	return $form_fields;
}
add_filter( 'attachment_fields_to_edit', 'livingdraft_ai_image_alt_attachment_field', 10, 2 );

/* ------------------------------------------------------------------
 * 2. ASSETS FOR THE ATTACHMENT SCREENS
 *
 * Loaded on any admin screen that could show the media modal (post
 * edit, media library, page edit, custom-post edit). Small enough that
 * enqueueing broadly is cheaper than gating carefully.
 * ------------------------------------------------------------------ */

function livingdraft_ai_image_alt_enqueue( $hook ) {
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php', 'upload.php' ), true ) ) {
		return;
	}

	$js = LIVINGDRAFT_CORE_DIR . 'assets/js/ai-image-alt.js';
	if ( ! file_exists( $js ) ) {
		return;
	}

	wp_enqueue_script(
		'livingdraft-ai-image-alt',
		LIVINGDRAFT_CORE_URL . 'assets/js/ai-image-alt.js',
		array(),
		filemtime( $js ),
		true
	);

	wp_localize_script(
		'livingdraft-ai-image-alt',
		'livingdraftAiImageAlt',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'strings' => array(
				'working' => __( 'Looking at the image…', 'livingdraft-core' ),
				'failed'  => __( 'Failed:', 'livingdraft-core' ),
				'saved'   => __( 'Alt text updated.', 'livingdraft-core' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'livingdraft_ai_image_alt_enqueue' );

/* ------------------------------------------------------------------
 * 3. AJAX
 * ------------------------------------------------------------------ */

/**
 * Generate alt text for a single attachment and save it to the
 * attachment's own alt meta so the change persists even without a
 * form submission.
 */
function livingdraft_ai_image_alt_ajax() {
	$attachment_id = isset( $_POST['attachment_id'] ) ? (int) $_POST['attachment_id'] : 0;
	if ( ! $attachment_id ) {
		wp_send_json_error( array( 'message' => __( 'Missing attachment.', 'livingdraft-core' ) ) );
	}

	check_ajax_referer( 'ld_ai_image_alt_' . $attachment_id, 'nonce' );

	if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'livingdraft-core' ) ) );
	}

	$post = get_post( $attachment_id );
	if ( ! $post || 0 !== strpos( (string) $post->post_mime_type, 'image/' ) ) {
		wp_send_json_error( array( 'message' => __( 'Not an image.', 'livingdraft-core' ) ) );
	}

	$image = livingdraft_ai_image_load_bytes( $attachment_id, 'large' );
	if ( is_wp_error( $image ) ) {
		wp_send_json_error( array( 'message' => $image->get_error_message() ) );
	}

	// Optional article context — when the call originates from the post
	// editor, pass the post title so the model matches its language and
	// tone to the article the image is illustrating.
	$context_title = isset( $_POST['article_title'] ) ? sanitize_text_field( wp_unslash( $_POST['article_title'] ) ) : '';

	$prompt = livingdraft_ai_build_prompt( 'image_alt', array( 'title' => $context_title ) );

	$override = livingdraft_ai_read_request_override();

	$result = livingdraft_ai_complete(
		$prompt,
		array_merge(
			array(
				'task'        => 'image_alt',
				'image'       => $image,
				'max_tokens'  => 120,
				'temperature' => 0.5,
			),
			$override
		)
	);

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	$alt = trim( $result, " \t\n\r\0\x0B\"'`" );
	// Strip any leading "Alt: " / "Alt text: " some models slip in.
	$alt = preg_replace( '/^(Alt(?:\s*text)?\s*:\s*)/i', '', $alt );
	$alt = trim( $alt );

	if ( '' === $alt ) {
		wp_send_json_error( array( 'message' => __( 'The model returned nothing.', 'livingdraft-core' ) ) );
	}

	// Persist to the attachment's own alt meta. Whatever surface fired
	// this request may or may not submit a form; storing directly means
	// the change is durable either way.
	update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );

	wp_send_json_success( array( 'alt' => $alt, 'attachment_id' => $attachment_id ) );
}
add_action( 'wp_ajax_ld_ai_image_alt', 'livingdraft_ai_image_alt_ajax' );

/* ------------------------------------------------------------------
 * 4. IMAGE-BYTES LOADER
 * ------------------------------------------------------------------ */

/**
 * Read an attachment's file bytes at a given size, preferring local
 * disk over an HTTP round trip. Falls back to wp_remote_get when the
 * file isn't on disk (offloaded to S3, CDN-only, etc.).
 *
 * @return array|WP_Error [ 'data' => bytes, 'mime' => 'image/jpeg' ]
 */
function livingdraft_ai_image_load_bytes( $attachment_id, $size = 'large' ) {
	$src = wp_get_attachment_image_src( $attachment_id, $size );
	if ( ! $src ) {
		return new WP_Error( 'ld_ai_no_src', __( 'Could not resolve image source.', 'livingdraft-core' ) );
	}
	$url = $src[0];

	// Attempt local disk read first. Faster and doesn't fail on hosts
	// where WP can't loop back to its own URL.
	$upload = wp_upload_dir();
	if ( strpos( $url, $upload['baseurl'] ) === 0 ) {
		$path = $upload['basedir'] . substr( $url, strlen( $upload['baseurl'] ) );
		if ( file_exists( $path ) && is_readable( $path ) ) {
			$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false !== $bytes ) {
				$ft = wp_check_filetype( $path );
				return array(
					'data' => $bytes,
					'mime' => $ft['type'] ?: 'image/jpeg',
				);
			}
		}
	}

	// Fallback: fetch via HTTP.
	$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return new WP_Error( 'ld_ai_img_http', sprintf( __( 'Could not fetch image (HTTP %d).', 'livingdraft-core' ), (int) wp_remote_retrieve_response_code( $response ) ) );
	}

	$mime = wp_remote_retrieve_header( $response, 'content-type' );
	if ( ! $mime || 0 !== strpos( $mime, 'image/' ) ) {
		$mime = 'image/jpeg';
	}

	return array(
		'data' => (string) wp_remote_retrieve_body( $response ),
		'mime' => $mime,
	);
}

/* ------------------------------------------------------------------
 * 5. SEO METABOX INTEGRATION
 *
 * Adds a "Fill missing alts" strip to the metabox showing how many
 * images in the current post are missing alt text. Button fires a
 * batch through the same AJAX endpoint, one image at a time, updating
 * the counter as each completes.
 * ------------------------------------------------------------------ */

/**
 * Render the strip inside the metabox. Called from seo-meta.php via
 * the `livingdraft_seo_metabox_after` action (added below).
 */
function livingdraft_ai_image_alt_metabox_strip( $post ) {
	if ( ! livingdraft_seo_ai_ready() ) {
		return;
	}
	if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
		return;
	}

	// Attachment IDs referenced in the post content via class="wp-image-N".
	$ids = livingdraft_ai_image_alt_attachments_in_post( $post );
	if ( empty( $ids ) ) {
		return;
	}

	$missing = array();
	foreach ( $ids as $id ) {
		$alt = get_post_meta( $id, '_wp_attachment_image_alt', true );
		if ( '' === trim( (string) $alt ) ) {
			$missing[] = $id;
		}
	}

	$total    = count( $ids );
	$missing_count = count( $missing );

	// Build a nonce for each image so the batch runner can hit the AJAX
	// endpoint without needing a global nonce.
	$nonces = array();
	foreach ( $missing as $id ) {
		$nonces[ $id ] = wp_create_nonce( 'ld_ai_image_alt_' . $id );
	}
	?>
	<div class="ld-seo-image-alt-strip"
		data-ld-image-alt-strip
		data-ld-total="<?php echo (int) $total; ?>"
		data-ld-missing="<?php echo (int) $missing_count; ?>"
		data-ld-post-title="<?php echo esc_attr( get_the_title( $post ) ); ?>"
		data-ld-nonces='<?php echo esc_attr( wp_json_encode( $nonces ) ); ?>'
		data-ld-ids='<?php echo esc_attr( wp_json_encode( $missing ) ); ?>'>
		<div class="ld-seo-image-alt-count">
			<strong data-ld-count><?php echo (int) $missing_count; ?></strong>
			<?php
			printf(
				/* translators: 1: missing count, 2: total count */
				esc_html( _n( 'of %2$d image is missing alt text.', 'of %2$d images are missing alt text.', $total, 'livingdraft-core' ) ),
				(int) $missing_count,
				(int) $total
			);
			?>
		</div>
		<?php if ( $missing_count > 0 ) : ?>
			<button type="button" class="ld-seo-image-alt-btn" data-ld-run>
				<span class="ld-seo-ai-glyph">✦</span>
				<?php esc_html_e( 'Fill missing alts with AI', 'livingdraft-core' ); ?>
			</button>
			<div class="ld-seo-image-alt-progress" data-ld-progress hidden></div>
		<?php else : ?>
			<span style="color:var(--tld-good, #2f7a3a);font-size:11px;font-family:var(--tld-mono, monospace)">
				✓ <?php esc_html_e( 'ALL ALTS SET', 'livingdraft-core' ); ?>
			</span>
		<?php endif; ?>
	</div>
	<?php
}
add_action( 'livingdraft_seo_metabox_after', 'livingdraft_ai_image_alt_metabox_strip' );

/**
 * Extract attachment IDs referenced by the current post content, via
 * the `wp-image-N` class WordPress adds to inserted images.
 */
function livingdraft_ai_image_alt_attachments_in_post( $post ) {
	if ( ! preg_match_all( '/wp-image-(\d+)/', (string) $post->post_content, $m ) ) {
		return array();
	}
	$ids = array_unique( array_map( 'intval', $m[1] ) );

	// Only include ones that actually exist as image attachments.
	$out = array();
	foreach ( $ids as $id ) {
		$post_obj = get_post( $id );
		if ( $post_obj && 0 === strpos( (string) $post_obj->post_mime_type, 'image/' ) ) {
			$out[] = $id;
		}
	}
	return $out;
}
