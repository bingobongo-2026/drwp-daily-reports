<?php
/**
 * カスタマイザー:トップページ設定
 *
 * 「外観 > カスタマイズ > トップページ」に、ヒーロー / サービス /
 * ブログ / 会社紹介 の各セクションと、ヘッダーCTA・フッター情報を追加します。
 *
 * @package jijipom
 */

/**
 * YouTube の URL または生の動画IDから、11文字の動画IDを取り出す。
 * 視聴URL / 共有URL(youtu.be) / 埋め込み / shorts / 生ID に対応。
 * 取り出せなければ空文字。
 *
 * @param string $input URL もしくは動画ID
 * @return string 動画ID(11文字) または ''
 */
function jijipom_youtube_id( $input ) {
	$input = trim( (string) $input );
	if ( '' === $input ) {
		return '';
	}
	// すでに動画IDそのもの。
	if ( preg_match( '/^[A-Za-z0-9_-]{11}$/', $input ) ) {
		return $input;
	}
	// 各種URL形式から抽出。
	if ( preg_match( '~(?:youtu\.be/|youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|v/|live/))([A-Za-z0-9_-]{11})~', $input, $m ) ) {
		return $m[1];
	}
	return '';
}

/**
 * 設定+コントロールをまとめて登録する小さなヘルパー
 */
function jijipom_fp_add( $wp_customize, $id, $args ) {
	$defaults = array(
		'type'      => 'text',      // text | textarea | checkbox | number | url | image | color | select
		'default'   => '',
		'label'     => '',
		'section'   => '',
		'desc'      => '',
		'choices'   => array(),     // select 用
	);
	$args = array_merge( $defaults, $args );

	// サニタイズコールバックを型から決定
	switch ( $args['type'] ) {
		case 'checkbox':
			$sanitize = 'jijipom_sanitize_checkbox';
			break;
		case 'textarea':
			$sanitize = 'sanitize_textarea_field';
			break;
		case 'url':
		case 'image':
		case 'video':
			$sanitize = 'esc_url_raw';
			break;
		case 'number':
			$sanitize = 'absint';
			break;
		case 'color':
			$sanitize = 'sanitize_hex_color';
			break;
		case 'select':
			$choices  = $args['choices'];
			$sanitize = function ( $value ) use ( $choices ) {
				return array_key_exists( (string) $value, $choices ) ? (string) $value : '';
			};
			break;
		default:
			$sanitize = 'sanitize_text_field';
	}

	$wp_customize->add_setting(
		$id,
		array(
			'default'           => $args['default'],
			'sanitize_callback' => $sanitize,
			'transport'         => 'refresh',
		)
	);

	$control_args = array(
		'label'       => $args['label'],
		'description' => $args['desc'],
		'section'     => $args['section'],
	);

	if ( 'image' === $args['type'] ) {
		$wp_customize->add_control(
			new WP_Customize_Image_Control( $wp_customize, $id, $control_args )
		);
	} elseif ( 'video' === $args['type'] ) {
		// Upload コントロールは URL を保存する。動画に限定して選ばせる。
		$control_args['mime_type'] = 'video';
		$wp_customize->add_control(
			new WP_Customize_Upload_Control( $wp_customize, $id, $control_args )
		);
	} elseif ( 'color' === $args['type'] ) {
		$wp_customize->add_control(
			new WP_Customize_Color_Control( $wp_customize, $id, $control_args )
		);
	} else {
		$control_args['type']       = $args['type'];
		$control_args['settings']   = $id;
		if ( 'number' === $args['type'] ) {
			$control_args['input_attrs'] = array( 'min' => 1, 'max' => 12, 'step' => 1 );
		}
		if ( 'select' === $args['type'] ) {
			$control_args['choices'] = $args['choices'];
		}
		$wp_customize->add_control( $id, $control_args );
	}
}

/**
 * トップページ用パネル・セクションを登録
 */
function jijipom_frontpage_customize_register( $wp_customize ) {

	// ===== パネル =====
	$wp_customize->add_panel(
		'jijipom_front_panel',
		array(
			'title'       => __( 'トップページ', 'jijipom' ),
			'description' => __( 'トップページ(front-page.php)の各セクションを設定します。反映するには「設定 > 表示設定」でホームページに固定ページを指定してください。', 'jijipom' ),
			'priority'    => 25,
		)
	);

	// ===== ① ヒーロー =====
	$wp_customize->add_section( 'jijipom_fp_hero', array( 'title' => __( '① メインビジュアル', 'jijipom' ), 'panel' => 'jijipom_front_panel' ) );
	jijipom_fp_add( $wp_customize, 'jijipom_hero_type',       array( 'type' => 'select', 'section' => 'jijipom_fp_hero', 'label' => __( '背景の種類', 'jijipom' ), 'default' => 'image', 'desc' => __( '選んだ種類の背景が使われます。「動画(MP4)」は下の背景動画、「YouTube」は下のYouTube欄を使います。', 'jijipom' ), 'choices' => array( 'image' => __( '画像', 'jijipom' ), 'video' => __( '動画 (MP4)', 'jijipom' ), 'youtube' => __( 'YouTube', 'jijipom' ) ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_hero_image',      array( 'type' => 'image',    'section' => 'jijipom_fp_hero', 'label' => __( '背景画像1', 'jijipom' ), 'desc' => __( '最大3枚まで設定できます。2枚以上でスライドショーになります。', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_hero_image_2',    array( 'type' => 'image',    'section' => 'jijipom_fp_hero', 'label' => __( '背景画像2', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_hero_image_3',    array( 'type' => 'image',    'section' => 'jijipom_fp_hero', 'label' => __( '背景画像3', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_hero_interval',   array( 'type' => 'number',   'section' => 'jijipom_fp_hero', 'label' => __( '切り替え間隔(秒)', 'jijipom' ), 'default' => 5, 'desc' => __( '画像が2枚以上のときに使われます。', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_hero_video',      array( 'type' => 'video',    'section' => 'jijipom_fp_hero', 'label' => __( '背景動画 (MP4)', 'jijipom' ), 'desc' => __( 'MP4 形式を推奨。音声なし・自動再生・ループで背景に流れます。', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_hero_video_poster', array( 'type' => 'image',  'section' => 'jijipom_fp_hero', 'label' => __( '動画のポスター画像', 'jijipom' ), 'desc' => __( '動画の読み込み前や自動再生できない端末で表示される画像です（任意）。', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_hero_youtube',    array( 'section' => 'jijipom_fp_hero', 'label' => __( 'YouTube の URL または動画ID', 'jijipom' ), 'desc' => __( '「背景の種類」を YouTube にしたときに使います。視聴URL・共有URL・動画IDのいずれでもOK。無音・自動再生・ループの背景として流れます。', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_hero_title',      array( 'type' => 'textarea', 'section' => 'jijipom_fp_hero', 'label' => __( 'キャッチコピー', 'jijipom' ), 'default' => __( 'キャッチコピー', 'jijipom' ), 'desc' => __( '改行で複数行にできます。', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_hero_subtitle',   array( 'type' => 'textarea', 'section' => 'jijipom_fp_hero', 'label' => __( 'サブテキスト', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_hero_align',      array( 'type' => 'select', 'section' => 'jijipom_fp_hero', 'label' => __( '文字・ボタンの配置', 'jijipom' ), 'default' => 'center', 'desc' => __( 'キャッチコピー・サブテキスト・ボタンの位置をまとめて切り替えます。', 'jijipom' ), 'choices' => array( 'left' => __( '左寄せ', 'jijipom' ), 'center' => __( '中央', 'jijipom' ), 'right' => __( '右寄せ', 'jijipom' ) ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_hero_button_text',array( 'section' => 'jijipom_fp_hero', 'label' => __( 'ボタンの文言', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_hero_button_url', array( 'type' => 'url', 'section' => 'jijipom_fp_hero', 'label' => __( 'ボタンのリンク先URL', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_hero_button_bg',  array( 'type' => 'color', 'section' => 'jijipom_fp_hero', 'label' => __( 'ボタンの背景色', 'jijipom' ), 'desc' => __( '未設定のときはテーマ標準色になります。', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_hero_button_color', array( 'type' => 'color', 'section' => 'jijipom_fp_hero', 'label' => __( 'ボタンの文字色', 'jijipom' ) ) );

	// ===== ② サービス =====
	$wp_customize->add_section( 'jijipom_fp_service', array( 'title' => __( '② サービス', 'jijipom' ), 'panel' => 'jijipom_front_panel' ) );
	jijipom_fp_add( $wp_customize, 'jijipom_service_enable',      array( 'type' => 'checkbox', 'default' => true, 'section' => 'jijipom_fp_service', 'label' => __( 'このセクションを表示する', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_service_heading',     array( 'section' => 'jijipom_fp_service', 'label' => __( '見出し', 'jijipom' ), 'default' => __( 'サービス', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_service_image',       array( 'type' => 'image', 'section' => 'jijipom_fp_service', 'label' => __( '画像', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_service_text',        array( 'type' => 'textarea', 'section' => 'jijipom_fp_service', 'label' => __( '説明文', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_service_button_text', array( 'section' => 'jijipom_fp_service', 'label' => __( 'ボタンの文言', 'jijipom' ), 'default' => __( 'サービスを見る', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_service_button_url',  array( 'type' => 'url', 'section' => 'jijipom_fp_service', 'label' => __( 'ボタンのリンク先URL', 'jijipom' ) ) );

	// ===== ③ ブログ =====
	$wp_customize->add_section( 'jijipom_fp_blog', array( 'title' => __( '③ ブログ', 'jijipom' ), 'panel' => 'jijipom_front_panel' ) );
	jijipom_fp_add( $wp_customize, 'jijipom_blog_enable',  array( 'type' => 'checkbox', 'default' => true, 'section' => 'jijipom_fp_blog', 'label' => __( 'このセクションを表示する', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_blog_heading', array( 'section' => 'jijipom_fp_blog', 'label' => __( '見出し', 'jijipom' ), 'default' => __( 'ブログ', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_blog_count',   array( 'type' => 'number', 'default' => 4, 'section' => 'jijipom_fp_blog', 'label' => __( '表示する記事数', 'jijipom' ), 'desc' => __( '最新の投稿を自動で表示します。', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_blog_fallback_image', array( 'type' => 'image', 'section' => 'jijipom_fp_blog', 'label' => __( '代替画像（アイキャッチ無しの記事用）', 'jijipom' ), 'desc' => __( 'アイキャッチ画像が設定されていない記事のサムネイルに使われます。未設定ならグレーのプレースホルダーになります。', 'jijipom' ) ) );

	// ===== ④ 会社紹介 =====
	$wp_customize->add_section( 'jijipom_fp_about', array( 'title' => __( '④ 会社紹介', 'jijipom' ), 'panel' => 'jijipom_front_panel' ) );
	jijipom_fp_add( $wp_customize, 'jijipom_about_enable',  array( 'type' => 'checkbox', 'default' => true, 'section' => 'jijipom_fp_about', 'label' => __( 'このセクションを表示する', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_about_heading', array( 'section' => 'jijipom_fp_about', 'label' => __( '見出し', 'jijipom' ), 'default' => __( '会社紹介', 'jijipom' ) ) );
	for ( $i = 1; $i <= 3; $i++ ) {
		/* translators: %d: ブロック番号 */
		$block_label = sprintf( __( 'ブロック%d', 'jijipom' ), $i );
		jijipom_fp_add( $wp_customize, "jijipom_about_{$i}_image", array( 'type' => 'image', 'section' => 'jijipom_fp_about', 'label' => $block_label . ' : ' . __( '画像', 'jijipom' ) ) );
		jijipom_fp_add( $wp_customize, "jijipom_about_{$i}_title", array( 'section' => 'jijipom_fp_about', 'label' => $block_label . ' : ' . __( 'タイトル', 'jijipom' ) ) );
		jijipom_fp_add( $wp_customize, "jijipom_about_{$i}_text",  array( 'type' => 'textarea', 'section' => 'jijipom_fp_about', 'label' => $block_label . ' : ' . __( '説明文', 'jijipom' ) ) );
	}

	// ===== ⑤ ヘッダーCTAボタン(全ページ共通) =====
	$wp_customize->add_section( 'jijipom_header_cta', array( 'title' => __( 'ヘッダーCTAボタン', 'jijipom' ), 'panel' => 'jijipom_front_panel', 'description' => __( 'ヘッダー右側の「お問い合わせ」等のボタン。文言とURL両方を入力すると表示されます。', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_header_cta_text', array( 'section' => 'jijipom_header_cta', 'label' => __( 'ボタンの文言', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_header_cta_url',  array( 'type' => 'url', 'section' => 'jijipom_header_cta', 'label' => __( 'リンク先URL', 'jijipom' ) ) );

	// ===== ⑥ フッター情報 =====
	$wp_customize->add_section( 'jijipom_footer_info', array( 'title' => __( 'フッター情報', 'jijipom' ), 'panel' => 'jijipom_front_panel' ) );
	jijipom_fp_add( $wp_customize, 'jijipom_footer_address', array( 'type' => 'textarea', 'section' => 'jijipom_footer_info', 'label' => __( '住所', 'jijipom' ), 'desc' => __( '改行で複数行にできます。', 'jijipom' ) ) );
	jijipom_fp_add( $wp_customize, 'jijipom_footer_tel',     array( 'section' => 'jijipom_footer_info', 'label' => __( '電話番号', 'jijipom' ) ) );
}
add_action( 'customize_register', 'jijipom_frontpage_customize_register' );
