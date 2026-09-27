<?php
/**
 * Subscribers: consent, confirmation and leaving.
 *
 * === WHY DOUBLE OPT-IN, WHEN IT COSTS YOU SIGNUPS ===
 *
 * It does cost signups. Somewhere between a fifth and a third of addresses
 * entered into a form never click the confirmation link, and a list built
 * with double opt-in is visibly smaller than the same list built without it.
 *
 * It is still the only defensible design, for reasons that are not about
 * politeness:
 *
 *   1. Anyone can type anyone's address into a form. Without confirmation,
 *      a stranger can subscribe your editor, your competitor, or a spam trap
 *      address that exists solely to catch senders who do not confirm. Spam
 *      traps are how a domain gets blocklisted, and the block applies to
 *      every message the domain sends, including password resets.
 *
 *   2. A confirmed list has a click on record for every address. When a
 *      recipient reports a message as spam — and someone always does — that
 *      record is the difference between an inbox provider treating it as a
 *      mistake and treating it as a pattern.
 *
 *   3. The addresses that do not confirm were mostly typos and bots. Sending
 *      to them produces bounces, and bounce rate is the single strongest
 *      signal a receiving server uses to decide where the next message goes.
 *
 * The smaller list gets delivered. The bigger one gets filtered.
 *
 * === THE EXISTING LIST IS NOT RE-CONFIRMED ===
 *
 * Everyone already stored before this module existed is marked confirmed,
 * with '_ld_optin' set to 'single' so the record shows what actually
 * happened. Mailing an existing list to ask them to opt in again is a
 * re-permission campaign, it typically recovers under a third of the list,
 * and — this is the part people miss — it is itself a bulk send to
 * unconfirmed addresses, which is the exact thing being avoided.
 *
 * @package LivingDraftCore
 * @since 4.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Statuses a subscriber can be in.
 *
 * 'bounced' is set by hand from the admin screen or by a provider webhook.
 * Nothing in this plugin sets it automatically, because doing so requires
 * reading a mailbox, which this plugin deliberately does not do.
 *
 * @since 4.3.0
 * @return array
 */
function livingdraft_subscriber_statuses() {
	return array(
		'pending'      => __( 'Awaiting confirmation', 'livingdraft-core' ),
		'confirmed'    => __( 'Confirmed', 'livingdraft-core' ),
		'unsubscribed' => __( 'Unsubscribed', 'livingdraft-core' ),
		'bounced'      => __( 'Bounced', 'livingdraft-core' ),
	);
}

/**
 * A subscriber's status.
 *
 * @since 4.3.0
 * @param int $id Subscriber post id.
 * @return string
 */
function livingdraft_subscriber_status( $id ) {
	$status = get_post_meta( $id, '_ld_status', true );

	// Stored before this module existed. See the file header.
	if ( ! $status ) {
		update_post_meta( $id, '_ld_status', 'confirmed' );
		update_post_meta( $id, '_ld_optin', 'single' );

		return 'confirmed';
	}

	return (string) $status;
}

/**
 * A subscriber's tokens, created on first use.
 *
 * Two separate tokens, not one. A confirmation link is emailed once and is
 * spent; an unsubscribe link is in the footer of every message ever sent and
 * lives as long as the address does. Sharing one secret between them would
 * mean a forwarded old newsletter carries a working confirmation link.
 *
 * @since 4.3.0
 * @param int    $id   Subscriber post id.
 * @param string $kind 'confirm' or 'unsub'.
 * @return string
 */
function livingdraft_subscriber_token( $id, $kind ) {
	$key   = '_ld_token_' . ( 'confirm' === $kind ? 'confirm' : 'unsub' );
	$token = get_post_meta( $id, $key, true );

	if ( ! $token ) {
		$token = wp_generate_password( 32, false, false );
		update_post_meta( $id, $key, $token );
	}

	return (string) $token;
}

/**
 * Find a subscriber by token.
 *
 * @since 4.3.0
 * @param string $token Token.
 * @param string $kind  'confirm' or 'unsub'.
 * @return int|false Post id, or false.
 */
function livingdraft_subscriber_by_token( $token, $kind ) {
	if ( ! $token || strlen( $token ) < 16 ) {
		return false;
	}

	$found = get_posts(
		array(
			'post_type'      => 'ld_subscriber',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => '_ld_token_' . ( 'confirm' === $kind ? 'confirm' : 'unsub' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => $token, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);

	return ! empty( $found ) ? (int) $found[0] : false;
}

/**
 * Every address that may receive a campaign.
 *
 * The only function that decides who gets bulk mail. Deliberately the only
 * one: a second place that builds a recipient list is a second place that can
 * forget to exclude the people who left.
 *
 * @since 4.3.0
 * @return int[] Subscriber post ids.
 */
function livingdraft_subscriber_mailable() {
	$ids = get_posts(
		array(
			'post_type'      => 'ld_subscriber',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);

	return array_values(
		array_filter(
			$ids,
			function ( $id ) {
				return 'confirmed' === livingdraft_subscriber_status( $id );
			}
		)
	);
}

/**
 * Count by status.
 *
 * @since 4.3.0
 * @return array
 */
function livingdraft_subscriber_counts() {
	$counts = array_fill_keys( array_keys( livingdraft_subscriber_statuses() ), 0 );

	$ids = get_posts(
		array(
			'post_type'      => 'ld_subscriber',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	foreach ( $ids as $id ) {
		$status = livingdraft_subscriber_status( $id );

		if ( isset( $counts[ $status ] ) ) {
			$counts[ $status ]++;
		}
	}

	return $counts;
}

/* ==================================================================
 * 1. THE LINKS
 * ================================================================== */

/**
 * Confirmation URL.
 *
 * @since 4.3.0
 * @param int $id Subscriber post id.
 * @return string
 */
function livingdraft_subscriber_confirm_url( $id ) {
	return add_query_arg(
		array( 'ld-confirm' => livingdraft_subscriber_token( $id, 'confirm' ) ),
		home_url( '/' )
	);
}

/**
 * Unsubscribe URL.
 *
 * @since 4.3.0
 * @param int $id Subscriber post id.
 * @return string
 */
function livingdraft_subscriber_unsubscribe_url( $id ) {
	return add_query_arg(
		array( 'ld-unsubscribe' => livingdraft_subscriber_token( $id, 'unsub' ) ),
		home_url( '/' )
	);
}

/**
 * Handle both links on the front end.
 *
 * === WHY UNSUBSCRIBE HAS NO CONFIRMATION STEP ===
 *
 * Because RFC 8058 one-click requires it. Gmail and Outlook POST to the
 * List-Unsubscribe URL themselves, with no browser, no session and no way to
 * press a second button. A URL that answers with "are you sure?" fails, and
 * the inbox provider records the failure against the sender.
 *
 * It is also just correct. A person who wants to leave a mailing list has
 * already made the decision, and an "are you sure?" is a small hostility at
 * the exact moment goodwill is most fragile. Leaving is one press. Coming
 * back is one form.
 *
 * @since 4.3.0
 * @return void
 */
function livingdraft_subscriber_handle_links() {
	if ( is_admin() ) {
		return;
	}

	// --- Unsubscribe. Accepts GET (link) and POST (one-click). ---
	$unsub = isset( $_REQUEST['ld-unsubscribe'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ld-unsubscribe'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The token IS the credential; a nonce is impossible here because the request comes from a mail client with no session.

	if ( $unsub ) {
		$id = livingdraft_subscriber_by_token( $unsub, 'unsub' );

		if ( $id ) {
			update_post_meta( $id, '_ld_status', 'unsubscribed' );
			update_post_meta( $id, '_ld_unsubscribed_at', time() );

			/**
			 * Fires when someone leaves the list.
			 *
			 * @since 4.3.0
			 * @param int $id Subscriber post id.
			 */
			do_action( 'livingdraft_subscriber_unsubscribed', $id );
		}

		// A bad or already-used token still gets the same page. Telling a
		// stranger "that token is not valid" confirms which tokens are,
		// and there is nothing useful the reader could do with the
		// difference anyway.
		livingdraft_subscriber_render_notice(
			__( 'You have been removed', 'livingdraft-core' ),
			__( 'You will not receive any more newsletters from us. If this was a mistake, you can sign up again from any page on the site.', 'livingdraft-core' )
		);
	}

	// --- Confirm. ---
	$confirm = isset( $_GET['ld-confirm'] ) ? sanitize_text_field( wp_unslash( $_GET['ld-confirm'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.

	if ( $confirm ) {
		$id = livingdraft_subscriber_by_token( $confirm, 'confirm' );

		if ( $id && 'unsubscribed' !== livingdraft_subscriber_status( $id ) ) {
			update_post_meta( $id, '_ld_status', 'confirmed' );
			update_post_meta( $id, '_ld_confirmed_at', time() );
			update_post_meta( $id, '_ld_optin', 'double' );

			/*
			 * Spend the confirmation token. The link is in an email that
			 * will sit in an inbox for years and may be forwarded; it
			 * should stop working once it has done its job.
			 */
			delete_post_meta( $id, '_ld_token_confirm' );

			/**
			 * Fires when an address is confirmed.
			 *
			 * @since 4.3.0
			 * @param int $id Subscriber post id.
			 */
			do_action( 'livingdraft_subscriber_confirmed', $id );

			livingdraft_subscriber_render_notice(
				__( 'You are on the list', 'livingdraft-core' ),
				__( 'Thank you for confirming. Every newsletter has an unsubscribe link at the bottom, and it works in one press.', 'livingdraft-core' )
			);
		}

		livingdraft_subscriber_render_notice(
			__( 'That link has expired', 'livingdraft-core' ),
			__( 'Confirmation links can only be used once. If you are not sure whether you are subscribed, sign up again — a second signup will not create a duplicate.', 'livingdraft-core' )
		);
	}
}
add_action( 'template_redirect', 'livingdraft_subscriber_handle_links' );

/**
 * A minimal standalone page for the confirm/unsubscribe result.
 *
 * Rendered rather than redirected to a WordPress page, because that page
 * would have to exist, be found by slug, and survive being renamed. A person
 * arriving here has come from an email client and needs one sentence.
 *
 * @since 4.3.0
 * @param string $title   Heading.
 * @param string $message Body.
 * @return never
 */
function livingdraft_subscriber_render_notice( $title, $message ) {
	status_header( 200 );
	nocache_headers();

	wp_die(
		sprintf(
			'<p style="font-size:16px;line-height:1.6;">%s</p><p style="margin-top:18px;"><a href="%s">%s</a></p>',
			esc_html( $message ),
			esc_url( home_url( '/' ) ),
			esc_html(
				sprintf(
					/* translators: %s: site name. */
					__( 'Back to %s', 'livingdraft-core' ),
					get_bloginfo( 'name' )
				)
			)
		),
		esc_html( $title ),
		array(
			'response'  => 200,
			'back_link' => false,
		)
	);
}

/* ==================================================================
 * 2. THE CONFIRMATION EMAIL
 * ================================================================== */

/**
 * Send the "please confirm" message.
 *
 * @since 4.3.0
 * @param string $email Address.
 * @param int    $id    Subscriber post id.
 * @return void
 */
function livingdraft_subscriber_send_confirmation( $email, $id ) {
	if ( ! function_exists( 'livingdraft_mail_send' ) ) {
		return;
	}

	$url  = livingdraft_subscriber_confirm_url( $id );
	$name = get_bloginfo( 'name' );

	$body = sprintf(
		'<p style="margin:0 0 16px;">%s</p>
		<p style="margin:0 0 22px;"><a href="%s" style="display:inline-block;background:#111111;color:#fdfdfb;padding:12px 22px;text-decoration:none;font-family:Helvetica,Arial,sans-serif;font-size:15px;font-weight:600;">%s</a></p>
		<p style="margin:0 0 16px;font-size:14px;color:#4a4a45;">%s</p>
		<p style="margin:0;font-size:13px;color:#63635c;word-break:break-all;">%s</p>',
		esc_html(
			sprintf(
				/* translators: %s: site name. */
				__( 'Someone — we hope you — asked for the %s newsletter to be sent to this address. Press the button to confirm it.', 'livingdraft-core' ),
				$name
			)
		),
		esc_url( $url ),
		esc_html__( 'Confirm my address', 'livingdraft-core' ),
		esc_html__( 'If it was not you, ignore this message. Nothing will be sent to this address unless the link is pressed.', 'livingdraft-core' ),
		esc_html( $url )
	);

	livingdraft_mail_send(
		$email,
		sprintf(
			/* translators: %s: site name. */
			__( 'Confirm your subscription to %s', 'livingdraft-core' ),
			$name
		),
		livingdraft_mail_wrap(
			$body,
			array(
				'preheader'   => __( 'One press and you are on the list.', 'livingdraft-core' ),
				'footer_html' => '<p style="margin:0;">' . esc_html__( 'You received this because this address was entered into a signup form on our site.', 'livingdraft-core' ) . '</p>',
			)
		),
		array(
			'kind'    => 'transactional',
			'context' => 'confirm',
		)
	);
}

/**
 * Hook the confirmation onto the existing signup handler.
 *
 * newsletter.php already fires `livingdraft_subscriber_added` after storing
 * an address, and its header says the hook exists so a sending service can be
 * attached later. This is that later.
 *
 * @since 4.3.0
 * @param string $email Address.
 * @param int    $id    Subscriber post id.
 * @return void
 */
function livingdraft_subscriber_on_added( $email, $id ) {
	update_post_meta( $id, '_ld_status', 'pending' );
	update_post_meta( $id, '_ld_signed_up_at', time() );

	livingdraft_subscriber_send_confirmation( $email, $id );
}
add_action( 'livingdraft_subscriber_added', 'livingdraft_subscriber_on_added', 10, 2 );

/**
 * Tell a new signup to go and check their inbox.
 *
 * Without this the form still says "You are on the list", which is now a lie
 * — they are pending, and if they never open the email they will wonder for
 * a month why nothing arrives.
 *
 * @since 4.3.0
 * @param array $response Response payload.
 * @return array
 */
function livingdraft_subscriber_pending_message( $response ) {
	$response['message'] = __( 'Almost done — check your inbox and press the link in the confirmation email.', 'livingdraft-core' );

	return $response;
}
add_filter( 'livingdraft_subscribe_response', 'livingdraft_subscriber_pending_message' );

/* ==================================================================
 * 3. ADMIN COLUMNS
 * ================================================================== */

/**
 * Status column on the subscriber list.
 *
 * @param array $cols Columns.
 * @return array
 * @since 4.3.0
 */
function livingdraft_subscriber_status_column( $cols ) {
	$out = array();

	foreach ( $cols as $key => $label ) {
		$out[ $key ] = $label;

		if ( 'title' === $key ) {
			$out['ld_status'] = __( 'Status', 'livingdraft-core' );
		}
	}

	return $out;
}
add_filter( 'manage_ld_subscriber_posts_columns', 'livingdraft_subscriber_status_column', 20 );

/**
 * Fill it.
 *
 * @param string $col     Column key.
 * @param int    $post_id Post id.
 * @return void
 * @since 4.3.0
 */
function livingdraft_subscriber_status_cell( $col, $post_id ) {
	if ( 'ld_status' !== $col ) {
		return;
	}

	$status   = livingdraft_subscriber_status( $post_id );
	$statuses = livingdraft_subscriber_statuses();
	$label    = $statuses[ $status ] ?? $status;

	$colour = array(
		'confirmed'    => '#1a7f37',
		'pending'      => '#9a6700',
		'unsubscribed' => '#63635c',
		'bounced'      => '#a3271f',
	);

	printf(
		'<strong style="color:%s">%s</strong>',
		esc_attr( $colour[ $status ] ?? '#111' ),
		esc_html( $label )
	);

	if ( 'confirmed' === $status && 'single' === get_post_meta( $post_id, '_ld_optin', true ) ) {
		echo '<br /><span style="color:#63635c;font-size:11px;">' . esc_html__( 'signed up before confirmation existed', 'livingdraft-core' ) . '</span>';
	}
}
add_action( 'manage_ld_subscriber_posts_custom_column', 'livingdraft_subscriber_status_cell', 20, 2 );
