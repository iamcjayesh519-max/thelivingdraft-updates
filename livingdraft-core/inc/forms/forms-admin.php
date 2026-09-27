<?php
/**
 * Forms: the Messages screen.
 *
 * A list of what readers have sent, and one message open at a time. The
 * "Draft replies" button reuses livingdraft_mail_ai_reply() — the same
 * function behind Mail → Replies — with the message text already filled in,
 * so answering a reader is one press rather than a copy, a paste and a
 * context switch.
 *
 * Nothing here sends. The drafts are copied into your own mail client, where
 * the thread and the sender are visible. Same line as everywhere else in this
 * plugin: the machine writes, a person sends.
 *
 * @package LivingDraftCore
 * @since 4.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the screen.
 *
 * @since 4.5.0
 * @return void
 */
function livingdraft_forms_menu() {
	$unread = livingdraft_forms_unread_count();

	add_submenu_page(
		'livingdraft-overview',
		__( 'Messages', 'livingdraft-core' ),
		$unread > 0
			? sprintf(
				/* translators: %s: unread count bubble. */
				__( 'Messages %s', 'livingdraft-core' ),
				'<span class="awaiting-mod"><span class="pending-count">' . esc_html( number_format_i18n( $unread ) ) . '</span></span>'
			)
			: __( 'Messages', 'livingdraft-core' ),
		'edit_posts',
		'livingdraft-messages',
		'livingdraft_forms_screen'
	);
}
add_action( 'admin_menu', 'livingdraft_forms_menu', 32 );

/**
 * Actions.
 *
 * @since 4.5.0
 * @return void
 */
function livingdraft_forms_handle_actions() {
	if ( ! isset( $_POST['ld_message_action'] ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'livingdraft-core' ) );
	}

	$action = sanitize_key( wp_unslash( $_POST['ld_message_action'] ) );
	check_admin_referer( 'ld_message_' . $action );

	$id = isset( $_POST['message'] ) ? absint( wp_unslash( $_POST['message'] ) ) : 0;

	if ( ! $id || 'ld_message' !== get_post_type( $id ) ) {
		return;
	}

	switch ( $action ) {
		case 'answered':
			update_post_meta( $id, '_ld_state', 'answered' );
			break;

		case 'reopen':
			update_post_meta( $id, '_ld_state', 'read' );
			break;

		case 'trash':
			wp_trash_post( $id );
			$id = 0;
			break;
	}

	wp_safe_redirect(
		add_query_arg(
			array_filter(
				array(
					'page'    => 'livingdraft-messages',
					'message' => $id ? $id : null,
				)
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}
add_action( 'admin_init', 'livingdraft_forms_handle_actions' );

/**
 * Assets. Reuses the mail admin script for the reply drafting.
 *
 * @since 4.5.0
 * @param string $hook Current page.
 * @return void
 */
function livingdraft_forms_admin_assets( $hook ) {
	if ( false === strpos( $hook, 'livingdraft-messages' ) ) {
		return;
	}

	$js = LIVINGDRAFT_CORE_DIR . 'assets/js/mail.js';

	if ( ! file_exists( $js ) ) {
		return;
	}

	wp_enqueue_script(
		'livingdraft-mail',
		LIVINGDRAFT_CORE_URL . 'assets/js/mail.js',
		array(),
		(string) filemtime( $js ),
		true
	);

	wp_localize_script(
		'livingdraft-mail',
		'ldMail',
		array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'ld_mail_ai' ),
			'i18n'  => array(
				'working' => __( 'Working…', 'livingdraft-core' ),
				'failed'  => __( 'That did not work. Try again.', 'livingdraft-core' ),
				'copy'    => __( 'Copy', 'livingdraft-core' ),
				'copied'  => __( 'Copied', 'livingdraft-core' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'livingdraft_forms_admin_assets' );

/**
 * The screen.
 *
 * @since 4.5.0
 * @return void
 */
function livingdraft_forms_screen() {
	$open = isset( $_GET['message'] ) ? absint( wp_unslash( $_GET['message'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	livingdraft_admin_render_header(
		array(
			'eyebrow' => __( 'The Living Draft Core · Messages', 'livingdraft-core' ),
			'title'   => __( 'Messages', 'livingdraft-core' ),
			'desc'    => __( 'What readers have sent through the contact form. Stored here whether or not the notification email got through.', 'livingdraft-core' ),
			'actions' => array(
				array(
					'label' => __( 'Form settings', 'livingdraft-core' ),
					'url'   => admin_url( 'admin.php?page=livingdraft-settings&section=forms' ),
				),
			),
		)
	);

	if ( $open ) {
		livingdraft_forms_render_message( $open );
	}

	livingdraft_forms_render_list( $open );

	livingdraft_admin_render_footer();
}

/**
 * One message, open.
 *
 * @since 4.5.0
 * @param int $id Message id.
 * @return void
 */
function livingdraft_forms_render_message( $id ) {
	$m = livingdraft_forms_message( $id );

	if ( empty( $m ) ) {
		return;
	}

	// Opening it is what marks it read. No separate button for something
	// the act of looking already establishes.
	if ( 'new' === $m['state'] ) {
		update_post_meta( $id, '_ld_state', 'read' );
		$m['state'] = 'read';
	}

	$states = livingdraft_forms_states();
	?>
	<div class="tld-card">
		<div class="tld-section-rule">
			<h2><?php echo esc_html( $m['subject'] ); ?></h2>
			<span class="tld-section-eyebrow"><?php echo esc_html( $states[ $m['state'] ] ?? $m['state'] ); ?></span>
		</div>

		<p class="tld-row-meta">
			<?php
			printf(
				/* translators: 1: sender name, 2: email, 3: date. */
				esc_html__( '%1$s (%2$s) · %3$s', 'livingdraft-core' ),
				esc_html( $m['name'] ),
				esc_html( $m['email'] ),
				esc_html( $m['received'] )
			);
			?>
		</p>

		<?php if ( ! $m['notified'] ) : ?>
			<div class="tld-notice is-bad">
				<p>
					<strong><?php esc_html_e( 'The notification email did not send.', 'livingdraft-core' ); ?></strong>
					<?php echo esc_html( $m['error'] ); ?>
					<?php esc_html_e( 'The message itself is safe — you are reading it. Check Settings → Mail.', 'livingdraft-core' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<div class="tld-message-body"><?php echo esc_html( $m['message'] ); ?></div>

		<div class="tld-btn-row">
			<a class="tld-btn is-primary" href="<?php echo esc_url( 'mailto:' . rawurlencode( $m['email'] ) . '?subject=' . rawurlencode( 'Re: ' . $m['subject'] ) ); ?>">
				<?php esc_html_e( 'Reply in your mail client', 'livingdraft-core' ); ?>
			</a>

			<?php if ( function_exists( 'livingdraft_mail_ai_ready' ) && livingdraft_mail_ai_ready() ) : ?>
				<button type="button" class="tld-btn is-ai" id="ld-ai-reply"><?php esc_html_e( 'Draft replies', 'livingdraft-core' ); ?></button>
				<span id="ld-reply-status" class="tld-inline-status"></span>
			<?php endif; ?>

			<?php if ( 'answered' !== $m['state'] ) : ?>
				<form method="post">
					<?php wp_nonce_field( 'ld_message_answered' ); ?>
					<input type="hidden" name="ld_message_action" value="answered" />
					<input type="hidden" name="message" value="<?php echo esc_attr( (string) $id ); ?>" />
					<button class="tld-btn"><?php esc_html_e( 'Mark answered', 'livingdraft-core' ); ?></button>
				</form>
			<?php else : ?>
				<form method="post">
					<?php wp_nonce_field( 'ld_message_reopen' ); ?>
					<input type="hidden" name="ld_message_action" value="reopen" />
					<input type="hidden" name="message" value="<?php echo esc_attr( (string) $id ); ?>" />
					<button class="tld-btn"><?php esc_html_e( 'Reopen', 'livingdraft-core' ); ?></button>
				</form>
			<?php endif; ?>

			<form method="post" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Move this message to the trash?', 'livingdraft-core' ) ) ); ?>);">
				<?php wp_nonce_field( 'ld_message_trash' ); ?>
				<input type="hidden" name="ld_message_action" value="trash" />
				<input type="hidden" name="message" value="<?php echo esc_attr( (string) $id ); ?>" />
				<button class="tld-btn is-danger"><?php esc_html_e( 'Trash', 'livingdraft-core' ); ?></button>
			</form>
		</div>

		<?php
		/*
		 * The reply drafter in mail.js reads #ld-reply-message and
		 * #ld-reply-intent. Both are present here, the message one already
		 * filled and hidden — the reader's text is directly above, and
		 * showing it twice in an editable box invites someone to change it
		 * and then wonder why the drafts do not match what was sent.
		 */
		?>
		<textarea id="ld-reply-message" hidden><?php echo esc_textarea( $m['message'] ); ?></textarea>

		<?php if ( function_exists( 'livingdraft_mail_ai_ready' ) && livingdraft_mail_ai_ready() ) : ?>
			<div class="tld-field">
				<label class="tld-label" for="ld-reply-intent"><?php esc_html_e( 'What should the reply do?', 'livingdraft-core' ); ?></label>
				<input type="text" id="ld-reply-intent" class="tld-input" placeholder="<?php esc_attr_e( 'thank them and say we are looking into it', 'livingdraft-core' ); ?>" />
			</div>
		<?php endif; ?>

		<div id="ld-reply-output" class="tld-suggestions"></div>
	</div>
	<?php
}

/**
 * The list.
 *
 * @since 4.5.0
 * @param int $open Currently open message.
 * @return void
 */
function livingdraft_forms_render_list( $open ) {
	$messages = get_posts(
		array(
			'post_type'      => 'ld_message',
			'post_status'    => 'publish',
			'posts_per_page' => 50,
		)
	);
	?>
	<div class="tld-card">
		<div class="tld-section-rule"><h2><?php esc_html_e( 'Inbox', 'livingdraft-core' ); ?></h2></div>

		<?php if ( empty( $messages ) ) : ?>
			<div class="tld-empty">
				<p class="tld-empty-title"><?php esc_html_e( 'No messages yet', 'livingdraft-core' ); ?></p>
				<p class="tld-empty-desc">
					<?php esc_html_e( 'Put the [contact_form] shortcode on a page to start receiving them.', 'livingdraft-core' ); ?>
				</p>
			</div>
		<?php else : ?>
			<ul class="tld-rows">
				<?php foreach ( $messages as $post ) : ?>
					<?php
					$m      = livingdraft_forms_message( $post->ID );
					$states = livingdraft_forms_states();
					$kick   = array(
						'new'      => 'is-mark',
						'read'     => 'is-ghost',
						'answered' => 'is-good',
					);
					?>
					<li class="tld-row<?php echo (int) $open === (int) $post->ID ? ' is-open' : ''; ?>">
						<div class="tld-row-main">
							<a class="tld-row-title" href="<?php echo esc_url( admin_url( 'admin.php?page=livingdraft-messages&message=' . $post->ID ) ); ?>">
								<?php echo esc_html( $m['subject'] ); ?>
							</a>
							<p class="tld-row-meta">
								<?php echo esc_html( $m['name'] ); ?> · <?php echo esc_html( $m['received'] ); ?>
							</p>
						</div>

						<?php if ( ! $m['notified'] ) : ?>
							<span class="tld-figure is-bad"><?php esc_html_e( 'not emailed', 'livingdraft-core' ); ?></span>
						<?php endif; ?>

						<span class="tld-kicker <?php echo esc_attr( $kick[ $m['state'] ] ?? 'is-ghost' ); ?>">
							<?php echo esc_html( $states[ $m['state'] ] ?? $m['state'] ); ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
	<?php
}

/* ==================================================================
 * SETTINGS SECTION
 * ================================================================== */

/**
 * Form settings.
 *
 * @since 4.5.0
 * @return void
 */
function livingdraft_forms_render_settings() {
	if ( isset( $_POST['ld_forms_save'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		check_admin_referer( 'ld_forms_settings' );

		update_option(
			LD_FORMS_SETTINGS,
			array(
				'recipient'      => sanitize_email( wp_unslash( $_POST['recipient'] ?? '' ) ),
				'subject_prefix' => sanitize_text_field( wp_unslash( $_POST['subject_prefix'] ?? '' ) ),
				'acknowledge'    => ! empty( $_POST['acknowledge'] ),
				'store_ip'       => ! empty( $_POST['store_ip'] ),
			)
		);

		echo '<div class="tld-notice"><p>' . esc_html__( 'Form settings saved.', 'livingdraft-core' ) . '</p></div>';
	}

	$settings = livingdraft_forms_settings();
	?>
	<div class="tld-card">
		<div class="tld-section-rule"><h2><?php esc_html_e( 'Contact form', 'livingdraft-core' ); ?></h2></div>

		<p class="tld-help">
			<?php esc_html_e( 'Place the form with the [contact_form] shortcode. Messages are stored here first and emailed second, so a mail failure never loses one.', 'livingdraft-core' ); ?>
		</p>

		<form method="post">
			<?php wp_nonce_field( 'ld_forms_settings' ); ?>

			<div class="tld-grid-2">
				<div class="tld-field">
					<label class="tld-label" for="ld-forms-recipient"><?php esc_html_e( 'Send notifications to', 'livingdraft-core' ); ?></label>
					<input type="email" id="ld-forms-recipient" name="recipient" class="tld-input" value="<?php echo esc_attr( $settings['recipient'] ); ?>" />
					<p class="tld-help"><?php esc_html_e( 'The notification comes From your own verified address with Reply-To set to the sender, so pressing reply answers the reader. Sending it as the reader would forge the From header and fail DMARC.', 'livingdraft-core' ); ?></p>
				</div>

				<div class="tld-field">
					<label class="tld-label" for="ld-forms-prefix"><?php esc_html_e( 'Subject prefix', 'livingdraft-core' ); ?></label>
					<input type="text" id="ld-forms-prefix" name="subject_prefix" class="tld-input" value="<?php echo esc_attr( $settings['subject_prefix'] ); ?>" />
					<p class="tld-help"><?php esc_html_e( 'Prepended to the reader\'s own subject line, so an inbox rule can file these.', 'livingdraft-core' ); ?></p>
				</div>
			</div>

			<div class="tld-field">
				<label class="tld-check">
					<input type="checkbox" name="acknowledge" value="1" <?php checked( ! empty( $settings['acknowledge'] ) ); ?> />
					<span><?php esc_html_e( 'Send the reader a copy of what they wrote', 'livingdraft-core' ); ?></span>
				</label>
				<p class="tld-help"><?php esc_html_e( 'Worth having. It proves the form worked, and gives them a record of what they said if they need to follow up.', 'livingdraft-core' ); ?></p>
			</div>

			<div class="tld-field">
				<label class="tld-check">
					<input type="checkbox" name="store_ip" value="1" <?php checked( ! empty( $settings['store_ip'] ) ); ?> />
					<span><?php esc_html_e( 'Store an anonymised IP with each message', 'livingdraft-core' ); ?></span>
				</label>
				<p class="tld-help"><?php esc_html_e( 'Off by default. Only useful for spotting a spam pattern, and it makes every message personal data you now have to account for.', 'livingdraft-core' ); ?></p>
			</div>

			<div class="tld-btn-row">
				<button type="submit" name="ld_forms_save" value="1" class="tld-btn is-primary"><?php esc_html_e( 'Save form settings', 'livingdraft-core' ); ?></button>
			</div>
		</form>
	</div>
	<?php
}

/**
 * Register Forms in the Settings rail.
 *
 * @since 4.5.0
 * @param array $sections Sections.
 * @return array
 */
function livingdraft_forms_register_settings_section( $sections ) {
	$sections['forms'] = array(
		'label'  => __( 'Forms', 'livingdraft-core' ),
		'desc'   => __( 'The contact form: where messages go, and what the reader gets back.', 'livingdraft-core' ),
		'render' => 'livingdraft_forms_render_settings',
		'cap'    => 'manage_options',
	);

	return $sections;
}
add_filter( 'livingdraft_settings_panels', 'livingdraft_forms_register_settings_section', 25 );
