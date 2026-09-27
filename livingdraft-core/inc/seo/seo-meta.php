<?php
/**
 * The per-post SEO panel.
 *
 * This is the surface an editor sees every time they write. SEO title,
 * meta description, focus keyword, canonical, Open Graph, robots — all
 * in one metabox. Live SERP preview at the top so a writer can see what
 * Google will actually show. Live checklist below the fields so mistakes
 * get caught before publish.
 *
 * === WHY OUR OWN META KEYS ===
 *
 * Storing SEO data under Rank Math's own meta keys (rank_math_title etc.)
 * would let the switch happen without a data-import step, but it also
 * would mean uninstalling this plugin doesn't cleanly hand back to Rank
 * Math and vice versa. Two SEO plugins fighting for the same postmeta
 * row is worse than a migration.
 *
 * So: this plugin uses `_ld_seo_*`. On READ, if a field is empty we look
 * for the Rank Math key as fallback, which makes an installation with
 * Rank Math still active behave sensibly and gives us a natural
 * migration path (users see Rank Math values in the field, edit them,
 * save into our namespace).
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------
 * 1. META KEYS + READ HELPERS
 * ------------------------------------------------------------------ */

/**
 * The meta keys this plugin owns, mapped to the Rank Math key we fall
 * back on when ours is empty. Keeping this in one array means an editor
 * looking at Rank Math data during migration doesn't have to touch every
 * function; the fallback logic lives in one place.
 */
function livingdraft_seo_meta_map() {
	return array(
		'_ld_seo_title'             => 'rank_math_title',
		'_ld_seo_description'       => 'rank_math_description',
		'_ld_seo_focus_keyword'     => 'rank_math_focus_keyword',
		'_ld_seo_canonical'         => 'rank_math_canonical_url',
		'_ld_seo_og_title'          => 'rank_math_facebook_title',
		'_ld_seo_og_description'    => 'rank_math_facebook_description',
		'_ld_seo_og_image'          => 'rank_math_facebook_image',
		'_ld_seo_robots'            => 'rank_math_robots',
		// v3.3.0: Twitter Card fields, separate from OG.
		'_ld_seo_twitter_title'      => 'rank_math_twitter_title',
		'_ld_seo_twitter_description' => 'rank_math_twitter_description',
		'_ld_seo_twitter_image'      => 'rank_math_twitter_image',
	);
}

/**
 * v3.3.0: Yoast fallback map. Second-line fallback: our key → Rank
 * Math key (from map above) → Yoast key (from this map) → empty.
 *
 * Yoast splits some things into more fields than we do. For example,
 * Yoast stores `_yoast_wpseo_meta-robots-noindex` (1 = noindex) and
 * `_yoast_wpseo_meta-robots-nofollow` as separate rows; we normalize
 * those into our combined `_ld_seo_robots` array in the reader below
 * rather than here.
 */
function livingdraft_seo_yoast_meta_map() {
	return array(
		'_ld_seo_title'             => '_yoast_wpseo_title',
		'_ld_seo_description'       => '_yoast_wpseo_metadesc',
		'_ld_seo_focus_keyword'     => '_yoast_wpseo_focuskw',
		'_ld_seo_canonical'         => '_yoast_wpseo_canonical',
		'_ld_seo_og_title'          => '_yoast_wpseo_opengraph-title',
		'_ld_seo_og_description'    => '_yoast_wpseo_opengraph-description',
		'_ld_seo_og_image'          => '_yoast_wpseo_opengraph-image',
		'_ld_seo_twitter_title'     => '_yoast_wpseo_twitter-title',
		'_ld_seo_twitter_description' => '_yoast_wpseo_twitter-description',
		'_ld_seo_twitter_image'     => '_yoast_wpseo_twitter-image',
	);
}

/**
 * Read a stored SEO value, chaining fallbacks:
 *   our key → Rank Math key → Yoast key → empty
 *
 * The chain means a site migrating from Yoast (via Rank Math or
 * directly) sees its old values in the metabox on day one, before
 * ever editing anything. First save under our namespace wins from
 * then on.
 *
 * v3.3.0: added Yoast as the third-line fallback.
 *
 * @param string $field   Field slug without prefix: title, description, ...
 * @param int    $post_id Post ID.
 * @return string|array   Array for robots, string otherwise. Always
 *                        the shape callers expect — no null returns.
 */
function livingdraft_seo_get( $field, $post_id ) {
	$map       = livingdraft_seo_meta_map();
	$yoast_map = livingdraft_seo_yoast_meta_map();
	$our       = '_ld_seo_' . $field;

	if ( ! isset( $map[ $our ] ) && ! isset( $yoast_map[ $our ] ) && '_ld_seo_robots' !== $our ) {
		return '';
	}

	$value = get_post_meta( $post_id, $our, true );

	// Robots — array — special-cased because Yoast stores each
	// directive under its own meta key rather than as one array.
	if ( '_ld_seo_robots' === $our ) {
		if ( is_array( $value ) && ! empty( $value ) ) {
			return $value;
		}
		// Rank Math falls back to their array.
		if ( isset( $map[ $our ] ) ) {
			$fallback = get_post_meta( $post_id, $map[ $our ], true );
			if ( is_array( $fallback ) && ! empty( $fallback ) ) {
				return $fallback;
			}
		}
		// Yoast: reconstruct our array from per-directive keys.
		$reconstructed = array();
		$yoast_flags = array(
			'noindex'      => '_yoast_wpseo_meta-robots-noindex',
			'nofollow'     => '_yoast_wpseo_meta-robots-nofollow',
			// Yoast stores extras as a comma list in one key.
		);
		foreach ( $yoast_flags as $directive => $key ) {
			$flag = get_post_meta( $post_id, $key, true );
			// Yoast noindex uses '1' for noindex, '2' for index, '0' for default.
			// nofollow uses '1' for nofollow, '0' for default.
			if ( 'noindex' === $directive && '1' === (string) $flag ) {
				$reconstructed[] = 'noindex';
			}
			if ( 'nofollow' === $directive && '1' === (string) $flag ) {
				$reconstructed[] = 'nofollow';
			}
		}
		$advanced = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-adv', true );
		if ( is_string( $advanced ) && '' !== $advanced ) {
			foreach ( explode( ',', $advanced ) as $d ) {
				$d = sanitize_key( trim( $d ) );
				if ( in_array( $d, array( 'noarchive', 'noimageindex', 'nosnippet' ), true ) ) {
					$reconstructed[] = $d;
				}
			}
		}
		return array_values( array_unique( $reconstructed ) );
	}

	// String fields — return ours if set, else Rank Math, else Yoast.
	if ( '' !== $value && null !== $value ) {
		return (string) $value;
	}

	if ( isset( $map[ $our ] ) ) {
		$rm = get_post_meta( $post_id, $map[ $our ], true );
		if ( is_string( $rm ) && '' !== $rm ) {
			return $rm;
		}
	}

	if ( isset( $yoast_map[ $our ] ) ) {
		$yo = get_post_meta( $post_id, $yoast_map[ $our ], true );
		if ( is_string( $yo ) && '' !== $yo ) {
			return $yo;
		}
	}

	return '';
}

/* ------------------------------------------------------------------
 * 2. META REGISTRATION (so REST can read them)
 * ------------------------------------------------------------------ */

function livingdraft_seo_register_meta() {
	$string_fields = array(
		'_ld_seo_title'          => 'string',
		'_ld_seo_description'    => 'string',
		'_ld_seo_focus_keyword'  => 'string',
		'_ld_seo_canonical'      => 'string',
		'_ld_seo_og_title'       => 'string',
		'_ld_seo_og_description' => 'string',
		'_ld_seo_og_image'       => 'string',
		// v3.3.0: separate Twitter Card fields, distinct from OG.
		'_ld_seo_twitter_title'       => 'string',
		'_ld_seo_twitter_description' => 'string',
		'_ld_seo_twitter_image'       => 'string',
	);

	foreach ( array( 'post', 'page' ) as $post_type ) {
		foreach ( $string_fields as $key => $type ) {
			register_post_meta(
				$post_type,
				$key,
				array(
					'type'              => $type,
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			);
		}

		register_post_meta(
			$post_type,
			'_ld_seo_robots',
			array(
				'type'         => 'array',
				'single'       => true,
				'show_in_rest' => array(
					'schema' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				),
				'auth_callback' => function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);
	}
}
add_action( 'init', 'livingdraft_seo_register_meta' );

/* ------------------------------------------------------------------
 * 3. THE METABOX
 * ------------------------------------------------------------------ */

function livingdraft_seo_add_metabox() {
	foreach ( array( 'post', 'page' ) as $post_type ) {
		add_meta_box(
			'livingdraft-seo',
			__( 'SEO', 'livingdraft-core' ),
			'livingdraft_seo_render_metabox',
			$post_type,
			// v3.1: moved from 'normal' to 'side'. The SEO panel, including
			// the AI-powered meta-description recommendation, now lives in
			// the post sidebar (right column) so it's visible while writing
			// instead of buried below the content. Sidebar-specific layout
			// rules live in the .ld-seo-metabox scoped CSS.
			'side',
			'high',
			array( '__block_editor_compatible_meta_box' => true )
		);
	}
}
add_action( 'add_meta_boxes', 'livingdraft_seo_add_metabox' );

/**
 * Render the metabox. Keeps its own JS/CSS inline because it is
 * self-contained and needs to see the wp_localize_script data via a
 * simpler bridge than the enqueue pipeline.
 */
function livingdraft_seo_render_metabox( $post ) {
	wp_nonce_field( 'livingdraft_seo_save', 'livingdraft_seo_nonce' );

	$title     = livingdraft_seo_get( 'title', $post->ID );
	$desc      = livingdraft_seo_get( 'description', $post->ID );
	$keyword   = livingdraft_seo_get( 'focus_keyword', $post->ID );
	$canonical = livingdraft_seo_get( 'canonical', $post->ID );
	$og_title  = livingdraft_seo_get( 'og_title', $post->ID );
	$og_desc   = livingdraft_seo_get( 'og_description', $post->ID );
	$og_image  = livingdraft_seo_get( 'og_image', $post->ID );
	// v3.3.0: separate Twitter Card fields, distinct from OG.
	$tw_title  = livingdraft_seo_get( 'twitter_title', $post->ID );
	$tw_desc   = livingdraft_seo_get( 'twitter_description', $post->ID );
	$tw_image  = livingdraft_seo_get( 'twitter_image', $post->ID );
	$robots    = livingdraft_seo_get( 'robots', $post->ID );
	if ( ! is_array( $robots ) ) {
		$robots = array();
	}

	$permalink = get_permalink( $post );
	$site_name = get_bloginfo( 'name' );

	// Fallback preview values when the user hasn't filled anything in.
	$display_title = $title ?: get_the_title( $post );
	$display_desc  = $desc ?: wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 30 );

	$ai_ready = livingdraft_seo_ai_ready();
	?>
	<div class="ld-seo-metabox">

		<!-- Model picker: sets the model used by every AI button on
		     this post — Generate + Fix + image alt batch. Persists per
		     user via localStorage, so a writer picks their preferred
		     model once and it sticks across posts. -->
		<?php if ( $ai_ready ) : ?>
			<div style="display:flex;justify-content:flex-end;margin-bottom:8px">
				<?php livingdraft_ai_render_model_picker( array( 'label' => __( 'Model', 'livingdraft-core' ), 'storage_key' => 'livingdraft.ai.model.metabox' ) ); ?>
			</div>
		<?php endif; ?>

		<!-- SERP preview -->
		<div class="ld-seo-serp" aria-label="<?php esc_attr_e( 'Google search preview', 'livingdraft-core' ); ?>">
			<div class="ld-seo-serp-url" data-ld-serp-url><?php echo esc_html( $permalink ); ?></div>
			<div class="ld-seo-serp-title" data-ld-serp-title><?php echo esc_html( $display_title ); ?><?php echo esc_html( ' | ' . $site_name ); ?></div>
			<div class="ld-seo-serp-desc" data-ld-serp-desc><?php echo esc_html( $display_desc ); ?></div>
		</div>

		<!-- Score + checklist -->
		<div class="ld-seo-analysis" data-ld-seo-analysis>
			<div class="ld-seo-score">
				<div class="ld-seo-score-num" data-ld-seo-score>—</div>
				<div class="ld-seo-score-labels">
					<div class="ld-seo-score-title"><?php esc_html_e( 'SEO score', 'livingdraft-core' ); ?></div>
					<div class="ld-seo-score-hint" data-ld-seo-hint><?php esc_html_e( 'Enter a focus keyword to get an analysis.', 'livingdraft-core' ); ?></div>
				</div>
			</div>
			<ul class="ld-seo-checks" data-ld-seo-checks></ul>
		</div>

		<!-- Fields -->
		<div class="ld-seo-fields">

			<div class="ld-seo-field">
				<label for="ld_seo_slug"><?php esc_html_e( 'URL slug', 'livingdraft-core' ); ?></label>
				<div class="ld-seo-input-row">
					<input type="text" id="ld_seo_slug" name="ld_seo[slug]"
						class="ld-seo-input is-mono"
						value="<?php echo esc_attr( $post->post_name ); ?>"
						placeholder="<?php echo esc_attr( sanitize_title( get_the_title( $post ) ) ); ?>">
					<?php if ( $ai_ready ) : ?>
						<button type="button" class="ld-seo-ai-btn"
							data-ld-ai-task="seo_slug"
							data-ld-ai-target="ld_seo_slug"
							title="<?php esc_attr_e( 'Suggest a slug with AI', 'livingdraft-core' ); ?>">
							<span class="ld-seo-ai-glyph">✦</span> <?php esc_html_e( 'Suggest', 'livingdraft-core' ); ?>
						</button>
					<?php endif; ?>
				</div>
				<?php
				// Live URL preview. We split the permalink around the current
				// slug so JS can update just the slug portion as the user
				// types, without needing to reconstruct the whole URL.
				//
				// For unpublished posts, get_permalink returns a preview URL
				// with ?p=ID or /?post_type=…&p=ID, which doesn't contain the
				// slug — in that case we synthesise a plausible pretty URL
				// from the home URL and post type.
				$current_slug = $post->post_name ? $post->post_name : sanitize_title( get_the_title( $post ) );

				// Brand-new posts have no slug and no title yet, so
				// $current_slug is empty. explode() with an empty separator
				// was silently tolerated in PHP 7 but throws ValueError in
				// PHP 8, crashing the whole editor. Guard with a placeholder
				// so the slug preview still renders.
				if ( '' === $current_slug ) {
					$current_slug = 'your-post-slug';
				}

				$full_url     = get_permalink( $post );
				if ( false === strpos( $full_url, $current_slug ) ) {
					$full_url = trailingslashit( home_url() ) . $current_slug . '/';
				}
				$parts = explode( $current_slug, $full_url, 2 );
				?>
				<p class="ld-seo-help ld-seo-slug-preview">
					<span class="ld-seo-slug-prefix"><?php echo esc_html( $parts[0] ); ?></span><span
						class="ld-seo-slug-current" data-ld-slug-mirror><?php echo esc_html( $current_slug ); ?></span><span
						class="ld-seo-slug-suffix"><?php echo esc_html( $parts[1] ?? '' ); ?></span>
				</p>
				<p class="ld-seo-help">
					<?php esc_html_e( 'The part of the URL unique to this post. Changing this on a published post will break any existing links — this plugin will offer to auto-create a 301 redirect from the old URL.', 'livingdraft-core' ); ?>
				</p>
			</div>

			<div class="ld-seo-field">
				<label for="ld_seo_focus_keyword"><?php esc_html_e( 'Focus keyword', 'livingdraft-core' ); ?></label>
				<div class="ld-seo-input-row">
					<input type="text" id="ld_seo_focus_keyword" name="ld_seo[focus_keyword]"
						class="ld-seo-input"
						value="<?php echo esc_attr( $keyword ); ?>"
						placeholder="<?php esc_attr_e( 'e.g. mumbai monsoon flooding', 'livingdraft-core' ); ?>">
					<?php if ( $ai_ready ) : ?>
						<button type="button" class="ld-seo-ai-btn"
							data-ld-ai-task="focus_keyword"
							data-ld-ai-target="ld_seo_focus_keyword"
							title="<?php esc_attr_e( 'Suggest with AI', 'livingdraft-core' ); ?>">
							<span class="ld-seo-ai-glyph">✦</span> <?php esc_html_e( 'Suggest', 'livingdraft-core' ); ?>
						</button>
					<?php endif; ?>
				</div>
				<p class="ld-seo-help"><?php esc_html_e( 'The one phrase you most want this piece to rank for. Analysis below uses it.', 'livingdraft-core' ); ?></p>
			</div>

			<div class="ld-seo-field">
				<label for="ld_seo_title"><?php esc_html_e( 'SEO title', 'livingdraft-core' ); ?>
					<span class="ld-seo-counter" data-ld-counter="ld_seo_title" data-ld-min="30" data-ld-max="60"></span>
				</label>
				<div class="ld-seo-input-row">
					<input type="text" id="ld_seo_title" name="ld_seo[title]"
						class="ld-seo-input"
						value="<?php echo esc_attr( $title ); ?>"
						placeholder="<?php echo esc_attr( get_the_title( $post ) ); ?>">
					<?php if ( $ai_ready ) : ?>
						<button type="button" class="ld-seo-ai-btn"
							data-ld-ai-task="seo_title"
							data-ld-ai-target="ld_seo_title">
							<span class="ld-seo-ai-glyph">✦</span> <?php esc_html_e( 'Generate', 'livingdraft-core' ); ?>
						</button>
					<?php endif; ?>
				</div>
				<p class="ld-seo-help"><?php esc_html_e( 'The clickable line in Google. Aim for 50–60 characters. Site name is appended automatically.', 'livingdraft-core' ); ?></p>
			</div>

			<div class="ld-seo-field">
				<label for="ld_seo_description"><?php esc_html_e( 'Meta description', 'livingdraft-core' ); ?>
					<span class="ld-seo-counter" data-ld-counter="ld_seo_description" data-ld-min="120" data-ld-max="160"></span>
				</label>
				<div class="ld-seo-input-row">
					<textarea id="ld_seo_description" name="ld_seo[description]"
						class="ld-seo-input ld-seo-textarea"
						rows="3"
						placeholder="<?php esc_attr_e( 'One sentence describing this piece. Written for a reader deciding whether to click.', 'livingdraft-core' ); ?>"><?php echo esc_textarea( $desc ); ?></textarea>
					<?php if ( $ai_ready ) : ?>
						<button type="button" class="ld-seo-ai-btn ld-seo-ai-btn-tall"
							data-ld-ai-task="meta_description"
							data-ld-ai-target="ld_seo_description">
							<span class="ld-seo-ai-glyph">✦</span> <?php esc_html_e( 'Generate', 'livingdraft-core' ); ?>
						</button>
					<?php endif; ?>
				</div>
				<p class="ld-seo-help"><?php esc_html_e( '140–160 characters is the sweet spot before Google truncates.', 'livingdraft-core' ); ?></p>
			</div>

			<details class="ld-seo-advanced">
				<summary><?php esc_html_e( 'Advanced: social, canonical, robots', 'livingdraft-core' ); ?></summary>

				<div class="ld-seo-field">
					<label for="ld_seo_canonical"><?php esc_html_e( 'Canonical URL', 'livingdraft-core' ); ?></label>
					<input type="url" id="ld_seo_canonical" name="ld_seo[canonical]"
						class="ld-seo-input" value="<?php echo esc_attr( $canonical ); ?>"
						placeholder="<?php echo esc_attr( $permalink ); ?>">
					<p class="ld-seo-help"><?php esc_html_e( 'Only fill this in if the same content lives at another URL that should get the credit.', 'livingdraft-core' ); ?></p>
				</div>

				<div class="ld-seo-field">
					<label for="ld_seo_og_title"><?php esc_html_e( 'Social sharing title (og:title)', 'livingdraft-core' ); ?></label>
					<input type="text" id="ld_seo_og_title" name="ld_seo[og_title]"
						class="ld-seo-input" value="<?php echo esc_attr( $og_title ); ?>"
						placeholder="<?php esc_attr_e( 'Falls back to SEO title if empty', 'livingdraft-core' ); ?>">
				</div>

				<div class="ld-seo-field">
					<label for="ld_seo_og_description"><?php esc_html_e( 'Social sharing description', 'livingdraft-core' ); ?></label>
					<textarea id="ld_seo_og_description" name="ld_seo[og_description]"
						class="ld-seo-input ld-seo-textarea" rows="2"
						placeholder="<?php esc_attr_e( 'Falls back to meta description if empty', 'livingdraft-core' ); ?>"><?php echo esc_textarea( $og_desc ); ?></textarea>
				</div>

				<div class="ld-seo-field">
					<label for="ld_seo_og_image"><?php esc_html_e( 'Social sharing image URL', 'livingdraft-core' ); ?></label>
					<input type="url" id="ld_seo_og_image" name="ld_seo[og_image]"
						class="ld-seo-input" value="<?php echo esc_attr( $og_image ); ?>"
						placeholder="<?php esc_attr_e( 'Falls back to featured image', 'livingdraft-core' ); ?>">
				</div>

				<!-- v3.3.0: Twitter Card fields, distinct from OG. All three
				     optional; each falls back to its OG counterpart if empty.
				     Kept in one collapsible sub-section so the sidebar isn't
				     overwhelming for writers who don't customise per-platform. -->
				<details class="ld-seo-subsection" style="margin-top:14px">
					<summary style="cursor:pointer;font-weight:500;font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:var(--tld-ink-3, #666)">
						<?php esc_html_e( 'Twitter / X (only if different from Facebook)', 'livingdraft-core' ); ?>
					</summary>
					<div style="padding-top:10px">
						<div class="ld-seo-field">
							<label for="ld_seo_twitter_title"><?php esc_html_e( 'Twitter title', 'livingdraft-core' ); ?></label>
							<input type="text" id="ld_seo_twitter_title" name="ld_seo[twitter_title]"
								class="ld-seo-input" value="<?php echo esc_attr( $tw_title ); ?>"
								maxlength="120"
								placeholder="<?php esc_attr_e( 'Falls back to Facebook / OG title', 'livingdraft-core' ); ?>">
						</div>
						<div class="ld-seo-field">
							<label for="ld_seo_twitter_description"><?php esc_html_e( 'Twitter description', 'livingdraft-core' ); ?></label>
							<textarea id="ld_seo_twitter_description" name="ld_seo[twitter_description]"
								class="ld-seo-input ld-seo-textarea" rows="2"
								maxlength="300"
								placeholder="<?php esc_attr_e( 'Falls back to Facebook / OG description', 'livingdraft-core' ); ?>"><?php echo esc_textarea( $tw_desc ); ?></textarea>
						</div>
						<div class="ld-seo-field">
							<label for="ld_seo_twitter_image"><?php esc_html_e( 'Twitter image URL', 'livingdraft-core' ); ?></label>
							<input type="url" id="ld_seo_twitter_image" name="ld_seo[twitter_image]"
								class="ld-seo-input" value="<?php echo esc_attr( $tw_image ); ?>"
								placeholder="<?php esc_attr_e( 'Falls back to Facebook / OG image', 'livingdraft-core' ); ?>">
						</div>
					</div>
				</details>

				<div class="ld-seo-field">
					<label><?php esc_html_e( 'Robots directives', 'livingdraft-core' ); ?></label>
					<div class="ld-seo-robots">
						<?php
						$directives = array(
							'noindex'      => __( 'Hide from search results (noindex)', 'livingdraft-core' ),
							'nofollow'     => __( 'Don\'t follow links on this page (nofollow)', 'livingdraft-core' ),
							'noarchive'    => __( 'No cached copy (noarchive)', 'livingdraft-core' ),
							'noimageindex' => __( 'Don\'t index images (noimageindex)', 'livingdraft-core' ),
							'nosnippet'    => __( 'No snippet in results (nosnippet)', 'livingdraft-core' ),
						);
						foreach ( $directives as $key => $label ) : ?>
							<label style="display:block;margin:4px 0">
								<input type="checkbox" name="ld_seo[robots][]" value="<?php echo esc_attr( $key ); ?>"
									<?php checked( in_array( $key, $robots, true ) ); ?>>
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
			</details>
		</div>

		<script>
			window.livingdraftSeoContext = {
				postId: <?php echo (int) $post->ID; ?>,
				permalink: <?php echo wp_json_encode( $permalink ); ?>,
				siteName: <?php echo wp_json_encode( $site_name ); ?>,
				postTitle: <?php echo wp_json_encode( get_the_title( $post ) ); ?>,
				ajaxUrl: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
				analyzeNonce: <?php echo wp_json_encode( wp_create_nonce( 'ld_seo_analyze' ) ); ?>,
				aiNonce: <?php echo wp_json_encode( wp_create_nonce( 'ld_seo_ai' ) ); ?>,
				aiReady: <?php echo $ai_ready ? 'true' : 'false'; ?>,
				modelStorageKey: 'livingdraft.ai.model.metabox',
				strings: {
					aiWorking: <?php echo wp_json_encode( __( 'Generating…', 'livingdraft-core' ) ); ?>,
					aiFailed: <?php echo wp_json_encode( __( 'AI request failed.', 'livingdraft-core' ) ); ?>,
					charsUsed: <?php echo wp_json_encode( __( 'characters', 'livingdraft-core' ) ); ?>
				}
			};
		</script>

		<?php
		/**
		 * Fires at the end of the SEO metabox body. Modules that want
		 * to add their own strips (image alts, internal links, etc.)
		 * hook here rather than editing the metabox render function.
		 */
		do_action( 'livingdraft_seo_metabox_after', $post );
		?>
	</div>
	<?php
}

/* ------------------------------------------------------------------
 * 4. SAVE
 * ------------------------------------------------------------------ */

function livingdraft_seo_save( $post_id ) {
	if ( ! isset( $_POST['livingdraft_seo_nonce'] ) ) {
		return;
	}
	$nonce = sanitize_text_field( wp_unslash( $_POST['livingdraft_seo_nonce'] ) );
	if ( ! wp_verify_nonce( $nonce, 'livingdraft_seo_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$posted = isset( $_POST['ld_seo'] ) ? (array) wp_unslash( $_POST['ld_seo'] ) : array();

	// v3.3.0: added twitter_title, twitter_description, twitter_image.
	$text_fields = array(
		'title', 'description', 'focus_keyword', 'canonical',
		'og_title', 'og_description', 'og_image',
		'twitter_title', 'twitter_description', 'twitter_image',
	);

	foreach ( $text_fields as $f ) {
		$val = isset( $posted[ $f ] ) ? sanitize_text_field( $posted[ $f ] ) : '';
		if ( in_array( $f, array( 'canonical', 'og_image', 'twitter_image' ), true ) ) {
			$val = esc_url_raw( $val );
		}
		if ( '' === $val ) {
			delete_post_meta( $post_id, '_ld_seo_' . $f );
		} else {
			update_post_meta( $post_id, '_ld_seo_' . $f, $val );
		}
	}

	$allowed_robots = array( 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet' );
	$robots         = isset( $posted['robots'] ) && is_array( $posted['robots'] )
		? array_values( array_intersect( $allowed_robots, array_map( 'sanitize_key', $posted['robots'] ) ) )
		: array();

	if ( empty( $robots ) ) {
		delete_post_meta( $post_id, '_ld_seo_robots' );
	} else {
		update_post_meta( $post_id, '_ld_seo_robots', $robots );
	}

	// Slug is not postmeta — it's the actual `post_name` column. We
	// update it via wp_update_post, but we're currently INSIDE a
	// save_post callback: calling wp_update_post from here would fire
	// save_post again, recursing forever. Remove our own hook for the
	// duration of the update, then put it back.
	if ( isset( $posted['slug'] ) ) {
		$new_slug    = sanitize_title( (string) $posted['slug'] );
		$post_before = get_post( $post_id );
		if ( $post_before
			&& '' !== $new_slug
			&& $new_slug !== $post_before->post_name
			// Never write slug for revisions or autosaves — save_post
			// on those would be a different post_id anyway, but belt-
			// and-suspenders.
			&& ! wp_is_post_revision( $post_id )
			&& ! wp_is_post_autosave( $post_id ) ) {

			remove_action( 'save_post_post', 'livingdraft_seo_save' );
			remove_action( 'save_post_page', 'livingdraft_seo_save' );

			wp_update_post( array(
				'ID'        => $post_id,
				'post_name' => $new_slug,
			) );

			add_action( 'save_post_post', 'livingdraft_seo_save' );
			add_action( 'save_post_page', 'livingdraft_seo_save' );
		}
	}
}
add_action( 'save_post_post', 'livingdraft_seo_save' );
add_action( 'save_post_page', 'livingdraft_seo_save' );

/* ------------------------------------------------------------------
 * 5. ENQUEUE ASSETS ON THE EDIT SCREEN
 * ------------------------------------------------------------------ */

function livingdraft_seo_enqueue_editor_assets( $hook ) {
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	$css = LIVINGDRAFT_CORE_DIR . 'assets/css/seo-metabox.css';
	if ( file_exists( $css ) ) {
		wp_enqueue_style(
			'livingdraft-seo-metabox',
			LIVINGDRAFT_CORE_URL . 'assets/css/seo-metabox.css',
			array(),
			filemtime( $css )
		);
	}

	$js = LIVINGDRAFT_CORE_DIR . 'assets/js/seo-metabox.js';
	if ( file_exists( $js ) ) {
		wp_enqueue_script(
			'livingdraft-seo-metabox',
			LIVINGDRAFT_CORE_URL . 'assets/js/seo-metabox.js',
			array(),
			filemtime( $js ),
			true
		);
	}
}
add_action( 'admin_enqueue_scripts', 'livingdraft_seo_enqueue_editor_assets' );

/* ------------------------------------------------------------------
 * 6. HELPERS FOR OTHER MODULES
 * ------------------------------------------------------------------ */

/**
 * Whether the AI provider layer has a usable key. Cached per-request.
 */
function livingdraft_seo_ai_ready() {
	static $ready = null;
	if ( null !== $ready ) {
		return $ready;
	}
	if ( ! function_exists( 'livingdraft_ai_get_settings' ) ) {
		$ready = false;
		return false;
	}
	$s = livingdraft_ai_get_settings();
	$ready = '' !== livingdraft_ai_get_key( $s['provider'] );
	return $ready;
}
