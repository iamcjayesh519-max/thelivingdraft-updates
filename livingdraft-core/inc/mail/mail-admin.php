<?php
/**
 * Mail: the admin screens.
 *
 * One page, four sub-tabs: Newsletters, Write, Replies, Settings.
 *
 * @package LivingDraftCore
 * @since 4.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the screen.
 *
 * @since 4.3.0
 * @return void
 */
function livingdraft_mail_menu() {
	add_submenu_page(
		'livingdraft-overview',
		__( 'Mail', 'livingdraft-core' ),
		__( 'Mail', 'livingdraft-core' ),
		'edit_posts',
		'livingdraft-mail',
		'livingdraft_mail_screen'
	);
}
add_action( 'admin_menu', 'livingdraft_mail_menu', 35 );

/**
 * Confirmed subscriber count, for the tab badge.
 *
 * @since 4.3.0
 * @return int
 */
function livingdraft_subscriber_confirmed_count() {
	$counts = livingdraft_subscriber_counts();

	return (int) ( $counts['confirmed'] ?? 0 );
}

/**
 * Assets for the compose screen.
 *
 * @since 4.3.0
 * @param string $hook Current admin page.
 * @return void
 */
function livingdraft_mail_admin_assets( $hook ) {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	// The mail settings moved to the Settings screen in 4.4.0, so the
	// script has to load there as well as on Mail itself.
	if ( 'livingdraft-mail' !== $page && 'livingdraft-settings' !== $page ) {
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
			'verifyNonce' => wp_create_nonce( 'ld_mail_verify' ),
			'i18n'  => array(
				'working' => __( 'Checking…', 'livingdraft-core' ),
				'failed'  => __( 'That did not work. Try again.', 'livingdraft-core' ),
				'use'     => __( 'Use this', 'livingdraft-core' ),
				'copy'    => __( 'Copy', 'livingdraft-core' ),
				'copied'  => __( 'Copied', 'livingdraft-core' ),
				'replace' => __( 'This will replace what is currently in the editor. Continue?', 'livingdraft-core' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'livingdraft_mail_admin_assets' );

/* ==================================================================
 * 1. ACTIONS
 * ================================================================== */

/**
 * Handle form posts, then redirect so a refresh cannot repeat them.
 *
 * That redirect matters more here than on most screens: the repeated action
 * would be sending a newsletter to the whole list a second time.
 *
 * @since 4.3.0
 * @return void
 */
function livingdraft_mail_handle_actions() {
	if ( ! isset( $_POST['ld_mail_action'] ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'livingdraft-core' ) );
	}

	$action = sanitize_key( wp_unslash( $_POST['ld_mail_action'] ) );
	check_admin_referer( 'ld_mail_' . $action );

	$id     = isset( $_POST['campaign'] ) ? absint( wp_unslash( $_POST['campaign'] ) ) : 0;
	$notice = '';
	$tab    = 'campaigns';

	// Settings actions return to the Settings screen, not to Mail.
	$redirect_settings = false;

	switch ( $action ) {

		case 'save':
			$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
			$body    = isset( $_POST['body'] ) ? wp_kses_post( wp_unslash( $_POST['body'] ) ) : '';

			$data = array(
				'post_type'    => 'ld_campaign',
				'post_status'  => 'publish',
				'post_title'   => $subject ? $subject : __( '(no subject)', 'livingdraft-core' ),
				'post_content' => $body,
			);

			if ( $id ) {
				$data['ID'] = $id;
				wp_update_post( $data );
			} else {
				$id = (int) wp_insert_post( $data );
				update_post_meta( $id, '_ld_state', 'draft' );
			}

			$notice = 'saved';
			$tab    = 'write';
			break;

		case 'test':
			$to = isset( $_POST['test_to'] ) ? sanitize_email( wp_unslash( $_POST['test_to'] ) ) : '';

			if ( ! is_email( $to ) ) {
				$notice = 'test_bad';
				break;
			}

			$post = $id ? get_post( $id ) : null;

			$result = livingdraft_mail_send(
				$to,
				$post ? $post->post_title : __( 'Test message', 'livingdraft-core' ),
				livingdraft_campaign_render(
					$post ? $post->post_content : __( 'This is a test of the mail settings. If it arrived, they work.', 'livingdraft-core' ),
					home_url( '/' )
				),
				array(
					'kind'    => 'transactional',
					'context' => 'test',
				)
			);

			$notice = is_wp_error( $result ) ? 'test_failed' : 'test_sent';

			if ( ! $id ) {
				$redirect_settings = true;
			} else {
				$tab = 'write';
			}

			if ( is_wp_error( $result ) ) {
				set_transient( 'ld_mail_error', $result->get_error_message(), 60 );
			}
			break;

		case 'send':
			$result = livingdraft_campaign_start( $id );

			if ( is_wp_error( $result ) ) {
				set_transient( 'ld_mail_error', $result->get_error_message(), 60 );
				$notice = 'send_failed';
			} else {
				$notice = 'sending';
			}
			break;

		case 'pause':
			livingdraft_campaign_pause( $id );
			$notice = 'paused';
			break;

		case 'resume':
			livingdraft_campaign_resume( $id );
			$notice = 'resumed';
			break;

		case 'settings':
			livingdraft_mail_save_settings();
			$notice = 'settings_saved';
			$redirect_settings = true;
			break;
	}

	if ( $redirect_settings ) {
		wp_safe_redirect(
			add_query_arg(
				array_filter(
					array(
						'page'    => 'livingdraft-settings',
						'section' => 'mail',
						'notice'  => $notice ? $notice : null,
					)
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	wp_safe_redirect(
		add_query_arg(
			array_filter(
				array(
					'page'     => 'livingdraft-mail',
					'tab'      => $tab,
					'campaign' => $id ? $id : null,
					'notice'   => $notice ? $notice : null,
				)
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}
add_action( 'admin_init', 'livingdraft_mail_handle_actions' );

/**
 * AJAX: check a credential.
 *
 * @since 4.6.0
 * @return void
 */
function livingdraft_mail_ajax_verify() {
	check_ajax_referer( 'ld_mail_verify', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'livingdraft-core' ) ), 403 );
	}

	$which = isset( $_POST['which'] ) ? sanitize_key( wp_unslash( $_POST['which'] ) ) : '';

	if ( 'smtp' === $which ) {
		$result = livingdraft_mail_verify_smtp();
	} elseif ( in_array( $which, array( 'brevo', 'resend' ), true ) ) {
		$result = livingdraft_mail_verify_key( $which );
	} else {
		wp_send_json_error( array( 'message' => __( 'Unknown credential.', 'livingdraft-core' ) ) );
	}

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success( array( 'findings' => (array) $result ) );
}
add_action( 'wp_ajax_ld_mail_verify', 'livingdraft_mail_ajax_verify' );

/**
 * Save the settings form.
 *
 * @since 4.3.0
 * @return void
 */
function livingdraft_mail_save_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to change these settings.', 'livingdraft-core' ) );
	}

	$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by the caller.

	$channels = array( 'wp', 'smtp', 'brevo', 'resend' );

	$settings = array(
		'transactional' => in_array( $post['transactional'] ?? '', $channels, true ) ? $post['transactional'] : 'wp',
		'bulk'          => in_array( $post['bulk'] ?? '', $channels, true ) ? $post['bulk'] : 'wp',
		'from_name'     => sanitize_text_field( $post['from_name'] ?? '' ),
		'from_email'    => sanitize_email( $post['from_email'] ?? '' ),
		'reply_to'      => sanitize_email( $post['reply_to'] ?? '' ),
		'smtp_host'     => sanitize_text_field( $post['smtp_host'] ?? '' ),
		'smtp_port'     => absint( $post['smtp_port'] ?? 587 ),
		'smtp_secure'   => in_array( $post['smtp_secure'] ?? '', array( 'tls', 'ssl', 'none' ), true ) ? $post['smtp_secure'] : 'tls',
		'smtp_user'     => sanitize_text_field( $post['smtp_user'] ?? '' ),
		'smtp_auth'     => ! empty( $post['smtp_auth'] ),
		'daily_cap'     => max( 1, absint( $post['daily_cap'] ?? 280 ) ),
		'batch_size'    => max( 1, min( 200, absint( $post['batch_size'] ?? 40 ) ) ),
		'take_over_wp'  => ! empty( $post['take_over_wp'] ),
	);

	update_option( LD_MAIL_SETTINGS, $settings );

	/*
	 * Secrets are only written when a value was typed. The fields render
	 * empty even when a key is stored, so an admin who opens the page and
	 * presses Save without touching them must not wipe the credentials —
	 * which is exactly what reading the empty field would do.
	 */
	foreach ( array( 'smtp_pass', 'brevo_key', 'resend_key' ) as $secret ) {
		if ( isset( $post[ $secret ] ) && '' !== trim( (string) $post[ $secret ] ) ) {
			livingdraft_mail_set_secret( $secret, trim( (string) $post[ $secret ] ) );
		}

		if ( ! empty( $post[ 'clear_' . $secret ] ) ) {
			livingdraft_mail_set_secret( $secret, '' );
		}
	}
}

/* ==================================================================
 * 2. THE SCREEN
 * ================================================================== */

/**
 * Router.
 *
 * @since 4.3.0
 * @return void
 */
function livingdraft_mail_screen() {
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'campaigns'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	$counts = livingdraft_subscriber_counts();

	livingdraft_admin_render_header(
		array(
			'eyebrow' => __( 'The Living Draft Core · Mail', 'livingdraft-core' ),
			'title'   => __( 'Mail', 'livingdraft-core' ),
			'desc'    => __( 'Newsletters, the subscriber list, and how mail leaves this server.', 'livingdraft-core' ),
			'actions' => array(
				array(
					'label' => __( 'Mail settings', 'livingdraft-core' ),
					'url'   => admin_url( 'admin.php?page=livingdraft-settings&section=mail' ),
				),
			),
		)
	);

	// Settings are NOT a tab here. They live on the consolidated Settings
	// screen with every other module's configuration — see 4.4.0.
	livingdraft_admin_render_subtabs(
		array(
			'campaigns' => array(
				'label' => __( 'Newsletters', 'livingdraft-core' ),
				'count' => 'livingdraft_subscriber_confirmed_count',
			),
			'write'     => array( 'label' => __( 'Write', 'livingdraft-core' ) ),
			'replies'   => array( 'label' => __( 'Replies', 'livingdraft-core' ) ),
		),
		$tab,
		'livingdraft-mail'
	);

	livingdraft_mail_render_notice();

	switch ( $tab ) {
		case 'write':
			livingdraft_mail_render_write();
			break;

		case 'replies':
			livingdraft_mail_render_replies();
			break;

		default:
			livingdraft_mail_render_campaigns( $counts );
	}

	livingdraft_admin_render_footer();
}

/**
 * Notices.
 *
 * @since 4.3.0
 * @return void
 */
function livingdraft_mail_render_notice() {
	$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( ! $notice ) {
		return;
	}

	$messages = array(
		'saved'          => array( 'ok', __( 'Saved.', 'livingdraft-core' ) ),
		'sending'        => array( 'ok', __( 'Sending has started. It continues in the background — you can close this page.', 'livingdraft-core' ) ),
		'paused'         => array( 'ok', __( 'Paused. Nothing more will go out until you resume it.', 'livingdraft-core' ) ),
		'resumed'        => array( 'ok', __( 'Resumed.', 'livingdraft-core' ) ),
		'test_sent'      => array( 'ok', __( 'Test message sent. If it does not arrive within a minute or two, check the send log at the bottom of Settings.', 'livingdraft-core' ) ),
		'settings_saved' => array( 'ok', __( 'Mail settings saved.', 'livingdraft-core' ) ),
		'test_bad'       => array( 'bad', __( 'That is not a valid email address.', 'livingdraft-core' ) ),
		'test_failed'    => array( 'bad', __( 'The test did not send.', 'livingdraft-core' ) ),
		'send_failed'    => array( 'bad', __( 'The campaign did not start.', 'livingdraft-core' ) ),
	);

	if ( ! isset( $messages[ $notice ] ) ) {
		return;
	}

	list( $kind, $text ) = $messages[ $notice ];

	$detail = get_transient( 'ld_mail_error' );

	if ( $detail ) {
		delete_transient( 'ld_mail_error' );
		$text .= ' ' . $detail;
	}

	printf(
		'<div class="tld-notice %s"><p>%s</p></div>',
		esc_attr( 'ok' === $kind ? '' : 'is-bad' ),
		esc_html( $text )
	);
}

/**
 * The newsletter list.
 *
 * @since 4.3.0
 * @param array $counts Subscriber counts by status.
 * @return void
 */
function livingdraft_mail_render_campaigns( $counts ) {
	$can = livingdraft_campaign_can_send();
	?>
	<div class="tld-numbers">
		<div class="tld-num">
			<p class="tld-num-label"><?php esc_html_e( 'Confirmed', 'livingdraft-core' ); ?></p>
			<p class="tld-num-value"><?php echo esc_html( number_format_i18n( $counts['confirmed'] ) ); ?></p>
			<p class="tld-num-delta"><?php esc_html_e( 'Will receive newsletters', 'livingdraft-core' ); ?></p>
		</div>
		<div class="tld-num">
			<p class="tld-num-label"><?php esc_html_e( 'Awaiting confirmation', 'livingdraft-core' ); ?></p>
			<p class="tld-num-value"><?php echo esc_html( number_format_i18n( $counts['pending'] ) ); ?></p>
			<p class="tld-num-delta"><?php esc_html_e( 'Signed up, never confirmed', 'livingdraft-core' ); ?></p>
		</div>
		<div class="tld-num">
			<p class="tld-num-label"><?php esc_html_e( 'Unsubscribed', 'livingdraft-core' ); ?></p>
			<p class="tld-num-value"><?php echo esc_html( number_format_i18n( $counts['unsubscribed'] ) ); ?></p>
			<p class="tld-num-delta"><?php esc_html_e( 'Never mailed again', 'livingdraft-core' ); ?></p>
		</div>
		<div class="tld-num">
			<p class="tld-num-label"><?php esc_html_e( 'Sent today', 'livingdraft-core' ); ?></p>
			<p class="tld-num-value"><?php echo esc_html( number_format_i18n( livingdraft_mail_sent_today() ) ); ?></p>
			<p class="tld-num-delta"><?php
				printf(
					/* translators: %s: the daily cap. */
					esc_html__( 'of %s allowed', 'livingdraft-core' ),
					esc_html( number_format_i18n( (int) livingdraft_mail_settings()['daily_cap'] ) )
				);
			?></p>
		</div>
	</div>

	<?php if ( is_wp_error( $can ) ) : ?>
		<div class="tld-notice is-bad">
			<p><strong><?php esc_html_e( 'Newsletters cannot be sent yet.', 'livingdraft-core' ); ?></strong> <?php echo esc_html( $can->get_error_message() ); ?></p>
		</div>
	<?php endif; ?>

	<div class="tld-card">
		<div class="tld-section-rule">
			<h2><?php esc_html_e( 'Newsletters', 'livingdraft-core' ); ?></h2>
			<a class="tld-btn is-small" href="<?php echo esc_url( admin_url( 'admin.php?page=livingdraft-mail&tab=write' ) ); ?>"><?php esc_html_e( 'Write a newsletter', 'livingdraft-core' ); ?></a>
		</div>

		<?php
		$campaigns = get_posts(
			array(
				'post_type'      => 'ld_campaign',
				'post_status'    => 'any',
				'posts_per_page' => 30,
			)
		);

		if ( empty( $campaigns ) ) :
			?>
			<div class="tld-empty">
				<p class="tld-empty-title"><?php esc_html_e( 'No newsletters yet', 'livingdraft-core' ); ?></p>
				<p class="tld-empty-desc"><?php esc_html_e( 'Write one, send yourself a test, then send it to the list.', 'livingdraft-core' ); ?></p>
				<a class="tld-btn is-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=livingdraft-mail&tab=write' ) ); ?>"><?php esc_html_e( 'Write the first one', 'livingdraft-core' ); ?></a>
			</div>
		<?php else : ?>
			<ul class="tld-rows">
				<?php foreach ( $campaigns as $campaign ) : ?>
					<?php
					$progress = livingdraft_campaign_progress( $campaign->ID );
					$states   = livingdraft_campaign_states();

					$kicker = array(
						'draft'   => 'is-ghost',
						'sending' => 'is-warn',
						'paused'  => 'is-ghost',
						'sent'    => 'is-good',
					);
					?>
					<li class="tld-row">
						<div class="tld-row-main">
							<a class="tld-row-title" href="<?php echo esc_url( admin_url( 'admin.php?page=livingdraft-mail&tab=write&campaign=' . $campaign->ID ) ); ?>">
								<?php echo esc_html( $campaign->post_title ); ?>
							</a>
							<p class="tld-row-meta"><?php echo esc_html( get_the_date( 'j M Y, H:i', $campaign ) ); ?></p>
						</div>

						<?php if ( 'draft' !== $progress['state'] ) : ?>
							<div class="tld-row-progress">
								<?php if ( in_array( $progress['state'], array( 'sending', 'paused' ), true ) ) : ?>
									<span class="tld-meter" role="img"
										aria-label="<?php echo esc_attr( sprintf( /* translators: %d: percent complete. */ __( '%d%% sent', 'livingdraft-core' ), (int) $progress['percent'] ) ); ?>">
										<span class="tld-meter-fill" style="width:<?php echo esc_attr( (string) (int) $progress['percent'] ); ?>%"></span>
									</span>
								<?php endif; ?>
								<span class="tld-figure">
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: sent, 2: total. */
											__( '%1$s/%2$s', 'livingdraft-core' ),
											number_format_i18n( $progress['sent'] ),
											number_format_i18n( $progress['total'] )
										)
									);
									?>
								</span>
								<?php if ( $progress['failed'] > 0 ) : ?>
									<span class="tld-figure is-bad">
										<?php
										printf(
											/* translators: %s: number of failures. */
											esc_html__( '%s failed', 'livingdraft-core' ),
											esc_html( number_format_i18n( $progress['failed'] ) )
										);
										?>
									</span>
								<?php endif; ?>
							</div>
						<?php endif; ?>

						<span class="tld-kicker <?php echo esc_attr( $kicker[ $progress['state'] ] ?? 'is-ghost' ); ?>">
							<?php echo esc_html( $states[ $progress['state'] ] ?? $progress['state'] ); ?>
						</span>

						<div class="tld-row-actions">
							<?php if ( 'sending' === $progress['state'] ) : ?>
								<form method="post">
									<?php wp_nonce_field( 'ld_mail_pause' ); ?>
									<input type="hidden" name="ld_mail_action" value="pause" />
									<input type="hidden" name="campaign" value="<?php echo esc_attr( (string) $campaign->ID ); ?>" />
									<button class="tld-btn is-small"><?php esc_html_e( 'Pause', 'livingdraft-core' ); ?></button>
								</form>
							<?php elseif ( 'paused' === $progress['state'] ) : ?>
								<form method="post">
									<?php wp_nonce_field( 'ld_mail_resume' ); ?>
									<input type="hidden" name="ld_mail_action" value="resume" />
									<input type="hidden" name="campaign" value="<?php echo esc_attr( (string) $campaign->ID ); ?>" />
									<button class="tld-btn is-small"><?php esc_html_e( 'Resume', 'livingdraft-core' ); ?></button>
								</form>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * The compose screen.
 *
 * @since 4.3.0
 * @return void
 */
function livingdraft_mail_render_write() {
	$id       = isset( $_GET['campaign'] ) ? absint( wp_unslash( $_GET['campaign'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$campaign = $id ? get_post( $id ) : null;
	$state    = $id ? livingdraft_campaign_state( $id ) : 'draft';
	$counts   = livingdraft_subscriber_counts();
	$can      = livingdraft_campaign_can_send();
	$locked   = in_array( $state, array( 'sending', 'sent' ), true );
	?>
	<div class="tld-card">
		<div class="tld-section-rule">
			<h2><?php echo $id ? esc_html__( 'Edit newsletter', 'livingdraft-core' ) : esc_html__( 'Write', 'livingdraft-core' ); ?></h2>
			<?php if ( $locked ) : ?>
				<span class="tld-kicker is-ghost"><?php esc_html_e( 'Locked — already sent', 'livingdraft-core' ); ?></span>
			<?php endif; ?>
		</div>

		<form method="post" id="ld-mail-form">
			<?php wp_nonce_field( 'ld_mail_save' ); ?>
			<input type="hidden" name="ld_mail_action" value="save" />
			<input type="hidden" name="campaign" value="<?php echo esc_attr( (string) $id ); ?>" />

			<div class="tld-field">
				<label class="tld-label" for="ld-subject"><?php esc_html_e( 'Subject', 'livingdraft-core' ); ?></label>
				<input type="text" id="ld-subject" name="subject" class="tld-input"
					value="<?php echo esc_attr( $campaign ? $campaign->post_title : '' ); ?>"
					<?php disabled( $locked ); ?> />
				<p class="tld-help"><?php esc_html_e( 'Under 50 characters. It is the only thing most readers see before deciding.', 'livingdraft-core' ); ?></p>
			</div>

			<div class="tld-field">
				<label class="tld-label" for="ld-body"><?php esc_html_e( 'Body', 'livingdraft-core' ); ?></label>
				<textarea id="ld-body" name="body" rows="16" class="tld-textarea"
					<?php disabled( $locked ); ?>><?php echo esc_textarea( $campaign ? $campaign->post_content : '' ); ?></textarea>
			</div>

			<?php if ( ! $locked ) : ?>
				<div class="tld-panel">
					<div class="tld-panel-head">
						<span class="tld-panel-title"><?php esc_html_e( 'Drafting help', 'livingdraft-core' ); ?></span>
						<span class="tld-panel-note"><?php esc_html_e( 'Writes into the fields above. It never sends anything.', 'livingdraft-core' ); ?></span>
					</div>

					<div class="tld-btn-row">
						<button type="button" class="tld-btn is-ai" id="ld-ai-newsletter"><?php esc_html_e( 'Draft from the last 7 days', 'livingdraft-core' ); ?></button>
						<button type="button" class="tld-btn is-ai" id="ld-ai-subjects"><?php esc_html_e( 'Suggest subject lines', 'livingdraft-core' ); ?></button>
						<span id="ld-ai-status" class="tld-inline-status"></span>
					</div>

					<div id="ld-ai-subject-list" class="tld-suggestions"></div>
				</div>

				<div class="tld-btn-row">
					<button class="tld-btn is-primary"><?php esc_html_e( 'Save draft', 'livingdraft-core' ); ?></button>
				</div>
			<?php endif; ?>
		</form>
	</div>

	<?php if ( $id && 'draft' === $state ) : ?>
		<div class="tld-card">
			<div class="tld-section-rule"><h2><?php esc_html_e( 'Before it goes out', 'livingdraft-core' ); ?></h2></div>

			<form method="post" class="tld-field-row">
				<?php wp_nonce_field( 'ld_mail_test' ); ?>
				<input type="hidden" name="ld_mail_action" value="test" />
				<input type="hidden" name="campaign" value="<?php echo esc_attr( (string) $id ); ?>" />
				<input type="email" name="test_to" class="tld-input" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" aria-label="<?php esc_attr_e( 'Test recipient', 'livingdraft-core' ); ?>" />
				<button class="tld-btn"><?php esc_html_e( 'Send test', 'livingdraft-core' ); ?></button>
			</form>
			<p class="tld-help"><?php esc_html_e( 'Read it in a real inbox first, and on a phone — that is where most people will open it.', 'livingdraft-core' ); ?></p>

			<div class="tld-send-bar">
				<form method="post" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( sprintf( /* translators: %s: number of subscribers. */ __( 'Send this to %s confirmed subscribers? This cannot be undone.', 'livingdraft-core' ), number_format_i18n( $counts['confirmed'] ) ) ) ); ?>);">
					<?php wp_nonce_field( 'ld_mail_send' ); ?>
					<input type="hidden" name="ld_mail_action" value="send" />
					<input type="hidden" name="campaign" value="<?php echo esc_attr( (string) $id ); ?>" />
					<button class="tld-btn is-primary" <?php disabled( is_wp_error( $can ) || 0 === (int) $counts['confirmed'] ); ?>>
						<?php
						printf(
							/* translators: %s: subscriber count. */
							esc_html__( 'Send to %s subscribers', 'livingdraft-core' ),
							esc_html( number_format_i18n( $counts['confirmed'] ) )
						);
						?>
					</button>
				</form>

				<?php if ( is_wp_error( $can ) ) : ?>
					<p class="tld-help is-bad"><?php echo esc_html( $can->get_error_message() ); ?></p>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>
	<?php
}

/**
 * Reply drafting.
 *
 * @since 4.3.0
 * @return void
 */
function livingdraft_mail_render_replies() {
	?>
	<div class="tld-card">
		<div class="tld-section-rule"><h2><?php esc_html_e( 'Reply drafts', 'livingdraft-core' ); ?></h2></div>

		<p class="tld-help">
			<?php esc_html_e( 'Paste a message you have been sent and get three replies taking different lines. Nothing is sent from here and no mailbox is read — copy the one you want into your own mail client, where you can see the thread and the sender.', 'livingdraft-core' ); ?>
		</p>

		<div class="tld-field">
			<label class="tld-label" for="ld-reply-message"><?php esc_html_e( 'The message', 'livingdraft-core' ); ?></label>
			<textarea id="ld-reply-message" rows="9" class="tld-textarea"></textarea>
		</div>

		<div class="tld-field">
			<label class="tld-label" for="ld-reply-intent"><?php esc_html_e( 'What should the reply do?', 'livingdraft-core' ); ?></label>
			<input type="text" id="ld-reply-intent" class="tld-input" placeholder="<?php esc_attr_e( 'decline politely but leave the door open', 'livingdraft-core' ); ?>" />
			<p class="tld-help"><?php esc_html_e( 'Optional. Without it, the drafts guess at what a reasonable answer would be.', 'livingdraft-core' ); ?></p>
		</div>

		<div class="tld-btn-row">
			<button type="button" class="tld-btn is-ai" id="ld-ai-reply"><?php esc_html_e( 'Draft replies', 'livingdraft-core' ); ?></button>
			<span id="ld-reply-status" class="tld-inline-status"></span>
		</div>

		<div id="ld-reply-output" class="tld-suggestions"></div>
	</div>
	<?php
}

/**
 * Settings.
 *
 * Rendered into the consolidated Settings screen (4.4.0), not on a sub-tab
 * of Mail. Configuration for the plugin lives in one place.
 *
 * @since 4.3.0
 * @return void
 */
function livingdraft_mail_render_settings() {
	// Saving happens here too, so the confirmation has to render here too.
	livingdraft_mail_render_notice();

	$settings = livingdraft_mail_settings();

	$channels = array(
		'wp'     => __( 'WordPress default', 'livingdraft-core' ),
		'smtp'   => __( 'SMTP', 'livingdraft-core' ),
		'brevo'  => __( 'Brevo API', 'livingdraft-core' ),
		'resend' => __( 'Resend API', 'livingdraft-core' ),
	);
	?>
	<div class="tld-card">
		<div class="tld-section-rule"><h2><?php esc_html_e( 'Channels', 'livingdraft-core' ); ?></h2></div>

		<p class="tld-help">
			<?php esc_html_e( 'Transactional is confirmations and one-off notices — a handful a day, from an address a reader recognises. Bulk is the newsletter. They do not have to share a channel, and usually should not.', 'livingdraft-core' ); ?>
		</p>

		<form method="post">
			<?php wp_nonce_field( 'ld_mail_settings' ); ?>
			<input type="hidden" name="ld_mail_action" value="settings" />

			<div class="tld-grid-2">
				<div class="tld-field">
					<label class="tld-label" for="ld-mail-transactional"><?php esc_html_e( 'Transactional', 'livingdraft-core' ); ?></label>
					<?php $ld_tx = livingdraft_mail_channel_status( 'transactional' ); ?>
					<?php if ( ! $ld_tx['ok'] ) : ?>
						<p class="tld-keystate tld-keystate--odd"><span><?php echo esc_html( $ld_tx['note'] ); ?></span></p>
					<?php endif; ?>
					<select id="ld-mail-transactional" name="transactional" class="tld-select">
						<?php foreach ( $channels as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $settings['transactional'] ); ?>>
								<?php echo esc_html( $label ); ?><?php echo livingdraft_mail_channel_ready( $key ) ? '' : esc_html__( ' — not configured', 'livingdraft-core' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="tld-field">
					<label class="tld-label" for="ld-mail-bulk"><?php esc_html_e( 'Bulk', 'livingdraft-core' ); ?></label>
					<?php $ld_bulk = livingdraft_mail_channel_status( 'bulk' ); ?>
					<?php if ( ! $ld_bulk['ok'] ) : ?>
						<p class="tld-keystate tld-keystate--odd"><span><?php echo esc_html( $ld_bulk['note'] ); ?></span></p>
					<?php endif; ?>
					<select id="ld-mail-bulk" name="bulk" class="tld-select">
						<?php foreach ( $channels as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $settings['bulk'] ); ?>>
								<?php echo esc_html( $label ); ?><?php echo livingdraft_mail_channel_ready( $key ) ? '' : esc_html__( ' — not configured', 'livingdraft-core' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="tld-help"><?php esc_html_e( 'The WordPress default is refused for bulk. mail() has no authentication, and sending a whole list through it is the fastest way to get the domain blocklisted — including for password resets.', 'livingdraft-core' ); ?></p>
				</div>
			</div>

			<div class="tld-section-rule"><h2><?php esc_html_e( 'Identity', 'livingdraft-core' ); ?></h2></div>

			<div class="tld-grid-2">
				<div class="tld-field">
					<label class="tld-label" for="ld-mail-from-name"><?php esc_html_e( 'From name', 'livingdraft-core' ); ?></label>
					<input type="text" id="ld-mail-from-name" name="from_name" class="tld-input" value="<?php echo esc_attr( $settings['from_name'] ); ?>" />
				</div>

				<div class="tld-field">
					<label class="tld-label" for="ld-mail-from"><?php esc_html_e( 'From address', 'livingdraft-core' ); ?></label>
					<input type="email" id="ld-mail-from" name="from_email" class="tld-input" value="<?php echo esc_attr( $settings['from_email'] ); ?>" />
					<p class="tld-help"><?php esc_html_e( 'Must be on a domain you control, with SPF and DKIM set up. A gmail.com address fails DMARC and is rejected outright.', 'livingdraft-core' ); ?></p>
				</div>

				<div class="tld-field">
					<label class="tld-label" for="ld-mail-reply"><?php esc_html_e( 'Reply-To', 'livingdraft-core' ); ?></label>
					<input type="email" id="ld-mail-reply" name="reply_to" class="tld-input" value="<?php echo esc_attr( $settings['reply_to'] ); ?>" />
					<p class="tld-help"><?php esc_html_e( 'Readers do reply to newsletters. A no-reply address is a quiet way of saying you would rather they did not.', 'livingdraft-core' ); ?></p>
				</div>
			</div>

			<div class="tld-section-rule"><h2><?php esc_html_e( 'SMTP', 'livingdraft-core' ); ?></h2></div>

			<div class="tld-grid-2">
				<div class="tld-field">
					<label class="tld-label" for="ld-smtp-host"><?php esc_html_e( 'Host', 'livingdraft-core' ); ?></label>
					<input type="text" id="ld-smtp-host" name="smtp_host" class="tld-input is-mono" value="<?php echo esc_attr( $settings['smtp_host'] ); ?>" placeholder="smtp.zoho.in" />
				</div>

				<div class="tld-field">
					<label class="tld-label" for="ld-smtp-port"><?php esc_html_e( 'Port and security', 'livingdraft-core' ); ?></label>
					<div class="tld-field-row">
						<input type="number" id="ld-smtp-port" name="smtp_port" class="tld-input is-mono" style="max-width:100px" value="<?php echo esc_attr( (string) $settings['smtp_port'] ); ?>" />
						<select name="smtp_secure" class="tld-select" aria-label="<?php esc_attr_e( 'Connection security', 'livingdraft-core' ); ?>">
							<option value="tls" <?php selected( 'tls', $settings['smtp_secure'] ); ?>><?php esc_html_e( 'STARTTLS', 'livingdraft-core' ); ?></option>
							<option value="ssl" <?php selected( 'ssl', $settings['smtp_secure'] ); ?>><?php esc_html_e( 'SSL', 'livingdraft-core' ); ?></option>
							<option value="none" <?php selected( 'none', $settings['smtp_secure'] ); ?>><?php esc_html_e( 'None', 'livingdraft-core' ); ?></option>
						</select>
					</div>
				</div>

				<div class="tld-field">
					<label class="tld-label" for="ld-smtp-user"><?php esc_html_e( 'Username', 'livingdraft-core' ); ?></label>
					<input type="text" id="ld-smtp-user" name="smtp_user" class="tld-input" value="<?php echo esc_attr( $settings['smtp_user'] ); ?>" autocomplete="off" />
				</div>

				<div class="tld-field">
					<label class="tld-label" for="ld-smtp-pass"><?php esc_html_e( 'Password', 'livingdraft-core' ); ?></label>
					<?php
					livingdraft_mail_render_secret_field(
						'smtp_pass',
						'ld-smtp-pass',
						__( 'Use an app-specific password, never your main account password. It is encrypted before storage, but anyone who can read wp-config.php can decrypt it — so it should be a credential you can revoke on its own.', 'livingdraft-core' ),
						'smtp'
					);
					?>
				</div>
			</div>

			<div class="tld-section-rule"><h2><?php esc_html_e( 'API keys', 'livingdraft-core' ); ?></h2></div>

			<div class="tld-grid-2">
				<div class="tld-field">
					<label class="tld-label" for="ld-brevo"><?php esc_html_e( 'Brevo', 'livingdraft-core' ); ?></label>
					<?php
					livingdraft_mail_render_secret_field(
						'brevo_key',
						'ld-brevo',
						__( 'Must be an API key from SMTP & API → API keys, beginning xkeysib-. The SMTP key on the same page is a different credential and will be rejected here. Free tier is 300 messages a day.', 'livingdraft-core' ),
						'brevo'
					);
					?>
				</div>

				<div class="tld-field">
					<label class="tld-label" for="ld-resend"><?php esc_html_e( 'Resend', 'livingdraft-core' ); ?></label>
					<?php
					livingdraft_mail_render_secret_field(
						'resend_key',
						'ld-resend',
						__( 'Begins re_. Needs a verified sending domain before anything will send.', 'livingdraft-core' ),
						'resend'
					);
					?>
				</div>
			</div>

			<div class="tld-section-rule"><h2><?php esc_html_e( 'Limits', 'livingdraft-core' ); ?></h2></div>

			<div class="tld-grid-2">
				<div class="tld-field">
					<label class="tld-label" for="ld-mail-cap"><?php esc_html_e( 'Daily cap', 'livingdraft-core' ); ?></label>
					<input type="number" id="ld-mail-cap" name="daily_cap" class="tld-input is-mono" style="max-width:120px" value="<?php echo esc_attr( (string) $settings['daily_cap'] ); ?>" />
					<p class="tld-help"><?php esc_html_e( 'Set slightly below your provider\'s real limit. Being cut off mid-campaign produces a run of failures that look like a broken configuration.', 'livingdraft-core' ); ?></p>
				</div>

				<div class="tld-field">
					<label class="tld-label" for="ld-mail-batch"><?php esc_html_e( 'Batch size', 'livingdraft-core' ); ?></label>
					<input type="number" id="ld-mail-batch" name="batch_size" class="tld-input is-mono" style="max-width:120px" value="<?php echo esc_attr( (string) $settings['batch_size'] ); ?>" />
					<p class="tld-help"><?php esc_html_e( 'Messages per five-minute run. Lower it if your host kills long requests.', 'livingdraft-core' ); ?></p>
				</div>
			</div>

			<div class="tld-field">
				<label class="tld-check">
					<input type="checkbox" name="take_over_wp" value="1" <?php checked( ! empty( $settings['take_over_wp'] ) ); ?> />
					<span><?php esc_html_e( 'Route password resets and comment notifications through SMTP too', 'livingdraft-core' ); ?></span>
				</label>
				<p class="tld-help"><?php esc_html_e( 'Usually worth turning on — those are the messages that most often vanish. Test a password reset afterwards.', 'livingdraft-core' ); ?></p>
			</div>

			<div class="tld-btn-row">
				<button class="tld-btn is-primary"><?php esc_html_e( 'Save mail settings', 'livingdraft-core' ); ?></button>
			</div>
		</form>
	</div>

	<div class="tld-card">
		<div class="tld-section-rule"><h2><?php esc_html_e( 'Test send', 'livingdraft-core' ); ?></h2></div>

		<form method="post" class="tld-field-row">
			<?php wp_nonce_field( 'ld_mail_test' ); ?>
			<input type="hidden" name="ld_mail_action" value="test" />
			<input type="email" name="test_to" class="tld-input" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" aria-label="<?php esc_attr_e( 'Test recipient', 'livingdraft-core' ); ?>" />
			<button class="tld-btn"><?php esc_html_e( 'Send test', 'livingdraft-core' ); ?></button>
		</form>
	</div>

	<?php livingdraft_mail_render_log_card(); ?>
	<?php
}

/**
 * A stored-secret field.
 *
 * Renders empty even when a value is stored, with the state in the
 * placeholder. Three of these had identical markup; the interesting part is
 * the pairing of an always-empty input with an explicit clear checkbox,
 * which is what makes "open the page, press Save, keep my credentials" work.
 *
 * @since 4.4.0
 * @param string $secret Secret name.
 * @param string $id     Field id.
 * @param string $help   Help text.
 * @return void
 */
function livingdraft_mail_render_secret_field( $secret, $id, $help, $verify = '' ) {
	$stored  = '' !== livingdraft_mail_get_secret( $secret );
	$inspect = livingdraft_mail_inspect_key( $secret );
	?>
	<input type="password" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $secret ); ?>" class="tld-input is-mono" value="" autocomplete="new-password"
		placeholder="<?php echo esc_attr( $stored ? __( 'stored — leave blank to keep', 'livingdraft-core' ) : __( 'not set', 'livingdraft-core' ) ); ?>" />

	<?php
	/*
	 * What is actually stored, named without revealing it. Prefix and last
	 * four are enough to tell "wrong key type" from "truncated paste" from
	 * "not the key I meant", and useless to anyone else.
	 */
	if ( 'missing' !== $inspect['state'] ) :
		?>
		<p class="tld-keystate tld-keystate--<?php echo esc_attr( $inspect['state'] ); ?>">
			<?php if ( $inspect['sample'] ) : ?>
				<code><?php echo esc_html( $inspect['sample'] ); ?></code>
			<?php endif; ?>
			<span><?php echo esc_html( $inspect['note'] ); ?></span>
		</p>
	<?php endif; ?>

	<?php if ( $verify ) : ?>
		<div class="tld-btn-row">
			<button type="button" class="tld-btn is-small ld-mail-verify" data-which="<?php echo esc_attr( $verify ); ?>">
				<?php esc_html_e( 'Check this credential', 'livingdraft-core' ); ?>
			</button>
			<span class="tld-inline-status ld-mail-verify-out"></span>
		</div>
	<?php endif; ?>

	<?php if ( $stored ) : ?>
		<label class="tld-check tld-check--tight">
			<input type="checkbox" name="clear_<?php echo esc_attr( $secret ); ?>" value="1" />
			<span><?php esc_html_e( 'Clear this credential', 'livingdraft-core' ); ?></span>
		</label>
	<?php endif; ?>

	<?php if ( $help ) : ?>
		<p class="tld-help"><?php echo esc_html( $help ); ?></p>
	<?php endif; ?>
	<?php
}

/**
 * The send log.
 *
 * @since 4.4.0
 * @return void
 */
function livingdraft_mail_render_log_card() {
	$log = livingdraft_mail_get_log( 40 );
	?>
	<div class="tld-card">
		<div class="tld-section-rule"><h2><?php esc_html_e( 'Send log', 'livingdraft-core' ); ?></h2></div>

		<?php if ( empty( $log ) ) : ?>
			<div class="tld-empty">
				<p class="tld-empty-title"><?php esc_html_e( 'Nothing sent yet', 'livingdraft-core' ); ?></p>
				<p class="tld-empty-desc"><?php esc_html_e( 'Every attempt lands here, with the provider\'s own error text when one fails.', 'livingdraft-core' ); ?></p>
			</div>
		<?php else : ?>
			<table class="tld-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'When', 'livingdraft-core' ); ?></th>
						<th><?php esc_html_e( 'To', 'livingdraft-core' ); ?></th>
						<th><?php esc_html_e( 'Channel', 'livingdraft-core' ); ?></th>
						<th><?php esc_html_e( 'Result', 'livingdraft-core' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $log as $row ) : ?>
					<tr>
						<td class="tld-cell-meta"><?php echo esc_html( date_i18n( 'j M, H:i', (int) $row['time'] ) ); ?></td>
						<td><?php echo esc_html( $row['to'] ); ?></td>
						<td><code><?php echo esc_html( $row['channel'] ); ?></code></td>
						<td>
							<?php if ( ! empty( $row['ok'] ) ) : ?>
								<span class="tld-badge is-good"><?php esc_html_e( 'Sent', 'livingdraft-core' ); ?></span>
							<?php else : ?>
								<span class="tld-badge is-bad"><?php esc_html_e( 'Failed', 'livingdraft-core' ); ?></span>
								<p class="tld-cell-meta"><?php echo esc_html( $row['error'] ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Register Mail in the Settings rail.
 *
 * @since 4.4.0
 * @param array $sections Sections.
 * @return array
 */
function livingdraft_mail_register_settings_section( $sections ) {
	$sections['mail'] = array(
		'label'  => __( 'Mail', 'livingdraft-core' ),
		'desc'   => __( 'How mail leaves this server, who it comes from, and how much of it goes out in a day.', 'livingdraft-core' ),
		'render' => 'livingdraft_mail_render_settings',
		'cap'    => 'manage_options',
	);

	return $sections;
}
add_filter( 'livingdraft_settings_panels', 'livingdraft_mail_register_settings_section', 20 );
