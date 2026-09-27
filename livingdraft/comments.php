<?php
/**
 * Comments.
 *
 * @package LivingDraft
 */

if ( post_password_required() ) {
	return;
}
?>
<div id="comments" class="comments-area">

	<?php if ( have_comments() ) : ?>
		<h2 class="comments-title">
			<?php
			printf(
				/* translators: %s: comment count. */
				esc_html( _n( '%s response', '%s responses', (int) get_comments_number(), 'livingdraft' ) ),
				esc_html( number_format_i18n( (int) get_comments_number() ) )
			);
			?>
		</h2>

		<ol class="comment-list">
			<?php
			wp_list_comments(
				array(
					'callback'   => 'livingdraft_comment',
					'style'      => 'ol',
					'short_ping' => true,
				)
			);
			?>
		</ol>

		<?php
		the_comments_pagination(
			array(
				'prev_text' => __( 'Previous', 'livingdraft' ),
				'next_text' => __( 'Next', 'livingdraft' ),
			)
		);
		?>
	<?php endif; ?>

	<?php if ( ! comments_open() && get_comments_number() ) : ?>
		<p><?php esc_html_e( 'Responses are closed on this story.', 'livingdraft' ); ?></p>
	<?php endif; ?>

	<?php
	comment_form(
		array(
			'title_reply'          => __( 'Add a response', 'livingdraft' ),
			'label_submit'         => __( 'Post response', 'livingdraft' ),
			'comment_notes_before' => '<p class="comment-meta">' . esc_html__( 'Responses are read before they appear. Corrections are welcome.', 'livingdraft' ) . '</p>',
			'comment_field'        => '<p><label for="comment">' . esc_html__( 'Response', 'livingdraft' ) . '</label>'
				. '<textarea id="comment" name="comment" rows="6" required></textarea></p>',
		)
	);
	?>
</div>
