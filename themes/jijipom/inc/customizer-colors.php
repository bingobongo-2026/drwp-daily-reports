<?php
/**
 * カスタマイザー: 配色
 *
 * 「外観 > カスタマイズ > 配色」で次の2つを設定できます。
 *   - メインカラー … ボタン・リンク・アンダーラインなどテーマ全体の
 *     アクセント色 (CSS 変数 --color-accent)。ホバー時の濃い色は自動計算。
 *   - リンクのホバー色 … リンク類にマウスをのせたときの色だけを個別に
 *     変えたい場合。おすすめ 4 色または自由設定。
 *
 * @package jijipom
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * おすすめのホバー色。key => array( ラベル, HEX )。
 */
function jijipom_hover_presets() {
	return array(
		'blue'   => array( __( 'ブルー', 'jijipom' ), '#1e46cc' ),
		'green'  => array( __( 'グリーン', 'jijipom' ), '#059669' ),
		'red'    => array( __( 'レッド', 'jijipom' ), '#dc2626' ),
		'purple' => array( __( 'パープル', 'jijipom' ), '#7c3aed' ),
	);
}

/**
 * ホバー色プリセット選択の候補（標準 + おすすめ4色 + 自由設定）。
 */
function jijipom_hover_choices() {
	$choices = array( '' => __( '標準（変更しない）', 'jijipom' ) );
	foreach ( jijipom_hover_presets() as $key => $data ) {
		$choices[ $key ] = $data[0];
	}
	$choices['custom'] = __( '自由に設定（下のパレット）', 'jijipom' );
	return $choices;
}

/**
 * プリセット選択のサニタイズ。
 */
function jijipom_sanitize_hover_preset( $value ) {
	$choices = jijipom_hover_choices();
	return array_key_exists( (string) $value, $choices ) ? (string) $value : '';
}

/**
 * HEX カラーを指定割合だけ暗くする (#rrggbb を返す)。失敗時は空文字。
 */
function jijipom_darken_hex( $hex, $ratio = 0.2 ) {
	$hex = ltrim( (string) $hex, '#' );
	if ( 3 === strlen( $hex ) ) {
		$hex = preg_replace( '/(.)/', '$1$1', $hex );
	}
	if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
		return '';
	}
	$out = '#';
	foreach ( str_split( $hex, 2 ) as $pair ) {
		$v    = (int) round( hexdec( $pair ) * ( 1 - $ratio ) );
		$out .= str_pad( dechex( max( 0, min( 255, $v ) ) ), 2, '0', STR_PAD_LEFT );
	}
	return $out;
}

/**
 * カスタマイザーに「配色」セクションを登録。
 */
function jijipom_customize_register_colors( $wp_customize ) {
	$wp_customize->add_section(
		'jijipom_colors',
		array(
			'title'       => __( '配色', 'jijipom' ),
			'description' => __( 'メインカラー(ボタン・リンクなどのアクセント色)と、リンクのホバー色を変更できます。', 'jijipom' ),
			'priority'    => 46,
		)
	);

	// メインカラー (テーマ全体のアクセント色)。
	$wp_customize->add_setting(
		'jijipom_accent_color',
		array(
			'default'           => '',
			'sanitize_callback' => 'sanitize_hex_color',
			'transport'         => 'refresh',
		)
	);
	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'jijipom_accent_color',
			array(
				'label'       => __( 'メインカラー', 'jijipom' ),
				'description' => __( 'ボタン・リンク・メニューのアンダーラインなど、テーマ全体のアクセント色。未設定ならテーマ標準(ブルー)。ホバー時の濃い色は自動で作られます。', 'jijipom' ),
				'section'     => 'jijipom_colors',
				'priority'    => 5,
			)
		)
	);

	$select_choices = jijipom_hover_choices();

	$wp_customize->add_setting(
		'jijipom_link_hover_preset',
		array(
			'default'           => '',
			'sanitize_callback' => 'jijipom_sanitize_hover_preset',
			'transport'         => 'refresh',
		)
	);
	$wp_customize->add_control(
		'jijipom_link_hover_preset',
		array(
			'label'    => __( 'リンクのホバー色', 'jijipom' ),
			'section'  => 'jijipom_colors',
			'type'     => 'select',
			'choices'  => $select_choices,
			'priority' => 10,
		)
	);

	$wp_customize->add_setting(
		'jijipom_link_hover_custom',
		array(
			'default'           => '',
			'sanitize_callback' => 'sanitize_hex_color',
			'transport'         => 'refresh',
		)
	);
	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'jijipom_link_hover_custom',
			array(
				'label'       => __( '自由に色を設定', 'jijipom' ),
				'description' => __( '上で「自由に設定」を選んだときに使われます。', 'jijipom' ),
				'section'     => 'jijipom_colors',
				'priority'    => 20,
			)
		)
	);
}
add_action( 'customize_register', 'jijipom_customize_register_colors' );

/**
 * 選択されたホバー色を解決する（無効なら空文字）。
 */
function jijipom_resolve_hover_color() {
	$preset = get_theme_mod( 'jijipom_link_hover_preset', '' );
	if ( 'custom' === $preset ) {
		return sanitize_hex_color( get_theme_mod( 'jijipom_link_hover_custom', '' ) );
	}
	$presets = jijipom_hover_presets();
	if ( isset( $presets[ $preset ] ) ) {
		return $presets[ $preset ][1];
	}
	return '';
}

/**
 * 配色を CSS として <head> に出力する。
 *  - メインカラー: CSS 変数 --color-accent / --color-accent-hover を上書き
 *    (ホバー色は 20% 暗くした色を自動計算)。
 *  - ホバー色: リンク系のホバー状態をまとめて上書き。
 * どちらも未設定なら何も出力せずテーマ標準のまま。
 */
function jijipom_colors_inline_css() {
	$css = '';

	$accent = sanitize_hex_color( get_theme_mod( 'jijipom_accent_color', '' ) );
	if ( $accent ) {
		$hover_auto = jijipom_darken_hex( $accent, 0.2 );
		$css       .= ':root{--color-accent:' . esc_attr( $accent ) . ';'
			. '--color-accent-hover:' . esc_attr( $hover_auto ? $hover_auto : $accent ) . ';}';
	}

	$c = jijipom_resolve_hover_color();
	if ( $c ) {
		$c    = esc_attr( $c );
		$css .= 'a:hover,';
		$css .= '.entry-title a:hover,';
		$css .= '.entry-meta a:hover,';
		$css .= '.breadcrumbs a:hover,';
		$css .= '.footer-navigation a:hover,';
		$css .= '.blog-card__title a:hover{color:' . $c . ';}';
		$css .= '.main-navigation a:hover{border-bottom-color:' . $c . ';}';
		$css .= '.pagination a.page-numbers:hover{border-color:' . $c . ';color:' . $c . ';}';
	}

	if ( '' === $css ) {
		return;
	}

	echo "\n<style id=\"jijipom-colors\">" . $css . "</style>\n";
}
add_action( 'wp_head', 'jijipom_colors_inline_css', 100 );
