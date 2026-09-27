<?php
/**
 * Campaigns: sending one message to the whole list.
 *
 * === WHY A QUEUE AND NOT A LOOP ===
 *
 * The obvious implementation is a foreach over the subscriber list inside the
 * request that pressed Send. It works on a list of forty and fails on a list
 * of four hundred, in the worst possible way: PHP's max_execution_time kills
 * the request partway through, the browser shows an error, and the sender has
 * no idea how many messages went out. Press Send again and the first two
 * hundred people get it twice.
 *
 * So sending is a queue. The Send button writes a list of recipient ids to
 * post meta and returns immediately. A cron event takes a batch off the front
 * of that list, sends it, and writes the shorter list back. Every message is
 * removed from the queue as it is attempted, so a run that dies halfway
 * resumes exactly where it stopped and nobody is sent the same campaign
 * twice.
 *
 * === THE THREE THINGS THAT CANNOT BE SKIPPED ===
 *
 * 1. The recipient list is snapshotted at Send. Somebody who unsubscribes
 *    while a campaign is running must not receive it — so the queue is
 *    re-checked per recipient, not just at snapshot time. Both, not either.
 *
 * 2. Every message carries a per-recipient unsubscribe URL in the footer AND
 *    in the List-Unsubscribe header. The header is what makes Gmail offer its
 *    own unsubscribe button, and a sender that offers it is treated better
 *    than one that does not.
 *
 * 3. The daily cap is checked before every batch, not once at the start. A
 *    free Brevo key is 300 messages a day; running into that limit mid-batch
 *    produces a run of failures that look like a broken configuration.
 *
 * === WHY NOTHING HERE SENDS WITHOUT A PRESS ===
 *
 * There is no schedule, no automation, no "send whenever a post is
 * published". Every campaign goes out because a person pressed a button on a
 * screen that showed them the recipient count first. The AI in mail-ai.php
 * writes drafts; it has no path to this file.
 *
 * @package LivingDraftCore
 * @since 4.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the campaign post type.
 *
 * @since 4.3.0
 * @return void
 */
function livingdraft_campaign_type() {
	register_post_type(
		'ld_campaign',
		array(
			'labels'          => array(
				'name'          => __( 'Newsletters', 'livingdraft-core' ),
				'singular_name' => __( 'Newsletter', 'livingdraft-core' ),
			),
			'public'          => false,
			'show_ui'         => false, // Our own screen handles these.
			'capability_type' => 'post',
			'map_meta_cap'    => true,
			'supports'        => array( 'title', 'editor' ),
			'has_archive'     => false,
			'rewrite'         => false,
			'show_in_rest'    => false,
		)
	);
}
add_action( 'init', 'livingdraft_campaign_type' );

/**
 * Campaign states.
 *
 * @since 4.3.0
 * @return array
 */
function livingdraft_campaign_states() {
	return array(
		'draft'   => __( 'Draft', 'livingdraft-core' ),
		'sending' => __( 'Sending', 'livingdraft-core' ),
		'paused'  => __( 'Paused', 'livingdraft-core' ),
		'sent'    => __( 'Sent', 'livingdraft-core' ),
	);
}

/**
 * Read a campaign's state.
 *
 * @since 4.3.0
 * @param int $id Campaign id.
 * @return string
 */
function livingdraft_campaign_state( $id ) {
	$state = get_post_meta( $id, '_ld_state', true );

	return $state ? (string) $state : 'draft';
}

/**
 * Can a campaign be sent at all right now?
 *
 * Unlike transactional mail, bulk does NOT silently fall back to wp_mail.
 * Nine hundred messages through PHP's mail() is how a domain gets
 * blocklisted, and the block applies to every message the domain sends
 * afterwards — including the password resets that had nothing to do with it.
 * Refusing to start is the kind thing to do.
 *
 * @since 4.3.0
 * @return true|WP_Error
 */
function livingdraft_campaign_can_send() {
	$settings = livingdraft_mail_settings();

	if ( ! livingdraft_mail_channel_ready( $settings['bulk'] ) ) {
		return new WP_Error(
			'no_channel',
			__( 'The bulk sending channel is not configured. Set up SMTP or an API key under Mail → Settings first.', 'livingdraft-core' )
		);
	}

	if ( 'wp' === $settings['bulk'] ) {
		return new WP_Error(
			'wp_mail_bulk',
			__( 'Bulk mail will not be sent through the default WordPress mailer. Choose SMTP or an API provider for bulk sending — mail() has no authentication, and using it for a whole list is the fastest way to get the domain blocklisted.', 'livingdraft-core' )
		);
	}

	if ( ! is_email( $settings['from_email'] ) ) {
		return new WP_Error( 'no_from', __( 'Set a valid From address under Mail → Settings.', 'livingdraft-core' ) );
	}

	return true;
}

/* ==================================================================
 * 1. STARTING A SEND
 * ================================================================== */

/**
 * Queue a campaign.
 *
 * @since 4.3.0
 * @param int $id Campaign id.
 * @return int|WP_Error Number of recipients queued.
 */
function livingdraft_campaign_start( $id ) {
	$can = livingdraft_campaign_can_send();

	if ( is_wp_error( $can ) ) {
		return $can;
	}

	if ( in_array( livingdraft_campaign_state( $id ), array( 'sending', 'sent' ), true ) ) {
		return new WP_Error( 'already', __( 'That campaign has already been sent or is sending now.', 'livingdraft-core' ) );
	}

	$recipients = livingdraft_subscriber_mailable();

	if ( empty( $recipients ) ) {
		return new WP_Error( 'no_recipients', __( 'There are no confirmed subscribers to send to.', 'livingdraft-core' ) );
	}

	update_post_meta( $id, '_ld_queue', $recipients );
	update_post_meta( $id, '_ld_total', count( $recipients ) );
	update_post_meta( $id, '_ld_sent', 0 );
	update_post_meta( $id, '_ld_failed', 0 );
	update_post_meta( $id, '_ld_started_at', time() );
	update_post_meta( $id, '_ld_state', 'sending' );

	livingdraft_campaign_schedule();

	/*
	 * Run the first batch immediately rather than waiting up to five
	 * minutes for cron. Someone who presses Send and sees nothing happen
	 * for five minutes presses it again.
	 */
	livingdraft_campaign_run_batch( $id );

	return count( $recipients );
}

/**
 * Pause a running campaign.
 *
 * @since 4.3.0
 * @param int $id Campaign id.
 * @return void
 */
function livingdraft_campaign_pause( $id ) {
	if ( 'sending' === livingdraft_campaign_state( $id ) ) {
		update_post_meta( $id, '_ld_state', 'paused' );
	}
}

/**
 * Resume a paused campaign.
 *
 * @since 4.3.0
 * @param int $id Campaign id.
 * @return void
 */
function livingdraft_campaign_resume( $id ) {
	if ( 'paused' === livingdraft_campaign_state( $id ) ) {
		update_post_meta( $id, '_ld_state', 'sending' );
		livingdraft_campaign_run_batch( $id );
	}
}

/* ==================================================================
 * 2. THE RUN
 * ================================================================== */

/**
 * Send one batch of one campaign.
 *
 * @since 4.3.0
 * @param int $id Campaign id.
 * @return int Messages attempted in this batch.
 */
function livingdraft_campaign_run_batch( $id ) {
	if ( 'sending' !== livingdraft_campaign_state( $id ) ) {
		return 0;
	}

	$settings = livingdraft_mail_settings();
	$batch    = max( 1, (int) $settings['batch_size'] );
	$cap      = (int) $settings['daily_cap'];

	$queue = get_post_meta( $id, '_ld_queue', true );
	$queue = is_array( $queue ) ? $queue : array();

	if ( empty( $queue ) ) {
		livingdraft_campaign_finish( $id );
		return 0;
	}

	// Never start a batch that the daily cap will cut off partway through.
	$remaining_today = $cap - livingdraft_mail_sent_today();

	if ( $remaining_today <= 0 ) {
		return 0;
	}

	$batch = min( $batch, $remaining_today );

	$post    = get_post( $id );
	$subject = $post ? $post->post_title : '';
	$body    = $post ? $post->post_content : '';

	$sent    = (int) get_post_meta( $id, '_ld_sent', true );
	$failed  = (int) get_post_meta( $id, '_ld_failed', true );
	$done    = 0;

	/*
	 * A wall clock, not just a count. Shared hosting kills a cron request
	 * at thirty seconds surprisingly often, and an SMTP conversation can
	 * take a second each. Stopping ourselves at twenty leaves the queue in
	 * a clean state instead of finding out the hard way.
	 */
	$deadline = time() + 20;

	while ( ! empty( $queue ) && $done < $batch && time() < $deadline ) {
		$subscriber_id = (int) array_shift( $queue );

		/*
		 * Written back BEFORE the send, not after. This is the choice
		 * between at-most-once and at-least-once delivery, and for a
		 * newsletter at-most-once is correct: if the process is killed
		 * during this send, one person misses this issue. Written back
		 * after the send instead, the same crash would leave the address
		 * in the queue and that person gets the newsletter twice — which
		 * is the version readers notice, complain about, and mark as spam.
		 */
		update_post_meta( $id, '_ld_queue', $queue );

		$done++;

		// Re-check: they may have unsubscribed since the snapshot.
		if ( 'confirmed' !== livingdraft_subscriber_status( $subscriber_id ) ) {
			continue;
		}

		$email = get_the_title( $subscriber_id );

		if ( ! is_email( $email ) ) {
			continue;
		}

		$unsub  = livingdraft_subscriber_unsubscribe_url( $subscriber_id );
		$result = livingdraft_mail_send(
			$email,
			$subject,
			livingdraft_campaign_render( $body, $unsub ),
			array(
				'kind'        => 'bulk',
				'unsubscribe' => $unsub,
				'context'     => 'campaign:' . $id,
			)
		);

		if ( is_wp_error( $result ) ) {
			$failed++;
		} else {
			$sent++;
		}
	}

	update_post_meta( $id, '_ld_sent', $sent );
	update_post_meta( $id, '_ld_failed', $failed );

	if ( empty( $queue ) ) {
		livingdraft_campaign_finish( $id );
	}

	return $done;
}

/**
 * Mark a campaign finished.
 *
 * @since 4.3.0
 * @param int $id Campaign id.
 * @return void
 */
function livingdraft_campaign_finish( $id ) {
	update_post_meta( $id, '_ld_state', 'sent' );
	update_post_meta( $id, '_ld_finished_at', time() );
	delete_post_meta( $id, '_ld_queue' );

	/**
	 * Fires when a campaign has finished sending.
	 *
	 * @since 4.3.0
	 * @param int $id Campaign id.
	 */
	do_action( 'livingdraft_campaign_sent', $id );
}

/**
 * Build the message body for one recipient.
 *
 * @since 4.3.0
 * @param string $body  Campaign HTML.
 * @param string $unsub Unsubscribe URL for this recipient.
 * @return string
 */
function livingdraft_campaign_render( $body, $unsub ) {
	$footer = sprintf(
		'<p style="margin:0;">%s</p><p style="margin:8px 0 0;"><a href="%s" style="color:#63635c;">%s</a></p>',
		esc_html(
			sprintf(
				/* translators: %s: site name. */
				__( 'You are receiving this because you confirmed your address for the %s newsletter.', 'livingdraft-core' ),
				get_bloginfo( 'name' )
			)
		),
		esc_url( $unsub ),
		esc_html__( 'Unsubscribe', 'livingdraft-core' )
	);

	return livingdraft_mail_wrap(
		wpautop( $body ),
		array(
			'preheader'   => wp_trim_words( wp_strip_all_tags( $body ), 22, '' ),
			'footer_html' => $footer,
		)
	);
}

/* ==================================================================
 * 3. CRON
 * ================================================================== */

/**
 * Every five minutes while anything is queued.
 *
 * @since 4.3.0
 * @param array $schedules Existing schedules.
 * @return array
 */
function livingdraft_campaign_cron_schedule( $schedules ) {
	if ( ! isset( $schedules['livingdraft_five_minutes'] ) ) {
		$schedules['livingdraft_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes (The Living Draft)', 'livingdraft-core' ),
		);
	}

	return $schedules;
}
add_filter( 'cron_schedules', 'livingdraft_campaign_cron_schedule' ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected

/**
 * Make sure the event exists.
 *
 * @since 4.3.0
 * @return void
 */
function livingdraft_campaign_schedule() {
	if ( ! wp_next_scheduled( 'livingdraft_mail_queue' ) ) {
		wp_schedule_event( time() + 60, 'livingdraft_five_minutes', 'livingdraft_mail_queue' );
	}
}

/**
 * Cron handler: run a batch of whatever is sending.
 *
 * One campaign per tick. Two campaigns sending at once would race each other
 * for the same daily allowance and neither would finish predictably.
 *
 * @since 4.3.0
 * @return void
 */
function livingdraft_campaign_tick() {
	$sending = get_posts(
		array(
			'post_type'      => 'ld_campaign',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'meta_key'       => '_ld_state', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => 'sending', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);

	if ( empty( $sending ) ) {
		// Nothing left to do. Take the event back off the schedule rather
		// than waking up every five minutes forever.
		wp_clear_scheduled_hook( 'livingdraft_mail_queue' );
		return;
	}

	livingdraft_campaign_run_batch( (int) $sending[0] );
}
add_action( 'livingdraft_mail_queue', 'livingdraft_campaign_tick' );

/* ==================================================================
 * 4. PROGRESS
 * ================================================================== */

/**
 * A campaign's numbers, for the admin screen.
 *
 * @since 4.3.0
 * @param int $id Campaign id.
 * @return array
 */
function livingdraft_campaign_progress( $id ) {
	$total  = (int) get_post_meta( $id, '_ld_total', true );
	$sent   = (int) get_post_meta( $id, '_ld_sent', true );
	$failed = (int) get_post_meta( $id, '_ld_failed', true );
	$queue  = get_post_meta( $id, '_ld_queue', true );

	return array(
		'total'     => $total,
		'sent'      => $sent,
		'failed'    => $failed,
		'remaining' => is_array( $queue ) ? count( $queue ) : 0,
		'percent'   => $total > 0 ? (int) round( ( ( $sent + $failed ) / $total ) * 100 ) : 0,
		'state'     => livingdraft_campaign_state( $id ),
	);
}
