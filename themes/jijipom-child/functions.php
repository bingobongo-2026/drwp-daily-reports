<?php
/**
 * jijipom child functions and definitions
 *
 * 子テーマの PHP カスタマイズはこのファイルに追記します。
 * 親テーマの functions.php が置き換わるわけではなく、
 * 「子 → 親」の順で両方が読み込まれます。
 *
 * @package jijipom-child
 */

if ( ! defined( 'JIJIPOM_CHILD_VERSION' ) ) {
	define( 'JIJIPOM_CHILD_VERSION', '1.0.0' );
}

/**
 * 親テーマの style.css を読み込む。
 *
 * 親テーマは自分のスタイルを get_stylesheet_uri() で読み込んでいる。この関数は
 * 「現在有効なテーマ」の style.css を指すため、子テーマを有効にすると子の
 * style.css だけが読まれ、親の style.css(デザイントークン・レイアウト一式)が
 * まったく読み込まれない。そのままではサイトの見た目が崩れるので、ここで親の
 * style.css を明示的に読み込む。
 *
 * 親側の読み込みは優先度10なので、こちらは優先度5にして先に出力させ、
 * 「親CSS → 子CSS」の順(＝子で上書きできる)になるようにしている。
 * 子の style.css は親側の 'jijipom-style' として読まれるため、
 * ここで改めて読み込む必要はない。
 */
function jijipom_child_enqueue_parent_style() {
	wp_enqueue_style(
		'jijipom-parent-style',
		get_template_directory_uri() . '/style.css',
		array(),
		defined( 'JIJIPOM_VERSION' ) ? JIJIPOM_VERSION : JIJIPOM_CHILD_VERSION
	);
}
add_action( 'wp_enqueue_scripts', 'jijipom_child_enqueue_parent_style', 5 );

/* =========================================================
   ここから下にカスタマイズを追記します
   ---------------------------------------------------------
   よく使う例をコメントアウトした状態で置いてあります。
   使うときは、その例を囲んでいるコメント記号を外してください。
   ========================================================= */

/**
 * 例1: 抜粋(excerpt)の文字数を変える
 */
/*
function jijipom_child_excerpt_length( $length ) {
	return 60;
}
add_filter( 'excerpt_length', 'jijipom_child_excerpt_length' );
*/

/**
 * 例2: 独自のCSS/JSファイルを追加で読み込む
 * (assets/css/custom.css, assets/js/custom.js を作った場合)
 */
/*
function jijipom_child_assets() {
	wp_enqueue_style(
		'jijipom-child-custom',
		get_stylesheet_directory_uri() . '/assets/css/custom.css',
		array( 'jijipom-style' ),
		JIJIPOM_CHILD_VERSION
	);
	wp_enqueue_script(
		'jijipom-child-custom',
		get_stylesheet_directory_uri() . '/assets/js/custom.js',
		array(),
		JIJIPOM_CHILD_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'jijipom_child_assets', 20 );
*/

/**
 * 例3: メニューの位置を追加する
 */
/*
function jijipom_child_menus() {
	register_nav_menus(
		array(
			'sub' => __( 'サブメニュー', 'jijipom-child' ),
		)
	);
}
add_action( 'after_setup_theme', 'jijipom_child_menus' );
*/
