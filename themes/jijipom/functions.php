<?php
/**
 * jijipom functions and definitions
 *
 * @package jijipom
 */

if ( ! defined( 'JIJIPOM_VERSION' ) ) {
	define( 'JIJIPOM_VERSION', '1.6.0' );
}

/**
 * テーマの基本セットアップ
 */
function jijipom_setup() {
	// 翻訳ファイルの読み込み
	load_theme_textdomain( 'jijipom', get_template_directory() . '/languages' );

	// <title> タグを WordPress に任せる(SEOの基本)
	add_theme_support( 'title-tag' );

	// フィードリンクの自動出力
	add_theme_support( 'automatic-feed-links' );

	// アイキャッチ画像
	add_theme_support( 'post-thumbnails' );
	set_post_thumbnail_size( 1200, 675, true );

	// HTML5 マークアップ
	add_theme_support(
		'html5',
		array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' )
	);

	// カスタムロゴ
	add_theme_support(
		'custom-logo',
		array(
			'height'      => 60,
			'width'       => 240,
			'flex-height' => true,
			'flex-width'  => true,
		)
	);

	// ブロックエディタ関連
	add_theme_support( 'align-wide' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'editor-styles' );
	add_theme_support( 'wp-block-styles' );
	add_editor_style( 'assets/css/editor-style.css' );

	// メニュー登録
	register_nav_menus(
		array(
			'primary' => __( 'メインメニュー', 'jijipom' ),
			'footer'  => __( 'フッターメニュー', 'jijipom' ),
		)
	);
}
add_action( 'after_setup_theme', 'jijipom_setup' );

/**
 * コンテンツ幅(埋め込みメディアの最大幅など)
 */
function jijipom_content_width() {
	$GLOBALS['content_width'] = apply_filters( 'jijipom_content_width', 720 );
}
add_action( 'after_setup_theme', 'jijipom_content_width', 0 );

/**
 * スタイル・スクリプトの読み込み
 */
function jijipom_scripts() {
	wp_enqueue_style( 'jijipom-style', get_stylesheet_uri(), array(), JIJIPOM_VERSION );

	wp_enqueue_script(
		'jijipom-navigation',
		get_template_directory_uri() . '/assets/js/navigation.js',
		array(),
		JIJIPOM_VERSION,
		true
	);

	// トップページのメインビジュアル用スライドショー
	if ( is_front_page() ) {
		wp_enqueue_script(
			'jijipom-hero-slider',
			get_template_directory_uri() . '/assets/js/hero-slider.js',
			array(),
			JIJIPOM_VERSION,
			true
		);
	}

	if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
		wp_enqueue_script( 'comment-reply' );
	}
}
add_action( 'wp_enqueue_scripts', 'jijipom_scripts' );

/**
 * ウィジェットエリア(サイドバー / フッター)の登録
 */
function jijipom_widgets_init() {
	register_sidebar(
		array(
			'name'          => __( 'サイドバー', 'jijipom' ),
			'id'            => 'sidebar-1',
			'description'   => __( '記事・固定ページの横に表示されるウィジェットエリアです。', 'jijipom' ),
			'before_widget' => '<section id="%1$s" class="widget %2$s">',
			'after_widget'  => '</section>',
			'before_title'  => '<h2 class="widget-title">',
			'after_title'   => '</h2>',
		)
	);

	register_sidebar(
		array(
			'name'          => __( 'フッター', 'jijipom' ),
			'id'            => 'footer-1',
			'description'   => __( 'フッターに表示されるウィジェットエリアです。', 'jijipom' ),
			'before_widget' => '<section id="%1$s" class="widget %2$s">',
			'after_widget'  => '</section>',
			'before_title'  => '<h2 class="widget-title">',
			'after_title'   => '</h2>',
		)
	);
}
add_action( 'widgets_init', 'jijipom_widgets_init' );

/**
 * 抜粋の「続きを読む」を調整
 */
function jijipom_excerpt_more( $more ) {
	return '…';
}
add_filter( 'excerpt_more', 'jijipom_excerpt_more' );

function jijipom_excerpt_length( $length ) {
	return 60;
}
add_filter( 'excerpt_length', 'jijipom_excerpt_length' );

/**
 * パフォーマンス / クリーンアップ
 * 不要なメタ情報を削って軽量化(SEO・表示速度に寄与)
 */
remove_action( 'wp_head', 'wp_generator' );                 // WPバージョンを隠す
remove_action( 'wp_head', 'wlwmanifest_link' );             // Windows Live Writer
remove_action( 'wp_head', 'rsd_link' );                     // Really Simple Discovery
add_filter( 'the_generator', '__return_empty_string' );

/**
 * 絵文字用の余計なスクリプト/スタイルを除去(不要なリクエスト削減)
 */
function jijipom_disable_emojis() {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
}
add_action( 'init', 'jijipom_disable_emojis' );

/**
 * 各種機能ファイルの読み込み
 */
require get_template_directory() . '/inc/template-functions.php';
require get_template_directory() . '/inc/breadcrumbs.php';
require get_template_directory() . '/inc/seo.php';
require get_template_directory() . '/inc/customizer.php';
require get_template_directory() . '/inc/customizer-frontpage.php';
require get_template_directory() . '/inc/customizer-fonts.php';
require get_template_directory() . '/inc/customizer-colors.php';
require get_template_directory() . '/inc/customizer-social.php';

// 自動アップデート (日報マンのライセンスサーバから配信)
require get_template_directory() . '/inc/theme-updater.php';
