<?php
/**
 * 投稿が見つからないときの表示
 *
 * @package jijipom
 */
?>

<section class="no-results not-found">
	<header class="page-header">
		<h1 class="page-title"><?php esc_html_e( '見つかりませんでした', 'jijipom' ); ?></h1>
	</header>

	<div class="page-content entry-content">
		<?php if ( is_search() ) : ?>
			<p><?php esc_html_e( 'キーワードに一致する記事がありませんでした。別のキーワードでお試しください。', 'jijipom' ); ?></p>
			<?php get_search_form(); ?>
		<?php else : ?>
			<p><?php esc_html_e( 'まだ投稿がありません。', 'jijipom' ); ?></p>
			<?php get_search_form(); ?>
		<?php endif; ?>
	</div>
</section>
