<?php
/**
 * コンテンツ取り込み(インポート)
 *
 * 「jijipom コンテンツビルダー」プラグインで書き出した ZIP
 * (jijipom-content.json) を読み込み、jijipom のカスタマイザー設定
 * (テーマ設定) と固定ページにまとめて反映します。
 *
 * 管理画面: 外観 > コンテンツ取込
 *
 * @package jijipom
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Jijipom_Importer {

	const CAP       = 'edit_theme_options';
	const MENU_SLUG = 'jijipom-import';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_jijipom_import', array( __CLASS__, 'handle' ) );
	}

	public static function menu() {
		add_theme_page(
			__( 'コンテンツ取込', 'jijipom' ),
			__( 'コンテンツ取込', 'jijipom' ),
			self::CAP,
			self::MENU_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '権限がありません', 'jijipom' ) );
		}

		$errmap = array(
			'no_file'  => __( 'ZIP ファイルが選択されていません。', 'jijipom' ),
			'bad_zip'  => __( 'ZIP から jijipom-content.json を読み取れませんでした。ビルダーで書き出した ZIP を選んでください。', 'jijipom' ),
			'bad_json' => __( 'ファイルの形式が正しくありません（jijipom-content の JSON ではありません）。', 'jijipom' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'コンテンツ取込', 'jijipom' ); ?></h1>

			<?php if ( ! empty( $_GET['done'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					printf(
						/* translators: 1: 設定件数, 2: 固定ページ件数 */
						esc_html__( '取り込みました。テーマ設定 %1$d 件、固定ページ %2$d 件を反映しました。', 'jijipom' ),
						(int) ( $_GET['mods'] ?? 0 ),
						(int) ( $_GET['pages'] ?? 0 )
					);
					?>
					<a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'サイトを表示', 'jijipom' ); ?></a>
				</p></div>
			<?php endif; ?>
			<?php
			if ( ! empty( $_GET['err'] ) ) :
				$code = sanitize_key( wp_unslash( $_GET['err'] ) );
				if ( isset( $errmap[ $code ] ) ) :
					?>
					<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $errmap[ $code ] ); ?></p></div>
					<?php
				endif;
			endif;
			?>

			<p class="description" style="max-width:820px;">
				<?php esc_html_e( '「jijipom コンテンツビルダー」で書き出した ZIP を読み込むと、各ページの内容（カスタマイザー設定）と固定ページにまとめて反映されます。色・フォント・ヘッダー/フッター・SNS などビルダーに無い項目は上書きされず、そのまま残ります。', 'jijipom' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data"
				style="max-width:820px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 18px;">
				<?php wp_nonce_field( 'jijipom_import' ); ?>
				<input type="hidden" name="action" value="jijipom_import" />

				<p>
					<label for="jijipom_zip" style="font-weight:600;display:block;margin-bottom:6px;"><?php esc_html_e( '取り込む ZIP を選択', 'jijipom' ); ?></label>
					<input type="file" id="jijipom_zip" name="jijipom_zip" accept=".zip,application/zip,application/json" required />
				</p>

				<p style="margin:.6em 0;">
					<label><input type="checkbox" name="create_pages" value="1" checked />
						<?php esc_html_e( '固定ページ（サービス/会社概要/お問い合わせ/プライバシー/ホーム）を作成・更新する', 'jijipom' ); ?></label>
				</p>
				<p style="margin:.6em 0;">
					<label><input type="checkbox" name="set_front" value="1" checked />
						<?php esc_html_e( '「ホーム」を固定のトップページに設定する', 'jijipom' ); ?></label>
				</p>

				<p class="description" style="margin:.4em 0 1em;">
					<?php esc_html_e( 'カスタマイザー設定を上書きします。固定ページの本文はビルダーで入力があった場合のみ上書きされます。', 'jijipom' ); ?>
				</p>

				<?php submit_button( __( '取り込んで反映する', 'jijipom' ), 'primary' ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '権限がありません', 'jijipom' ) );
		}
		check_admin_referer( 'jijipom_import' );

		$back = admin_url( 'themes.php?page=' . self::MENU_SLUG );

		if ( empty( $_FILES['jijipom_zip']['tmp_name'] ) || ! is_uploaded_file( $_FILES['jijipom_zip']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'err', 'no_file', $back ) );
			exit;
		}

		$json = self::read_json( $_FILES['jijipom_zip']['tmp_name'] );
		if ( null === $json ) {
			wp_safe_redirect( add_query_arg( 'err', 'bad_zip', $back ) );
			exit;
		}
		$doc = json_decode( $json, true );
		if ( ! is_array( $doc ) || ( $doc['schema'] ?? '' ) !== 'jijipom-content' ) {
			wp_safe_redirect( add_query_arg( 'err', 'bad_json', $back ) );
			exit;
		}

		$mods = self::apply_theme_mods( isset( $doc['theme_mods'] ) && is_array( $doc['theme_mods'] ) ? $doc['theme_mods'] : array() );
		$mods += self::apply_site( isset( $doc['site'] ) && is_array( $doc['site'] ) ? $doc['site'] : array() );

		$pages = 0;
		if ( ! empty( $_POST['create_pages'] ) ) {
			$pages = self::apply_pages(
				isset( $doc['pages'] ) && is_array( $doc['pages'] ) ? $doc['pages'] : array(),
				! empty( $_POST['set_front'] )
			);
		}

		wp_safe_redirect( add_query_arg( array( 'done' => 1, 'mods' => $mods, 'pages' => $pages ), $back ) );
		exit;
	}

	/** ZIP から jijipom-content.json を取り出す。生 JSON も許容。 */
	private static function read_json( $tmp ) {
		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new ZipArchive();
			if ( true === $zip->open( $tmp ) ) {
				$data = $zip->getFromName( 'jijipom-content.json' );
				$zip->close();
				if ( false !== $data ) {
					return $data;
				}
			}
		}
		$raw = file_get_contents( $tmp );
		if ( is_string( $raw ) && 0 === strpos( ltrim( $raw ), '{' ) ) {
			return $raw;
		}
		return null;
	}

	/** 取り込み対象のテーマ設定キーと型（ホワイトリスト）。 */
	private static function mod_schema() {
		$s = array(
			'jijipom_hero_type'           => 'select_hero',
			'jijipom_hero_image'          => 'url',
			'jijipom_hero_image_2'        => 'url',
			'jijipom_hero_image_3'        => 'url',
			'jijipom_hero_interval'       => 'interval',
			'jijipom_hero_video'          => 'url',
			'jijipom_hero_youtube'        => 'text',
			'jijipom_hero_title'          => 'textarea',
			'jijipom_hero_subtitle'       => 'textarea',
			'jijipom_hero_align'          => 'select_align',
			'jijipom_hero_button_text'    => 'text',
			'jijipom_hero_button_url'     => 'url',
			'jijipom_hero_button_bg'      => 'color',
			'jijipom_hero_button_color'   => 'color',
			'jijipom_service_enable'      => 'bool',
			'jijipom_service_heading'     => 'text',
			'jijipom_service_text'        => 'textarea',
			'jijipom_service_button_text' => 'text',
			'jijipom_service_button_url'  => 'url',
			'jijipom_service_image'       => 'url',
			'jijipom_blog_enable'         => 'bool',
			'jijipom_blog_heading'        => 'text',
			'jijipom_about_enable'        => 'bool',
			'jijipom_accent_color'        => 'color',
			'jijipom_content_width'       => 'px',
			'jijipom_wide_width'          => 'px',
			'jijipom_hero_height'         => 'select_hero_height',
			'jijipom_front_order'         => 'select_front_order',
			'jijipom_footer_address'      => 'textarea',
			'jijipom_footer_tel'          => 'text',
			'jijipom_contact_form_shortcode' => 'text',
			'jijipom_font_base'           => 'font',
			'jijipom_font_form'           => 'font',
			'jijipom_font_heading'        => 'font',
			'jijipom_font_body'           => 'font',
			'jijipom_about_heading'       => 'text',
			'jijipom_svc_lead'            => 'textarea',
			'jijipom_svc_items_heading'   => 'text',
			'jijipom_svc_feature_heading' => 'text',
			'jijipom_svc_feature_text'    => 'textarea',
			'jijipom_svc_cta_heading'     => 'text',
			'jijipom_svc_cta_button_text' => 'text',
			'jijipom_svc_cta_button_url'  => 'url',
			'jijipom_company_lead'            => 'textarea',
			'jijipom_company_greeting_text'  => 'textarea',
			'jijipom_company_greeting_name'  => 'text',
			'jijipom_company_greeting_image' => 'url',
			'jijipom_company_access_address' => 'textarea',
			'jijipom_company_access_hours'   => 'text',
			'jijipom_company_access_holiday' => 'text',
			'jijipom_company_map_url'        => 'url',
			'jijipom_contact_lead'      => 'textarea',
			'jijipom_contact_tel'       => 'text',
			'jijipom_contact_tel_note'  => 'text',
			'jijipom_contact_email'     => 'text',
			'jijipom_contact_hours'     => 'text',
			'jijipom_contact_holiday'   => 'text',
			'jijipom_contact_area'      => 'textarea',
			'jijipom_contact_map_url'   => 'url',
			'jijipom_privacy_intro'       => 'textarea',
			'jijipom_privacy_operator'    => 'text',
			'jijipom_privacy_established'  => 'text',
		);
		// ヘッダーボタン(メイン=cta / サブ=sub)
		foreach ( array( 'cta', 'sub' ) as $slot ) {
			$s[ "jijipom_header_{$slot}_text" ]  = 'text';
			$s[ "jijipom_header_{$slot}_url" ]   = 'url';
			$s[ "jijipom_header_{$slot}_icon" ]  = 'icon';
			$s[ "jijipom_header_{$slot}_bg" ]    = 'color';
			$s[ "jijipom_header_{$slot}_color" ] = 'color';
		}
		for ( $n = 1; $n <= 3; $n++ ) {
			$s[ "jijipom_about_{$n}_title" ] = 'text';
			$s[ "jijipom_about_{$n}_text" ]  = 'textarea';
			$s[ "jijipom_about_{$n}_image" ] = 'url';
		}
		for ( $n = 1; $n <= 4; $n++ ) {
			$s[ "jijipom_svc_item{$n}_title" ] = 'text';
			$s[ "jijipom_svc_item{$n}_text" ]  = 'textarea';
			$s[ "jijipom_svc_item{$n}_image" ] = 'url';
			$s[ "jijipom_svc_item{$n}_url" ]   = 'url';
		}
		for ( $n = 1; $n <= 8; $n++ ) {
			$s[ "jijipom_company_row{$n}_label" ] = 'text';
			$s[ "jijipom_company_row{$n}_value" ] = 'textarea';
		}
		foreach ( array( 'x', 'instagram', 'facebook', 'threads', 'tiktok', 'youtube' ) as $slug ) {
			$s[ "jijipom_social_{$slug}_url" ]   = 'url';
			$s[ "jijipom_social_{$slug}_order" ] = 'order';
		}
		return $s;
	}

	private static function sanitize_mod( $type, $value ) {
		switch ( $type ) {
			case 'url':
				return esc_url_raw( (string) $value );
			case 'textarea':
				return sanitize_textarea_field( (string) $value );
			case 'bool':
				return ! empty( $value ) && 'false' !== $value && '0' !== $value;
			case 'select_hero':
				$v = sanitize_text_field( (string) $value );
				return in_array( $v, array( 'image', 'video', 'youtube' ), true ) ? $v : 'image';
			case 'select_align':
				$v = sanitize_text_field( (string) $value );
				return in_array( $v, array( 'left', 'center', 'right' ), true ) ? $v : 'center';
			case 'select_hero_height':
				$v = sanitize_text_field( (string) $value );
				$ok = function_exists( 'jijipom_hero_height_choices' )
					? array_keys( jijipom_hero_height_choices() )
					: array( '', 'compact', 'tall', 'full' );
				return in_array( $v, $ok, true ) ? $v : '';
			case 'select_front_order':
				$v = sanitize_text_field( (string) $value );
				$ok = function_exists( 'jijipom_front_order_choices' )
					? array_keys( jijipom_front_order_choices() )
					: array( 'service-blog-about' );
				return in_array( $v, $ok, true ) ? $v : 'service-blog-about';
			case 'interval':
				$n = (int) $value;
				return ( $n >= 2 && $n <= 12 ) ? $n : 5;
			case 'px':
				// 0 = テーマ標準。範囲外は 0 に倒す。
				$n = (int) $value;
				return ( $n >= 0 && $n <= 1600 ) ? $n : 0;
			case 'font':
				$v = sanitize_text_field( (string) $value );
				$allowed = array( '', 'gothic', 'mincho', 'yugothic', 'yumincho', 'maru', 'meiryo', 'system', 'mono' );
				return in_array( $v, $allowed, true ) ? $v : '';
			case 'order':
				$n = (int) $value;
				return ( $n >= 1 && $n <= 6 ) ? $n : 6;
			case 'icon':
				$v = sanitize_text_field( (string) $value );
				return array_key_exists( $v, jijipom_button_icon_choices() ) ? $v : '';
			case 'color':
				// 未指定(空)ならテーマ標準。不正な値も空に倒す。
				$v = sanitize_hex_color( (string) $value );
				return $v ? $v : '';
			case 'text':
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/** ホワイトリストのキーだけを set_theme_mod で適用。適用件数を返す。 */
	private static function apply_theme_mods( array $incoming ) {
		$schema = self::mod_schema();
		$count  = 0;
		foreach ( $schema as $key => $type ) {
			if ( ! array_key_exists( $key, $incoming ) ) {
				continue;
			}
			set_theme_mod( $key, self::sanitize_mod( $type, $incoming[ $key ] ) );
			$count++;
		}
		return $count;
	}

	/**
	 * サイト基本情報を適用する。サイトタイトル(blogname) とロゴ画像
	 * (custom_logo)。適用した件数を返す。
	 */
	private static function apply_site( array $site ) {
		$n = 0;
		if ( ! empty( $site['title'] ) ) {
			update_option( 'blogname', sanitize_text_field( (string) $site['title'] ) );
			$n++;
		}
		if ( ! empty( $site['logo_url'] ) ) {
			$id = self::sideload_logo( esc_url_raw( (string) $site['logo_url'] ) );
			if ( $id ) {
				set_theme_mod( 'custom_logo', $id );
				$n++;
			}
		}
		return $n;
	}

	/** ロゴ画像 URL をメディアライブラリに取り込み、添付 ID を返す(失敗0)。 */
	private static function sideload_logo( $url ) {
		if ( '' === $url ) {
			return 0;
		}
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$id = media_sideload_image( $url, 0, null, 'id' );
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		return (int) $id;
	}

	/** 固定ページの作成/更新 + トップページ設定。作成/更新した件数を返す。 */
	private static function apply_pages( array $pages, $set_front ) {
		$templates = array(
			'service' => 'templates/page-service.php',
			'company' => 'templates/page-company.php',
			'contact' => 'templates/page-contact.php',
			'privacy' => 'templates/page-privacy.php',
		);
		$count   = 0;
		$home_id = 0;

		foreach ( array( 'home', 'service', 'company', 'contact', 'privacy' ) as $key ) {
			if ( empty( $pages[ $key ] ) || ! is_array( $pages[ $key ] ) ) {
				continue;
			}
			$title = isset( $pages[ $key ]['title'] ) ? sanitize_text_field( (string) $pages[ $key ]['title'] ) : '';
			if ( '' === $title ) {
				$title = ucfirst( $key );
			}
			$content = isset( $pages[ $key ]['content'] ) ? wp_kses_post( (string) $pages[ $key ]['content'] ) : '';
			$tpl     = isset( $templates[ $key ] ) ? $templates[ $key ] : '';
			$id      = self::ensure_page( $key, $title, $tpl, $content );
			if ( $id ) {
				$count++;
				if ( 'home' === $key ) {
					$home_id = $id;
				}
			}
		}

		if ( $set_front && $home_id ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $home_id );
		}
		return $count;
	}

	/**
	 * `_jijipom_page` メタで管理する固定ページを 1 つ用意する。既にあれば
	 * タイトル(+本文があれば本文)を更新し、テンプレートを設定する。
	 */
	private static function ensure_page( $slug_key, $title, $template, $content ) {
		$existing = get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_key'    => '_jijipom_page',
				'meta_value'  => $slug_key,
			)
		);

		$postarr = array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => $title,
		);
		if ( '' !== $content ) {
			$postarr['post_content'] = $content;
		}

		if ( ! empty( $existing ) ) {
			$postarr['ID'] = (int) $existing[0];
			$id            = wp_update_post( $postarr, true );
		} else {
			$id = wp_insert_post( $postarr, true );
		}
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		$id = (int) $id;

		update_post_meta( $id, '_jijipom_page', $slug_key );
		if ( '' !== $template ) {
			update_post_meta( $id, '_wp_page_template', $template );
		}
		return $id;
	}
}

Jijipom_Importer::init();
