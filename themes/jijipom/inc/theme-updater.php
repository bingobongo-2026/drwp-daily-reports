<?php
/**
 * jijipom テーマの自動アップデート。
 *
 * 日報マン (プラグイン) のライセンスサーバから配信される最新テーマを、
 * WordPress 標準のテーマ更新フローに載せる。更新元 (API URL) とライセンス
 * キーは、日報マンが wp_options に保存している値をそのまま利用するため、
 * テーマ単体での設定は不要。日報マンが導入・ライセンス設定済みであることが
 * 前提 (工務店には日報マンとセットで配布する想定)。
 *
 * 日報マン本体の DRWP_Updater と同じ自己ホスト実装で、外部ライブラリは
 * 同梱しない。ダウンロード URL に license_key / domain をクエリで載せ、
 * サーバ側 /api/theme/download がライセンス検証してから zip を返す。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Jijipom_Updater {

	/** リリース情報のキャッシュ (1h)。 */
	const TRANSIENT = 'jijipom_updater_release';
	const CACHE_TTL = HOUR_IN_SECONDS;

	public static function init() {
		add_filter( 'pre_set_site_transient_update_themes', array( __CLASS__, 'inject_update' ) );
		add_filter( 'themes_api', array( __CLASS__, 'themes_api' ), 20, 3 );
		add_filter( 'auto_update_theme', array( __CLASS__, 'enable_auto_update' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache' ), 10, 2 );
		// 「ダッシュボード → 更新」を開いた / 「更新を確認」を押したときは、
		// 自前のリリースキャッシュを捨ててから WP のチェックに入る。これを
		// しないと最大 CACHE_TTL の間、手動チェックでも新バージョンに
		// 気づけない。
		add_action( 'load-update-core.php', array( __CLASS__, 'clear_cache' ) );
	}

	/** テーマのディレクトリ名 (= 更新 transient のキー)。 */
	private static function slug() {
		return get_template();
	}

	private static function current_version() {
		$theme = wp_get_theme( self::slug() );
		return $theme->exists() ? (string) $theme->get( 'Version' ) : '';
	}

	/** 日報マンが保存しているライセンスサーバの URL。 */
	private static function api_url() {
		return rtrim( (string) get_option( 'drwp_license_api_url', '' ), '/' );
	}

	private static function domain() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return is_string( $host ) ? $host : '';
	}

	/**
	 * ライセンスサーバから最新リリース情報を取得 (キャッシュ付き)。
	 * 未設定 / 到達不能 / 不正レスポンス / ライセンス無効 (403) は null。
	 */
	public static function fetch_release( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$api = self::api_url();
		if ( '' === $api ) {
			return null;
		}

		$url = add_query_arg(
			array(
				'license_key' => rawurlencode( (string) get_option( 'drwp_license_key', '' ) ),
				'domain'      => rawurlencode( self::domain() ),
				'current'     => rawurlencode( self::current_version() ),
			),
			$api . '/api/theme/update'
		);

		$r = wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $r ) ) {
			return null;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( ! is_array( $data ) || empty( $data['version'] ) ) {
			return null;
		}

		$data += array(
			'package'      => '',
			'requires'     => '',
			'requires_php' => '',
			'tested'       => '',
			'homepage'     => '',
			'changelog'    => '',
		);
		// サーバは package を相対パスで返すので、API URL を前置して絶対化。
		if ( '' !== $data['package'] && 0 !== strpos( $data['package'], 'http' ) ) {
			$data['package'] = $api . '/' . ltrim( (string) $data['package'], '/' );
		}
		set_transient( self::TRANSIENT, $data, self::CACHE_TTL );
		return $data;
	}

	/**
	 * 更新チェック時のフック。手元より新しければ response に、そうで
	 * なければ no_update に積む (テーマ一覧の「自動更新」列を出すため)。
	 */
	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = self::fetch_release();
		if ( ! $release ) {
			return $transient;
		}

		$slug = self::slug();
		$new  = (string) $release['version'];
		$cur  = self::current_version();

		if ( version_compare( $new, $cur, '>' ) ) {
			$transient->response[ $slug ] = array(
				'theme'        => $slug,
				'new_version'  => $new,
				'url'          => (string) $release['homepage'],
				'package'      => (string) $release['package'],
				'requires'     => (string) $release['requires'],
				'requires_php' => (string) $release['requires_php'],
			);
			unset( $transient->no_update[ $slug ] );
		} else {
			$transient->no_update[ $slug ] = array(
				'theme'       => $slug,
				'new_version' => $cur,
				'url'         => (string) $release['homepage'],
				'package'     => (string) $release['package'],
			);
			unset( $transient->response[ $slug ] );
		}
		return $transient;
	}

	/**
	 * 「テーマの詳細」モーダル用のメタ情報 (変更履歴等)。
	 */
	public static function themes_api( $result, $action, $args ) {
		if ( 'theme_information' !== $action ) {
			return $result;
		}
		if ( empty( $args->slug ) || $args->slug !== self::slug() ) {
			return $result;
		}
		$release = self::fetch_release();
		if ( ! $release ) {
			return $result;
		}
		$theme = wp_get_theme( self::slug() );
		return (object) array(
			'name'          => $theme->exists() ? $theme->get( 'Name' ) : self::slug(),
			'slug'          => self::slug(),
			'version'       => (string) $release['version'],
			'requires'      => (string) $release['requires'],
			'requires_php'  => (string) $release['requires_php'],
			'homepage'      => (string) $release['homepage'],
			'download_link' => (string) $release['package'],
			'sections'      => array(
				'changelog' => (string) $release['changelog'],
			),
		);
	}

	/**
	 * このテーマを WP の自動更新対象にする。他テーマの判定には影響
	 * させない (自分の slug のときだけ true を返す)。
	 */
	public static function enable_auto_update( $update, $item ) {
		$slug = '';
		if ( is_object( $item ) && isset( $item->theme ) ) {
			$slug = $item->theme;
		} elseif ( is_array( $item ) && isset( $item['theme'] ) ) {
			$slug = $item['theme'];
		}
		if ( $slug === self::slug() ) {
			return true;
		}
		return $update;
	}

	/** 更新完了後 (or 手動チェック時) にリリースキャッシュを捨てる。 */
	public static function clear_cache( $upgrader = null, $data = array() ) {
		delete_transient( self::TRANSIENT );
	}
}

Jijipom_Updater::init();
