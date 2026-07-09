<?php
/**
 * パンくずリスト
 *
 * データ生成(jijipom_get_breadcrumb_items)と
 * 表示(jijipom_breadcrumbs)を分離。
 * データは JSON-LD の BreadcrumbList でも再利用します。
 *
 * @package jijipom
 */

/**
 * パンくずの各項目を配列で返す
 *
 * @return array{name:string, url:string}[]
 */
function jijipom_get_breadcrumb_items() {
	$items = array();

	// ホーム
	$items[] = array(
		'name' => __( 'ホーム', 'jijipom' ),
		'url'  => home_url( '/' ),
	);

	if ( is_front_page() ) {
		return $items;
	}

	if ( is_singular( 'post' ) ) {
		// 投稿:主要カテゴリー → 記事
		$categories = get_the_category();
		if ( ! empty( $categories ) ) {
			$primary   = $categories[0];
			$ancestors = array_reverse( get_ancestors( $primary->term_id, 'category' ) );
			foreach ( $ancestors as $ancestor_id ) {
				$ancestor = get_category( $ancestor_id );
				$items[]  = array(
					'name' => $ancestor->name,
					'url'  => get_category_link( $ancestor->term_id ),
				);
			}
			$items[] = array(
				'name' => $primary->name,
				'url'  => get_category_link( $primary->term_id ),
			);
		}
		$items[] = array(
			'name' => get_the_title(),
			'url'  => '',
		);

	} elseif ( is_page() ) {
		// 固定ページ:親ページを辿る
		$ancestors = array_reverse( get_post_ancestors( get_the_ID() ) );
		foreach ( $ancestors as $ancestor_id ) {
			$items[] = array(
				'name' => get_the_title( $ancestor_id ),
				'url'  => get_permalink( $ancestor_id ),
			);
		}
		$items[] = array(
			'name' => get_the_title(),
			'url'  => '',
		);

	} elseif ( is_category() ) {
		$term      = get_queried_object();
		$ancestors = array_reverse( get_ancestors( $term->term_id, 'category' ) );
		foreach ( $ancestors as $ancestor_id ) {
			$ancestor = get_category( $ancestor_id );
			$items[]  = array(
				'name' => $ancestor->name,
				'url'  => get_category_link( $ancestor->term_id ),
			);
		}
		$items[] = array(
			'name' => single_cat_title( '', false ),
			'url'  => '',
		);

	} elseif ( is_tag() ) {
		$items[] = array(
			/* translators: %s: タグ名 */
			'name' => sprintf( __( 'タグ: %s', 'jijipom' ), single_tag_title( '', false ) ),
			'url'  => '',
		);

	} elseif ( is_tax() ) {
		$items[] = array(
			'name' => single_term_title( '', false ),
			'url'  => '',
		);

	} elseif ( is_author() ) {
		$items[] = array(
			/* translators: %s: 著者名 */
			'name' => sprintf( __( '著者: %s', 'jijipom' ), get_the_author() ),
			'url'  => '',
		);

	} elseif ( is_search() ) {
		$items[] = array(
			/* translators: %s: 検索語 */
			'name' => sprintf( __( '「%s」の検索結果', 'jijipom' ), get_search_query() ),
			'url'  => '',
		);

	} elseif ( is_404() ) {
		$items[] = array(
			'name' => __( 'ページが見つかりません', 'jijipom' ),
			'url'  => '',
		);

	} elseif ( is_archive() ) {
		$items[] = array(
			'name' => wp_strip_all_tags( get_the_archive_title() ),
			'url'  => '',
		);
	}

	return $items;
}

/**
 * パンくずリストを表示
 * 視覚表示のみ。構造化データは inc/seo.php 側で出力します。
 */
function jijipom_breadcrumbs() {
	if ( is_front_page() ) {
		return;
	}

	$items = jijipom_get_breadcrumb_items();
	if ( count( $items ) < 2 ) {
		return;
	}

	echo '<nav class="breadcrumbs" aria-label="' . esc_attr__( 'パンくずリスト', 'jijipom' ) . '">';
	echo '<ol>';
	$last = count( $items ) - 1;
	foreach ( $items as $i => $item ) {
		echo '<li>';
		if ( ! empty( $item['url'] ) && $i !== $last ) {
			printf( '<a href="%s">%s</a>', esc_url( $item['url'] ), esc_html( $item['name'] ) );
		} else {
			printf( '<span aria-current="page">%s</span>', esc_html( $item['name'] ) );
		}
		echo '</li>';
	}
	echo '</ol>';
	echo '</nav>';
}
