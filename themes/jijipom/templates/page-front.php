<?php
/**
 * Template Name: トップページ
 * Template Post Type: page
 *
 * front-page.php と同じ「トップページ」レイアウト(ヒーロー / サービス /
 * ブログ / 会社紹介)を、任意の固定ページに割り当てられる選択式テンプレート。
 * 「設定 > 表示設定」でホームページに固定ページを指定した場合は front-page.php
 * が自動で使われますが、それ以外のページでトップのレイアウトを使いたいとき
 * (取り込みで作成したホームページ等)はこのテンプレートを選びます。
 *
 * 各セクションの内容は「外観 > カスタマイズ > トップページ」で設定します。
 * このテンプレートを選ぶと body に jijipom-front クラスが付き(inc/
 * template-functions.php)、front-page.php と同じ全幅表示になります。
 *
 * @package jijipom
 */

get_header();
?>

<div class="front-page">
	<?php
	get_template_part( 'template-parts/front-page/hero' );

	if ( get_theme_mod( 'jijipom_service_enable', true ) ) {
		get_template_part( 'template-parts/front-page/service' );
	}

	if ( get_theme_mod( 'jijipom_blog_enable', true ) ) {
		get_template_part( 'template-parts/front-page/blog' );
	}

	if ( get_theme_mod( 'jijipom_about_enable', true ) ) {
		get_template_part( 'template-parts/front-page/about' );
	}
	?>
</div><!-- .front-page -->

<?php
get_footer();
