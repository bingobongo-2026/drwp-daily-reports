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
