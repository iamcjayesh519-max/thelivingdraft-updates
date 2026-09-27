<?php
/**
 * Story timelines: the review screen.
 *
 * Two lists. The stories that were joined automatically, and the pairs that
 * scored in the middle and are waiting for one click.
 *
 * The queue is the part that makes the whole thing trustworthy. A purely
 * automatic system on a news site will be wrong sometimes, and a wrong
 * timeline at the foot of an article is publicly visible. Rather than guess
 * at the borderline cases, they are shown with the reason for the score and
 * decided by a person. That decision is stored against the two article ids
 * and survives every future rebuild.
 *
 * @package LivingDraftCore
 * @since 4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the screen.
 *
 * @since 4.0.0
 * @return void
 */
function livingdraft_timeline_menu() {
	add_submenu_page(
		'livingdraft-overview',
		__( 'Story Timelines', 'livingdraft-core' ),
		__( 'Timelines', 'livingdraft-core' ),
		'manage_categories',
		'livingdraft-timelines',
		'livingdraft_timeline_screen'
	);
}
add_action( 'admin_menu', 'livingdraft_timeline_menu', 30 );

/**
 * Handle form posts, then redirect so a refresh cannot repeat them.
 *
 * @since 4.0.0
 * @return void
 */
function livingdraft_timeline_handle_actions() {
	if ( empty( $_POST['ld_timeline_action'] ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_categories' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'livingdraft-core' ), 403 );
	}

	$action = sanitize_key( wp_unslash( $_POST['ld_timeline_action'] ) );
	check_admin_referer( 'ld_timeline_' . $action );

	$notice = 'saved';

	if ( 'rebuild' === $action ) {
		livingdraft_timeline_rebuild_index();
		$notice = 'rebuilt';

	} elseif ( 'decide' === $action ) {
		$a       = isset( $_POST['a'] ) ? absint( wp_unslash( $_POST['a'] ) ) : 0;
		$b       = isset( $_POST['b'] ) ? absint( wp_unslash( $_POST['b'] ) ) : 0;
		$verdict = isset( $_POST['verdict'] ) ? sanitize_key( wp_unslash( $_POST['verdict'] ) ) : '';

		if ( $a && $b && in_array( $verdict, array( 'yes', 'no' ), true ) ) {
			livingdraft_timeline_set_pair( $a, $b, $verdict );
			livingdraft_timeline_rebuild_index();
		}

	} elseif ( 'suppress' === $action ) {
		$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';

		if ( '' !== $key ) {
			livingdraft_timeline_set_override( $key, 'topic' );
			livingdraft_timeline_rebuild_index();
		}

	} elseif ( 'rename' === $action ) {
		$key   = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';

		if ( '' !== $key ) {
			livingdraft_timeline_set_override( $key, 'keep', $label );
			livingdraft_timeline_rebuild_index();
		}

	} elseif ( 'settings' === $action ) {
		update_option( 'livingdraft_timeline_enabled', ! empty( $_POST['enabled'] ) ? 1 : 0 );

	} else {
		return;
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'      => 'livingdraft-timelines',
				'ld_notice' => $notice,
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}
add_action( 'admin_init', 'livingdraft_timeline_handle_actions' );

/**
 * Render the screen.
 *
 * @since 4.0.0
 * @return void
 */
function livingdraft_timeline_screen() {
	if ( ! current_user_can( 'manage_categories' ) ) {
		return;
	}

	$stories = livingdraft_timeline_index();
	$queue   = livingdraft_timeline_queue();
	$built   = livingdraft_timeline_index_age();
	$enabled = livingdraft_timeline_enabled();
	$rules   = livingdraft_timeline_rules();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	$notice = isset( $_GET['ld_notice'] ) ? sanitize_key( wp_unslash( $_GET['ld_notice'] ) ) : '';

	$total_articles = 0;
	foreach ( $stories as $story ) {
		$total_articles += (int) $story['count'];
	}

	livingdraft_admin_render_header(
		array(
			'title' => __( 'Story Timelines', 'livingdraft-core' ),
			'desc'  => __( 'Groups your articles into the running stories they belong to, and prints a dated timeline at the foot of each one.', 'livingdraft-core' ),
		)
	);
	?>

	<?php if ( $notice ) : ?>
		<div class="tld-notice is-info" style="margin-bottom:20px">
			<p style="margin:0">
				<?php
				echo 'rebuilt' === $notice
					? esc_html__( 'Timelines worked out again.', 'livingdraft-core' )
					: esc_html__( 'Saved.', 'livingdraft-core' );
				?>
			</p>
		</div>
	<?php endif; ?>

	<div class="tld-numbers" style="margin-bottom:24px">
		<div>
			<div class="tld-num-value"><?php echo esc_html( number_format_i18n( count( $stories ) ) ); ?></div>
			<div class="tld-num-label"><?php esc_html_e( 'Timelines', 'livingdraft-core' ); ?></div>
		</div>
		<div>
			<div class="tld-num-value"><?php echo esc_html( number_format_i18n( $total_articles ) ); ?></div>
			<div class="tld-num-label"><?php esc_html_e( 'Articles grouped', 'livingdraft-core' ); ?></div>
		</div>
		<div>
			<div class="tld-num-value"><?php echo esc_html( number_format_i18n( count( $queue ) ) ); ?></div>
			<div class="tld-num-label"><?php esc_html_e( 'Waiting for you', 'livingdraft-core' ); ?></div>
		</div>
		<div>
			<div class="tld-num-value"><?php echo $built ? esc_html( human_time_diff( $built ) ) : '—'; ?></div>
			<div class="tld-num-label"><?php esc_html_e( 'Since last run', 'livingdraft-core' ); ?></div>
		</div>
	</div>

	<div class="tld-notice is-warn" style="margin-bottom:24px">
		<p style="margin:0"><?php esc_html_e( 'Every pair of articles is scored on four things: whether one links to the other, whether they share rare names like IN-SPACe or PPPAC, whether their URLs share a word, and how close together they ran. A high score joins them. A middling score waits for you below. Your decisions are permanent.', 'livingdraft-core' ); ?></p>
	</div>

	<div class="tld-card" style="margin-bottom:20px">
		<form method="post" style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin:0">
			<?php wp_nonce_field( 'ld_timeline_settings' ); ?>
			<input type="hidden" name="ld_timeline_action" value="settings">
			<label style="display:flex;align-items:center;gap:8px;font-size:13px;margin:0">
				<input type="checkbox" name="enabled" value="1" <?php checked( $enabled ); ?>>
				<?php esc_html_e( 'Show timelines on articles', 'livingdraft-core' ); ?>
			</label>
			<button type="submit" class="tld-btn is-ghost"><?php esc_html_e( 'Save', 'livingdraft-core' ); ?></button>
		</form>
	</div>

	<?php /* ---------------- THE QUEUE ---------------- */ ?>
	<div class="tld-card" style="margin-bottom:20px">
		<div class="tld-card-header">
			<div>
				<span class="tld-card-eyebrow">
					<?php
					printf(
						/* translators: 1: lower threshold, 2: upper threshold. */
						esc_html__( 'Scored between %1$d and %2$d', 'livingdraft-core' ),
						(int) $rules['ask_at'],
						(int) $rules['join_at']
					);
					?>
				</span>
				<h2 class="tld-card-title"><?php esc_html_e( 'Waiting for you', 'livingdraft-core' ); ?></h2>
			</div>
			<?php if ( $queue ) : ?>
				<span class="tld-badge is-warn"><?php echo esc_html( number_format_i18n( count( $queue ) ) ); ?></span>
			<?php endif; ?>
		</div>

		<p class="tld-help" style="margin-top:0">
			<?php esc_html_e( 'Enough to be worth asking about, not enough to act on alone. Whichever you choose is remembered against those two articles and never asked again.', 'livingdraft-core' ); ?>
		</p>

		<?php if ( empty( $queue ) ) : ?>
			<div class="tld-empty">
				<div class="tld-empty-title"><?php esc_html_e( 'Nothing waiting', 'livingdraft-core' ); ?></div>
				<div class="tld-empty-desc"><?php esc_html_e( 'Borderline pairs appear here for one click.', 'livingdraft-core' ); ?></div>
			</div>
		<?php else : ?>
			<?php foreach ( $queue as $row ) : ?>
				<?php
				$ld_a = get_post( (int) $row['a'] );
				$ld_b = get_post( (int) $row['b'] );

				if ( ! $ld_a || ! $ld_b ) {
					continue;
				}
				?>
				<div style="padding:14px 0;border-bottom:1px solid var(--tld-rule-2,#e6e3da)">
					<div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start">
						<div style="flex:1;min-width:0">
							<div style="font-size:13px;margin-bottom:4px">
								<a href="<?php echo esc_url( (string) get_edit_post_link( $ld_a ) ); ?>"><?php echo esc_html( get_the_title( $ld_a ) ); ?></a>
								<span style="color:var(--tld-ink-3,#999)"> · <?php echo esc_html( get_the_date( 'j M Y', $ld_a ) ); ?></span>
							</div>
							<div style="font-size:13px;margin-bottom:6px">
								<a href="<?php echo esc_url( (string) get_edit_post_link( $ld_b ) ); ?>"><?php echo esc_html( get_the_title( $ld_b ) ); ?></a>
								<span style="color:var(--tld-ink-3,#999)"> · <?php echo esc_html( get_the_date( 'j M Y', $ld_b ) ); ?></span>
							</div>
							<?php if ( ! empty( $row['why'] ) ) : ?>
								<div style="font-size:12px;color:var(--tld-ink-3,#666);font-style:italic">
									<?php echo esc_html( implode( '; ', (array) $row['why'] ) ); ?>
								</div>
							<?php endif; ?>
						</div>
						<div style="text-align:right;white-space:nowrap">
							<div style="font-size:20px;font-variant-numeric:tabular-nums;margin-bottom:6px"><?php echo esc_html( (int) $row['score'] ); ?></div>
							<form method="post" style="display:flex;gap:4px">
								<?php wp_nonce_field( 'ld_timeline_decide' ); ?>
								<input type="hidden" name="ld_timeline_action" value="decide">
								<input type="hidden" name="a" value="<?php echo esc_attr( (int) $row['a'] ); ?>">
								<input type="hidden" name="b" value="<?php echo esc_attr( (int) $row['b'] ); ?>">
								<button class="tld-btn is-primary is-small" name="verdict" value="yes"><?php esc_html_e( 'Same story', 'livingdraft-core' ); ?></button>
								<button class="tld-btn is-ghost is-small" name="verdict" value="no"><?php esc_html_e( 'No', 'livingdraft-core' ); ?></button>
							</form>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>

	<?php /* ---------------- THE STORIES ---------------- */ ?>
	<div class="tld-card">
		<div class="tld-card-header">
			<div>
				<span class="tld-card-eyebrow"><?php esc_html_e( 'Joined automatically', 'livingdraft-core' ); ?></span>
				<h2 class="tld-card-title"><?php esc_html_e( 'Timelines', 'livingdraft-core' ); ?></h2>
			</div>
			<form method="post" style="margin:0">
				<?php wp_nonce_field( 'ld_timeline_rebuild' ); ?>
				<input type="hidden" name="ld_timeline_action" value="rebuild">
				<button type="submit" class="tld-btn is-ghost is-small"><?php esc_html_e( 'Work them out again', 'livingdraft-core' ); ?></button>
			</form>
		</div>

		<?php if ( empty( $stories ) ) : ?>
			<div class="tld-empty">
				<div class="tld-empty-title"><?php esc_html_e( 'No timelines yet', 'livingdraft-core' ); ?></div>
				<div class="tld-empty-desc">
					<?php
					printf(
						/* translators: %d: minimum articles per story. */
						esc_html__( 'A story needs at least %d articles that link to each other or share rare names.', 'livingdraft-core' ),
						(int) $rules['min_posts']
					);
					?>
				</div>
			</div>
		<?php else : ?>
			<?php foreach ( $stories as $ld_key => $story ) : ?>
				<div style="padding:16px 0;border-bottom:1px solid var(--tld-rule-2,#e6e3da)">
					<div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:8px">
						<div style="flex:1;min-width:0">
							<div style="font-size:16px;font-weight:500;margin-bottom:2px"><?php echo esc_html( $story['label'] ); ?></div>
							<div style="font-size:12px;color:var(--tld-ink-3,#666)">
								<?php
								printf(
									/* translators: 1: article count, 2: first date, 3: last date, 4: span in days. */
									esc_html__( '%1$d articles · %2$s to %3$s · %4$d days', 'livingdraft-core' ),
									(int) $story['count'],
									esc_html( mysql2date( 'j M Y', $story['first'] ) ),
									esc_html( mysql2date( 'j M Y', $story['last'] ) ),
									(int) $story['span_days']
								);
								?>
							</div>
						</div>
						<div style="display:flex;gap:6px;white-space:nowrap">
							<form method="post" style="display:flex;gap:4px;margin:0">
								<?php wp_nonce_field( 'ld_timeline_rename' ); ?>
								<input type="hidden" name="ld_timeline_action" value="rename">
								<input type="hidden" name="key" value="<?php echo esc_attr( $ld_key ); ?>">
								<input type="text" name="label" class="tld-input" style="max-width:170px"
									value="<?php echo esc_attr( $story['label'] ); ?>"
									aria-label="<?php esc_attr_e( 'Story name shown to readers', 'livingdraft-core' ); ?>">
								<button class="tld-btn is-ghost is-small"><?php esc_html_e( 'Rename', 'livingdraft-core' ); ?></button>
							</form>
							<form method="post" style="margin:0">
								<?php wp_nonce_field( 'ld_timeline_suppress' ); ?>
								<input type="hidden" name="ld_timeline_action" value="suppress">
								<input type="hidden" name="key" value="<?php echo esc_attr( $ld_key ); ?>">
								<button class="tld-btn is-danger is-small"><?php esc_html_e( 'Not a story', 'livingdraft-core' ); ?></button>
							</form>
						</div>
					</div>

					<div style="font-size:13px;line-height:1.7">
						<?php foreach ( array_slice( (array) $story['posts'], 0, 5 ) as $ld_id ) : ?>
							<?php $ld_p = get_post( (int) $ld_id ); ?>
							<?php if ( $ld_p ) : ?>
								<div><a href="<?php echo esc_url( (string) get_edit_post_link( $ld_p ) ); ?>"><?php echo esc_html( get_the_title( $ld_p ) ); ?></a></div>
							<?php endif; ?>
						<?php endforeach; ?>
						<?php if ( count( (array) $story['posts'] ) > 5 ) : ?>
							<div style="color:var(--tld-ink-3,#999);font-style:italic">
								<?php
								printf(
									/* translators: %d: number of further articles. */
									esc_html__( 'and %d more', 'livingdraft-core' ),
									count( (array) $story['posts'] ) - 5
								);
								?>
							</div>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>

	<?php
	livingdraft_admin_render_footer();
}
