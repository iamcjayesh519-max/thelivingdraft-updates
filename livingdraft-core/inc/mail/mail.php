<?php
/**
 * Mail: getting a message off the server.
 *
 * === WHY THIS IS A LAYER AND NOT A wp_mail() CALL ===
 *
 * wp_mail() hands the message to PHP's mail() function, which hands it to
 * whatever the host has configured, which on most shared hosting is a local
 * sendmail with no SPF alignment, no DKIM signature, and an IP address shared
 * with several thousand other sites. Mail sent that way does not usually
 * bounce. It is accepted, and then filed in Spam, silently, which is worse:
 * there is no error to see and no way to know it happened.
 *
 * So every message this plugin sends goes out over an authenticated channel
 * that the receiving server can verify. Two kinds are supported and they are
 * genuinely different tools:
 *
 *   SMTP      — your own mailbox (Zoho, Google Workspace, your host). Good
 *               for transactional mail: confirmations, replies, one-off
 *               notices. Low limits, but the mail comes from the address a
 *               reader would expect and replies land in your inbox.
 *
 *   API       — Brevo or Resend over HTTPS. Built for volume, reports hard
 *               bounces and complaints back to you, and does not tie up a
 *               PHP process for the length of an SMTP conversation.
 *
 * Both are configured; either can be selected per KIND of mail. That split is
 * the point of the 'transactional' and 'bulk' settings: a confirmation email
 * going out over your own mailbox while a 900-recipient newsletter goes over
 * an API is not a compromise, it is the correct arrangement.
 *
 * === WHAT IS DELIBERATELY NOT HERE ===
 *
 * Open tracking. A tracking pixel means a request to your server for every
 * reader who opens a newsletter, recording that a specific address read a
 * specific email at a specific time, on a site that ships no external
 * requests on the front end and asks for cookie consent before it counts a
 * page view. The number is not worth the file it would create.
 *
 * Click tracking, for the same reason and one more: it rewrites every link
 * in the email through a redirector, so the reader cannot see where a link
 * goes before pressing it, and the archive of the email stops working the
 * day the site moves.
 *
 * @package LivingDraftCore
 * @since 4.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings option.
 *
 * @since 4.3.0
 * @var string
 */
const LD_MAIL_SETTINGS = 'livingdraft_mail_settings';

/**
 * Credentials, encrypted at rest. Kept apart from the settings so a settings
 * dump for support never contains a password.
 *
 * @since 4.3.0
 * @var string
 */
const LD_MAIL_SECRETS = 'livingdraft_mail_secrets';

/**
 * The send log.
 *
 * @since 4.3.0
 * @var string
 */
const LD_MAIL_LOG = 'livingdraft_mail_log';

/* ==================================================================
 * 1. SETTINGS
 * ================================================================== */

/**
 * Settings, with defaults.
 *
 * @since 4.3.0
 * @return array
 */
function livingdraft_mail_settings() {
	$defaults = array(
		// Which channel carries which kind of mail.
		'transactional'   => 'wp',      // wp | smtp | brevo | resend
		'bulk'            => 'wp',      // wp | smtp | brevo | resend

		// Identity. Used by every channel.
		'from_name'       => get_bloginfo( 'name' ),
		'from_email'      => get_option( 'admin_email' ),
		'reply_to'        => '',

		// SMTP.
		'smtp_host'       => '',
		'smtp_port'       => 587,
		'smtp_secure'     => 'tls',     // tls | ssl | none
		'smtp_user'       => '',
		'smtp_auth'       => true,

		// Volume guards. Not vanity limits — a free Brevo key is 300 a day
		// and being cut off halfway through a send is worse than not
		// starting.
		'daily_cap'       => 280,
		'batch_size'      => 40,

		// Route ALL of WordPress's own mail through the transactional
		// channel, not just this plugin's. Off by default: it changes the
		// behaviour of password resets and comment notifications, which is
		// not a thing a plugin should do without being asked.
		'take_over_wp'    => false,
	);

	$stored = get_option( LD_MAIL_SETTINGS, array() );

	return wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );
}

/**
 * Read a stored secret.
 *
 * Reuses the AI module's AES-256-CBC helpers rather than growing a second
 * encryption implementation in the same plugin. Same caveat applies and is
 * worth repeating: the key is derived from wp_salt(), so anyone who can read
 * wp-config.php can decrypt these. It defends against a database dump, which
 * is the realistic exposure, not against a compromised filesystem.
 *
 * @since 4.3.0
 * @param string $key Secret name.
 * @return string
 */
function livingdraft_mail_get_secret( $key ) {
	$store = get_option( LD_MAIL_SECRETS, array() );

	if ( ! is_array( $store ) || empty( $store[ $key ] ) ) {
		return '';
	}

	if ( function_exists( 'livingdraft_ai_decrypt' ) ) {
		return (string) livingdraft_ai_decrypt( $store[ $key ] );
	}

	return (string) $store[ $key ];
}

/**
 * Store a secret. An empty value deletes it.
 *
 * @since 4.3.0
 * @param string $key   Secret name.
 * @param string $value Plaintext.
 * @return void
 */
function livingdraft_mail_set_secret( $key, $value ) {
	$store = get_option( LD_MAIL_SECRETS, array() );
	$store = is_array( $store ) ? $store : array();

	if ( '' === $value ) {
		unset( $store[ $key ] );
	} elseif ( function_exists( 'livingdraft_ai_encrypt' ) ) {
		$store[ $key ] = livingdraft_ai_encrypt( $value );
	} else {
		$store[ $key ] = $value;
	}

	update_option( LD_MAIL_SECRETS, $store, false );
}

/**
 * Is a given channel actually usable?
 *
 * @since 4.3.0
 * @param string $channel wp | smtp | brevo | resend.
 * @return bool
 */
function livingdraft_mail_channel_ready( $channel ) {
	$settings = livingdraft_mail_settings();

	switch ( $channel ) {
		case 'smtp':
			return '' !== $settings['smtp_host'] && '' !== livingdraft_mail_get_secret( 'smtp_pass' );

		case 'brevo':
			return '' !== livingdraft_mail_get_secret( 'brevo_key' );

		case 'resend':
			return '' !== livingdraft_mail_get_secret( 'resend_key' );

		case 'wp':
			return true;
	}

	return false;
}

/**
 * The channel for a kind of mail, falling back to wp_mail if the configured
 * one is not usable.
 *
 * Falling back rather than failing is the right call for transactional mail
 * specifically: a subscriber who confirms their address should get the
 * confirmation even if the API key expired this morning. It is the WRONG call
 * for bulk, so livingdraft_mail_campaign_can_send() checks readiness
 * separately and refuses to start a campaign on a fallback channel.
 *
 * @since 4.3.0
 * @param string $kind 'transactional' or 'bulk'.
 * @return string
 */
function livingdraft_mail_channel( $kind = 'transactional' ) {
	$settings = livingdraft_mail_settings();
	$channel  = 'bulk' === $kind ? $settings['bulk'] : $settings['transactional'];

	return livingdraft_mail_channel_ready( $channel ) ? $channel : 'wp';
}

/* ==================================================================
 * 2. SMTP
 * ================================================================== */

/**
 * Point PHPMailer at the configured SMTP server.
 *
 * Hooked unconditionally but gated inside, because the decision depends on
 * what is being sent and that is only knowable per-message. The
 * `livingdraft_mail_force_smtp` static is set by our own sender immediately
 * before calling wp_mail(); everything else is WordPress's own mail, which
 * only gets rerouted when take_over_wp is on.
 *
 * @since 4.3.0
 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer instance.
 * @return void
 */
function livingdraft_mail_configure_smtp( $phpmailer ) {
	$settings = livingdraft_mail_settings();

	$ours = livingdraft_mail_smtp_flag();
	$all  = ! empty( $settings['take_over_wp'] ) && livingdraft_mail_channel_ready( 'smtp' );

	if ( ! $ours && ! $all ) {
		return;
	}

	if ( ! livingdraft_mail_channel_ready( 'smtp' ) ) {
		return;
	}

	$phpmailer->isSMTP();
	$phpmailer->Host       = $settings['smtp_host'];
	$phpmailer->Port       = (int) $settings['smtp_port'];
	$phpmailer->SMTPAuth   = (bool) $settings['smtp_auth'];
	$phpmailer->Username   = $settings['smtp_user'];
	$phpmailer->Password   = livingdraft_mail_get_secret( 'smtp_pass' );
	$phpmailer->SMTPSecure = 'none' === $settings['smtp_secure'] ? '' : $settings['smtp_secure'];

	/*
	 * A ten-second connect timeout. The default is 300, and a mail server
	 * that has stopped answering will otherwise hold a PHP worker open for
	 * five minutes — during a campaign run, that is the whole batch gone
	 * and a cron event that looks like it hung.
	 */
	$phpmailer->Timeout = 10;

	if ( '' !== $settings['from_email'] && is_email( $settings['from_email'] ) ) {
		$phpmailer->setFrom( $settings['from_email'], $settings['from_name'], false );
	}
}
add_action( 'phpmailer_init', 'livingdraft_mail_configure_smtp' );

/**
 * Per-request flag saying "this message is ours, use SMTP".
 *
 * @since 4.3.0
 * @param bool|null $set Set the flag, or null to read it.
 * @return bool
 */
function livingdraft_mail_smtp_flag( $set = null ) {
	static $flag = false;

	if ( null !== $set ) {
		$flag = (bool) $set;
	}

	return $flag;
}

/* ==================================================================
 * 3. THE SENDER
 * ================================================================== */

/**
 * Send one message.
 *
 * @since 4.3.0
 * @param string $to      Recipient address.
 * @param string $subject Subject line.
 * @param string $html    HTML body.
 * @param array  $args {
 *   @type string $kind        'transactional' (default) or 'bulk'.
 *   @type string $text        Plain-text alternative. Generated if omitted.
 *   @type string $unsubscribe URL for the List-Unsubscribe header.
 *   @type string $reply_to    Override the configured reply-to.
 *   @type string $context     Short label for the log, e.g. 'confirm'.
 * }
 * @return true|WP_Error
 */
function livingdraft_mail_send( $to, $subject, $html, $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'kind'        => 'transactional',
			'text'        => '',
			'unsubscribe' => '',
			'reply_to'    => '',
			'context'     => 'generic',
		)
	);

	$to = sanitize_email( $to );

	if ( ! is_email( $to ) ) {
		return new WP_Error( 'bad_address', __( 'That is not a valid email address.', 'livingdraft-core' ) );
	}

	if ( livingdraft_mail_sent_today() >= (int) livingdraft_mail_settings()['daily_cap'] ) {
		return new WP_Error(
			'daily_cap',
			__( 'The daily send limit has been reached. It resets at midnight, site time.', 'livingdraft-core' )
		);
	}

	$channel = livingdraft_mail_channel( $args['kind'] );

	if ( '' === $args['text'] ) {
		$args['text'] = livingdraft_mail_to_text( $html );
	}

	switch ( $channel ) {
		case 'brevo':
			$result = livingdraft_mail_send_brevo( $to, $subject, $html, $args );
			break;

		case 'resend':
			$result = livingdraft_mail_send_resend( $to, $subject, $html, $args );
			break;

		default:
			$result = livingdraft_mail_send_wp( $to, $subject, $html, $args, 'smtp' === $channel );
	}

	livingdraft_mail_log( $to, $subject, $channel, $args['context'], $result );

	if ( ! is_wp_error( $result ) ) {
		livingdraft_mail_count_send();
	}

	return $result;
}

/**
 * Send through wp_mail, optionally forcing SMTP.
 *
 * @since 4.3.0
 * @param string $to      Recipient.
 * @param string $subject Subject.
 * @param string $html    Body.
 * @param array  $args    Send args.
 * @param bool   $smtp    Force SMTP.
 * @return true|WP_Error
 */
function livingdraft_mail_send_wp( $to, $subject, $html, $args, $smtp ) {
	$settings = livingdraft_mail_settings();

	$headers = array( 'Content-Type: text/html; charset=UTF-8' );

	if ( is_email( $settings['from_email'] ) ) {
		$headers[] = sprintf( 'From: %s <%s>', $settings['from_name'], $settings['from_email'] );
	}

	$reply = $args['reply_to'] ? $args['reply_to'] : $settings['reply_to'];

	if ( is_email( $reply ) ) {
		$headers[] = 'Reply-To: ' . $reply;
	}

	if ( $args['unsubscribe'] ) {
		$headers = array_merge( $headers, livingdraft_mail_unsubscribe_headers( $args['unsubscribe'] ) );
	}

	livingdraft_mail_smtp_flag( $smtp );

	$sent = wp_mail( $to, $subject, $html, $headers );

	livingdraft_mail_smtp_flag( false );

	if ( ! $sent ) {
		return new WP_Error( 'send_failed', __( 'The mail server refused the message. Check the SMTP settings and try the test send.', 'livingdraft-core' ) );
	}

	return true;
}

/**
 * Send through Brevo.
 *
 * @since 4.3.0
 * @param string $to      Recipient.
 * @param string $subject Subject.
 * @param string $html    Body.
 * @param array  $args    Send args.
 * @return true|WP_Error
 */
function livingdraft_mail_send_brevo( $to, $subject, $html, $args ) {
	$settings = livingdraft_mail_settings();

	$body = array(
		'sender'      => array(
			'name'  => $settings['from_name'],
			'email' => $settings['from_email'],
		),
		'to'          => array( array( 'email' => $to ) ),
		'subject'     => $subject,
		'htmlContent' => $html,
		'textContent' => $args['text'],
	);

	$reply = $args['reply_to'] ? $args['reply_to'] : $settings['reply_to'];

	if ( is_email( $reply ) ) {
		$body['replyTo'] = array( 'email' => $reply );
	}

	if ( $args['unsubscribe'] ) {
		$body['headers'] = array(
			'List-Unsubscribe'      => '<' . $args['unsubscribe'] . '>',
			'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
		);
	}

	$response = wp_remote_post(
		'https://api.brevo.com/v3/smtp/email',
		array(
			'timeout' => 20,
			'headers' => array(
				'api-key'      => livingdraft_mail_get_secret( 'brevo_key' ),
				'Content-Type' => 'application/json',
				'accept'       => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		)
	);

	return livingdraft_mail_read_api_response( $response, 'Brevo' );
}

/**
 * Send through Resend.
 *
 * @since 4.3.0
 * @param string $to      Recipient.
 * @param string $subject Subject.
 * @param string $html    Body.
 * @param array  $args    Send args.
 * @return true|WP_Error
 */
function livingdraft_mail_send_resend( $to, $subject, $html, $args ) {
	$settings = livingdraft_mail_settings();

	$body = array(
		'from'    => sprintf( '%s <%s>', $settings['from_name'], $settings['from_email'] ),
		'to'      => array( $to ),
		'subject' => $subject,
		'html'    => $html,
		'text'    => $args['text'],
	);

	$reply = $args['reply_to'] ? $args['reply_to'] : $settings['reply_to'];

	if ( is_email( $reply ) ) {
		$body['reply_to'] = $reply;
	}

	if ( $args['unsubscribe'] ) {
		$body['headers'] = array(
			'List-Unsubscribe'      => '<' . $args['unsubscribe'] . '>',
			'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
		);
	}

	$response = wp_remote_post(
		'https://api.resend.com/emails',
		array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . livingdraft_mail_get_secret( 'resend_key' ),
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		)
	);

	return livingdraft_mail_read_api_response( $response, 'Resend' );
}

/**
 * Turn an HTTP response from a mail API into true or a WP_Error.
 *
 * The provider's own message is preserved where there is one. "Resend: The
 * domain example.com is not verified" is a fixable problem stated plainly;
 * "Sending failed" is a support ticket.
 *
 * @since 4.3.0
 * @param array|WP_Error $response Response.
 * @param string         $label    Provider name for messages.
 * @return true|WP_Error
 */
function livingdraft_mail_read_api_response( $response, $label ) {
	if ( is_wp_error( $response ) ) {
		return new WP_Error(
			'http_failed',
			sprintf(
				/* translators: 1: provider name, 2: error message. */
				__( 'Could not reach %1$s: %2$s', 'livingdraft-core' ),
				$label,
				$response->get_error_message()
			)
		);
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	if ( $code >= 200 && $code < 300 ) {
		return true;
	}

	$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
	$detail  = '';

	if ( is_array( $decoded ) ) {
		foreach ( array( 'message', 'error', 'detail' ) as $field ) {
			if ( ! empty( $decoded[ $field ] ) && is_string( $decoded[ $field ] ) ) {
				$detail = $decoded[ $field ];
				break;
			}
		}
	}

	return new WP_Error(
		'api_error',
		sprintf(
			/* translators: 1: provider name, 2: HTTP status, 3: provider message. */
			__( '%1$s refused the message (HTTP %2$d). %3$s', 'livingdraft-core' ),
			$label,
			$code,
			$detail
		)
	);
}

/* ==================================================================
 * 4. HEADERS, TEXT, LIMITS
 * ================================================================== */

/**
 * One-click unsubscribe headers, per RFC 8058.
 *
 * Both headers or neither. List-Unsubscribe on its own makes Gmail show an
 * "Unsubscribe" link that opens the URL in a browser; adding
 * List-Unsubscribe-Post is what makes Gmail and Outlook POST to it directly
 * and treat the sender as one that honours unsubscribes. That reputation
 * effect is worth more to deliverability than anything else on this page.
 *
 * The URL must therefore accept a POST with no session and no confirmation
 * step — see livingdraft_mail_handle_unsubscribe().
 *
 * @since 4.3.0
 * @param string $url Unsubscribe URL.
 * @return array
 */
function livingdraft_mail_unsubscribe_headers( $url ) {
	return array(
		'List-Unsubscribe: <' . $url . '>',
		'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
	);
}

/**
 * A readable plain-text alternative to an HTML body.
 *
 * Not just strip_tags: a link whose text is "here" becomes useless without
 * its href, so links are rendered as "text (url)" before the tags come off.
 * Every message goes out multipart, and the text part is what a screen
 * reader, a watch, and most spam filters actually look at.
 *
 * @since 4.3.0
 * @param string $html HTML body.
 * @return string
 */
function livingdraft_mail_to_text( $html ) {
	$text = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', '', $html );

	$text = preg_replace(
		'#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is',
		'$2 ($1)',
		(string) $text
	);

	$text = preg_replace( '#</(p|div|h[1-6]|li|tr)>#i', "\n\n", (string) $text );
	$text = preg_replace( '#<br\s*/?>#i', "\n", (string) $text );

	$text = wp_strip_all_tags( (string) $text );
	$text = wp_specialchars_decode( $text, ENT_QUOTES );
	$text = preg_replace( "/\n{3,}/", "\n\n", $text );

	return trim( (string) $text );
}

/**
 * How many messages have gone out today.
 *
 * Keyed to the site's own date, not UTC, because the cap exists to match a
 * provider's daily allowance and the person reading the number thinks in
 * local time.
 *
 * @since 4.3.0
 * @return int
 */
function livingdraft_mail_sent_today() {
	$today = get_option( 'livingdraft_mail_sent_today', array() );

	if ( ! is_array( $today ) || ( $today['date'] ?? '' ) !== date_i18n( 'Y-m-d' ) ) {
		return 0;
	}

	return (int) ( $today['count'] ?? 0 );
}

/**
 * Record one send against today's total.
 *
 * @since 4.3.0
 * @return void
 */
function livingdraft_mail_count_send() {
	$date  = date_i18n( 'Y-m-d' );
	$today = get_option( 'livingdraft_mail_sent_today', array() );

	if ( ! is_array( $today ) || ( $today['date'] ?? '' ) !== $date ) {
		$today = array(
			'date'  => $date,
			'count' => 0,
		);
	}

	$today['count'] = (int) $today['count'] + 1;

	update_option( 'livingdraft_mail_sent_today', $today, false );
}

/* ==================================================================
 * 5. THE LOG
 * ================================================================== */

/**
 * Record one send attempt.
 *
 * Capped at 300 entries. A log that grows without bound in an option is a
 * slow site six months from now.
 *
 * @since 4.3.0
 * @param string        $to      Recipient.
 * @param string        $subject Subject.
 * @param string        $channel Channel used.
 * @param string        $context Short label.
 * @param true|WP_Error $result  Outcome.
 * @return void
 */
function livingdraft_mail_log( $to, $subject, $channel, $context, $result ) {
	$log = get_option( LD_MAIL_LOG, array() );
	$log = is_array( $log ) ? $log : array();

	array_unshift(
		$log,
		array(
			'time'    => time(),
			'to'      => $to,
			'subject' => $subject,
			'channel' => $channel,
			'context' => $context,
			'ok'      => ! is_wp_error( $result ),
			'error'   => is_wp_error( $result ) ? $result->get_error_message() : '',
		)
	);

	update_option( LD_MAIL_LOG, array_slice( $log, 0, 300 ), false );
}

/**
 * Read the log.
 *
 * @since 4.3.0
 * @param int $limit How many entries.
 * @return array
 */
function livingdraft_mail_get_log( $limit = 50 ) {
	$log = get_option( LD_MAIL_LOG, array() );

	return is_array( $log ) ? array_slice( $log, 0, $limit ) : array();
}

/* ==================================================================
 * 6. THE WRAPPER EVERY MESSAGE GOES IN
 * ================================================================== */

/**
 * Wrap body HTML in an email shell.
 *
 * Table-based, inline styles, no external stylesheet, no web font, one
 * column, max 600px. Every one of those is a concession to Outlook, which
 * renders mail with Word's HTML engine and ignores most of the last fifteen
 * years of CSS. This is not how the site is built and it is not supposed to
 * be.
 *
 * @since 4.3.0
 * @param string $body_html   Inner HTML.
 * @param array  $args        Optional. 'preheader', 'footer_html'.
 * @return string
 */
function livingdraft_mail_wrap( $body_html, $args = array() ) {
	$preheader = isset( $args['preheader'] ) ? $args['preheader'] : '';
	$footer    = isset( $args['footer_html'] ) ? $args['footer_html'] : '';
	$name      = get_bloginfo( 'name' );

	ob_start();
	?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?php echo esc_html( $name ); ?></title>
</head>
<body style="margin:0;padding:0;background:#f4f3ee;">

<?php if ( $preheader ) : ?>
	<!--
		The preheader. Inboxes show the first text in the body as a preview
		beside the subject line; without this they show the masthead, so
		every email in the list previews as the site name. Hidden in the
		message itself by zero dimensions.
	-->
	<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">
		<?php echo esc_html( $preheader ); ?>
	</div>
<?php endif; ?>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f3ee;">
<tr>
<td align="center" style="padding:24px 12px;">

	<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:100%;background:#fdfdfb;border:1px solid #d8d5cc;">

		<tr>
			<td style="padding:22px 28px 14px;border-bottom:2px solid #111111;">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>" style="font-family:Georgia,'Times New Roman',serif;font-size:21px;font-weight:700;color:#111111;text-decoration:none;letter-spacing:-0.01em;">
					<?php echo esc_html( $name ); ?>
				</a>
			</td>
		</tr>

		<tr>
			<td style="padding:26px 28px;font-family:Georgia,'Times New Roman',serif;font-size:16px;line-height:1.62;color:#111111;">
				<?php echo $body_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Composed HTML, escaped at the point each part is built. ?>
			</td>
		</tr>

		<tr>
			<td style="padding:18px 28px 24px;border-top:1px solid #e6e3da;font-family:Helvetica,Arial,sans-serif;font-size:12px;line-height:1.6;color:#63635c;">
				<?php echo $footer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- As above. ?>
				<p style="margin:10px 0 0;">
					<?php echo esc_html__( 'Corrections are published, not quietly made.', 'livingdraft-core' ); ?>
				</p>
			</td>
		</tr>

	</table>

</td>
</tr>
</table>

</body>
</html>
	<?php

	return (string) ob_get_clean();
}
