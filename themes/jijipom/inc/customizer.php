<?php
/**
 * カスタマイザー設定
 *
 * 「外観 > カスタマイズ > サイト基本情報」に以下を追加します。
 * - サイトタイトル画像(ロゴ):WordPress標準の「ロゴ」機能をそのまま利用
 * - 文字タイトルの表示 / 非表示(ロゴ画像だけ見せたいとき用)
 * - サイトの説明文(キャッチフレーズ)の表示 / 非表示
 *
 * @package jijipom
 */

/**
 * チェックボックス用のサニタイズ
 */
function jijipom_sanitize_checkbox( $checked ) {
	return ( isset( $checked ) && true === (bool) $checked );
}

/**
 * カスタマイザーに設定を登録
 */
function jijipom_customize_register( $wp_customize ) {

	// --- 文字タイトルの表示切替 -------------------------------------------
	$wp_customize->add_setting(
		'jijipom_show_site_title',
		array(
			'default'           => true,
			'sanitize_callback' => 'jijipom_sanitize_checkbox',
			'transport'         => 'refresh',
		)
	);
	$wp_customize->add_control(
		'jijipom_show_site_title',
		array(
			'label'       => __( 'サイトタイトル(文字)を表示する', 'jijipom' ),
			'description' => __( 'ロゴ画像を設定したとき、文字のタイトルを非表示にできます。ロゴ未設定の場合は常に表示されます。', 'jijipom' ),
			'section'     => 'title_tagline',
			'type'        => 'checkbox',
			'priority'    => 9,
		)
	);

	// --- 説明文(キャッチフレーズ)の表示切替 -----------------------------
	$wp_customize->add_setting(
		'jijipom_show_site_description',
		array(
			'default'           => true,
			'sanitize_callback' => 'jijipom_sanitize_checkbox',
			'transport'         => 'refresh',
		)
	);
	$wp_customize->add_control(
		'jijipom_show_site_description',
		array(
			'label'    => __( 'サイトの説明文(キャッチフレーズ)を表示する', 'jijipom' ),
			'section'  => 'title_tagline',
			'type'     => 'checkbox',
			'priority' => 11,
		)
	);
}
add_action( 'customize_register', 'jijipom_customize_register' );
