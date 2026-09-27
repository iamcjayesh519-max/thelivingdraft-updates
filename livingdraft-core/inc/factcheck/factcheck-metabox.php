<?php
/**
 * Fact checking: the panel in the post sidebar, and the settings screen.
 *
 * The panel deliberately never disables the Publish button. See the note at
 * the top of factcheck.php: a newsroom tool that can stop the presses will
 * eventually stop the presses, on the day a Google outage coincides with a
 * breaking story. It warns as loudly as it can and always yields.
 *
 * @package LivingDraftCore
 * @since 4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ==================================================================
 * 1. THE PANEL
 * ================================================================== */

/**
 * Register the metabox on posts.
 *
 * @since 4.0.0
 * @return void
 */
function livingdraft_factcheck_metabox() {
	if ( ! livingdraft_factcheck_enabled() ) {
		return;
	}

	add_meta_box(
		'livingdraft-factcheck',
		__( 'Fact check', 'livingdraft-core' ),
		'livingdraft_factcheck_render_metabox',
		'post',
		'side',
		'high'
	);
}
add_action( 'add_meta_boxes', 'livingdraft_factcheck_metabox' );

/**
 * Render the panel.
 *
 * @since 4.0.0
 * @param WP_Post $post Post.
 * @return void
 */
function livingdraft_factcheck_render_metabox( $post ) {
	$result = livingdraft_factcheck_result( $post->ID );
	?>
	<div class="ld-fc" data-post="<?php echo esc_attr( (int) $post->ID ); ?>">
		<p class="ld-fc-eyebrow"><?php esc_html_e( 'Fact check', 'livingdraft-core' ); ?></p>

		<p class="ld-fc-intro">
			<?php esc_html_e( 'Searches published fact-checks for claims in this draft. It cannot verify your figures or names — it finds claims that other newsrooms have already debunked.', 'livingdraft-core' ); ?>
		</p>

		<button type="button" class="ld-fc-run">
			<?php esc_html_e( 'Check this article', 'livingdraft-core' ); ?>
		</button>

		<div class="ld-fc-status" role="status" aria-live="polite"></div>
		<div class="ld-fc-results">
			<?php
			if ( $result ) {
				livingdraft_factcheck_render_results( $result );
			}
			?>
		</div>
	</div>
	<?php
}

/**
 * Render one stored result.
 *
 * Also used by the AJAX handler, so the panel after a run and the panel after
 * a page reload are always identical.
 *
 * @since 4.0.0
 * @param array $result Stored result.
 * @return void
 */
function livingdraft_factcheck_render_results( $result ) {
	$flagged = (int) ( isset( $result['flagged'] ) ? $result['flagged'] : 0 );
	$total   = (int) ( isset( $result['total'] ) ? $result['total'] : 0 );
	$stale   = ! empty( $result['stale'] );
	?>
	<?php if ( $stale ) : ?>
		<div class="ld-fc-stale">
			<?php esc_html_e( 'This article has been edited since it was checked. Run it again.', 'livingdraft-core' ); ?>
		</div>
	<?php endif; ?>

	<div class="ld-fc-summary <?php echo $flagged ? 'is-flagged' : 'is-clear'; ?>">
		<?php
		/*
		 * The verdict kicker. The sentence underneath already says the same
		 * thing, but it says it in twelve words — and this panel is read in
		 * the half-second before someone presses Publish. Two words in the
		 * house kicker style carry the answer at a glance; the sentence
		 * carries the detail for anyone who stops to read it.
		 */
		?>
		<span class="ld-fc-verdict">
			<?php echo $flagged ? esc_html__( 'Disputed', 'livingdraft-core' ) : esc_html__( 'No match', 'livingdraft-core' ); ?>
		</span>

		<?php if ( $flagged ) : ?>
			<strong>
				<?php
				printf(
					/* translators: 1: number flagged, 2: number of claims checked. */
					esc_html( _n( '%1$d of %2$d claims has been disputed elsewhere', '%1$d of %2$d claims have been disputed elsewhere', $flagged, 'livingdraft-core' ) ),
					$flagged,
					$total
				);
				?>
			</strong>
		<?php else : ?>
			<strong>
				<?php
				printf(
					/* translators: %d: number of claims checked. */
					esc_html( _n( 'No published fact-check matched the %d claim checked', 'No published fact-check matched the %d claims checked', $total, 'livingdraft-core' ) ),
					$total
				);
				?>
			</strong>
			<div class="ld-fc-caveat">
				<?php esc_html_e( 'That is not a clean bill of health. Most claims have never been fact-checked by anybody.', 'livingdraft-core' ); ?>
			</div>
		<?php endif; ?>
	</div>

	<?php foreach ( (array) $result['findings'] as $finding ) : ?>
		<?php
		$status = isset( $finding['status'] ) ? $finding['status'] : 'clear';

		if ( 'clear' === $status ) {
			continue; // Nothing to say about a claim nobody has reviewed.
		}
		?>
		<div class="ld-fc-item is-<?php echo esc_attr( $status ); ?>">
			<div class="ld-fc-claim"><?php echo esc_html( $finding['claim'] ); ?></div>

			<?php if ( 'error' === $status ) : ?>
				<div class="ld-fc-error"><?php echo esc_html( $finding['message'] ); ?></div>
			<?php endif; ?>

			<?php foreach ( (array) $finding['reviews'] as $review ) : ?>
				<div class="ld-fc-review">
					<span class="ld-fc-rating sev-<?php echo esc_attr( $review['severity'] ); ?>">
						<?php echo esc_html( $review['rating'] ); ?>
					</span>
					<a href="<?php echo esc_url( $review['url'] ); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html( $review['publisher'] ); ?>
					</a>
					<?php if ( ! empty( $review['local'] ) ) : ?>
						<span class="ld-fc-local"><?php esc_html_e( 'India', 'livingdraft-core' ); ?></span>
					<?php endif; ?>
					<?php if ( ! empty( $review['date'] ) ) : ?>
						<span class="ld-fc-date"><?php echo esc_html( mysql2date( 'j M Y', $review['date'] ) ); ?></span>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>

			<?php if ( ! empty( $finding['summary']['text'] ) ) : ?>
				<div class="ld-fc-record">
					<div class="ld-fc-record-head"><?php esc_html_e( 'What the published record says', 'livingdraft-core' ); ?></div>
					<?php echo wp_kses_post( wpautop( $finding['summary']['text'] ) ); ?>
					<div class="ld-fc-sources">
						<?php esc_html_e( 'Sources:', 'livingdraft-core' ); ?>
						<?php foreach ( (array) $finding['summary']['sources'] as $ld_src ) : ?>
							<a href="<?php echo esc_url( $ld_src ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( wp_parse_url( $ld_src, PHP_URL_HOST ) ); ?></a>
						<?php endforeach; ?>
					</div>
					<div class="ld-fc-verify">
						<?php esc_html_e( 'Summarised from the sources above. Read them before you rely on this.', 'livingdraft-core' ); ?>
					</div>
				</div>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>

	<?php if ( ! empty( $result['checked_at'] ) ) : ?>
		<div class="ld-fc-when">
			<?php
			printf(
				/* translators: %s: date and time. */
				esc_html__( 'Checked %s', 'livingdraft-core' ),
				esc_html( mysql2date( 'j M Y, H:i', $result['checked_at'] ) )
			);
			?>
		</div>
	<?php endif; ?>
	<?php
}

/* ==================================================================
 * 2. THE AJAX ENDPOINT
 * ================================================================== */

/**
 * Run a check and return the rendered panel.
 *
 * @since 4.0.0
 * @return void
 */
function livingdraft_factcheck_ajax_run() {
	check_ajax_referer( 'ld_factcheck', 'nonce' );

	$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

	if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'livingdraft-core' ) ) );
	}

	$result = livingdraft_factcheck_run( $post_id );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	ob_start();
	livingdraft_factcheck_render_results( $result );
	$html = ob_get_clean();

	wp_send_json_success(
		array(
			'html'    => $html,
			'flagged' => (int) $result['flagged'],
		)
	);
}
add_action( 'wp_ajax_ld_factcheck_run', 'livingdraft_factcheck_ajax_run' );

/**
 * Panel assets.
 *
 * @since 4.0.0
 * @param string $hook Current admin page.
 * @return void
 */
function livingdraft_factcheck_assets( $hook ) {
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	if ( ! livingdraft_factcheck_enabled() ) {
		return;
	}

	$path = LIVINGDRAFT_CORE_DIR . 'assets/js/factcheck.js';

	if ( ! file_exists( $path ) ) {
		return;
	}

	wp_enqueue_script(
		'livingdraft-factcheck',
		LIVINGDRAFT_CORE_URL . 'assets/js/factcheck.js',
		array(),
		(string) filemtime( $path ),
		true
	);

	wp_localize_script(
		'livingdraft-factcheck',
		'livingdraftFactCheck',
		array(
			'ajax'     => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'ld_factcheck' ),
			'working'  => __( 'Reading the draft, then searching published fact-checks…', 'livingdraft-core' ),
			'failed'   => __( 'The check could not be completed.', 'livingdraft-core' ),
		)
	);

	wp_add_inline_style( 'wp-admin', livingdraft_factcheck_css() );
}
add_action( 'admin_enqueue_scripts', 'livingdraft_factcheck_assets' );

/**
 * Panel styles.
 *
 * @since 4.0.0
 * @return string
 */
function livingdraft_factcheck_css() {
	/*
	 * === WHY THIS PANEL STOPPED USING WORDPRESS BUTTONS (4.4.0) ===
	 *
	 * The run button was `button button-primary`, which is WordPress's
	 * #2271b1 blue. It was the single loudest element in a sidebar panel
	 * whose every other colour came from this plugin's editorial palette —
	 * warm cream, near-black ink, a reddish-brown mark. One bright blue
	 * rectangle in the middle of that reads as something another plugin
	 * installed.
	 *
	 * It is now the plugin's own ink button, and the panel opens with a
	 * mono eyebrow rather than a sans heading, matching the section rules
	 * used on every admin screen and the kickers used on the front end.
	 *
	 * The palette here is written as literal hex rather than the --tld-*
	 * variables because this CSS is injected into the post editor, which
	 * never loads admin.css. Keeping the values in sync is a real cost;
	 * loading a 1,600-line stylesheet into every editor screen to avoid it
	 * would be a larger one.
	 */
	return '
.ld-fc-eyebrow{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:#8b3a2c;margin:0 0 8px;padding-bottom:7px;border-bottom:2px solid #1a1a1a}
.ld-fc-intro{font-size:12px;color:#75726c;margin:0 0 10px;line-height:1.5}
.ld-fc-run{display:block;width:100%;padding:9px 14px;background:#1a1a1a;color:#fff;border:1px solid #1a1a1a;border-radius:0;font-family:inherit;font-size:13px;font-weight:500;line-height:1.3;cursor:pointer;transition:background 160ms cubic-bezier(.2,.8,.2,1)}
.ld-fc-run:hover{background:#8b3a2c;border-color:#8b3a2c;color:#fff}
.ld-fc-run:focus-visible{outline:2px solid #8b3a2c;outline-offset:2px}
.ld-fc-run[disabled]{background:#a8a49b;border-color:#a8a49b;cursor:default}
.ld-fc-status{font-size:12px;color:#75726c;margin:8px 0;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
.ld-fc-summary{margin:12px 0 8px;padding:9px 11px;border-left:3px solid #cfc9bb;font-size:13px;line-height:1.45}
.ld-fc-summary.is-flagged{border-left-color:#a32e2e;background:#f6e6e6}
.ld-fc-summary.is-clear{border-left-color:#2f7a3a;background:#eaf5ec}
.ld-fc-verdict{display:inline-block;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:9px;letter-spacing:.1em;text-transform:uppercase;color:#fff;padding:2px 6px;margin-bottom:6px}
.ld-fc-summary.is-clear .ld-fc-verdict{background:#2f7a3a}
.ld-fc-summary.is-flagged .ld-fc-verdict{background:#a32e2e}
.ld-fc-caveat{font-size:11px;color:#75726c;margin-top:5px;font-style:italic;font-family:Georgia,serif}
.ld-fc-stale{background:#fdf4e3;border-left:3px solid #b7791f;padding:8px 10px;font-size:12px;margin:10px 0}
.ld-fc-item{border-top:1px solid #e5e0d1;padding:10px 0}
.ld-fc-claim{font-size:12px;font-style:italic;color:#383634;margin-bottom:6px;line-height:1.45;font-family:Georgia,serif}
.ld-fc-review{font-size:11px;margin:3px 0;display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.ld-fc-rating{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-weight:500;text-transform:uppercase;letter-spacing:.06em;font-size:10px}
.ld-fc-rating.sev-false{color:#a32e2e}
.ld-fc-rating.sev-mixed{color:#b7791f}
.ld-fc-rating.sev-true{color:#2f7a3a}
.ld-fc-rating.sev-unknown{color:#75726c}
.ld-fc-local{background:#ede8dc;color:#5f5e5a;padding:1px 5px;font-size:10px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.04em}
.ld-fc-date{color:#a8a49b;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:10px}
.ld-fc-record{margin-top:8px;padding:9px 11px;background:#fbf8f0;border-left:2px solid #cfc9bb;font-size:12px;line-height:1.5}
.ld-fc-record p{margin:0 0 6px}
.ld-fc-record-head{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:9px;text-transform:uppercase;letter-spacing:.1em;color:#75726c;margin-bottom:4px}
.ld-fc-sources{font-size:11px;color:#75726c;margin-top:6px}
.ld-fc-sources a{margin-right:6px;color:#1a4b8c}
.ld-fc-verify{font-size:11px;color:#a32e2e;margin-top:6px;font-style:italic;font-family:Georgia,serif}
.ld-fc-error{font-size:11px;color:#a32e2e}
.ld-fc-when{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:10px;color:#a8a49b;margin-top:10px}
';
}

/* ==================================================================
 * 3. THE WARNING, WHICH NEVER BLOCKS
 * ================================================================== */

/**
 * Show a notice at the top of the editor when something is flagged.
 *
 * This is the loudest the feature gets. Publish stays enabled.
 *
 * @since 4.0.0
 * @return void
 */
function livingdraft_factcheck_editor_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen || 'post' !== $screen->id ) {
		return;
	}

	$post_id = isset( $GLOBALS['post']->ID ) ? (int) $GLOBALS['post']->ID : 0;
	$result  = $post_id ? livingdraft_factcheck_result( $post_id ) : null;

	if ( ! $result || empty( $result['flagged'] ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
		esc_html__( 'Fact check:', 'livingdraft-core' ),
		esc_html(
			sprintf(
				/* translators: %d: number of claims. */
				_n(
					'%d claim in this article has been disputed by a fact-checking organisation. See the Fact check panel before publishing.',
					'%d claims in this article have been disputed by fact-checking organisations. See the Fact check panel before publishing.',
					(int) $result['flagged'],
					'livingdraft-core'
				),
				(int) $result['flagged']
			)
		)
	);
}
add_action( 'admin_notices', 'livingdraft_factcheck_editor_notice' );

/* ==================================================================
 * 4. SETTINGS
 * ================================================================== */

/**
 * Save the key from the AI settings screen.
 *
 * @since 4.0.0
 * @return void
 */
function livingdraft_factcheck_save_settings() {
	if ( empty( $_POST['ld_factcheck_settings'] ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'livingdraft-core' ), 403 );
	}

	check_admin_referer( 'ld_factcheck_settings' );

	if ( isset( $_POST['ld_factcheck_key'] ) ) {
		$key = sanitize_text_field( wp_unslash( $_POST['ld_factcheck_key'] ) );

		// An unchanged masked value means "leave it alone", not "set it to
		// a row of dots".
		if ( '' === $key || false === strpos( $key, '•' ) ) {
			livingdraft_factcheck_set_key( $key );
		}
	}

	update_option( 'livingdraft_factcheck_enabled', ! empty( $_POST['ld_factcheck_enabled'] ) ? 1 : 0 );

	foreach ( array( 'jina', 'firecrawl' ) as $reader ) {
		if ( isset( $_POST[ 'ld_reader_' . $reader ] ) ) {
			$k = sanitize_text_field( wp_unslash( $_POST[ 'ld_reader_' . $reader ] ) );

			if ( '' === $k || false === strpos( $k, '•' ) ) {
				livingdraft_factcheck_set_reader_key( $reader, $k );
			}
		}
	}

	wp_safe_redirect( add_query_arg( 'ld_notice', 'saved', wp_get_referer() ) );
	exit;
}
add_action( 'admin_init', 'livingdraft_factcheck_save_settings' );

/**
 * The settings section.
 *
 * Its own entry in the Settings rail since 4.4.0. It used to hang off the
 * bottom of the AI screen on the reasoning that it cannot work without a
 * key — true, but that logic would put half the plugin there.
 *
 * @since 4.0.0
 * @return void
 */
function livingdraft_factcheck_settings_panel() {
	$key    = livingdraft_factcheck_key();
	$masked = '' !== $key ? str_repeat( '•', 28 ) . substr( $key, -4 ) : '';
	?>
	<div class="tld-card">
	<form method="post" action="">
		<?php wp_nonce_field( 'ld_factcheck_settings' ); ?>
		<input type="hidden" name="ld_factcheck_settings" value="1">

		<div class="tld-section-rule"><h2><?php esc_html_e( 'Fact checking', 'livingdraft-core' ); ?></h2></div>

		<p class="tld-help">
			<?php esc_html_e( 'Searches Google\'s index of published fact-checks for claims in a draft. It does not verify your facts — it finds claims that Boom, Alt News, Factly and others have already ruled on. Indian fact-checkers are shown first.', 'livingdraft-core' ); ?>
		</p>

		<p class="tld-help">
			<?php esc_html_e( 'Picking claims out of the draft uses your configured AI provider, so a key must be set above. Nothing is ever asserted from the model\'s own knowledge: any summary that comes back without a source link is discarded rather than shown.', 'livingdraft-core' ); ?>
		</p>

		<div class="tld-fields">
			<div class="tld-field">
				<label class="tld-label" for="ld_factcheck_key"><?php esc_html_e( 'Fact Check API key', 'livingdraft-core' ); ?></label>
					<input type="text" id="ld_factcheck_key" name="ld_factcheck_key" class="tld-input"
						value="<?php echo esc_attr( $masked ); ?>" autocomplete="off">
					<p class="tld-help">
						<?php esc_html_e( 'A Google Cloud API key with the Fact Check Tools API enabled. Stored encrypted. Leave the dots untouched to keep the current key.', 'livingdraft-core' ); ?>
					</p>
			</div>
			<div class="tld-field">
				<span class="tld-label"><?php esc_html_e( 'Enabled', 'livingdraft-core' ); ?></span>
					<label>
						<input type="checkbox" name="ld_factcheck_enabled" value="1"
							<?php checked( (bool) get_option( 'livingdraft_factcheck_enabled', true ) ); ?>>
						<?php esc_html_e( 'Show the Fact check panel when writing', 'livingdraft-core' ); ?>
					</label>
			</div>

			<div class="tld-field">
				<span class="tld-label"><?php esc_html_e( 'Article reader', 'livingdraft-core' ); ?></span>
					<?php
					$ld_readers = get_option( 'livingdraft_reader_keys', array() );
					$ld_readers = is_array( $ld_readers ) ? $ld_readers : array();

					foreach ( array( 'jina' => 'Jina Reader', 'firecrawl' => 'Firecrawl' ) as $ld_k => $ld_label ) :
						?>
						<p>
							<label style="display:inline-block;width:8em;"><?php echo esc_html( $ld_label ); ?></label>
							<input type="text" name="ld_reader_<?php echo esc_attr( $ld_k ); ?>" class="tld-input" autocomplete="off"
								value="<?php echo esc_attr( empty( $ld_readers[ $ld_k ] ) ? '' : str_repeat( '•', 24 ) ); ?>">
						</p>
					<?php endforeach; ?>
					<p class="tld-help">
						<?php esc_html_e( 'Optional. When a claim is flagged, the fact-check article itself is fetched and summarised. Without one of these the page is stripped of tags with a regular expression, which leaves navigation, cookie banners and related-article rails in the text — noise that competes with the actual fact-check for the model\'s attention. Both of these return the article as clean text instead.', 'livingdraft-core' ); ?>
					</p>
					<p class="tld-help">
						<?php esc_html_e( 'If the service is down or the key is wrong, the check falls back to fetching the page directly. A reader outage never stops a fact check.', 'livingdraft-core' ); ?>
					</p>
			</div>
		</div>

		<div class="tld-btn-row"><button class="tld-btn is-primary"><?php esc_html_e( 'Save fact-check settings', 'livingdraft-core' ); ?></button></div>
	</form>
	</div>
	<?php
}

/**
 * Register fact checking in the Settings rail.
 *
 * @since 4.4.0
 * @param array $sections Sections.
 * @return array
 */
function livingdraft_factcheck_register_settings_section( $sections ) {
	$sections['factcheck'] = array(
		'label'  => __( 'Fact check', 'livingdraft-core' ),
		'desc'   => __( 'Searches published fact-checks for claims in a draft. It does not verify your own figures.', 'livingdraft-core' ),
		'render' => 'livingdraft_factcheck_settings_panel',
		'cap'    => 'manage_options',
	);

	return $sections;
}
add_filter( 'livingdraft_settings_panels', 'livingdraft_factcheck_register_settings_section', 40 );
