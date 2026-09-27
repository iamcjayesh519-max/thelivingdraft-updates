<?php
/**
 * The AI settings screen.
 *
 * Lives under The Living Draft → SEO → AI. The user picks a provider
 * (OpenAI, Gemini, xAI Grok, OpenRouter or Groq), pastes their key, and chooses which
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
	if ( ! in_array( $provider, livingdraft_ai_provider_ids(), true ) ) {
		$provider = 'openai';
	}

	// Start from what is stored, so fields not on this form (and future
	// ones) are never wiped by a save.
	$settings             = $existing;
	$settings['provider'] = $provider;

	foreach ( livingdraft_ai_provider_ids() as $p ) {
		if ( isset( $posted[ 'model_' . $p ] ) ) {
			$settings[ 'model_' . $p ] = sanitize_text_field( $posted[ 'model_' . $p ] );
		}
		if ( isset( $posted[ 'models_' . $p ] ) ) {
			$settings[ 'models_' . $p ] = sanitize_textarea_field( $posted[ 'models_' . $p ] );
		}
	}
	foreach ( array( 'embedding_model_openai', 'embedding_model_gemini' ) as $f ) {
		if ( isset( $posted[ $f ] ) ) {
			$settings[ $f ] = sanitize_text_field( $posted[ $f ] );
		}
	}

	$settings['fallback'] = ! empty( $posted['fallback'] );
	if ( isset( $posted['fallback_order'] ) ) {
		$order = array_intersect( array_map( 'sanitize_key', explode( ',', (string) $posted['fallback_order'] ) ), livingdraft_ai_provider_ids() );
		$settings['fallback_order'] = implode( ',', array_unique( $order ) );
	}

	update_option( 'livingdraft_ai_settings', $settings, false );

	// Keys: an empty submitted field means "no change". Only actual text
	// (not the "•••••" placeholder we render) triggers a rewrite. Users
	// who want to clear a key type the word "clear".
	foreach ( livingdraft_ai_provider_ids() as $p ) {
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

	// v4.9.0: test every provider that has a key, each on its own,
	// without fallback — otherwise a dead key would look healthy.
	$lines = array();
	$ok    = false;
	foreach ( livingdraft_ai_provider_labels() as $p => $label ) {
		if ( '' === livingdraft_ai_get_key( $p ) ) {
			continue;
		}
		$out = livingdraft_ai_complete(
			'Reply with the single word: pong',
			array(
				'task'        => 'connection_test',
				'provider'    => $p,
				'max_tokens'  => 16,
				'temperature' => 0,
				'no_fallback' => true,
			)
		);
		if ( is_wp_error( $out ) ) {
			$lines[] = '✗ ' . $label . ' (' . livingdraft_ai_default_model( $p ) . '): ' . $out->get_error_message();
		} else {
			$ok      = true;
			$lines[] = '✓ ' . $label . ' (' . livingdraft_ai_default_model( $p ) . '): ' . wp_trim_words( $out, 6 );
		}
	}

	if ( empty( $lines ) ) {
		wp_send_json_error( array( 'message' => __( 'No provider has a key yet. Paste one and click Save first.', 'livingdraft-core' ) ) );
	}

	$payload = array( 'message' => implode( "\n", $lines ) );
	if ( $ok ) {
		wp_send_json_success( $payload );
	}
	wp_send_json_error( $payload );
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
					$options = livingdraft_ai_provider_labels();
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
				'xai'        => array(
					'label'       => 'xAI Grok',
					'placeholder' => 'xai-...',
					'model_field' => 'model_xai',
					'model_hint'  => 'grok-4.3 (everyday, 1M context) · grok-4.20-non-reasoning (fast) · grok-4.6 / grok-4.7 (flagship)',
					'get_key'     => 'https://console.x.ai',
				),
				'openrouter' => array(
					'label'   => 'OpenRouter',
					'placeholder' => 'sk-or-...',
					'model_field' => 'model_openrouter',
					'model_hint'  => 'openai/gpt-5.4-mini · anthropic/claude-sonnet-5 · google/gemini-3.6-flash',
					'get_key'  => 'https://openrouter.ai/settings/keys',
				),
				'groq'       => array(
					'label'       => 'Groq',
					'placeholder' => 'gsk_...',
					'model_field' => 'model_groq',
					'model_hint'  => 'llama-3.3-70b-versatile · llama-3.1-8b-instant (text only, very fast)',
					'get_key'     => 'https://console.groq.com/keys',
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
								value="<?php echo esc_attr( $s[ $row['model_field'] ] ?? '' ); ?>">
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
							placeholder="<?php esc_attr_e( 'One model name per line', 'livingdraft-core' ); ?>"><?php echo esc_textarea( $s[ 'models_' . $p ] ?? '' ); ?></textarea>
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

			<div style="border:1px solid #e5e5e5;padding:16px;margin-bottom:12px;background:#fff">
				<h4 style="margin:0 0 8px;font-family:var(--tld-serif,Georgia,serif);font-weight:400;font-size:17px"><?php esc_html_e( 'If a provider fails', 'livingdraft-core' ); ?></h4>
				<label class="tld-check">
					<input type="checkbox" name="ld_ai[fallback]" value="1" <?php checked( ! empty( $s['fallback'] ) ); ?>>
					<span><?php esc_html_e( 'Fall back to the next provider that has a key', 'livingdraft-core' ); ?></span>
				</label>
				<p class="tld-help"><?php esc_html_e( 'With Gemini, OpenAI and Grok all set, an outage, a spent quota or a retired model name on one of them no longer stops a button from working — the same request goes to the next one.', 'livingdraft-core' ); ?></p>
				<label class="tld-label" for="ld-ai-fallback-order"><?php esc_html_e( 'Order', 'livingdraft-core' ); ?></label>
				<input id="ld-ai-fallback-order" type="text" class="tld-input is-mono" name="ld_ai[fallback_order]" value="<?php echo esc_attr( $s['fallback_order'] ); ?>">
				<p class="tld-help"><?php echo esc_html( sprintf( /* translators: %s: ids */ __( 'Comma-separated. Ids: %s', 'livingdraft-core' ), implode( ', ', livingdraft_ai_provider_ids() ) ) ); ?></p>
			</div>

			<div style="display:flex;gap:12px;align-items:center;margin-top:16px">
				<button type="submit" name="ld_ai_save" class="tld-btn is-primary">
					<?php esc_html_e( 'Save', 'livingdraft-core' ); ?>
				</button>
				<button type="button" id="ld-ai-test" class="tld-btn">
					<?php esc_html_e( 'Test connection', 'livingdraft-core' ); ?>
				</button>
				<span id="ld-ai-test-result" style="font-family:var(--tld-mono,monospace);font-size:12px;color:#666;white-space:pre-line"></span>
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
						out.textContent = ( res.data.message || '✗ Failed.' );
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
