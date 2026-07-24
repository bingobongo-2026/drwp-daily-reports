<?php
/**
 * カスタマイザー:固定ページテンプレート設定
 *
 * 「外観 > カスタマイズ > 固定ページ」に、サービス / 会社概要 /
 * お問い合わせ / プライバシーポリシー の各テンプレートの領域を追加します。
 *
 * ページ本文(ブロックエディタ)とは別に、テンプレートが決まった位置に
 * 差し込む「領域(見出し・項目・会社概要表・連絡先など)」をここで入力します。
 * どの領域に何を入れるかは同梱の「コンテンツ入力シート」で整理できます。
 *
 * フィールドの登録には customizer-frontpage.php の jijipom_fp_add() を
 * 再利用します(先に require 済み)。
 *
 * @package jijipom
 */

/**
 * 固定ページテンプレート用のパネル・セクション・フィールドを登録
 */
function jijipom_pages_customize_register( $wp_customize ) {

	if ( ! function_exists( 'jijipom_fp_add' ) ) {
		return; // ヘルパー未読み込み時は何もしない(保険)。
	}

	$wp_customize->add_panel(
		'jijipom_pages_panel',
		array(
			'title'       => __( '固定ページ', 'jijipom' ),
			'description' => __( 'サービス / 会社概要 / お問い合わせ / プライバシーポリシーの各テンプレートの領域を設定します。固定ページを作成し「ページ属性 > テンプレート」で対応するテンプレートを選ぶと反映されます。', 'jijipom' ),
			'priority'    => 26,
		)
	);

	// =====================================================================
	// サービス (templates/page-service.php)
	// =====================================================================
	$wp_customize->add_section( 'jijipom_page_service', array( 'title' => __( 'サービス ページ', 'jijipom' ), 'panel' => 'jijipom_pages_panel' ) );
	jijipom_fp_add( $wp_customize, 'jijipom_svc_lead', array( 'type' => 'textarea', 'section' => 'jijipom_page_service', 'label' => __( 'リード文(タイトル下の説明)', 'jijipom' ), 'desc' => __( 'ページ上部・タイトルの下に出る導入文。改行できます。', 'jijipom' ) ) );

	jijipom_fp_add( $wp_customize, 'jijipom_svc_items_heading', array( 'section' => 'jijipom_page_service', 'label' => __( 'サービス一覧の見出し', 'jijipom' ), 'default' => __( '提供サービス', 'jijipom' ) ) );
	for ( $i = 1; $i <= 4; $i++ ) {
		/* translators: %d: サービス項目の番号 */
		$svc_label = sprintf( __( 'サービス%d', 'jijipom' ), $i );
		jijipom_fp_add( $wp_customize, "jijipom_svc_item{$i}_title", array( 'section' => 'jijipom_page_service', 'label' => $svc_label . ' : ' . __( 'タイトル', 'jijipom' ) ) );
		jijipom_fp_add( $wp_customize, "jijipom_svc_item{$i}_text",  array( 'type' => 'textarea', 'section' => 'jijipom_page_service', 'label' => $svc_label . ' : ' . __( '説明文', 'jijipom' ) ) );
		jijipom_fp_add( $wp_customize, "jijipom_svc_item{$i}_image", array( 'type' => 'image', 'section' => 'jijipom_page_service', 'label' => $svc_label . ' : ' . __( '画像', 'jijipom' ) ) );
	}

	jijipom_fp_add( $wp_customize, 'jijipom_svc_feature_heading', array( 'section' => 'jijipom_page_service', 'label' => __( '強み・特徴の見出し', 'jijipom' ), 'default' => __( '私たちの強み', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_svc_feature_text',    array( 'type' => 'textarea', 'section' => 'jijipom_page_service', 'label' => __( '強み・特徴の本文', 'jijipom' ) ) );

	jijipom_fp_add( $wp_customize, 'jijipom_svc_cta_heading',     array( 'section' => 'jijipom_page_service', 'label' => __( 'CTA(お問い合わせ誘導)の見出し', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_svc_cta_text',        array( 'type' => 'textarea', 'section' => 'jijipom_page_service', 'label' => __( 'CTA の説明文', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_svc_cta_button_text', array( 'section' => 'jijipom_page_service', 'label' => __( 'CTA ボタンの文言', 'jijipom' ), 'default' => __( 'お問い合わせはこちら', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_svc_cta_button_url',  array( 'type' => 'url', 'section' => 'jijipom_page_service', 'label' => __( 'CTA ボタンのリンク先URL', 'jijipom' ) ) );

	// =====================================================================
	// 会社概要 (templates/page-company.php)
	// =====================================================================
	$wp_customize->add_section( 'jijipom_page_company', array( 'title' => __( '会社概要 ページ', 'jijipom' ), 'panel' => 'jijipom_pages_panel' ) );
	jijipom_fp_add( $wp_customize, 'jijipom_company_lead', array( 'type' => 'textarea', 'section' => 'jijipom_page_company', 'label' => __( 'リード文(タイトル下の説明)', 'jijipom' ) ) );

	// ごあいさつ
	jijipom_fp_add( $wp_customize, 'jijipom_company_greeting_heading', array( 'section' => 'jijipom_page_company', 'label' => __( 'ごあいさつの見出し', 'jijipom' ), 'default' => __( 'ごあいさつ', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_company_greeting_text',    array( 'type' => 'textarea', 'section' => 'jijipom_page_company', 'label' => __( 'ごあいさつの本文', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_company_greeting_image',   array( 'type' => 'image', 'section' => 'jijipom_page_company', 'label' => __( '代表者の写真', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_company_greeting_name',    array( 'section' => 'jijipom_page_company', 'label' => __( '役職・氏名', 'jijipom' ), 'desc' => __( '例: 代表取締役 山田 太郎', 'jijipom' ) ) );

	// 会社概要テーブル(8行)。ラベルは既定を入れておく。
	jijipom_fp_add( $wp_customize, 'jijipom_company_overview_heading', array( 'section' => 'jijipom_page_company', 'label' => __( '会社概要表の見出し', 'jijipom' ), 'default' => __( '会社概要', 'jijipom' ) ) );
	$company_row_labels = array( __( '会社名', 'jijipom' ), __( '代表者', 'jijipom' ), __( '所在地', 'jijipom' ), __( '電話番号', 'jijipom' ), __( '設立', 'jijipom' ), __( '資本金', 'jijipom' ), __( '事業内容', 'jijipom' ), __( '許認可番号', 'jijipom' ) );
	for ( $i = 1; $i <= 8; $i++ ) {
		/* translators: %d: 会社概要表の行番号 */
		$row_label = sprintf( __( '概要 行%d', 'jijipom' ), $i );
		jijipom_fp_add( $wp_customize, "jijipom_company_row{$i}_label", array( 'section' => 'jijipom_page_company', 'label' => $row_label . ' : ' . __( '項目名', 'jijipom' ), 'default' => $company_row_labels[ $i - 1 ] ) );
		jijipom_fp_add( $wp_customize, "jijipom_company_row{$i}_value", array( 'type' => 'textarea', 'section' => 'jijipom_page_company', 'label' => $row_label . ' : ' . __( '内容', 'jijipom' ) ) );
	}

	// アクセス
	jijipom_fp_add( $wp_customize, 'jijipom_company_access_heading', array( 'section' => 'jijipom_page_company', 'label' => __( 'アクセスの見出し', 'jijipom' ), 'default' => __( 'アクセス', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_company_access_address', array( 'type' => 'textarea', 'section' => 'jijipom_page_company', 'label' => __( '住所', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_company_access_hours',   array( 'section' => 'jijipom_page_company', 'label' => __( '営業時間', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_company_access_holiday', array( 'section' => 'jijipom_page_company', 'label' => __( '定休日', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_company_map_url',        array( 'type' => 'url', 'section' => 'jijipom_page_company', 'label' => __( '地図の埋め込みURL(Googleマップ)', 'jijipom' ), 'desc' => __( 'Googleマップ「共有 > 地図を埋め込む」の iframe の src(https://www.google.com/maps/embed?... )を貼ってください。', 'jijipom' ) ) );

	// =====================================================================
	// お問い合わせ (templates/page-contact.php)
	// =====================================================================
	$wp_customize->add_section( 'jijipom_page_contact', array( 'title' => __( 'お問い合わせ ページ', 'jijipom' ), 'panel' => 'jijipom_pages_panel' ) );
	jijipom_fp_add( $wp_customize, 'jijipom_contact_lead',     array( 'type' => 'textarea', 'section' => 'jijipom_page_contact', 'label' => __( 'リード文(タイトル下の説明)', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_contact_tel',      array( 'section' => 'jijipom_page_contact', 'label' => __( '電話番号', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_contact_tel_note', array( 'section' => 'jijipom_page_contact', 'label' => __( '電話の補足(受付時間など)', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_contact_email',    array( 'section' => 'jijipom_page_contact', 'label' => __( 'メールアドレス', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_contact_hours',    array( 'section' => 'jijipom_page_contact', 'label' => __( '営業時間', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_contact_holiday',  array( 'section' => 'jijipom_page_contact', 'label' => __( '定休日', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_contact_area',     array( 'type' => 'textarea', 'section' => 'jijipom_page_contact', 'label' => __( '対応エリア・補足', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_contact_form_shortcode', array( 'section' => 'jijipom_page_contact', 'label' => __( '問い合わせフォームのショートコード', 'jijipom' ), 'desc' => __( 'Contact Form 7 などのショートコード(例: [contact-form-7 id="123"])。入力するとフォーム領域に表示されます。', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_contact_map_url',  array( 'type' => 'url', 'section' => 'jijipom_page_contact', 'label' => __( '地図の埋め込みURL(Googleマップ)', 'jijipom' ), 'desc' => __( '会社概要と同じ形式(embed の src)。', 'jijipom' ) ) );

	// =====================================================================
	// プライバシーポリシー (templates/page-privacy.php)
	// =====================================================================
	$wp_customize->add_section( 'jijipom_page_privacy', array( 'title' => __( 'プライバシーポリシー ページ', 'jijipom' ), 'panel' => 'jijipom_pages_panel' ) );
	jijipom_fp_add( $wp_customize, 'jijipom_privacy_intro',       array( 'type' => 'textarea', 'section' => 'jijipom_page_privacy', 'label' => __( '前文', 'jijipom' ), 'desc' => __( '各条項の本文はページ本文(ブロックエディタ)に記載します。ここは冒頭の前文だけ。', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_privacy_operator',    array( 'section' => 'jijipom_page_privacy', 'label' => __( '事業者名', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_privacy_established', array( 'section' => 'jijipom_page_privacy', 'label' => __( '制定日・改定日', 'jijipom' ), 'desc' => __( '例: 制定 2026年4月1日', 'jijipom' ) ) );
}
add_action( 'customize_register', 'jijipom_pages_customize_register' );
