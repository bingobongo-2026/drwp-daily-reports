<?php
/**
 * トップページテンプレート
 *
 * 「設定 > 表示設定 > ホームページの表示」で固定ページを指定すると、
 * このテンプレートが自動的に使われます。
 * 各セクションの内容は「外観 > カスタマイズ > トップページ」で設定できます。
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
