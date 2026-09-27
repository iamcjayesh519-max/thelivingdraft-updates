<?php
/**
 * The AI settings screen.
 *
 * Lives under The Living Draft → SEO → AI. The user picks a provider
 * (OpenAI, Gemini, or OpenRouter), pastes their key, and chooses which
 * model to use per provider. A "Test" button fires one real call so
 * they know within two seconds whether the key works.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Save AI settings — handled here rather than the Settings API because
 * the encrypted-key round trip does not fit the SAPI pattern cleanly
 * and it is easier to reason about a single POST handler.
 */
function livingdraft_ai_admin_handle_save() {
	if ( ! isset( $_POST['ld_ai_save'] ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	check_admin_referer( 'ld_ai_settings' );

	$posted   = isset( $_POST['ld_ai'] ) ? (array) wp_unslash( $_POST['ld_ai'] ) : array();
	$existing = livingdraft_ai_get_settings();

	$provider = isset( $posted['provider'] ) ? sanitize_key( $posted['provider'] ) : 'openai';
	if ( ! in_array( $provider, array( 'openai', 'gemini', 'openrouter' ), true ) ) {
		$provider = 'openai';
	}

	$settings = array(
		'provider'          => $provider,
		'model_openai'      => isset( $posted['model_openai'] ) ? sanitize_text_field( $posted['model_openai'] ) : $existing['model_openai'],
		'model_gemini'      => isset( $posted['model_gemini'] ) ? sanitize_text_field( $posted['model_gemini'] ) : $existing['model_gemini'],
		'model_openrouter'  => isset( $posted['model_openrouter'] ) ? sanitize_text_field( $posted['model_openrouter'] ) : $existing['model_openrouter'],
		'models_openai'     => isset( $posted['models_openai'] ) ? sanitize_textarea_field( $posted['models_openai'] ) : $existing['models_openai'],
		'models_gemini'     => isset( $posted['models_gemini'] ) ? sanitize_textarea_field( $posted['models_gemini'] ) : $existing['models_gemini'],
		'models_openrouter' => isset( $posted['models_openrouter'] ) ? sanitize_textarea_field( $posted['models_openrouter'] ) : $existing['models_openrouter'],
		'embedding_model_openai' => isset( $posted['embedding_model_openai'] ) ? sanitize_text_field( $posted['embedding_model_openai'] ) : $existing['embedding_model_openai'],
		'embedding_model_gemini' => isset( $posted['embedding_model_gemini'] ) ? sanitize_text_field( $posted['embedding_model_gemini'] ) : $existing['embedding_model_gemini'],
	);

	update_option( 'livingdraft_ai_settings', $settings, false );

	// Keys: an empty submitted field means "no change". Only actual text
	// (not the "•••••" placeholder we render) triggers a rewrite. Users
	// who want to clear a key type the word "clear".
	foreach ( array( 'openai', 'gemini', 'openrouter' ) as $p ) {
		if ( ! isset( $posted[ 'key_' . $p ] ) ) {
			continue;
		}
		$submitted = trim( (string) $posted[ 'key_' . $p ] );
		if ( '' === $submitted || 0 === strpos( $submitted, '•' ) ) {
			continue; // Placeholder round-trip, no change intended.
		}
		if ( 'clear' === strtolower( $submitted ) ) {
			livingdraft_ai_set_key( $p, '' );
			continue;
		}
		livingdraft_ai_set_key( $p, $submitted );
	}

	wp_safe_redirect( add_query_arg( array( 'page' => 'livingdraft-settings', 'section' => 'ai', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
	exit;
}
add_action( 'admin_init', 'livingdraft_ai_admin_handle_save' );

/**
 * AJAX endpoint: fire one prompt at the configured provider and show
 * the result. Lets the user verify the key without leaving the page.
 */
function livingdraft_ai_admin_test() {
	check_ajax_referer( 'ld_ai_test', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permission.', 'livingdraft-core' ) ) );
	}

	$out = livingdraft_ai_complete(
		'Reply with the single word: pong',
		array(
			'task'        => 'connection_test',
			'max_tokens'  => 10,
			'temperature' => 0,
		)
	);

	if ( is_wp_error( $out ) ) {
		wp_send_json_error( array( 'message' => $out->get_error_message() ) );
	}

	wp_send_json_success( array( 'message' => sprintf( __( 'OK — model replied: %s', 'livingdraft-core' ), $out ) ) );
}
add_action( 'wp_ajax_ld_ai_test', 'livingdraft_ai_admin_test' );

/* ------------------------------------------------------------------
 * RENDER
 * ------------------------------------------------------------------ */

function livingdraft_ai_admin_render_tab() {
	$s     = livingdraft_ai_get_settings();
	$has   = function ( $provider ) {
		return '' !== livingdraft_ai_get_key( $provider );
	};

	if ( isset( $_GET['saved'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'AI settings saved.', 'livingdraft-core' ) . '</p></div>';
	}
	?>

	<div class="tld-card">
		<div class="tld-card-header">
			<div>
				<span class="tld-card-eyebrow"><?php esc_html_e( 'Bring your own key', 'livingdraft-core' ); ?></span>
				<h2 class="tld-card-title"><?php esc_html_e( 'AI provider', 'livingdraft-core' ); ?></h2>
			</div>
		</div>

		<p style="color:#666;max-width:60ch;margin-top:0">
			<?php esc_html_e( 'Every AI feature — meta generation, alt text, "fix this check" — routes through the provider selected here. Keys are stored encrypted; nothing is sent anywhere until you click a button that uses them.', 'livingdraft-core' ); ?>
		</p>

		<form method="post" action="">
			<?php wp_nonce_field( 'ld_ai_settings' ); ?>

			<div class="tld-field">
				<label class="tld-label"><?php esc_html_e( 'Active provider', 'livingdraft-core' ); ?></label>
				<div style="display:flex;gap:8px;flex-wrap:wrap">
					<?php
					$options = array(
						'openai'     => 'OpenAI',
						'gemini'     => 'Google Gemini',
						'openrouter' => 'OpenRouter',
					);
					foreach ( $options as $val => $label ) :
						$checked = $val === $s['provider'];
						?>
						<label style="display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border:1px solid <?php echo $checked ? 'var(--tld-mark,#8b3a2c)' : '#d4d4d4'; ?>;cursor:pointer;background:<?php echo $checked ? '#faf6f5' : '#fff'; ?>">
							<input type="radio" name="ld_ai[provider]" value="<?php echo esc_attr( $val ); ?>" <?php checked( $checked ); ?>>
							<span><?php echo esc_html( $label ); ?></span>
							<?php if ( $has( $val ) ) : ?>
								<span style="font-size:10px;color:var(--tld-good,#2f7a3a);font-family:var(--tld-mono,monospace)">● KEY SET</span>
							<?php endif; ?>
						</label>
					<?php endforeach; ?>
				</div>
			</div>

			<hr style="margin:24px 0;border:0;border-top:1px solid #eee">

			<?php
			$rows = array(
				'openai'     => array(
					'label'   => 'OpenAI',
					'placeholder' => 'sk-...',
					'model_field' => 'model_openai',
					'model_hint'  => 'gpt-5.4-mini · gpt-5.4-nano · gpt-5.4 · gpt-4.1-mini',
					'get_key'  => 'https://platform.openai.com/api-keys',
				),
				'gemini'     => array(
					'label'   => 'Google Gemini',
					'placeholder' => 'AIza...',
					'model_field' => 'model_gemini',
					'model_hint'  => 'gemini-3.6-flash · gemini-3.5-flash-lite · gemini-3-pro',
					'get_key'  => 'https://aistudio.google.com/apikey',
				),
				'openrouter' => array(
					'label'   => 'OpenRouter',
					'placeholder' => 'sk-or-...',
					'model_field' => 'model_openrouter',
					'model_hint'  => 'openai/gpt-5.4-mini · anthropic/claude-sonnet-5 · google/gemini-3.6-flash',
					'get_key'  => 'https://openrouter.ai/settings/keys',
				),
			);
			foreach ( $rows as $p => $row ) :
				$key_set = $has( $p );
				?>
				<div style="border:1px solid #e5e5e5;padding:16px;margin-bottom:12px;background:#fafafa">
					<h4 style="margin:0 0 12px;font-family:var(--tld-serif,Georgia,serif);font-weight:400;font-size:17px">
						<?php echo esc_html( $row['label'] ); ?>
						<a href="<?php echo esc_url( $row['get_key'] ); ?>" target="_blank" rel="noopener"
						   style="font-size:11px;font-family:var(--tld-mono,monospace);font-weight:400;margin-left:10px;color:var(--tld-ink-3,#666)">
							<?php esc_html_e( 'Get a key →', 'livingdraft-core' ); ?>
						</a>
					</h4>

					<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
						<div>
							<label class="tld-label"><?php esc_html_e( 'API key', 'livingdraft-core' ); ?></label>
							<input type="password" class="tld-input is-mono" autocomplete="off"
								name="ld_ai[key_<?php echo esc_attr( $p ); ?>]"
								placeholder="<?php echo esc_attr( $key_set ? '••••••••••••••••••' : $row['placeholder'] ); ?>">
							<p class="tld-help">
								<?php if ( $key_set ) : ?>
									<?php esc_html_e( 'A key is stored. Leave blank to keep it. Type "clear" to remove it.', 'livingdraft-core' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'Not set. Paste your key and click Save.', 'livingdraft-core' ); ?>
								<?php endif; ?>
							</p>
						</div>

						<div>
							<label class="tld-label"><?php esc_html_e( 'Default model', 'livingdraft-core' ); ?></label>
							<input type="text" class="tld-input is-mono"
								name="ld_ai[<?php echo esc_attr( $row['model_field'] ); ?>]"
								value="<?php echo esc_attr( $s[ $row['model_field'] ] ); ?>">
							<p class="tld-help"><?php echo esc_html( $row['model_hint'] ); ?></p>
						</div>
					</div>

					<div style="margin-top:12px">
						<label class="tld-label">
							<?php esc_html_e( 'Available models', 'livingdraft-core' ); ?>
							<span style="font-family:inherit;text-transform:none;letter-spacing:0;font-weight:400;color:#999">
								— <?php esc_html_e( 'one per line, shown in the per-task model picker', 'livingdraft-core' ); ?>
							</span>
						</label>
						<textarea class="tld-input is-mono" rows="3"
							name="ld_ai[models_<?php echo esc_attr( $p ); ?>]"
							placeholder="<?php esc_attr_e( 'One model name per line', 'livingdraft-core' ); ?>"><?php echo esc_textarea( $s[ 'models_' . $p ] ); ?></textarea>
						<p class="tld-help"><?php esc_html_e( 'These populate the "Model" dropdown on every AI-powered surface (brief workspace, metabox buttons, image alt) so you can switch models per invocation. The default above always shows first.', 'livingdraft-core' ); ?></p>
					</div>

					<?php if ( in_array( $p, array( 'openai', 'gemini' ), true ) ) : ?>
						<div style="margin-top:12px">
							<label class="tld-label"><?php esc_html_e( 'Embedding model', 'livingdraft-core' ); ?>
								<span style="font-family:inherit;text-transform:none;letter-spacing:0;font-weight:400;color:#999">
									— <?php esc_html_e( 'used by internal-link suggestions', 'livingdraft-core' ); ?>
								</span>
							</label>
							<input type="text" class="tld-input is-mono"
								name="ld_ai[embedding_model_<?php echo esc_attr( $p ); ?>]"
								value="<?php echo esc_attr( $s[ 'embedding_model_' . $p ] ); ?>">
							<p class="tld-help">
								<?php if ( 'openai' === $p ) : ?>
									text-embedding-3-small (cheap, 1536d) · text-embedding-3-large (bigger, 3072d)
								<?php else : ?>
									text-embedding-004 (768d)
								<?php endif; ?>
							</p>
						</div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>

			<div style="display:flex;gap:12px;align-items:center;margin-top:16px">
				<button type="submit" name="ld_ai_save" class="tld-btn is-primary">
					<?php esc_html_e( 'Save', 'livingdraft-core' ); ?>
				</button>
				<button type="button" id="ld-ai-test" class="tld-btn">
					<?php esc_html_e( 'Test connection', 'livingdraft-core' ); ?>
				</button>
				<span id="ld-ai-test-result" style="font-family:var(--tld-mono,monospace);font-size:12px;color:#666"></span>
			</div>
		</form>
	</div>


	<script>
	(function () {
		var btn = document.getElementById( 'ld-ai-test' );
		var out = document.getElementById( 'ld-ai-test-result' );
		if ( ! btn ) return;
		btn.addEventListener( 'click', function () {
			out.textContent = '<?php echo esc_js( __( 'Calling…', 'livingdraft-core' ) ); ?>';
			out.style.color = '#666';
			var body = new FormData();
			body.append( 'action', 'ld_ai_test' );
			body.append( 'nonce', '<?php echo esc_js( wp_create_nonce( 'ld_ai_test' ) ); ?>' );
			fetch( ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( res.success ) {
						out.textContent = res.data.message;
						out.style.color = '#2f7a3a';
					} else {
						out.textContent = '✗ ' + ( res.data.message || 'Failed.' );
						out.style.color = '#a32e2e';
					}
				} )
				.catch( function ( err ) {
					out.textContent = '✗ ' + err.message;
					out.style.color = '#a32e2e';
				} );
		} );
	}());
	</script>
	<?php
}

/**
 * Register AI in the Settings rail.
 *
 * Moved out of SEO → AI in 4.4.0. The keys power the SEO metabox, but they
 * also power fact-checking, image alt text, content briefs, internal links
 * and the mail drafting buttons — filing them under SEO made them look like
 * an SEO feature, which is why people could not find them.
 *
 * @since 4.4.0
 * @param array $sections Sections.
 * @return array
 */
function livingdraft_ai_register_settings_section( $sections ) {
	$sections['ai'] = array(
		'label'  => __( 'AI', 'livingdraft-core' ),
		'desc'   => __( 'Bring your own key. Nothing calls out until you press a button that needs it.', 'livingdraft-core' ),
		'render' => 'livingdraft_ai_admin_render_tab',
		'cap'    => 'manage_options',
	);

	return $sections;
}
add_filter( 'livingdraft_settings_panels', 'livingdraft_ai_register_settings_section', 10 );
