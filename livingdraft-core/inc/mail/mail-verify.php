<?php
/**
 * Mail: checking a credential before you rely on it.
 *
 * === WHY THIS EXISTS ===
 *
 * A test send answers "did this message go?" and nothing else. When it fails
 * you are left holding a provider's error string, and provider error strings
 * are written for the person who built the API, not the person who pasted a
 * key into a box. Brevo's answer to an unrecognised key is:
 *
 *     Key not found
 *
 * Which is true, and tells you nothing about WHICH key is not found, or that
 * the reason is almost always that Brevo shows two different credentials on
 * the same settings page and only one of them works here.
 *
 * So this file checks credentials directly, in two stages:
 *
 *   1. Locally, with no network call. Every provider prefixes its keys, so a
 *      wrong key TYPE can be named exactly — "that is an SMTP key, this
 *      field needs an API key" — before anything leaves the server.
 *
 *   2. Against the provider's own account endpoint, which answers "is this
 *      key real and what can it do" without sending anybody an email.
 *
 * The second stage is deliberately not the send endpoint. Verifying by
 * sending means every failed attempt burns a message from a 300-a-day
 * allowance, and a key that is valid but whose sender is unverified would
 * pass stage one and fail the send for an unrelated reason.
 *
 * === WHAT IS NEVER SHOWN ===
 *
 * The stored key. Diagnostics report its prefix and last four characters,
 * which is enough to tell "wrong key type" from "truncated paste" from "not
 * the key I meant", and not enough to use.
 *
 * @package LivingDraftCore
 * @since 4.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What each provider's keys look like.
 *
 * Prefixes are stable and documented, and getting this wrong is the single
 * most common configuration mistake, so it is worth catching without a
 * round trip.
 *
 * @since 4.6.0
 * @return array
 */
function livingdraft_mail_key_shapes() {
	return array(
		'brevo_key'  => array(
			'label'     => __( 'Brevo API key', 'livingdraft-core' ),
			'expect'    => 'xkeysib-',
			'confusable' => array(
				'xsmtpsib-' => __( 'That is a Brevo SMTP key. It works with the SMTP channel, not the API one. Either paste it into the SMTP password field with host smtp-relay.brevo.com, or go back to Brevo and create an API key under SMTP & API → API keys — those begin xkeysib-.', 'livingdraft-core' ),
				're_'       => __( 'That is a Resend key. It belongs in the Resend field.', 'livingdraft-core' ),
			),
		),
		'resend_key' => array(
			'label'      => __( 'Resend API key', 'livingdraft-core' ),
			'expect'     => 're_',
			'confusable' => array(
				'xkeysib-'  => __( 'That is a Brevo API key. It belongs in the Brevo field.', 'livingdraft-core' ),
				'xsmtpsib-' => __( 'That is a Brevo SMTP key. It belongs in the SMTP password field.', 'livingdraft-core' ),
			),
		),
	);
}

/**
 * Look at a stored key without calling anyone.
 *
 * @since 4.6.0
 * @param string $secret Secret name.
 * @return array {
 *   @type string $state  'missing', 'wrong_type', 'odd', or 'ok'.
 *   @type string $note   What to do about it.
 *   @type string $sample Safe fragment of the key.
 * }
 */
function livingdraft_mail_inspect_key( $secret ) {
	$shapes = livingdraft_mail_key_shapes();
	$shape  = $shapes[ $secret ] ?? null;
	$key    = livingdraft_mail_get_secret( $secret );

	if ( '' === $key ) {
		$raw = get_option( LD_MAIL_SECRETS, array() );

		/*
		 * An empty key with a non-empty stored value means the ciphertext
		 * would not decrypt. That happens for exactly one reason worth
		 * naming: the AUTH_SALT in wp-config.php changed since the key was
		 * saved — usually a host migration, a security plugin regenerating
		 * salts, or a staging copy with its own wp-config. The key is not
		 * recoverable; it has to be pasted again.
		 */
		if ( is_array( $raw ) && ! empty( $raw[ $secret ] ) ) {
			return array(
				'state'  => 'undecryptable',
				'note'   => __( 'A key is stored but cannot be read back. This happens when the salts in wp-config.php change after a key is saved — a host move, a staging copy, or a security plugin regenerating them. Paste the key again to fix it.', 'livingdraft-core' ),
				'sample' => '',
			);
		}

		return array(
			'state'  => 'missing',
			'note'   => __( 'No key stored.', 'livingdraft-core' ),
			'sample' => '',
		);
	}

	$sample = substr( $key, 0, 9 ) . '…' . substr( $key, -4 );

	if ( $shape ) {
		foreach ( $shape['confusable'] as $prefix => $note ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return array(
					'state'  => 'wrong_type',
					'note'   => $note,
					'sample' => $sample,
				);
			}
		}

		if ( 0 !== strpos( $key, $shape['expect'] ) ) {
			return array(
				'state'  => 'odd',
				'note'   => sprintf(
					/* translators: 1: expected prefix, 2: what was found. */
					__( 'A %1$s normally begins "%2$s". This one does not, so it is probably from somewhere else or was pasted incompletely.', 'livingdraft-core' ),
					$shape['label'],
					$shape['expect']
				),
				'sample' => $sample,
			);
		}
	}

	/*
	 * Whitespace inside a key survives a copy from a PDF or a wrapped
	 * terminal and produces the same 401 as a wrong key, with no visible
	 * difference in the field.
	 */
	if ( preg_match( '/\s/', $key ) ) {
		return array(
			'state'  => 'odd',
			'note'   => __( 'The stored key contains a space or line break. Copy it again, taking care not to include a line wrap.', 'livingdraft-core' ),
			'sample' => $sample,
		);
	}

	return array(
		'state'  => 'ok',
		'note'   => __( 'Looks like the right kind of key.', 'livingdraft-core' ),
		'sample' => $sample,
	);
}

/* ==================================================================
 * PROVIDER CHECKS
 * ================================================================== */

/**
 * Ask a provider whether a key is real.
 *
 * @since 4.6.0
 * @param string $provider 'brevo' or 'resend'.
 * @return array|WP_Error Human-readable findings.
 */
function livingdraft_mail_verify_key( $provider ) {
	$secret  = 'brevo' === $provider ? 'brevo_key' : 'resend_key';
	$inspect = livingdraft_mail_inspect_key( $secret );

	if ( in_array( $inspect['state'], array( 'missing', 'wrong_type', 'undecryptable' ), true ) ) {
		return new WP_Error( $inspect['state'], $inspect['note'] );
	}

	return 'brevo' === $provider
		? livingdraft_mail_verify_brevo()
		: livingdraft_mail_verify_resend();
}

/**
 * Brevo: /v3/account.
 *
 * @since 4.6.0
 * @return array|WP_Error
 */
function livingdraft_mail_verify_brevo() {
	$response = wp_remote_get(
		'https://api.brevo.com/v3/account',
		array(
			'timeout' => 15,
			'headers' => array(
				'api-key' => livingdraft_mail_get_secret( 'brevo_key' ),
				'accept'  => 'application/json',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error(
			'unreachable',
			sprintf(
				/* translators: %s: error message. */
				__( 'Could not reach Brevo at all: %s. That is a network or firewall problem on this server, not a key problem.', 'livingdraft-core' ),
				$response->get_error_message()
			)
		);
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( 401 === $code ) {
		return new WP_Error(
			'rejected',
			__( 'Brevo does not recognise this key. It has either been revoked, regenerated, or copied incompletely — Brevo only shows a key in full once, at the moment you create it. Create a fresh one under SMTP & API → API keys and paste it again.', 'livingdraft-core' )
		);
	}

	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error(
			'error',
			sprintf(
				/* translators: 1: HTTP status, 2: provider message. */
				__( 'Brevo answered HTTP %1$d. %2$s', 'livingdraft-core' ),
				$code,
				is_array( $body ) ? (string) ( $body['message'] ?? '' ) : ''
			)
		);
	}

	$findings = array(
		sprintf(
			/* translators: %s: account email. */
			__( 'Key accepted. Account: %s', 'livingdraft-core' ),
			is_array( $body ) ? (string) ( $body['email'] ?? __( 'unknown', 'livingdraft-core' ) ) : ''
		),
	);

	/*
	 * A valid key with an unverified From address is the next wall, and it
	 * produces a different and equally opaque error at send time. Worth
	 * naming now while the person is already on this screen.
	 */
	$settings = livingdraft_mail_settings();

	if ( is_email( $settings['from_email'] ) ) {
		$senders = wp_remote_get(
			'https://api.brevo.com/v3/senders',
			array(
				'timeout' => 15,
				'headers' => array(
					'api-key' => livingdraft_mail_get_secret( 'brevo_key' ),
					'accept'  => 'application/json',
				),
			)
		);

		if ( ! is_wp_error( $senders ) && 200 === (int) wp_remote_retrieve_response_code( $senders ) ) {
			$list  = json_decode( wp_remote_retrieve_body( $senders ), true );
			$known = array();

			if ( is_array( $list ) && ! empty( $list['senders'] ) ) {
				foreach ( $list['senders'] as $sender ) {
					if ( ! empty( $sender['email'] ) ) {
						$known[] = strtolower( (string) $sender['email'] );
					}
				}
			}

			$findings[] = in_array( strtolower( $settings['from_email'] ), $known, true )
				? sprintf(
					/* translators: %s: from address. */
					__( 'Your From address (%s) is a verified sender.', 'livingdraft-core' ),
					$settings['from_email']
				)
				: sprintf(
					/* translators: %s: from address. */
					__( 'Warning: %s is NOT a verified sender on this Brevo account. The key works, but sends will be refused until you verify that address or its domain in Brevo.', 'livingdraft-core' ),
					$settings['from_email']
				);
		}
	}

	return $findings;
}

/**
 * Resend: /domains.
 *
 * @since 4.6.0
 * @return array|WP_Error
 */
function livingdraft_mail_verify_resend() {
	$response = wp_remote_get(
		'https://api.resend.com/domains',
		array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . livingdraft_mail_get_secret( 'resend_key' ),
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error(
			'unreachable',
			sprintf(
				/* translators: %s: error message. */
				__( 'Could not reach Resend at all: %s. That is a network or firewall problem on this server, not a key problem.', 'livingdraft-core' ),
				$response->get_error_message()
			)
		);
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	if ( 401 === $code || 403 === $code ) {
		return new WP_Error(
			'rejected',
			__( 'Resend does not recognise this key, or it lacks permission. Check it has not been revoked, and that it is a full-access key rather than a sending-only key restricted to another domain.', 'livingdraft-core' )
		);
	}

	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error(
			'error',
			sprintf(
				/* translators: %d: HTTP status. */
				__( 'Resend answered HTTP %d.', 'livingdraft-core' ),
				$code
			)
		);
	}

	$body     = json_decode( wp_remote_retrieve_body( $response ), true );
	$findings = array( __( 'Key accepted.', 'livingdraft-core' ) );

	$settings = livingdraft_mail_settings();
	$domain   = is_email( $settings['from_email'] ) ? strtolower( substr( strrchr( $settings['from_email'], '@' ), 1 ) ) : '';
	$verified = array();

	if ( is_array( $body ) && ! empty( $body['data'] ) ) {
		foreach ( $body['data'] as $row ) {
			if ( ! empty( $row['name'] ) && 'verified' === ( $row['status'] ?? '' ) ) {
				$verified[] = strtolower( (string) $row['name'] );
			}
		}
	}

	if ( $domain ) {
		$findings[] = in_array( $domain, $verified, true )
			? sprintf(
				/* translators: %s: domain. */
				__( 'Your sending domain (%s) is verified.', 'livingdraft-core' ),
				$domain
			)
			: sprintf(
				/* translators: %s: domain. */
				__( 'Warning: %s is not a verified domain on this Resend account. The key works, but sends will be refused until the domain is verified.', 'livingdraft-core' ),
				$domain
			);
	}

	return $findings;
}

/**
 * SMTP: open a connection and authenticate, without sending.
 *
 * PHPMailer's smtpConnect() performs the full handshake including AUTH, so a
 * bad password fails here exactly as it would during a send — but no message
 * is queued and no allowance is spent.
 *
 * @since 4.6.0
 * @return array|WP_Error
 */
function livingdraft_mail_verify_smtp() {
	$settings = livingdraft_mail_settings();

	if ( '' === $settings['smtp_host'] ) {
		return new WP_Error( 'no_host', __( 'No SMTP host set.', 'livingdraft-core' ) );
	}

	if ( '' === livingdraft_mail_get_secret( 'smtp_pass' ) ) {
		$inspect = livingdraft_mail_inspect_key( 'smtp_pass' );

		return new WP_Error( 'no_pass', $inspect['note'] );
	}

	if ( ! class_exists( 'PHPMailer\\PHPMailer\\PHPMailer' ) ) {
		require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
		require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
	}

	$mail = new PHPMailer\PHPMailer\PHPMailer( true );

	try {
		$mail->isSMTP();
		$mail->Host       = $settings['smtp_host'];
		$mail->Port       = (int) $settings['smtp_port'];
		$mail->SMTPAuth   = (bool) $settings['smtp_auth'];
		$mail->Username   = $settings['smtp_user'];
		$mail->Password   = livingdraft_mail_get_secret( 'smtp_pass' );
		$mail->SMTPSecure = 'none' === $settings['smtp_secure'] ? '' : $settings['smtp_secure'];
		$mail->Timeout    = 10;

		if ( ! $mail->smtpConnect() ) {
			return new WP_Error( 'connect_failed', __( 'The server would not accept the connection. Check the host, port and security setting.', 'livingdraft-core' ) );
		}

		$mail->smtpClose();
	} catch ( Exception $e ) {
		return new WP_Error(
			'smtp_error',
			sprintf(
				/* translators: %s: error text. */
				__( 'SMTP refused the connection: %s', 'livingdraft-core' ),
				$e->getMessage()
			)
		);
	}

	return array(
		sprintf(
			/* translators: 1: host, 2: port. */
			__( 'Connected and authenticated with %1$s on port %2$d.', 'livingdraft-core' ),
			$settings['smtp_host'],
			(int) $settings['smtp_port']
		),
	);
}

/**
 * Which channel a kind of mail would actually use right now, and why.
 *
 * The fallback in livingdraft_mail_channel() is deliberate but invisible: a
 * transactional message quietly goes out over wp_mail when the configured
 * channel is not ready. Invisible is right at send time and wrong on a
 * settings screen, where the whole question is what is configured.
 *
 * @since 4.6.0
 * @param string $kind 'transactional' or 'bulk'.
 * @return array
 */
function livingdraft_mail_channel_status( $kind ) {
	$settings  = livingdraft_mail_settings();
	$chosen    = 'bulk' === $kind ? $settings['bulk'] : $settings['transactional'];
	$effective = livingdraft_mail_channel( $kind );

	if ( $chosen === $effective ) {
		return array(
			'ok'   => true,
			'note' => '',
		);
	}

	return array(
		'ok'   => false,
		'note' => sprintf(
			/* translators: 1: chosen channel, 2: channel actually used. */
			__( 'Set to %1$s, but %1$s is not configured — mail is going out over %2$s instead.', 'livingdraft-core' ),
			$chosen,
			$effective
		),
	);
}
