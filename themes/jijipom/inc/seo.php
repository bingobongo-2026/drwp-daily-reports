<?php
/**
 * SEO 機能
 *
 * - メタディスクリプション
 * - canonical URL
 * - OGP / Twitter Card
 * - 構造化データ (JSON-LD): WebSite / Organization / Article / BreadcrumbList
 *
 * プラグイン(Yoast, SEO SIMPLE PACK など)を導入する場合は
 * 重複を避けるため jijipom_seo_output() の呼び出しを外してください。
 *
 * @package jijipom
 */

/**
 * 現在のページのメタディスクリプションを取得
 */
function jijipom_get_meta_description() {
	$description = '';

	if ( is_singular() ) {
		$post = get_queried_object();
		if ( has_excerpt( $post->ID ) ) {
			$description = get_the_excerpt( $post );
		} else {
			$description = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
		}
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$description = term_description();
	} elseif ( is_author() ) {
		$description = get_the_author_meta( 'description', get_query_var( 'author' ) );
	} elseif ( is_home() || is_front_page() ) {
		$description = get_bloginfo( 'description' );
	}

	$description = wp_strip_all_tags( $description );
	$description = trim( preg_replace( '/\s+/u', ' ', $description ) );

	// 全角も考慮して 120 文字程度で丸める
	if ( mb_strlen( $description ) > 120 ) {
		$description = mb_substr( $description, 0, 120 ) . '…';
	}

	return apply_filters( 'jijipom_meta_description', $description );
}

/**
 * 現在のページの canonical URL を取得
 */
function jijipom_get_canonical_url() {
	if ( is_singular() ) {
		return get_permalink();
	}
	if ( is_front_page() ) {
		return home_url( '/' );
	}
	if ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();
		if ( $term && ! is_wp_error( $term ) ) {
			return get_term_link( $term );
		}
	}
	if ( is_author() ) {
		return get_author_posts_url( get_query_var( 'author' ) );
	}
	if ( is_post_type_archive() ) {
		return get_post_type_archive_link( get_post_type() );
	}
	// フォールバック
	global $wp;
	return home_url( add_query_arg( array(), $wp->request ) );
}

/**
 * 代表画像(OGP用)を取得
 */
function jijipom_get_og_image() {
	if ( is_singular() && has_post_thumbnail() ) {
		$image = wp_get_attachment_image_src( get_post_thumbnail_id(), 'full' );
		if ( $image ) {
			return $image[0];
		}
	}
	// カスタムロゴをフォールバックに
	$custom_logo_id = get_theme_mod( 'custom_logo' );
	if ( $custom_logo_id ) {
		$logo = wp_get_attachment_image_src( $custom_logo_id, 'full' );
		if ( $logo ) {
			return $logo[0];
		}
	}
	return apply_filters( 'jijipom_default_og_image', '' );
}

/**
 * <head> にSEO関連タグを出力
 */
function jijipom_seo_output() {
	$description = jijipom_get_meta_description();
	$canonical   = jijipom_get_canonical_url();
	$og_image    = jijipom_get_og_image();
	$site_name   = get_bloginfo( 'name' );

	// タイトル(wp_get_document_title は title-tag が有効なら利用可能)
	$title = wp_get_document_title();

	// OGタイプ
	$og_type = is_singular( 'post' ) ? 'article' : 'website';

	echo "\n<!-- jijipom SEO -->\n";

	if ( $description ) {
		printf( '<meta name="description" content="%s">' . "\n", esc_attr( $description ) );
	}

	if ( $canonical ) {
		printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $canonical ) );
	}

	// Open Graph
	printf( '<meta property="og:locale" content="%s">' . "\n", esc_attr( get_locale() ) );
	printf( '<meta property="og:type" content="%s">' . "\n", esc_attr( $og_type ) );
	printf( '<meta property="og:title" content="%s">' . "\n", esc_attr( $title ) );
	if ( $description ) {
		printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( $description ) );
	}
	printf( '<meta property="og:url" content="%s">' . "\n", esc_url( $canonical ) );
	printf( '<meta property="og:site_name" content="%s">' . "\n", esc_attr( $site_name ) );
	if ( $og_image ) {
		printf( '<meta property="og:image" content="%s">' . "\n", esc_url( $og_image ) );
	}

	// 記事の場合は公開日時などを付与
	if ( is_singular( 'post' ) ) {
		printf( '<meta property="article:published_time" content="%s">' . "\n", esc_attr( get_the_date( 'c' ) ) );
		printf( '<meta property="article:modified_time" content="%s">' . "\n", esc_attr( get_the_modified_date( 'c' ) ) );
	}

	// Twitter Card
	printf( '<meta name="twitter:card" content="%s">' . "\n", $og_image ? 'summary_large_image' : 'summary' );
	printf( '<meta name="twitter:title" content="%s">' . "\n", esc_attr( $title ) );
	if ( $description ) {
		printf( '<meta name="twitter:description" content="%s">' . "\n", esc_attr( $description ) );
	}
	if ( $og_image ) {
		printf( '<meta name="twitter:image" content="%s">' . "\n", esc_url( $og_image ) );
	}

	// 構造化データ
	jijipom_output_jsonld();

	echo "<!-- /jijipom SEO -->\n\n";
}
add_action( 'wp_head', 'jijipom_seo_output', 5 );

/**
 * JSON-LD 構造化データを出力
 */
function jijipom_output_jsonld() {
	$graph = array();

	$site_url  = home_url( '/' );
	$site_name = get_bloginfo( 'name' );

	// WebSite(検索ボックス付き)
	$graph[] = array(
		'@type'           => 'WebSite',
		'@id'             => $site_url . '#website',
		'url'             => $site_url,
		'name'            => $site_name,
		'description'     => get_bloginfo( 'description' ),
		'inLanguage'      => get_bloginfo( 'language' ),
		'potentialAction' => array(
			'@type'       => 'SearchAction',
			'target'      => array(
				'@type'       => 'EntryPoint',
				'urlTemplate' => $site_url . '?s={search_term_string}',
			),
			'query-input' => 'required name=search_term_string',
		),
	);

	// Organization(サイト運営者)
	$organization = array(
		'@type' => 'Organization',
		'@id'   => $site_url . '#organization',
		'name'  => $site_name,
		'url'   => $site_url,
	);
	$custom_logo_id = get_theme_mod( 'custom_logo' );
	if ( $custom_logo_id ) {
		$logo = wp_get_attachment_image_src( $custom_logo_id, 'full' );
		if ( $logo ) {
			$organization['logo'] = array(
				'@type' => 'ImageObject',
				'url'   => $logo[0],
			);
		}
	}
	$graph[] = $organization;

	// 記事ページ
	if ( is_singular( 'post' ) ) {
		$post      = get_queried_object();
		$author_id = $post->post_author;

		$article = array(
			'@type'            => 'Article',
			'@id'              => get_permalink() . '#article',
			'isPartOf'         => array( '@id' => $site_url . '#website' ),
			'headline'         => get_the_title(),
			'description'      => jijipom_get_meta_description(),
			'datePublished'    => get_the_date( 'c' ),
			'dateModified'     => get_the_modified_date( 'c' ),
			'mainEntityOfPage' => array( '@id' => get_permalink() ),
			'author'           => array(
				'@type' => 'Person',
				'name'  => get_the_author_meta( 'display_name', $author_id ),
				'url'   => get_author_posts_url( $author_id ),
			),
			'publisher'        => array( '@id' => $site_url . '#organization' ),
		);

		if ( has_post_thumbnail() ) {
			$image = wp_get_attachment_image_src( get_post_thumbnail_id(), 'full' );
			if ( $image ) {
				$article['image'] = array(
					'@type'  => 'ImageObject',
					'url'    => $image[0],
					'width'  => $image[1],
					'height' => $image[2],
				);
			}
		}

		$graph[] = $article;
	}

	// パンくずリスト(共通関数からデータを取得)
	if ( function_exists( 'jijipom_get_breadcrumb_items' ) ) {
		$crumbs = jijipom_get_breadcrumb_items();
		if ( count( $crumbs ) > 1 ) {
			$list = array();
			foreach ( $crumbs as $i => $crumb ) {
				$item = array(
					'@type'    => 'ListItem',
					'position' => $i + 1,
					'name'     => $crumb['name'],
				);
				if ( ! empty( $crumb['url'] ) ) {
					$item['item'] = $crumb['url'];
				}
				$list[] = $item;
			}
			$graph[] = array(
				'@type'           => 'BreadcrumbList',
				'@id'             => jijipom_get_canonical_url() . '#breadcrumb',
				'itemListElement' => $list,
			);
		}
	}

	$jsonld = array(
		'@context' => 'https://schema.org',
		'@graph'   => $graph,
	);

	echo '<script type="application/ld+json">'
		. wp_json_encode( $jsonld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		. '</script>' . "\n";
}
