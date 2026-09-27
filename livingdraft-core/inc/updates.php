<?php
/**
 * The update log.
 *
 * A living story is not just a post with a newer timestamp: it is a post whose
 * changes are on the record. Each entry is a time, a kind and a note.
 *
 * This lives in the plugin, not the theme, so the record survives a theme
 * change. Every function is guarded: if an old copy of the theme still defines
 * its own, the site keeps running and the plugin's version wins.
 *
 * @package LivingDraftCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The kinds of change a story can record.
 *
 * @return array
 */
if ( ! function_exists( 'livingdraft_update_kinds' ) ) :
		function livingdraft_update_kinds() {
		return apply_filters(
			'livingdraft_update_kinds',
			array(
				'update'        => __( 'Update', 'livingdraft-core' ),
				'correction'    => __( 'Correction', 'livingdraft-core' ),
				'clarification' => __( 'Clarification', 'livingdraft-core' ),
			)
		);
	}
endif;

/**
 * The labels a story can carry. This says what KIND of writing a piece is —
 * not what it is about. That is what categories are for.
 *
 * @return array
 */
if ( ! function_exists( 'livingdraft_label_choices' ) ) :
	function livingdraft_label_choices() {
		return apply_filters(
			'livingdraft_label_choices',
			array(
				'opinion'   => __( 'Opinion', 'livingdraft-core' ),
				'analysis'  => __( 'Analysis', 'livingdraft-core' ),
				'interview' => __( 'Interview', 'livingdraft-core' ),
				'review'    => __( 'Review', 'livingdraft-core' ),
				'explainer' => __( 'Explainer', 'livingdraft-core' ),
				'sponsored' => __( 'Sponsored', 'livingdraft-core' ),
			)
		);
	}
endif;

/**
 * Every logged change on a story, oldest first.
 *
 * @param int|null $post_id Post ID.
 * @return array List of {kind, time, note}.
 */
if ( ! function_exists( 'livingdraft_get_updates' ) ) :
		function livingdraft_get_updates( $post_id = null ) {
		$post_id = $post_id ? $post_id : get_the_ID();
		$rows    = get_post_meta( $post_id, '_livingdraft_updates', true );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$kinds = livingdraft_update_kinds();
		$clean = array();

		foreach ( $rows as $row ) {
			if ( empty( $row['note'] ) || empty( $row['time'] ) ) {
				continue;
			}

			$kind = isset( $row['kind'] ) && isset( $kinds[ $row['kind'] ] ) ? $row['kind'] : 'update';

			$clean[] = array(
				'kind' => $kind,
				'time' => $row['time'],
				'note' => $row['note'],
			);
		}

		usort(
			$clean,
			function ( $a, $b ) {
				return strcmp( $a['time'], $b['time'] );
			}
		);

		return $clean;
	}
endif;

/**
 * The time a story was genuinely last changed: the newest logged entry, or
 * the post's own modified time when nothing has been logged.
 *
 * @param int|null $post_id Post ID.
 * @return string MySQL datetime in GMT.
 */
if ( ! function_exists( 'livingdraft_last_changed_gmt' ) ) :
		function livingdraft_last_changed_gmt( $post_id = null ) {
		$post_id = $post_id ? $post_id : get_the_ID();
		$updates = livingdraft_get_updates( $post_id );

		$modified = (string) get_post_modified_time( 'Y-m-d H:i:s', true, $post_id );

		if ( empty( $updates ) ) {
			return $modified;
		}

		$latest = end( $updates );
		$logged = get_gmt_from_date( $latest['time'] );

		return ( strtotime( $logged ) > strtotime( $modified ) ) ? $logged : $modified;
	}
endif;

/* ------------------------------------------------------------------
 * The editor screen
 * ------------------------------------------------------------------ */

/**
 * Register the box on posts.
 */
if ( ! function_exists( 'livingdraft_updates_meta_box' ) ) :
		function livingdraft_updates_meta_box() {
		add_meta_box(
			'livingdraft-updates',
			__( 'Byline and update log', 'livingdraft-core' ),
			'livingdraft_updates_meta_box_render',
			'post',
			'normal',
			'default',
			array( '__block_editor_compatible_meta_box' => true )
		);
	}
endif;
add_action( 'add_meta_boxes', 'livingdraft_updates_meta_box' );

/**
 * Render the box. No admin JavaScript: two blank rows are always offered, and
 * saving with a blank note removes that row.
 *
 * @param WP_Post $post Post being edited.
 */
if ( ! function_exists( 'livingdraft_updates_meta_box_render' ) ) :
		function livingdraft_updates_meta_box_render( $post ) {
		wp_nonce_field( 'livingdraft_save_updates', 'livingdraft_updates_nonce' );

		$updates   = livingdraft_get_updates( $post->ID );
		$kinds     = livingdraft_update_kinds();
		$labels    = livingdraft_label_choices();
		$label     = (string) get_post_meta( $post->ID, '_livingdraft_label', true );
		$coauthor  = (string) get_post_meta( $post->ID, '_livingdraft_coauthor', true );
		$row_count = count( $updates ) + 2;
		?>
		<p>
			<label for="livingdraft_coauthor"><strong><?php esc_html_e( 'Second byline name', 'livingdraft-core' ); ?></strong></label><br>
			<input type="text" class="widefat" id="livingdraft_coauthor" name="livingdraft_coauthor"
				value="<?php echo esc_attr( $coauthor ); ?>"
				placeholder="<?php esc_attr_e( 'Leave blank for a single byline', 'livingdraft-core' ); ?>">
		</p>

		<p>
			<label for="livingdraft_label"><strong><?php esc_html_e( 'Editorial label', 'livingdraft-core' ); ?></strong></label><br>
			<select id="livingdraft_label" name="livingdraft_label">
				<option value=""><?php esc_html_e( '— None (straight news) —', 'livingdraft-core' ); ?></option>
				<?php foreach ( $labels as $key => $name ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $label, $key ); ?>>
						<?php echo esc_html( $name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<span class="description"><?php esc_html_e( 'Says what kind of writing this is. Sponsored content must be labelled.', 'livingdraft-core' ); ?></span>
		</p>

		<hr>

		<p><strong><?php esc_html_e( 'Update log', 'livingdraft-core' ); ?></strong><br>
			<span class="description"><?php esc_html_e( 'What changed after publication, and when. Printed under the byline, oldest first. Clear the note to delete a row.', 'livingdraft-core' ); ?></span>
		</p>

		<table class="widefat striped">
			<thead>
				<tr>
					<th style="width:150px"><?php esc_html_e( 'Kind', 'livingdraft-core' ); ?></th>
					<th style="width:220px"><?php esc_html_e( 'When', 'livingdraft-core' ); ?></th>
					<th><?php esc_html_e( 'What changed', 'livingdraft-core' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php for ( $i = 0; $i < $row_count; $i++ ) : ?>
					<?php
					$row  = isset( $updates[ $i ] ) ? $updates[ $i ] : array(
						'kind' => 'update',
						'time' => '',
						'note' => '',
					);
					$time = $row['time'] ? mysql2date( 'Y-m-d\TH:i', $row['time'] ) : '';
					?>
					<tr>
						<td>
							<label class="screen-reader-text" for="livingdraft_updates_kind_<?php echo esc_attr( $i ); ?>">
								<?php esc_html_e( 'Kind of change', 'livingdraft-core' ); ?>
							</label>
							<select id="livingdraft_updates_kind_<?php echo esc_attr( $i ); ?>" name="livingdraft_updates[<?php echo esc_attr( $i ); ?>][kind]">
								<?php foreach ( $kinds as $key => $name ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $row['kind'], $key ); ?>>
										<?php echo esc_html( $name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
						<td>
							<label class="screen-reader-text" for="livingdraft_updates_time_<?php echo esc_attr( $i ); ?>">
								<?php esc_html_e( 'Time of change', 'livingdraft-core' ); ?>
							</label>
							<input type="datetime-local" id="livingdraft_updates_time_<?php echo esc_attr( $i ); ?>"
								name="livingdraft_updates[<?php echo esc_attr( $i ); ?>][time]"
								value="<?php echo esc_attr( $time ); ?>">
						</td>
						<td>
							<label class="screen-reader-text" for="livingdraft_updates_note_<?php echo esc_attr( $i ); ?>">
								<?php esc_html_e( 'What changed', 'livingdraft-core' ); ?>
							</label>
							<input type="text" class="widefat" id="livingdraft_updates_note_<?php echo esc_attr( $i ); ?>"
								name="livingdraft_updates[<?php echo esc_attr( $i ); ?>][note]"
								value="<?php echo esc_attr( $row['note'] ); ?>"
								placeholder="<?php esc_attr_e( 'Death toll revised to 62 after the state bulletin.', 'livingdraft-core' ); ?>">
						</td>
					</tr>
				<?php endfor; ?>
			</tbody>
		</table>
		<?php
	}
endif;

/**
 * Save the box.
 *
 * @param int $post_id Post ID.
 */
if ( ! function_exists( 'livingdraft_save_updates' ) ) :
		function livingdraft_save_updates( $post_id ) {
		if ( ! isset( $_POST['livingdraft_updates_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['livingdraft_updates_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'livingdraft_save_updates' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Second byline name.
		$coauthor = isset( $_POST['livingdraft_coauthor'] )
			? sanitize_text_field( wp_unslash( $_POST['livingdraft_coauthor'] ) )
			: '';

		if ( '' === $coauthor ) {
			delete_post_meta( $post_id, '_livingdraft_coauthor' );
		} else {
			update_post_meta( $post_id, '_livingdraft_coauthor', $coauthor );
		}

		// Editorial label.
		$label   = isset( $_POST['livingdraft_label'] )
			? sanitize_key( wp_unslash( $_POST['livingdraft_label'] ) )
			: '';
		$choices = livingdraft_label_choices();

		if ( '' === $label || ! isset( $choices[ $label ] ) ) {
			delete_post_meta( $post_id, '_livingdraft_label' );
		} else {
			update_post_meta( $post_id, '_livingdraft_label', $label );
		}

		// The log itself.
		$rows  = isset( $_POST['livingdraft_updates'] ) ? (array) wp_unslash( $_POST['livingdraft_updates'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$kinds = livingdraft_update_kinds();
		$clean = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$note = isset( $row['note'] ) ? sanitize_text_field( $row['note'] ) : '';
			if ( '' === trim( $note ) ) {
				continue;
			}

			$kind = isset( $row['kind'] ) ? sanitize_key( $row['kind'] ) : 'update';
			if ( ! isset( $kinds[ $kind ] ) ) {
				$kind = 'update';
			}

			$raw   = isset( $row['time'] ) ? sanitize_text_field( $row['time'] ) : '';
			$stamp = $raw ? strtotime( $raw ) : false;
			$time  = $stamp ? gmdate( 'Y-m-d H:i:s', $stamp ) : current_time( 'mysql' );

			$clean[] = array(
				'kind' => $kind,
				'time' => $time,
				'note' => $note,
			);
		}

		if ( empty( $clean ) ) {
			delete_post_meta( $post_id, '_livingdraft_updates' );
		} else {
			update_post_meta( $post_id, '_livingdraft_updates', $clean );
		}
	}
endif;
add_action( 'save_post_post', 'livingdraft_save_updates' );

/**
 * Keep the log out of the REST API's reach as an arbitrary meta field, but
 * make it readable for anyone building against the site.
 */
if ( ! function_exists( 'livingdraft_register_meta' ) ) :
		function livingdraft_register_meta() {
		register_post_meta(
			'post',
			'_livingdraft_coauthor',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);

		register_post_meta(
			'post',
			'_livingdraft_label',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_key',
				'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);
	}
endif;
add_action( 'init', 'livingdraft_register_meta' );
