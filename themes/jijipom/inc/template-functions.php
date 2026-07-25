<?php
/**
 * テンプレート用ヘルパー関数
 *
 * @package jijipom
 */

/**
 * 投稿日を <time> 要素で出力(公開日と更新日)
 */
function jijipom_posted_on() {
	$time_string = '<time class="entry-date published" datetime="%1$s">%2$s</time>';

	if ( get_the_time( 'U' ) !== get_the_modified_time( 'U' ) ) {
		$time_string .= '<time class="updated" datetime="%3$s">%4$s</time>';
	}

	$time_string = sprintf(
		$time_string,
		esc_attr( get_the_date( DATE_W3C ) ),
		esc_html( get_the_date() ),
		esc_attr( get_the_modified_date( DATE_W3C ) ),
		esc_html( get_the_modified_date() )
	);

	printf(
		'<span class="posted-on">%1$s %2$s</span>',
		'<span class="screen-reader-text">' . esc_html__( '投稿日', 'jijipom' ) . '</span>',
		$time_string // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	);
}

/**
 * 投稿者を出力
 */
function jijipom_posted_by() {
	printf(
		'<span class="byline"><span class="screen-reader-text">%1$s </span><a href="%2$s">%3$s</a></span>',
		esc_html__( '投稿者', 'jijipom' ),
		esc_url( get_author_posts_url( get_the_author_meta( 'ID' ) ) ),
		esc_html( get_the_author() )
	);
}

/**
 * カテゴリー・タグを出力
 */
function jijipom_entry_taxonomies() {
	if ( 'post' !== get_post_type() ) {
		return;
	}

	$categories = get_the_category_list( ', ' );
	if ( $categories ) {
		printf(
			'<span class="cat-links"><span class="screen-reader-text">%1$s </span>%2$s</span>',
			esc_html__( 'カテゴリー', 'jijipom' ),
			$categories // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	$tags = get_the_tag_list( '', ', ' );
	if ( $tags ) {
		printf(
			'<span class="tags-links"><span class="screen-reader-text">%1$s </span>%2$s</span>',
			esc_html__( 'タグ', 'jijipom' ),
			$tags // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}
}

/**
 * body_class にサイドバー有無のクラスを追加
 */
function jijipom_body_classes( $classes ) {
	if ( is_active_sidebar( 'sidebar-1' ) && ! is_page_template( 'templates/full-width.php' ) && ! is_404() ) {
		$classes[] = 'has-sidebar';
	} else {
		$classes[] = 'no-sidebar';
	}

	if ( ! is_singular() ) {
		$classes[] = 'is-list-view';
	}

	if ( is_front_page() ) {
		$classes[] = 'jijipom-front';
	}

	return $classes;
}
add_filter( 'body_class', 'jijipom_body_classes' );

/**
 * ページネーション表示
 */
function jijipom_pagination() {
	the_posts_pagination(
		array(
			'mid_size'           => 1,
			'prev_text'          => __( '前へ', 'jijipom' ),
			'next_text'          => __( '次へ', 'jijipom' ),
			'screen_reader_text' => __( 'ページ送りナビゲーション', 'jijipom' ),
			'class'              => 'pagination',
		)
	);
}

/**
 * ヘッダーボタン用アイコンの選択肢。
 *
 * カスタマイザーのセレクトと、コンテンツ取込のバリデーションで共用する。
 * キーは jijipom_button_icon_svg() のスラッグと一致させること。
 *
 * @return array スラッグ => 表示名
 */
function jijipom_button_icon_choices() {
	return array(
		''         => __( 'なし', 'jijipom' ),
		'mail'     => __( 'メール', 'jijipom' ),
		'phone'    => __( '電話', 'jijipom' ),
		'chat'     => __( '吹き出し(相談)', 'jijipom' ),
		'calendar' => __( 'カレンダー(予約)', 'jijipom' ),
		'document' => __( '書類(資料請求)', 'jijipom' ),
		'download' => __( 'ダウンロード', 'jijipom' ),
		'pin'      => __( '地図ピン', 'jijipom' ),
		'person'   => __( '人(担当者)', 'jijipom' ),
		'arrow'    => __( '右矢印', 'jijipom' ),
		'external' => __( '外部リンク', 'jijipom' ),
	);
}

/**
 * ヘッダーボタン用アイコンのインラインSVGを返す(外部読込なし・currentColor)。
 *
 * @param string $slug アイコンのスラッグ
 * @return string SVG文字列。未指定・未知のスラッグなら空文字。
 */
function jijipom_button_icon_svg( $slug ) {
	$paths = array(
		'mail'     => 'M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z',
		'phone'    => 'M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z',
		'chat'     => 'M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z',
		'calendar' => 'M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11z',
		'document' => 'M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z',
		'download' => 'M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z',
		'pin'      => 'M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 010-5 2.5 2.5 0 010 5z',
		'person'   => 'M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z',
		'arrow'    => 'M12 4l-1.41 1.41L16.17 11H4v2h12.17l-5.58 5.59L12 20l8-8z',
		'external' => 'M19 19H5V5h7V3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2v-7h-2v7zM14 3v2h3.59l-9.83 9.83 1.41 1.41L19 6.41V10h2V3h-7z',
	);

	$slug = (string) $slug;
	if ( '' === $slug || ! isset( $paths[ $slug ] ) ) {
		return '';
	}

	return '<svg class="btn-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="' . $paths[ $slug ] . '"/></svg>';
}

/**
 * ヘッダーボタン(メイン/サブ)を1つ出力する。
 *
 * 文言とURLの両方が設定されているときだけ表示する。色は未指定なら
 * テーマ標準(CSS変数)のままにする。
 *
 * @param string $key   'cta'(メイン) または 'sub'(左側のサブボタン)
 * @param string $class ルート要素に付けるクラス名
 */
function jijipom_header_button( $key, $class ) {
	$text = get_theme_mod( "jijipom_header_{$key}_text", '' );
	$url  = get_theme_mod( "jijipom_header_{$key}_url", '' );
	if ( ! $text || ! $url ) {
		return;
	}

	$icon  = jijipom_button_icon_svg( get_theme_mod( "jijipom_header_{$key}_icon", '' ) );
	$bg    = sanitize_hex_color( get_theme_mod( "jijipom_header_{$key}_bg", '' ) );
	$color = sanitize_hex_color( get_theme_mod( "jijipom_header_{$key}_color", '' ) );

	$style = '';
	if ( $bg ) {
		$style .= 'background:' . $bg . ';border-color:' . $bg . ';';
	}
	if ( $color ) {
		$style .= 'color:' . $color . ';';
	}

	printf(
		'<a class="%1$s" href="%2$s"%3$s>%4$s<span class="btn-label">%5$s</span></a>',
		esc_attr( $class ),
		esc_url( $url ),
		$style ? ' style="' . esc_attr( $style ) . '"' : '',
		$icon, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 固定のインラインSVG
		esc_html( $text )
	);
}
