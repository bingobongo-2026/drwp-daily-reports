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

	// セクションの並び順は「外観 > カスタマイズ > レイアウト」で変更できる。
	// 非表示に設定されたセクションは飛ばす。
	$jijipom_order = function_exists( 'jijipom_front_section_order' )
		? jijipom_front_section_order()
		: array( 'service', 'blog', 'about' );

	foreach ( $jijipom_order as $jijipom_section ) {
		if ( get_theme_mod( "jijipom_{$jijipom_section}_enable", true ) ) {
			get_template_part( 'template-parts/front-page/' . $jijipom_section );
		}
	}

	// 固定ページに書いた本文があれば、各セクションの下に表示。
	get_template_part( 'template-parts/front-page/page-content' );
	?>
</div><!-- .front-page -->

<?php
get_footer();
