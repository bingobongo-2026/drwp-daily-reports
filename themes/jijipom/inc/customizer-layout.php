<?php
/**
 * カスタマイザー: レイアウト設定
 *
 * 「外観 > カスタマイズ > レイアウト」で、コードを書かずにサイトの
 * 骨格を調整できます。すべて CSS 変数の上書きで実装しているため
 * 追加のスタイルシートは読み込まず、表示速度に影響しません。
 *   - 本文の幅 / 全体の幅
 *   - メインビジュアルの高さ
 *   - トップページのセクション並び順
 *
 * @package jijipom
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ヒーローの高さの選択肢。key => array( ラベル, min-height のCSS値 )。
 * 空文字はテーマ標準 ( clamp(320px, 46vw, 520px) ) のまま。
 */
function jijipom_hero_height_choices() {
	return array(
		''        => array( __( '標準', 'jijipom' ), '' ),
		'compact' => array( __( '低め（コンパクト）', 'jijipom' ), 'clamp(240px, 32vw, 380px)' ),
		'tall'    => array( __( '高め（ワイド）', 'jijipom' ), 'clamp(420px, 60vw, 680px)' ),
		'full'    => array( __( '画面いっぱい', 'jijipom' ), 'calc(100svh - 120px)' ),
	);
}

/**
 * トップページのセクション並び順の選択肢。
 * key はテンプレートパーツ名を「-」で並べたもの。
 */
function jijipom_front_order_choices() {
	return array(
		'service-blog-about' => __( 'サービス → ブログ → 会社紹介（標準）', 'jijipom' ),
		'service-about-blog' => __( 'サービス → 会社紹介 → ブログ', 'jijipom' ),
		'blog-service-about' => __( 'ブログ → サービス → 会社紹介', 'jijipom' ),
		'blog-about-service' => __( 'ブログ → 会社紹介 → サービス', 'jijipom' ),
		'about-service-blog' => __( '会社紹介 → サービス → ブログ', 'jijipom' ),
		'about-blog-service' => __( '会社紹介 → ブログ → サービス', 'jijipom' ),
	);
}

/**
 * 並び順設定を検証済みの配列 ( 'service', 'blog', 'about' の並び ) で返す。
 */
function jijipom_front_section_order() {
	$value   = get_theme_mod( 'jijipom_front_order', 'service-blog-about' );
	$choices = jijipom_front_order_choices();
	if ( ! isset( $choices[ $value ] ) ) {
		$value = 'service-blog-about';
	}
	return explode( '-', $value );
}

/**
 * カスタマイザーに「レイアウト」セクションを登録。
 * フィールド登録は customizer-frontpage.php の jijipom_fp_add() を再利用。
 */
function jijipom_customize_register_layout( $wp_customize ) {
	if ( ! function_exists( 'jijipom_fp_add' ) ) {
		return;
	}

	$wp_customize->add_section(
		'jijipom_layout',
		array(
			'title'       => __( 'レイアウト', 'jijipom' ),
			'description' => __( 'サイト全体の幅やトップページの構成を、コードを書かずに調整できます。', 'jijipom' ),
			'priority'    => 44,
		)
	);

	// 本文の幅 (px)。0 または空はテーマ標準 (720px)。
	$wp_customize->add_setting(
		'jijipom_content_width',
		array( 'default' => 0, 'sanitize_callback' => 'absint', 'transport' => 'refresh' )
	);
	$wp_customize->add_control(
		'jijipom_content_width',
		array(
			'label'       => __( '本文の幅 (px)', 'jijipom' ),
			'description' => __( '記事・固定ページ本文の読みやすい幅。標準は 720。0 で標準のまま。目安: 640〜840。', 'jijipom' ),
			'section'     => 'jijipom_layout',
			'type'        => 'number',
			'input_attrs' => array( 'min' => 0, 'max' => 960, 'step' => 10 ),
		)
	);

	// 全体の幅 (px)。0 または空はテーマ標準 (1080px)。
	$wp_customize->add_setting(
		'jijipom_wide_width',
		array( 'default' => 0, 'sanitize_callback' => 'absint', 'transport' => 'refresh' )
	);
	$wp_customize->add_control(
		'jijipom_wide_width',
		array(
			'label'       => __( '全体の幅 (px)', 'jijipom' ),
			'description' => __( 'ヘッダー・サイドバー含むコンテナの最大幅。標準は 1080。0 で標準のまま。目安: 960〜1280。', 'jijipom' ),
			'section'     => 'jijipom_layout',
			'type'        => 'number',
			'input_attrs' => array( 'min' => 0, 'max' => 1600, 'step' => 20 ),
		)
	);

	// ヒーローの高さ。
	$height_choices = array();
	foreach ( jijipom_hero_height_choices() as $key => $data ) {
		$height_choices[ $key ] = $data[0];
	}
	jijipom_fp_add(
		$wp_customize,
		'jijipom_hero_height',
		array(
			'type'    => 'select',
			'section' => 'jijipom_layout',
			'label'   => __( 'メインビジュアルの高さ', 'jijipom' ),
			'desc'    => __( 'トップページのメインビジュアルの高さを切り替えます。', 'jijipom' ),
			'choices' => $height_choices,
		)
	);

	// トップページのセクション並び順。
	jijipom_fp_add(
		$wp_customize,
		'jijipom_front_order',
		array(
			'type'    => 'select',
			'section' => 'jijipom_layout',
			'label'   => __( 'トップページのセクション並び順', 'jijipom' ),
			'desc'    => __( 'メインビジュアルの下に並ぶ3セクションの順番。非表示のセクションは飛ばされます。', 'jijipom' ),
			'default' => 'service-blog-about',
			'choices' => jijipom_front_order_choices(),
		)
	);
}
add_action( 'customize_register', 'jijipom_customize_register_layout' );

/**
 * レイアウト設定を CSS 変数の上書きとして <head> に出力。
 * 未設定 (標準) のものは出力しない。
 */
function jijipom_layout_inline_css() {
	$root = array();

	$content = absint( get_theme_mod( 'jijipom_content_width', 0 ) );
	if ( $content >= 480 ) {
		$root[] = '--content-width:' . $content . 'px;';
	}

	$wide = absint( get_theme_mod( 'jijipom_wide_width', 0 ) );
	if ( $wide >= 720 ) {
		$root[] = '--wide-width:' . $wide . 'px;';
	}

	$rules = array();
	if ( $root ) {
		$rules[] = ':root{' . implode( '', $root ) . '}';
	}

	$heights = jijipom_hero_height_choices();
	$hkey    = get_theme_mod( 'jijipom_hero_height', '' );
	if ( '' !== $hkey && isset( $heights[ $hkey ] ) && '' !== $heights[ $hkey ][1] ) {
		$rules[] = '.front-hero__inner{min-height:' . $heights[ $hkey ][1] . ';}';
	}

	if ( ! $rules ) {
		return;
	}

	echo "\n<style id=\"jijipom-layout\">" . implode( '', $rules ) . "</style>\n";
}
add_action( 'wp_head', 'jijipom_layout_inline_css', 100 );
