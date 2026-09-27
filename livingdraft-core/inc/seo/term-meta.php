<?php
/**
 * Term SEO meta.
 *
 * Adds the same SEO field set to Category / Tag / custom taxonomy edit
 * screens that we already have on posts. Storage uses `wp_termmeta` with
 * the same key names (`_ld_seo_title`, `_ld_seo_description`, etc.) so
 * READ helpers can be near-identical between post and term contexts.
 *
 * Which taxonomies get the fields:
 *   Every public taxonomy EXCEPT `nav_menu`, `link_category`,
 *   `post_format` — those are UI/internal taxonomies where SEO fields
 *   would just clutter the screen.
 *
 * Rank Math doesn't use `_ld_seo_*` on terms either, so there's no
 * fallback to it here (unlike the post helper). Migration is one-way:
 * users copy from Rank Math term meta the first time they edit.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The taxonomies that get SEO fields. Filterable.
 */
function livingdraft_seo_term_taxonomies() {
	$excluded = array( 'nav_menu', 'link_category', 'post_format' );
	$out      = array();
	foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
		if ( in_array( $tax->name, $excluded, true ) ) {
			continue;
		}
		$out[] = $tax->name;
	}
	return (array) apply_filters( 'livingdraft_seo_term_taxonomies', $out );
}

/**
 * Read helper — mirrors livingdraft_seo_get() for posts.
 *
 * Robots is stored as an array of directive strings. Other fields are
 * plain strings. Always returns a value of the expected shape so
 * callers don't have to guard for null/false.
 */
function livingdraft_seo_term_get( $field, $term_id ) {
	$field = (string) $field;
	$key   = '_ld_seo_' . $field;
	$val   = get_term_meta( (int) $term_id, $key, true );

	if ( '_ld_seo_robots' === $key ) {
		return is_array( $val ) ? array_values( array_filter( array_map( 'sanitize_key', $val ) ) ) : array();
	}
	return is_string( $val ) ? $val : '';
}

/**
 * Register term meta on every eligible taxonomy so the values are
 * exposed via REST and can be sanitised uniformly. The auth callback
 * mirrors the post-meta setup — only editors and above can write.
 */
function livingdraft_seo_term_register_meta() {
	$auth = function ( $allowed, $meta_key, $term_id, $user_id, $cap, $caps ) {
		return current_user_can( 'manage_categories' );
	};

	$string_fields = array(
		'_ld_seo_title'          => 'string',
		'_ld_seo_description'    => 'string',
		'_ld_seo_focus_keyword'  => 'string',
		'_ld_seo_canonical'      => 'string',
		'_ld_seo_og_title'       => 'string',
		'_ld_seo_og_description' => 'string',
		'_ld_seo_og_image'       => 'string',
	);

	foreach ( livingdraft_seo_term_taxonomies() as $taxonomy ) {
		foreach ( $string_fields as $key => $type ) {
			register_term_meta( $taxonomy, $key, array(
				'type'              => $type,
				'single'            => true,
				'sanitize_callback' => '_ld_seo_canonical' === $key || '_ld_seo_og_image' === $key ? 'esc_url_raw' : 'sanitize_text_field',
				'show_in_rest'      => true,
				'auth_callback'     => $auth,
			) );
		}
		register_term_meta( $taxonomy, '_ld_seo_robots', array(
			'type'              => 'array',
			'single'            => true,
			'show_in_rest'      => array(
				'schema' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'sanitize_callback' => function ( $v ) {
				if ( ! is_array( $v ) ) { return array(); }
				// v3.2.1: added noimageindex for parity with the post metabox.
				$allowed = array( 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet' );
				$out = array();
				foreach ( $v as $d ) {
					$d = sanitize_key( $d );
					if ( in_array( $d, $allowed, true ) ) { $out[] = $d; }
				}
				return array_values( array_unique( $out ) );
			},
			'auth_callback' => $auth,
		) );
	}
}
add_action( 'init', 'livingdraft_seo_term_register_meta', 20 );

/**
 * Hook into every eligible taxonomy's admin screens. We do this on
 * admin_init so get_taxonomies() has settled and we don't add hooks
 * for a taxonomy that later gets unregistered.
 */
function livingdraft_seo_term_add_hooks() {
	if ( ! is_admin() ) {
		return;
	}
	foreach ( livingdraft_seo_term_taxonomies() as $taxonomy ) {
		add_action( $taxonomy . '_edit_form',        'livingdraft_seo_term_render_edit', 20 );
		add_action( $taxonomy . '_add_form_fields',  'livingdraft_seo_term_render_add',  20 );
		add_action( 'edited_' . $taxonomy,           'livingdraft_seo_term_save',        20 );
		add_action( 'created_' . $taxonomy,          'livingdraft_seo_term_save',        20 );
	}
}
add_action( 'admin_init', 'livingdraft_seo_term_add_hooks' );

/**
 * v3.2.1: register (but don't enqueue automatically) the SEO metabox CSS
 * on term edit screens. The render function calls wp_print_styles() to
 * emit it inline when the term SEO panel is actually being rendered —
 * this avoids loading the stylesheet on every taxonomy admin page.
 *
 * The stylesheet is the same one the post metabox uses; every rule is
 * scoped under `.ld-seo-metabox`, so it can't leak into WP's own term
 * form styles.
 */
function livingdraft_seo_term_register_assets( $hook ) {
	// term.php = edit-tag screen, edit-tags.php = list + inline-add screen.
	// Both can render our fields, so we register on both.
	if ( ! in_array( $hook, array( 'term.php', 'edit-tags.php' ), true ) ) {
		return;
	}
	$css_rel = 'assets/css/seo-metabox.css';
	$css_abs = LIVINGDRAFT_CORE_DIR . $css_rel;
	if ( file_exists( $css_abs ) ) {
		wp_register_style(
			'livingdraft-seo-metabox',
			LIVINGDRAFT_CORE_URL . $css_rel,
			array(),
			filemtime( $css_abs )
		);
	}
}
add_action( 'admin_enqueue_scripts', 'livingdraft_seo_term_register_assets' );

/**
 * Save handler — one function for both edit and create. Nonce is
 * only required for the edit form (WP's inline add-form doesn't get
 * one automatically for extra fields), so we check for it and skip
 * verification on create when absent.
 */
function livingdraft_seo_term_save( $term_id ) {
	if ( ! current_user_can( 'manage_categories' ) ) {
		return;
	}
	if ( ! isset( $_POST['livingdraft_seo_term_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['livingdraft_seo_term_nonce'] ) ), 'livingdraft_seo_term' ) ) {
		return;
	}

	$in = wp_unslash( $_POST );

	$string_fields = array(
		'title'          => 'sanitize_text_field',
		'description'    => 'sanitize_text_field',
		'focus_keyword'  => 'sanitize_text_field',
		'canonical'      => 'esc_url_raw',
		'og_title'       => 'sanitize_text_field',
		'og_description' => 'sanitize_text_field',
		'og_image'       => 'esc_url_raw',
	);
	foreach ( $string_fields as $field => $sanitiser ) {
		$post_key = 'ld_seo_' . $field;
		$val = isset( $in[ $post_key ] ) ? call_user_func( $sanitiser, (string) $in[ $post_key ] ) : '';
		if ( '' === $val ) {
			delete_term_meta( $term_id, '_ld_seo_' . $field );
		} else {
			update_term_meta( $term_id, '_ld_seo_' . $field, $val );
		}
	}

	// Robots — array of directive checkboxes.
	// v3.2.1: added noimageindex for parity with the post metabox.
	$robots  = array();
	$allowed = array( 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet' );
	if ( isset( $in['ld_seo_robots'] ) && is_array( $in['ld_seo_robots'] ) ) {
		foreach ( $in['ld_seo_robots'] as $d ) {
			$d = sanitize_key( $d );
			if ( in_array( $d, $allowed, true ) ) { $robots[] = $d; }
		}
	}
	if ( empty( $robots ) ) {
		delete_term_meta( $term_id, '_ld_seo_robots' );
	} else {
		update_term_meta( $term_id, '_ld_seo_robots', array_values( array_unique( $robots ) ) );
	}
}

/**
 * Render on the term EDIT screen.
 *
 * v3.2.1: rebuilt for parity with the post SEO metabox.
 *   - Model picker (uses whichever provider/model the writer prefers)
 *   - Live SERP preview updates as the user types
 *   - AI "Generate" buttons on SEO title, meta description, focus keyword
 *   - Live character counters with color-coded ranges
 *   - Uses the same .ld-seo-* class names as the post metabox so the
 *     sidebar-metabox CSS also skins this screen — no design drift.
 */
function livingdraft_seo_term_render_edit( $term ) {
	$term_id     = (int) $term->term_id;
	$title       = livingdraft_seo_term_get( 'title', $term_id );
	$desc        = livingdraft_seo_term_get( 'description', $term_id );
	$keyword     = livingdraft_seo_term_get( 'focus_keyword', $term_id );
	$canonical   = livingdraft_seo_term_get( 'canonical', $term_id );
	$og_title    = livingdraft_seo_term_get( 'og_title', $term_id );
	$og_desc     = livingdraft_seo_term_get( 'og_description', $term_id );
	$og_image    = livingdraft_seo_term_get( 'og_image', $term_id );
	$robots      = livingdraft_seo_term_get( 'robots', $term_id );

	// Preview fallbacks — what the front-end will actually emit if
	// the operator leaves the fields empty.
	$preview_title = $title ?: $term->name;
	$preview_desc  = $desc ?: wp_trim_words( wp_strip_all_tags( (string) $term->description ), 30 );
	$preview_url   = $canonical ?: get_term_link( $term );
	if ( is_wp_error( $preview_url ) ) {
		$preview_url = home_url( '/' );
	}
	$site_name = get_bloginfo( 'name' );

	// Is the AI provider layer configured with a usable key? Same helper
	// the post metabox uses.
	$ai_ready = function_exists( 'livingdraft_seo_ai_ready' ) ? livingdraft_seo_ai_ready() : false;

	// Enqueue the SEO metabox CSS on this screen too — that stylesheet
	// scopes everything under .ld-seo-metabox, so it's safe to load
	// alongside WP's term-edit styles and it gives us the SERP preview,
	// AI button, character counter, and slug-preview look-and-feel for
	// free. Enqueued inline rather than through admin_enqueue_scripts
	// because the taxonomy edit screen fires late and we're already in
	// its output; a matching enqueue hook is registered in
	// livingdraft_seo_term_enqueue() below for the model-picker JS.
	if ( wp_style_is( 'livingdraft-seo-metabox', 'registered' ) && ! wp_style_is( 'livingdraft-seo-metabox', 'done' ) ) {
		wp_print_styles( array( 'livingdraft-seo-metabox' ) );
	}
	?>
	<h2 style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #dcdcde;">
		<?php esc_html_e( 'SEO — The Living Draft', 'livingdraft-core' ); ?>
	</h2>
	<p class="description" style="margin-bottom: 16px;">
		<?php esc_html_e( 'Overrides for the search engine and social share appearance of this archive page. Every field is optional — leave blank to use sensible defaults.', 'livingdraft-core' ); ?>
	</p>

	<div class="ld-seo-metabox" style="max-width: 780px;">

		<?php
		wp_nonce_field( 'livingdraft_seo_term', 'livingdraft_seo_term_nonce' );

		// v3.5.0: AI slug suggestion. Targets the NATIVE WordPress term
		// slug field (#slug, rendered by WP above our SEO section) rather
		// than one of our own postmeta-backed fields. Same button style
		// as the meta-title / meta-description generators; the JS on this
		// page picks it up via the data-ld-term-ai-task attribute.
		if ( $ai_ready ) : ?>
			<div class="ld-seo-field" style="margin-top:14px">
				<div style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:#fafaf7;border:1px solid #e5e5e5">
					<div style="flex:1">
						<strong style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:var(--tld-ink-3, #666)"><?php esc_html_e( 'URL slug', 'livingdraft-core' ); ?></strong>
						<div style="font-size:11px;color:var(--tld-ink-3, #666);margin-top:2px"><?php esc_html_e( 'Suggest a slug for this archive and paste it into the Slug field above.', 'livingdraft-core' ); ?></div>
					</div>
					<button type="button" class="ld-seo-ai-btn"
						data-ld-term-ai-task="seo_slug"
						data-ld-term-ai-target="slug"
						title="<?php esc_attr_e( 'Suggest a URL slug with AI', 'livingdraft-core' ); ?>">
						<span class="ld-seo-ai-glyph">✦</span> <?php esc_html_e( 'Suggest slug', 'livingdraft-core' ); ?>
					</button>
				</div>
			</div>
		<?php endif; ?>

		<?php
		// Model picker: sets the model used by every AI button on this term.
		// Persists per user via localStorage using a term-scoped key so the
		// choice can differ from the post metabox if the writer wants.
		if ( $ai_ready && function_exists( 'livingdraft_ai_render_model_picker' ) ) : ?>
			<div style="display:flex;justify-content:flex-end;margin-bottom:8px">
				<?php livingdraft_ai_render_model_picker( array(
					'label'       => __( 'Model', 'livingdraft-core' ),
					'storage_key' => 'livingdraft.ai.model.term',
				) ); ?>
			</div>
		<?php endif; ?>

		<!-- Live SERP preview. Updates as the user edits the fields
		     below via the inline JS at the bottom of this function. -->
		<div class="ld-seo-serp" aria-label="<?php esc_attr_e( 'Google search preview', 'livingdraft-core' ); ?>">
			<div class="ld-seo-serp-url" data-ld-serp-url><?php echo esc_html( $preview_url ); ?></div>
			<div class="ld-seo-serp-title" data-ld-serp-title><?php echo esc_html( $preview_title ); ?><?php echo esc_html( ' | ' . $site_name ); ?></div>
			<div class="ld-seo-serp-desc" data-ld-serp-desc><?php echo esc_html( $preview_desc ?: __( '(No description yet — will fall back to term description or auto-summary.)', 'livingdraft-core' ) ); ?></div>
		</div>

		<!-- Fields -->
		<div class="ld-seo-fields">

			<div class="ld-seo-field">
				<label for="ld_seo_focus_keyword"><?php esc_html_e( 'Focus keyword', 'livingdraft-core' ); ?></label>
				<div class="ld-seo-input-row">
					<input type="text" id="ld_seo_focus_keyword" name="ld_seo_focus_keyword"
						class="ld-seo-input"
						value="<?php echo esc_attr( $keyword ); ?>"
						maxlength="120"
						placeholder="<?php esc_attr_e( 'e.g. mumbai monsoon flooding', 'livingdraft-core' ); ?>">
					<?php if ( $ai_ready ) : ?>
						<button type="button" class="ld-seo-ai-btn"
							data-ld-term-ai-task="focus_keyword"
							data-ld-term-ai-target="ld_seo_focus_keyword"
							title="<?php esc_attr_e( 'Suggest with AI', 'livingdraft-core' ); ?>">
							<span class="ld-seo-ai-glyph">✦</span> <?php esc_html_e( 'Suggest', 'livingdraft-core' ); ?>
						</button>
					<?php endif; ?>
				</div>
				<p class="ld-seo-help"><?php esc_html_e( 'The one search phrase this archive should best rank for. Used as context when the AI generates the title and description.', 'livingdraft-core' ); ?></p>
			</div>

			<div class="ld-seo-field">
				<label for="ld_seo_title"><?php esc_html_e( 'SEO title', 'livingdraft-core' ); ?>
					<span class="ld-seo-counter" data-ld-counter="ld_seo_title" data-ld-min="30" data-ld-max="60"></span>
				</label>
				<div class="ld-seo-input-row">
					<input type="text" id="ld_seo_title" name="ld_seo_title"
						class="ld-seo-input"
						value="<?php echo esc_attr( $title ); ?>"
						maxlength="120"
						placeholder="<?php echo esc_attr( $term->name ); ?>">
					<?php if ( $ai_ready ) : ?>
						<button type="button" class="ld-seo-ai-btn"
							data-ld-term-ai-task="seo_title"
							data-ld-term-ai-target="ld_seo_title">
							<span class="ld-seo-ai-glyph">✦</span> <?php esc_html_e( 'Generate', 'livingdraft-core' ); ?>
						</button>
					<?php endif; ?>
				</div>
				<p class="ld-seo-help"><?php esc_html_e( 'What appears in the browser tab and Google results. Aim for 50–60 characters. Site name is appended automatically.', 'livingdraft-core' ); ?></p>
			</div>

			<div class="ld-seo-field">
				<label for="ld_seo_description"><?php esc_html_e( 'Meta description', 'livingdraft-core' ); ?>
					<span class="ld-seo-counter" data-ld-counter="ld_seo_description" data-ld-min="140" data-ld-max="160"></span>
				</label>
				<div class="ld-seo-input-row">
					<textarea id="ld_seo_description" name="ld_seo_description"
						class="ld-seo-input ld-seo-textarea"
						rows="3"
						maxlength="300"
						placeholder="<?php esc_attr_e( 'One sentence describing what this archive covers. Written for a reader deciding whether to click.', 'livingdraft-core' ); ?>"><?php echo esc_textarea( $desc ); ?></textarea>
					<?php if ( $ai_ready ) : ?>
						<button type="button" class="ld-seo-ai-btn ld-seo-ai-btn-tall"
							data-ld-term-ai-task="meta_description"
							data-ld-term-ai-target="ld_seo_description">
							<span class="ld-seo-ai-glyph">✦</span> <?php esc_html_e( 'Generate', 'livingdraft-core' ); ?>
						</button>
					<?php endif; ?>
				</div>
				<p class="ld-seo-help"><?php esc_html_e( '140–160 characters is the sweet spot before Google truncates.', 'livingdraft-core' ); ?></p>
			</div>

			<details class="ld-seo-advanced">
				<summary><?php esc_html_e( 'Advanced: canonical, robots, social sharing', 'livingdraft-core' ); ?></summary>

				<div class="ld-seo-field">
					<label for="ld_seo_canonical"><?php esc_html_e( 'Canonical URL', 'livingdraft-core' ); ?></label>
					<input type="url" id="ld_seo_canonical" name="ld_seo_canonical"
						class="ld-seo-input is-mono"
						value="<?php echo esc_attr( $canonical ); ?>"
						placeholder="<?php echo esc_attr( is_wp_error( get_term_link( $term ) ) ? '' : get_term_link( $term ) ); ?>">
					<p class="ld-seo-help"><?php esc_html_e( 'Only set this if the same archive lives at another URL that should get the credit.', 'livingdraft-core' ); ?></p>
				</div>

				<div class="ld-seo-field">
					<label><?php esc_html_e( 'Search engine robots', 'livingdraft-core' ); ?></label>
					<div class="ld-seo-robots">
						<?php
						$directives = array(
							'noindex'      => __( 'Hide from search results (noindex)', 'livingdraft-core' ),
							'nofollow'     => __( 'Don\'t follow links from this archive (nofollow)', 'livingdraft-core' ),
							'noarchive'    => __( 'No cached copy (noarchive)', 'livingdraft-core' ),
							'noimageindex' => __( 'Don\'t index images (noimageindex)', 'livingdraft-core' ),
							'nosnippet'    => __( 'No snippet in results (nosnippet)', 'livingdraft-core' ),
						);
						foreach ( $directives as $val => $lab ) : ?>
							<label style="display:block;margin:4px 0">
								<input type="checkbox" name="ld_seo_robots[]" value="<?php echo esc_attr( $val ); ?>" <?php checked( in_array( $val, (array) $robots, true ) ); ?>>
								<?php echo esc_html( $lab ); ?>
							</label>
						<?php endforeach; ?>
					</div>
					<p class="ld-seo-help"><?php esc_html_e( 'Thin tag archives are often best set to noindex to keep them out of search.', 'livingdraft-core' ); ?></p>
				</div>

				<div class="ld-seo-field">
					<label for="ld_seo_og_title"><?php esc_html_e( 'Social sharing title (og:title)', 'livingdraft-core' ); ?></label>
					<input type="text" id="ld_seo_og_title" name="ld_seo_og_title"
						class="ld-seo-input" value="<?php echo esc_attr( $og_title ); ?>"
						maxlength="120"
						placeholder="<?php esc_attr_e( 'Falls back to SEO title if empty', 'livingdraft-core' ); ?>">
				</div>

				<div class="ld-seo-field">
					<label for="ld_seo_og_description"><?php esc_html_e( 'Social sharing description', 'livingdraft-core' ); ?></label>
					<textarea id="ld_seo_og_description" name="ld_seo_og_description"
						class="ld-seo-input ld-seo-textarea" rows="2"
						maxlength="300"
						placeholder="<?php esc_attr_e( 'Falls back to meta description if empty', 'livingdraft-core' ); ?>"><?php echo esc_textarea( $og_desc ); ?></textarea>
				</div>

				<div class="ld-seo-field">
					<label for="ld_seo_og_image"><?php esc_html_e( 'Social sharing image URL', 'livingdraft-core' ); ?></label>
					<input type="url" id="ld_seo_og_image" name="ld_seo_og_image"
						class="ld-seo-input is-mono" value="<?php echo esc_attr( $og_image ); ?>"
						placeholder="https://…">
					<p class="ld-seo-help"><?php esc_html_e( 'Ideal 1200×630. Paste a URL from your Media Library.', 'livingdraft-core' ); ?></p>
					<?php if ( $og_image ) : ?>
						<div style="margin-top:10px;">
							<img src="<?php echo esc_url( $og_image ); ?>" alt="" style="max-width:300px;height:auto;border:1px solid #dcdcde;">
						</div>
					<?php endif; ?>
				</div>
			</details>
		</div>

	</div>

	<script>
	( function () {
		'use strict';

		var termCtx = {
			termId:       <?php echo (int) $term_id; ?>,
			taxonomy:     <?php echo wp_json_encode( $term->taxonomy ); ?>,
			termName:     <?php echo wp_json_encode( $term->name ); ?>,
			permalink:    <?php echo wp_json_encode( $preview_url ); ?>,
			siteName:     <?php echo wp_json_encode( $site_name ); ?>,
			ajaxUrl:      <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
			aiNonce:      <?php echo wp_json_encode( wp_create_nonce( 'ld_seo_term_ai' ) ); ?>,
			aiReady:      <?php echo $ai_ready ? 'true' : 'false'; ?>,
			modelStorage: 'livingdraft.ai.model.term',
			strings: {
				aiWorking: <?php echo wp_json_encode( __( 'Generating…', 'livingdraft-core' ) ); ?>,
				aiFailed:  <?php echo wp_json_encode( __( 'AI request failed.', 'livingdraft-core' ) ); ?>,
				chars:     <?php echo wp_json_encode( __( 'chars', 'livingdraft-core' ) ); ?>
			}
		};

		var $ = function ( sel ) { return document.querySelector( sel ); };

		var titleEl = $( '#ld_seo_title' );
		var descEl  = $( '#ld_seo_description' );
		var kwEl    = $( '#ld_seo_focus_keyword' );

		var serpTitle = $( '[data-ld-serp-title]' );
		var serpDesc  = $( '[data-ld-serp-desc]' );

		// --- Live SERP preview --------------------------------------
		function renderPreview() {
			var t = ( titleEl && titleEl.value.trim() ) || termCtx.termName;
			var d = ( descEl && descEl.value.trim() ) || '';
			if ( serpTitle ) serpTitle.textContent = t + ' | ' + termCtx.siteName;
			if ( serpDesc ) {
				serpDesc.textContent = d || <?php echo wp_json_encode( __( '(No description yet — will fall back to term description or auto-summary.)', 'livingdraft-core' ) ); ?>;
			}
		}

		// --- Character counters (color-coded to ideal range) --------
		function renderCounters() {
			document.querySelectorAll( '[data-ld-counter]' ).forEach( function ( span ) {
				var targetId = span.getAttribute( 'data-ld-counter' );
				var target   = document.getElementById( targetId );
				if ( ! target ) return;
				var len = ( target.value || '' ).length;
				var min = parseInt( span.getAttribute( 'data-ld-min' ), 10 ) || 0;
				var max = parseInt( span.getAttribute( 'data-ld-max' ), 10 ) || 999;
				span.textContent = ' · ' + len + ' ' + termCtx.strings.chars;
				span.style.fontFamily   = '"IBM Plex Mono", monospace';
				span.style.fontSize     = '10px';
				span.style.marginLeft   = '6px';
				span.style.fontWeight   = '500';
				span.style.color        = ( len >= min && len <= max ) ? '#2f7a3a'
					: ( len > 0 && ( len < min * 0.8 || len > max * 1.15 ) ) ? '#a32e2e'
					: '#b7791f';
			} );
		}

		// --- Read the model override the picker stores in localStorage.
		// Same wire format the ai-provider reads: "provider:model".
		function currentModel() {
			try {
				return window.localStorage.getItem( termCtx.modelStorage ) || '';
			} catch ( e ) { return ''; }
		}

		// --- AI button dispatch -------------------------------------
		function runAI( btn ) {
			if ( ! termCtx.aiReady ) return;
			var task    = btn.getAttribute( 'data-ld-term-ai-task' );
			var target  = document.getElementById( btn.getAttribute( 'data-ld-term-ai-target' ) );
			if ( ! task || ! target ) return;

			var origLabel = btn.innerHTML;
			btn.disabled  = true;
			btn.innerHTML = '<span class="ld-seo-ai-glyph">✦</span> ' + termCtx.strings.aiWorking;

			var body = new FormData();
			body.append( 'action',    'ld_seo_term_ai' );
			body.append( 'nonce',     termCtx.aiNonce );
			body.append( 'term_id',   String( termCtx.termId ) );
			body.append( 'taxonomy',  termCtx.taxonomy );
			body.append( 'task',      task );
			body.append( 'title',     titleEl ? titleEl.value : '' );
			body.append( 'keyword',   kwEl ? kwEl.value : '' );
			body.append( 'existing',  target.value || '' );
			var m = currentModel();
			if ( m ) body.append( 'ai_model', m );

			fetch( termCtx.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( json ) {
					if ( json && json.success && json.data && json.data.text ) {
						target.value = json.data.text;
						target.dispatchEvent( new Event( 'input', { bubbles: true } ) );
					} else {
						var msg = ( json && json.data && json.data.message ) ? json.data.message : termCtx.strings.aiFailed;
						alert( msg );
					}
				} )
				.catch( function () { alert( termCtx.strings.aiFailed ); } )
				.finally( function () {
					btn.disabled  = false;
					btn.innerHTML = origLabel;
				} );
		}

		// --- Wire it all up ------------------------------------------
		document.querySelectorAll( '[data-ld-term-ai-task]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () { runAI( btn ); } );
		} );

		[ titleEl, descEl, kwEl ].forEach( function ( el ) {
			if ( ! el ) return;
			el.addEventListener( 'input', function () { renderPreview(); renderCounters(); } );
		} );

		renderPreview();
		renderCounters();
	} )();
	</script>
	<?php
}

/**
 * Render on the term ADD-NEW screen — compact form-field layout,
 * fewer fields (just the essentials, which the operator can flesh
 * out later on the edit screen).
 */
function livingdraft_seo_term_render_add() {
	?>
	<h2 style="margin-top:30px;"><?php esc_html_e( 'SEO — The Living Draft', 'livingdraft-core' ); ?></h2>
	<p class="description" style="margin-bottom:10px;"><?php esc_html_e( 'Optional — you can also set these later by editing this term.', 'livingdraft-core' ); ?></p>

	<?php wp_nonce_field( 'livingdraft_seo_term', 'livingdraft_seo_term_nonce' ); ?>

	<div class="form-field">
		<label for="ld_seo_title"><?php esc_html_e( 'SEO title', 'livingdraft-core' ); ?></label>
		<input name="ld_seo_title" id="ld_seo_title" type="text" value="" maxlength="120">
	</div>
	<div class="form-field">
		<label for="ld_seo_description"><?php esc_html_e( 'Meta description', 'livingdraft-core' ); ?></label>
		<textarea name="ld_seo_description" id="ld_seo_description" rows="3" maxlength="300"></textarea>
	</div>
	<div class="form-field">
		<label><input type="checkbox" name="ld_seo_robots[]" value="noindex"> <?php esc_html_e( 'noindex — keep this archive out of search results', 'livingdraft-core' ); ?></label>
	</div>
	<?php
}
