<?php
/**
 * カスタマイザー: フォント設定
 *
 * 「外観 > カスタマイズ > フォント設定」で、次の4か所のフォントを
 * それぞれ選べるようにします。
 *   - 全体      … サイト全体の基本フォント (body)
 *   - フォーム   … 入力欄・ボタン等のフォーム部品
 *   - 見出し     … h1〜h6
 *   - 本文      … 記事・固定ページの本文 (.entry-content)
 *
 * フォントは外部読込なしの日本語システムフォントスタックから選びます
 * (軽量・高速・プライバシー面でも安全)。各欄で「標準」を選ぶと上位の
 * 設定を引き継ぎます (見出し/本文/フォーム未指定 → 全体に従う)。
 *
 * @package jijipom
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 選択できるフォント一覧。key => array( ラベル, font-family スタック )。
 * 空文字 '' は「標準 (指定なし)」を表す。
 */
function jijipom_font_choices() {
	return array(
		''         => array( __( '標準 (指定なし)', 'jijipom' ), '' ),
		'gothic'   => array( __( 'ゴシック体', 'jijipom' ), '"Hiragino Kaku Gothic ProN","Hiragino Sans","Noto Sans JP","Yu Gothic",Meiryo,sans-serif' ),
		'mincho'   => array( __( '明朝体', 'jijipom' ), '"Hiragino Mincho ProN","Yu Mincho","YuMincho","MS PMincho",serif' ),
		'yugothic' => array( __( '游ゴシック', 'jijipom' ), '"Yu Gothic","YuGothic","Hiragino Kaku Gothic ProN",sans-serif' ),
		'yumincho' => array( __( '游明朝', 'jijipom' ), '"Yu Mincho","YuMincho","Hiragino Mincho ProN",serif' ),
		'maru'     => array( __( '丸ゴシック', 'jijipom' ), '"Hiragino Maru Gothic ProN","Kosugi Maru","M PLUS Rounded 1c",sans-serif' ),
		'meiryo'   => array( __( 'メイリオ', 'jijipom' ), 'Meiryo,"Hiragino Kaku Gothic ProN","Noto Sans JP",sans-serif' ),
		'system'   => array( __( 'システム標準', 'jijipom' ), 'system-ui,-apple-system,"Segoe UI","Hiragino Sans",sans-serif' ),
		'mono'     => array( __( '等幅 (モノスペース)', 'jijipom' ), 'ui-monospace,SFMono-Regular,Menlo,Consolas,"Noto Sans Mono",monospace' ),
	);
}

/**
 * 各フォント設定のキーとラベル (見出しラベル・並び順)。
 */
function jijipom_font_settings() {
	return array(
		'jijipom_font_base'    => __( '全体のフォント', 'jijipom' ),
		'jijipom_font_form'    => __( 'フォーム部品のフォント (入力欄・ボタン等)', 'jijipom' ),
		'jijipom_font_heading' => __( '見出しのフォント (h1〜h6)', 'jijipom' ),
		'jijipom_font_body'    => __( '本文のフォント (記事・固定ページ)', 'jijipom' ),
	);
}

/**
 * セレクトのサニタイズ — 一覧にあるキーだけ許可。
 */
function jijipom_sanitize_font( $value ) {
	$choices = jijipom_font_choices();
	return array_key_exists( (string) $value, $choices ) ? (string) $value : '';
}

/**
 * カスタマイザーに「フォント設定」セクションと4つのセレクトを登録。
 */
function jijipom_customize_register_fonts( $wp_customize ) {
	$wp_customize->add_section(
		'jijipom_fonts',
		array(
			'title'       => __( 'フォント設定', 'jijipom' ),
			'description' => __( '全体・フォーム・見出し・本文のフォントをそれぞれ選べます。「標準」を選ぶと上位の設定を引き継ぎます。', 'jijipom' ),
			'priority'    => 45,
		)
	);

	$select_choices = array();
	foreach ( jijipom_font_choices() as $key => $data ) {
		$select_choices[ $key ] = $data[0];
	}

	$priority = 10;
	foreach ( jijipom_font_settings() as $setting_id => $label ) {
		$wp_customize->add_setting(
			$setting_id,
			array(
				'default'           => '',
				'sanitize_callback' => 'jijipom_sanitize_font',
				'transport'         => 'refresh',
			)
		);
		$wp_customize->add_control(
			$setting_id,
			array(
				'label'    => $label,
				'section'  => 'jijipom_fonts',
				'type'     => 'select',
				'choices'  => $select_choices,
				'priority' => $priority,
			)
		);
		$priority += 10;
	}
}
add_action( 'customize_register', 'jijipom_customize_register_fonts' );

/**
 * 選択されたフォントを CSS として <head> に出力する。
 * 「標準」の欄は出力せず、テーマ既定 / 上位設定を引き継ぐ。
 */
function jijipom_fonts_inline_css() {
	$choices = jijipom_font_choices();

	$stack = function ( $setting_id ) use ( $choices ) {
		$key = get_theme_mod( $setting_id, '' );
		if ( '' === $key || ! isset( $choices[ $key ] ) ) {
			return '';
		}
		return $choices[ $key ][1];
	};

	$base    = $stack( 'jijipom_font_base' );
	$form    = $stack( 'jijipom_font_form' );
	$heading = $stack( 'jijipom_font_heading' );
	$body    = $stack( 'jijipom_font_body' );

	$rules = array();

	// 全体 + 見出しは CSS 変数を上書き (見出しは既定で --font-body を継承)。
	$root = array();
	if ( '' !== $base ) {
		$root[] = '--font-body:' . $base . ';';
	}
	if ( '' !== $heading ) {
		$root[] = '--font-heading:' . $heading . ';';
	}
	if ( $root ) {
		$rules[] = ':root{' . implode( '', $root ) . '}';
	}

	// 本文 — 記事本文コンテナに直接指定。
	if ( '' !== $body ) {
		$rules[] = '.entry-content{font-family:' . $body . ';}';
	}

	// フォーム部品。
	if ( '' !== $form ) {
		$rules[] = 'input,textarea,select,button,.search-field,.wp-block-button__link{font-family:' . $form . ';}';
	}

	if ( ! $rules ) {
		return;
	}

	echo "\n<style id=\"jijipom-fonts\">" . implode( '', $rules ) . "</style>\n";
}
add_action( 'wp_head', 'jijipom_fonts_inline_css', 100 );
