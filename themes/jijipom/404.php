<?php
/**
 * 404 (ページが見つかりません) テンプレート
 *
 * @package jijipom
 */

get_header();
?>

<div class="content-area">
	<section class="error-404 not-found">
		<header class="page-header">
			<h1 class="page-title"><?php esc_html_e( 'ページが見つかりませんでした', 'jijipom' ); ?></h1>
		</header>

		<div class="page-content entry-content">
			<p><?php esc_html_e( 'お探しのページは移動または削除された可能性があります。検索をお試しください。', 'jijipom' ); ?></p>

			<?php get_search_form(); ?>

			<p style="margin-top:2rem;">
				<a class="button" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'ホームに戻る', 'jijipom' ); ?></a>
			</p>
		</div>
	</section>
</div><!-- .content-area -->

<?php
get_footer();
