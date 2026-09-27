<?php
/**
 * Content brief generator.
 *
 * A workspace tool that produces a structured brief for a focus
 * keyword: title options, meta description, target word count, an
 * ordered H2 outline, entities to cover, questions to answer,
 * semantic terms, and hints about what to internally link to.
 *
 * This is what Surfer SEO and Rank Math Content AI sell as premium
 * features. With BYO-key it's just a single LLM call against
 * whichever provider the user has configured — no separate
 * subscription, no per-brief credits.
 *
 * === WHY JSON, NOT PROSE ===
 *
 * The model returns a JSON document rather than a wall of markdown so
 * we can render each section in its own UI card with its own Copy
 * button. That lets a writer pull just the outline into a new draft
 * without the surrounding chrome, or copy just the entities list into
 * a research doc.
 *
 * === THE "CREATE DRAFT" BUTTON ===
 *
 * Once a brief renders, one click creates a WP draft post whose
 * content is the outline as H2 sections with the notes as reminder
 * paragraphs. The writer opens the editor and starts writing into
 * a structured skeleton rather than a blank page. The focus keyword
 * and suggested meta description are pre-saved into the SEO meta
 * fields so the brief connects to the on-page tools cleanly.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------
 * 1. AJAX: GENERATE
 * ------------------------------------------------------------------ */

function livingdraft_content_brief_ajax_generate() {
	check_ajax_referer( 'ld_content_brief', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'livingdraft-core' ) ) );
	}

	$keyword      = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
	$content_type = isset( $_POST['content_type'] ) ? sanitize_text_field( wp_unslash( $_POST['content_type'] ) ) : '';
	$audience     = isset( $_POST['audience'] ) ? sanitize_text_field( wp_unslash( $_POST['audience'] ) ) : '';

	if ( '' === $keyword ) {
		wp_send_json_error( array( 'message' => __( 'A focus keyword is required.', 'livingdraft-core' ) ) );
	}

	// Language name from locale — helps the model write briefs in the
	// site's actual language rather than defaulting to English.
	$locale   = get_locale();
	$language = livingdraft_content_brief_locale_name( $locale );

	$prompt = livingdraft_ai_build_prompt(
		'content_brief',
		array(
			'keyword'      => $keyword,
			'content_type' => $content_type,
			'audience'     => $audience,
			'language'     => $language,
		)
	);

	$override = livingdraft_ai_read_request_override();

	$result = livingdraft_ai_complete(
		$prompt,
		array_merge(
			array(
				'task'        => 'content_brief',
				'max_tokens'  => 2500,   // Full brief fits comfortably under 2000; buffer for verbose models.
				'temperature' => 0.55,   // Low enough for structure; high enough for varied title options.
			),
			$override
		)
	);

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	$brief = livingdraft_content_brief_parse_json( $result );
	if ( is_wp_error( $brief ) ) {
		wp_send_json_error( array(
			'message' => $brief->get_error_message(),
			'raw'     => $result, // For diagnostics in the UI when parse fails.
		) );
	}

	// Cache the parsed brief in a transient keyed by the user so the
	// "Create draft" button can look it up without needing to POST the
	// whole payload back.
	$brief['keyword'] = $keyword;
	set_transient( 'ld_brief_' . get_current_user_id(), $brief, 30 * MINUTE_IN_SECONDS );

	wp_send_json_success( $brief );
}
add_action( 'wp_ajax_ld_content_brief_generate', 'livingdraft_content_brief_ajax_generate' );

/* ------------------------------------------------------------------
 * 2. FORM POST: CREATE DRAFT FROM BRIEF
 * ------------------------------------------------------------------ */

function livingdraft_content_brief_handle_create_draft() {
	if ( ! isset( $_POST['ld_brief_create_draft'] ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	check_admin_referer( 'ld_brief_create_draft' );

	$brief = get_transient( 'ld_brief_' . get_current_user_id() );
	if ( ! is_array( $brief ) || empty( $brief['outline'] ) ) {
		wp_safe_redirect( add_query_arg( array( 'page' => 'livingdraft-seo', 'tab' => 'brief', 'draft_error' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	// Build the outline into block-editor-friendly HTML. Each H2 gets
	// its own core/heading block with the note underneath as a
	// core/paragraph the writer will replace.
	$content_parts = array();
	$content_parts[] = "<!-- wp:paragraph -->\n<p><em>" . esc_html__( 'Draft skeleton generated from a content brief. Replace italic notes with your writing.', 'livingdraft-core' ) . "</em></p>\n<!-- /wp:paragraph -->\n";

	foreach ( (array) $brief['outline'] as $section ) {
		$heading = isset( $section['heading'] ) ? (string) $section['heading'] : '';
		$notes   = isset( $section['notes'] ) ? (string) $section['notes'] : '';
		if ( '' === $heading ) {
			continue;
		}
		$content_parts[] = "<!-- wp:heading -->\n<h2>" . esc_html( $heading ) . "</h2>\n<!-- /wp:heading -->\n";
		if ( '' !== $notes ) {
			$content_parts[] = "<!-- wp:paragraph -->\n<p><em>" . esc_html( $notes ) . "</em></p>\n<!-- /wp:paragraph -->\n";
		}
	}

	// Questions the writer should address, as a check block near the end.
	if ( ! empty( $brief['questions_to_answer'] ) && is_array( $brief['questions_to_answer'] ) ) {
		$content_parts[] = "<!-- wp:heading {\"level\":3} -->\n<h3><em>" . esc_html__( 'Questions to answer', 'livingdraft-core' ) . "</em></h3>\n<!-- /wp:heading -->\n";
		$li = array();
		foreach ( $brief['questions_to_answer'] as $q ) {
			$li[] = '<li>' . esc_html( (string) $q ) . '</li>';
		}
		$content_parts[] = "<!-- wp:list -->\n<ul>" . implode( '', $li ) . "</ul>\n<!-- /wp:list -->\n";
	}

	$title = ! empty( $brief['suggested_titles'][0] ) ? (string) $brief['suggested_titles'][0] : (string) ( $brief['keyword'] ?? __( 'Untitled draft', 'livingdraft-core' ) );

	$post_id = wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'draft',
			'post_title'   => $title,
			'post_content' => implode( "\n", $content_parts ),
			'post_author'  => get_current_user_id(),
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		wp_safe_redirect( add_query_arg( array( 'page' => 'livingdraft-seo', 'tab' => 'brief', 'draft_error' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	// Pre-populate the SEO meta so the brief connects to the on-page
	// analyser immediately — the writer opens the post and the focus
	// keyword and meta description are already there.
	if ( ! empty( $brief['keyword'] ) ) {
		update_post_meta( $post_id, '_ld_seo_focus_keyword', sanitize_text_field( $brief['keyword'] ) );
	}
	if ( ! empty( $brief['meta_description'] ) ) {
		update_post_meta( $post_id, '_ld_seo_description', sanitize_text_field( $brief['meta_description'] ) );
	}
	if ( ! empty( $brief['suggested_titles'][0] ) ) {
		update_post_meta( $post_id, '_ld_seo_title', sanitize_text_field( $brief['suggested_titles'][0] ) );
	}

	// Clear the transient so accidentally clicking again doesn't
	// produce a duplicate post.
	delete_transient( 'ld_brief_' . get_current_user_id() );

	wp_safe_redirect( get_edit_post_link( $post_id, 'redirect' ) );
	exit;
}
add_action( 'admin_init', 'livingdraft_content_brief_handle_create_draft' );

/* ------------------------------------------------------------------
 * 3. JSON EXTRACTOR
 *
 * Even with a clear "no markdown fences" instruction, some models
 * (particularly Gemini and some OpenRouter routes) wrap the JSON in
 * ```json ... ``` about a quarter of the time. Others prepend a
 * conversational sentence. Extract cautiously.
 * ------------------------------------------------------------------ */

function livingdraft_content_brief_parse_json( $raw ) {
	$text = trim( $raw );

	// Strip common markdown fence wrappers.
	if ( 0 === strpos( $text, '```' ) ) {
		$text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
		$text = preg_replace( '/```\s*$/', '', $text );
		$text = trim( $text );
	}

	// If there's leading prose, isolate from the first '{' to the last '}'.
	$first = strpos( $text, '{' );
	$last  = strrpos( $text, '}' );
	if ( false !== $first && false !== $last && $last > $first ) {
		$text = substr( $text, $first, $last - $first + 1 );
	}

	$data = json_decode( $text, true );
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'ld_brief_bad_json', __( 'The model didn\'t return valid JSON. Try again — a different retry usually parses cleanly.', 'livingdraft-core' ) );
	}

	// Minimum shape check — the fields we depend on for rendering
	// must exist. Missing optional fields are fine.
	$required = array( 'suggested_titles', 'meta_description', 'outline' );
	foreach ( $required as $key ) {
		if ( ! isset( $data[ $key ] ) ) {
			return new WP_Error( 'ld_brief_incomplete', sprintf( __( 'The brief is missing the "%s" field. Try again.', 'livingdraft-core' ), $key ) );
		}
	}

	return $data;
}

/* ------------------------------------------------------------------
 * 4. LOCALE → LANGUAGE NAME
 *
 * The model does better when told "write in Hindi" than "en_US"-style
 * codes. Small map covers the languages likely for the plugin's
 * audience; falls back to the WP locale string when unknown so the
 * model can guess.
 * ------------------------------------------------------------------ */

function livingdraft_content_brief_locale_name( $locale ) {
	$map = array(
		'en'  => 'English',
		'en_US' => 'English',
		'en_GB' => 'British English',
		'hi'  => 'Hindi',
		'hi_IN' => 'Hindi',
		'mr'  => 'Marathi',
		'mr_IN' => 'Marathi',
		'bn'  => 'Bengali',
		'ta'  => 'Tamil',
		'te'  => 'Telugu',
		'gu'  => 'Gujarati',
		'kn'  => 'Kannada',
		'ml'  => 'Malayalam',
		'pa'  => 'Punjabi',
		'ur'  => 'Urdu',
		'es'  => 'Spanish',
		'fr'  => 'French',
		'de'  => 'German',
		'pt'  => 'Portuguese',
		'it'  => 'Italian',
		'ja'  => 'Japanese',
		'ko'  => 'Korean',
		'zh'  => 'Chinese',
		'ar'  => 'Arabic',
		'ru'  => 'Russian',
	);
	if ( isset( $map[ $locale ] ) ) {
		return $map[ $locale ];
	}
	$short = substr( $locale, 0, 2 );
	if ( isset( $map[ $short ] ) ) {
		return $map[ $short ];
	}
	return $locale; // Let the model guess.
}

/* ------------------------------------------------------------------
 * 5. ADMIN RENDER
 * ------------------------------------------------------------------ */

function livingdraft_content_brief_render_tab() {
	$ai_ready = livingdraft_seo_ai_ready();
	?>

	<?php if ( ! $ai_ready ) : ?>
		<div class="notice notice-warning" style="margin-top:0">
			<p>
				<?php esc_html_e( 'Configure an AI provider first under the AI tab. The brief generator needs a working key.', 'livingdraft-core' ); ?>
			</p>
		</div>
		<?php return; ?>
	<?php endif; ?>

	<?php if ( isset( $_GET['draft_error'] ) ) : ?>
		<div class="notice notice-error is-dismissible">
			<p><?php esc_html_e( 'Could not create draft — try regenerating the brief first.', 'livingdraft-core' ); ?></p>
		</div>
	<?php endif; ?>

	<div class="tld-card">
		<div class="tld-card-header">
			<div>
				<span class="tld-card-eyebrow"><?php esc_html_e( 'Writer workspace', 'livingdraft-core' ); ?></span>
				<h2 class="tld-card-title"><?php esc_html_e( 'Content brief', 'livingdraft-core' ); ?></h2>
			</div>
		</div>

		<p style="color:var(--tld-ink-3, #666);max-width:60ch;margin-top:0">
			<?php esc_html_e( 'Enter a focus keyword. The model returns a structured brief — title options, meta description, target length, outline, entities, questions, semantic terms. From there, "Create draft post" spins up a new post pre-populated with the outline as headings and your focus keyword already set in the SEO metabox.', 'livingdraft-core' ); ?>
		</p>

		<form id="ld-brief-form" onsubmit="return false">
			<div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px;margin-bottom:12px">
				<div>
					<label class="tld-label" for="ld_brief_keyword"><?php esc_html_e( 'Focus keyword *', 'livingdraft-core' ); ?></label>
					<input type="text" id="ld_brief_keyword" class="tld-input" placeholder="<?php esc_attr_e( 'e.g. mumbai monsoon flooding 2026', 'livingdraft-core' ); ?>" required>
				</div>
				<div>
					<label class="tld-label" for="ld_brief_type"><?php esc_html_e( 'Content type', 'livingdraft-core' ); ?></label>
					<select id="ld_brief_type" class="tld-input">
						<option value=""><?php esc_html_e( 'Not specified', 'livingdraft-core' ); ?></option>
						<option value="news article">News article</option>
						<option value="explainer">Explainer</option>
						<option value="how-to guide">How-to guide</option>
						<option value="listicle">Listicle</option>
						<option value="opinion / analysis">Opinion / analysis</option>
						<option value="interview">Interview</option>
						<option value="review">Review</option>
					</select>
				</div>
				<div>
					<label class="tld-label" for="ld_brief_audience"><?php esc_html_e( 'Audience', 'livingdraft-core' ); ?></label>
					<input type="text" id="ld_brief_audience" class="tld-input" placeholder="<?php esc_attr_e( 'e.g. mumbai commuters', 'livingdraft-core' ); ?>">
				</div>
			</div>

			<button type="button" id="ld-brief-generate" class="tld-btn is-primary">
				<span class="ld-seo-ai-glyph">✦</span> <?php esc_html_e( 'Generate brief', 'livingdraft-core' ); ?>
			</button>
			<span id="ld-brief-status" style="margin-left:12px;font-family:var(--tld-mono, monospace);font-size:12px;color:#666"></span>
			<span style="float:right">
				<?php livingdraft_ai_render_model_picker( array( 'label' => __( 'Model', 'livingdraft-core' ), 'storage_key' => 'livingdraft.ai.model.brief' ) ); ?>
			</span>
		</form>

		<div id="ld-brief-output" style="margin-top:24px"></div>

		<form method="post" id="ld-brief-draft-form" style="display:none;margin-top:20px">
			<?php wp_nonce_field( 'ld_brief_create_draft' ); ?>
			<button type="submit" name="ld_brief_create_draft" class="tld-btn is-primary">
				<?php esc_html_e( 'Create draft post from this brief →', 'livingdraft-core' ); ?>
			</button>
			<p style="color:#666;font-size:12px;margin-top:8px">
				<?php esc_html_e( 'Opens the editor with the outline as H2 sections and your focus keyword already set.', 'livingdraft-core' ); ?>
			</p>
		</form>
	</div>

	<style>
		.ld-brief-section {
			background: #fff;
			border: 1px solid #e5e5e5;
			padding: 16px 18px;
			margin-bottom: 12px;
		}
		.ld-brief-section-head {
			display: flex;
			justify-content: space-between;
			align-items: baseline;
			margin-bottom: 10px;
		}
		.ld-brief-section-title {
			margin: 0;
			font-family: var(--tld-serif, Georgia, serif);
			font-weight: 400;
			font-size: 15px;
			color: #1a1a1a;
		}
		.ld-brief-section-eyebrow {
			font-family: var(--tld-mono, monospace);
			font-size: 10px;
			letter-spacing: 0.12em;
			text-transform: uppercase;
			color: #999;
			margin-bottom: 3px;
		}
		.ld-brief-copy {
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
		.ld-brief-copy:hover { background: #faf6f5; }
		.ld-brief-copy.is-copied { background: #2f7a3a !important; color: #fff !important; border-color: #2f7a3a !important; }

		.ld-brief-title-option {
			display: block;
			padding: 8px 12px;
			background: #fafaf7;
			border-left: 3px solid #8b3a2c;
			margin-bottom: 6px;
			font-family: Georgia, serif;
			font-size: 15px;
		}
		.ld-brief-outline {
			list-style: none;
			padding: 0;
			margin: 0;
		}
		.ld-brief-outline li {
			padding: 10px 0;
			border-bottom: 1px solid #eee;
		}
		.ld-brief-outline li:last-child { border-bottom: 0; }
		.ld-brief-outline strong {
			display: block;
			font-family: Georgia, serif;
			font-weight: 400;
			font-size: 14px;
		}
		.ld-brief-outline em {
			display: block;
			color: #666;
			font-size: 12px;
			margin-top: 2px;
			font-style: normal;
		}
		.ld-brief-tags {
			display: flex;
			flex-wrap: wrap;
			gap: 6px;
		}
		.ld-brief-tags span {
			padding: 4px 10px;
			background: #f5f0ee;
			border: 1px solid #e5d8d4;
			font-size: 12px;
			font-family: var(--tld-mono, monospace);
			color: #333;
		}
		.ld-brief-questions {
			list-style: none;
			padding: 0;
			margin: 0;
			counter-reset: q;
		}
		.ld-brief-questions li {
			counter-increment: q;
			padding: 6px 0 6px 34px;
			position: relative;
			font-size: 13px;
		}
		.ld-brief-questions li::before {
			content: counter(q, decimal-leading-zero);
			position: absolute;
			left: 0;
			top: 6px;
			font-family: var(--tld-mono, monospace);
			font-size: 10px;
			color: #999;
			letter-spacing: .1em;
		}
		.ld-brief-meta-row {
			display: flex;
			gap: 24px;
			padding: 10px 14px;
			background: #fafaf7;
			border: 1px solid #e5e5e5;
			margin-bottom: 12px;
			font-family: var(--tld-mono, monospace);
			font-size: 11px;
		}
		.ld-brief-meta-row span { color: #666; }
		.ld-brief-meta-row strong { color: #8b3a2c; margin-left: 4px; font-weight: 500; }
	</style>

	<script>
	(function () {
		var btn      = document.getElementById( 'ld-brief-generate' );
		var kwEl     = document.getElementById( 'ld_brief_keyword' );
		var typeEl   = document.getElementById( 'ld_brief_type' );
		var audEl    = document.getElementById( 'ld_brief_audience' );
		var status   = document.getElementById( 'ld-brief-status' );
		var output   = document.getElementById( 'ld-brief-output' );
		var draftForm = document.getElementById( 'ld-brief-draft-form' );

		if ( ! btn ) return;

		function esc( s ) {
			return String( s == null ? '' : s )
				.replace( /&/g, '&amp;' )
				.replace( /</g, '&lt;' )
				.replace( />/g, '&gt;' )
				.replace( /"/g, '&quot;' )
				.replace( /'/g, '&#39;' );
		}

		function section( title, eyebrow, body, copyText ) {
			var head = '<div class="ld-brief-section-head">' +
				'<div><div class="ld-brief-section-eyebrow">' + esc( eyebrow ) + '</div>' +
				'<h3 class="ld-brief-section-title">' + esc( title ) + '</h3></div>' +
				( copyText ? '<button type="button" class="ld-brief-copy" data-copy="' + esc( copyText ) + '">Copy</button>' : '' ) +
				'</div>';
			return '<div class="ld-brief-section">' + head + body + '</div>';
		}

		function render( brief ) {
			var html = '';

			// Top meta strip: word count target + section counts.
			var wc = brief.target_word_count ? parseInt( brief.target_word_count, 10 ) : 0;
			var outlineCount = Array.isArray( brief.outline ) ? brief.outline.length : 0;
			var entCount     = Array.isArray( brief.key_entities ) ? brief.key_entities.length : 0;

			html += '<div class="ld-brief-meta-row">' +
				'<span>KEYWORD <strong>' + esc( brief.keyword || '' ) + '</strong></span>' +
				( wc ? '<span>TARGET WORDS <strong>~' + wc + '</strong></span>' : '' ) +
				( outlineCount ? '<span>OUTLINE <strong>' + outlineCount + ' sections</strong></span>' : '' ) +
				( entCount ? '<span>ENTITIES <strong>' + entCount + '</strong></span>' : '' ) +
				'</div>';

			// Titles.
			if ( Array.isArray( brief.suggested_titles ) && brief.suggested_titles.length ) {
				var titlesBody = brief.suggested_titles.map( function ( t ) {
					return '<span class="ld-brief-title-option">' + esc( t ) + '</span>';
				} ).join( '' );
				html += section( 'Title options', 'CTR + KEYWORD', titlesBody, brief.suggested_titles.join( '\n' ) );
			}

			// Meta description.
			if ( brief.meta_description ) {
				html += section(
					'Meta description',
					esc( brief.meta_description.length ) + ' CHARS',
					'<div style="font-family:Georgia,serif;font-size:14px;line-height:1.5;color:#333">' + esc( brief.meta_description ) + '</div>',
					brief.meta_description
				);
			}

			// Outline.
			if ( Array.isArray( brief.outline ) && brief.outline.length ) {
				var outlineHtml = '<ul class="ld-brief-outline">' + brief.outline.map( function ( s ) {
					return '<li><strong>' + esc( s.heading ) + '</strong>' +
						( s.notes ? '<em>' + esc( s.notes ) + '</em>' : '' ) + '</li>';
				} ).join( '' ) + '</ul>';
				var outlineCopy = brief.outline.map( function ( s ) {
					return '## ' + s.heading + ( s.notes ? '\n' + s.notes : '' );
				} ).join( '\n\n' );
				html += section( 'Outline', outlineCount + ' H2 SECTIONS', outlineHtml, outlineCopy );
			}

			// Entities.
			if ( Array.isArray( brief.key_entities ) && brief.key_entities.length ) {
				var entBody = '<div class="ld-brief-tags">' + brief.key_entities.map( function ( e ) {
					return '<span>' + esc( e ) + '</span>';
				} ).join( '' ) + '</div>';
				html += section( 'Key entities to mention', 'ENTITY COVERAGE', entBody, brief.key_entities.join( ', ' ) );
			}

			// Questions.
			if ( Array.isArray( brief.questions_to_answer ) && brief.questions_to_answer.length ) {
				var qHtml = '<ul class="ld-brief-questions">' + brief.questions_to_answer.map( function ( q ) {
					return '<li>' + esc( q ) + '</li>';
				} ).join( '' ) + '</ul>';
				html += section( 'Questions to answer', 'READER INTENT', qHtml, brief.questions_to_answer.join( '\n' ) );
			}

			// Semantic terms.
			if ( Array.isArray( brief.semantic_terms ) && brief.semantic_terms.length ) {
				var stBody = '<div class="ld-brief-tags">' + brief.semantic_terms.map( function ( t ) {
					return '<span>' + esc( t ) + '</span>';
				} ).join( '' ) + '</div>';
				html += section( 'Semantic terms', 'TOPICAL COVERAGE', stBody, brief.semantic_terms.join( ', ' ) );
			}

			// Internal link hints.
			if ( Array.isArray( brief.internal_link_hints ) && brief.internal_link_hints.length ) {
				var ilHtml = '<ul style="margin:0;padding-left:18px;font-size:13px;line-height:1.7">' + brief.internal_link_hints.map( function ( h ) {
					return '<li>' + esc( h ) + '</li>';
				} ).join( '' ) + '</ul>';
				html += section( 'Internal linking', 'FROM YOUR SITE', ilHtml, brief.internal_link_hints.join( '\n' ) );
			}

			output.innerHTML = html;
			draftForm.style.display = 'block';
			wireCopy();
		}

		function wireCopy() {
			document.querySelectorAll( '#ld-brief-output .ld-brief-copy' ).forEach( function ( b ) {
				if ( b._wired ) return;
				b._wired = true;
				b.addEventListener( 'click', function () {
					var text = b.getAttribute( 'data-copy' );
					if ( ! text ) return;
					if ( navigator.clipboard && navigator.clipboard.writeText ) {
						navigator.clipboard.writeText( text ).then( flash );
					} else {
						var ta = document.createElement( 'textarea' );
						ta.value = text;
						ta.style.position = 'fixed';
						ta.style.opacity = '0';
						document.body.appendChild( ta );
						ta.select();
						try { document.execCommand( 'copy' ); } catch ( e ) {}
						document.body.removeChild( ta );
						flash();
					}
					function flash() {
						var orig = b.textContent;
						b.textContent = 'Copied';
						b.classList.add( 'is-copied' );
						setTimeout( function () {
							b.textContent = orig;
							b.classList.remove( 'is-copied' );
						}, 1400 );
					}
				} );
			} );
		}

		btn.addEventListener( 'click', function () {
			if ( ! kwEl.value.trim() ) {
				status.textContent = 'A focus keyword is required.';
				status.style.color = '#a32e2e';
				kwEl.focus();
				return;
			}

			btn.disabled = true;
			status.textContent = 'Thinking (usually 10–20 seconds)…';
			status.style.color = '#666';
			output.innerHTML = '';
			draftForm.style.display = 'none';

			var body = new FormData();
			body.append( 'action', 'ld_content_brief_generate' );
			body.append( 'nonce', '<?php echo esc_js( wp_create_nonce( 'ld_content_brief' ) ); ?>' );
			body.append( 'keyword', kwEl.value.trim() );
			body.append( 'content_type', typeEl.value );
			body.append( 'audience', audEl.value.trim() );

			var m = window.livingdraftAiCurrentModel && window.livingdraftAiCurrentModel( 'livingdraft.ai.model.brief' );
			if ( m ) body.append( 'ai_model', m );

			fetch( ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( ! res || ! res.success ) {
						status.textContent = ( res && res.data && res.data.message ) || 'Failed.';
						status.style.color = '#a32e2e';
						if ( res && res.data && res.data.raw ) {
							output.innerHTML = '<div class="ld-brief-section"><h3 class="ld-brief-section-title">Raw response (for debugging)</h3><pre style="white-space:pre-wrap;font-size:11px;color:#666">' + esc( res.data.raw ) + '</pre></div>';
						}
						return;
					}
					status.textContent = 'Ready.';
					status.style.color = '#2f7a3a';
					render( res.data );
				} )
				.catch( function ( err ) {
					status.textContent = 'Network error: ' + err.message;
					status.style.color = '#a32e2e';
				} )
				.finally( function () {
					btn.disabled = false;
				} );
		} );

		// Allow Enter in the keyword field to submit.
		kwEl.addEventListener( 'keydown', function ( ev ) {
			if ( ev.key === 'Enter' ) {
				ev.preventDefault();
				btn.click();
			}
		} );
	}());
	</script>
	<?php
}
