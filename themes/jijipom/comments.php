<?php
/**
 * コメントテンプレート
 *
 * @package jijipom
 */

if ( post_password_required() ) {
	return;
}
?>

<div id="comments" class="comments-area">
	<?php if ( have_comments() ) : ?>
		<h2 class="comments-title">
			<?php
			$jijipom_comment_count = get_comments_number();
			if ( '1' === $jijipom_comment_count ) {
				esc_html_e( '1件のコメント', 'jijipom' );
			} else {
				/* translators: %s: コメント数 */
				printf( esc_html__( '%s件のコメント', 'jijipom' ), esc_html( number_format_i18n( $jijipom_comment_count ) ) );
			}
			?>
		</h2>

		<ol class="comment-list">
			<?php
			wp_list_comments(
				array(
					'style'      => 'ol',
					'short_ping' => true,
					'avatar_size' => 48,
				)
			);
			?>
		</ol>

		<?php
		the_comments_pagination(
			array(
				'prev_text' => esc_html__( '前のコメント', 'jijipom' ),
				'next_text' => esc_html__( '次のコメント', 'jijipom' ),
			)
		);
		?>

	<?php endif; ?>

	<?php if ( ! comments_open() && get_comments_number() && post_type_supports( get_post_type(), 'comments' ) ) : ?>
		<p class="no-comments"><?php esc_html_e( 'コメントは受け付けていません。', 'jijipom' ); ?></p>
	<?php endif; ?>

	<?php comment_form(); ?>
</div><!-- #comments -->
