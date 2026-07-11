<?php
if (!defined('ABSPATH')) exit;

/**
 * 日報マン本体の自動アップデート (plugin-update-checker 相当の仕組み)。
 *
 * WordPress.org には載せない (ライセンス制の商用プラグイン) ため、更新元を
 * 自前のライセンスサーバに向ける。外部ライブラリは同梱せず、WP 標準の更新
 * フックに直接ぶら下がる軽量な自己ホスト実装。plugin-update-checker と同じ
 * 動きをする:
 *   1. pre_set_site_transient_update_plugins — WP の更新チェック時に
 *      ライセンスサーバの /api/plugin/update へ問い合わせ、サーバの版が
 *      手元より新しければ「更新あり」を transient に注入する。
 *   2. plugins_api — 「詳細を表示」モーダル用のメタ情報 (変更履歴等)。
 *   3. auto_update_plugin — 日報マンを WP の自動更新対象にする。
 *   4. upgrader_process_complete — 更新完了後にキャッシュを捨てる。
 *
 * ダウンロード URL には license_key と domain をクエリで載せる。WP の
 * アップグレーダは package URL を素の GET で取りに行く (認証ヘッダを
 * 付けられない) ため、認証はクエリで行い、サーバ側 /api/plugin/download
 * がライセンス検証してから zip を返す。
 */
class DRWP_Updater {

    /** 取得したリリース情報のキャッシュ (1h)。 */
    const TRANSIENT = 'drwp_updater_release';
    const CACHE_TTL = HOUR_IN_SECONDS;

    public static function init() {
        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'inject_update']);
        add_filter('plugins_api', [__CLASS__, 'plugins_api'], 20, 3);
        add_filter('auto_update_plugin', [__CLASS__, 'enable_auto_update'], 10, 2);
        add_action('upgrader_process_complete', [__CLASS__, 'clear_cache'], 10, 2);
        // 「ダッシュボード → 更新」を開いた / 「更新を確認」を押したときは、
        // 自前のリリースキャッシュを捨ててから WP のチェックに入るよう
        // にする。これをしないと最大 CACHE_TTL の間、新バージョンを
        // アップしても手動チェックで気づけない。
        add_action('load-update-core.php', [__CLASS__, 'clear_cache']);
    }

    /** `drwp-daily-reports/drwp-daily-reports.php` を返す。 */
    private static function basename() {
        return plugin_basename(DRWP_PATH . 'drwp-daily-reports.php');
    }

    /** ディレクトリ名スラッグ。 */
    private static function slug() {
        return 'drwp-daily-reports';
    }

    private static function domain() {
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        return is_string($host) ? $host : '';
    }

    /**
     * ライセンスサーバから最新リリース情報を取得 (キャッシュ付き)。
     * 失敗時 (未設定 / 到達不能 / 不正レスポンス) は null。
     *
     * @param bool $force キャッシュを無視して取り直すか
     * @return array{version:string,package:string,...}|null
     */
    public static function fetch_release($force = false) {
        if (!$force) {
            $cached = get_transient(self::TRANSIENT);
            if (is_array($cached)) return $cached;
        }
        $api_url = rtrim((string) get_option(DRWP_License::OPT_API_URL, ''), '/');
        if ($api_url === '') return null;

        $url = add_query_arg([
            'license_key' => (string) get_option(DRWP_License::OPT_KEY, ''),
            'domain'      => self::domain(),
            'current'     => DRWP_VERSION,
        ], $api_url . '/api/plugin/update');

        $r = wp_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($r)) return null;
        if ((int) wp_remote_retrieve_response_code($r) !== 200) return null;
        $data = json_decode(wp_remote_retrieve_body($r), true);
        if (!is_array($data) || empty($data['version'])) return null;

        $data += [
            'package'      => '',
            'requires'     => '',
            'requires_php' => '',
            'tested'       => '',
            'homepage'     => '',
            'changelog'    => '',
        ];
        // サーバは package を相対パス (/api/plugin/download?...) で返す。
        // WP のアップグレーダは package を絶対 URL として取得するので、
        // ライセンスサーバの URL を前置して絶対化する。
        if ($data['package'] !== '' && strpos($data['package'], 'http') !== 0) {
            $data['package'] = $api_url . '/' . ltrim((string) $data['package'], '/');
        }
        set_transient(self::TRANSIENT, $data, self::CACHE_TTL);
        return $data;
    }

    /**
     * 更新チェック時のフック。手元より新しければ response に、そう
     * でなければ no_update に積む (no_update に入れておくと管理画面の
     * 「自動更新を有効化」列がこのプラグインにも出る)。
     */
    public static function inject_update($transient) {
        if (!is_object($transient)) return $transient;
        // WP がまだプラグイン一覧を集計していない段階では何もしない。
        if (empty($transient->checked)) return $transient;

        $release = self::fetch_release();
        if (!$release) return $transient;

        $basename = self::basename();
        $new      = (string) $release['version'];
        $obj = (object) [
            'slug'         => self::slug(),
            'plugin'       => $basename,
            'new_version'  => $new,
            'url'          => (string) $release['homepage'],
            'package'      => (string) $release['package'],
            'tested'       => (string) $release['tested'],
            'requires'     => (string) $release['requires'],
            'requires_php' => (string) $release['requires_php'],
            'icons'        => [],
            'banners'      => [],
            'banners_rtl'  => [],
        ];

        if (version_compare($new, DRWP_VERSION, '>')) {
            $transient->response[$basename] = $obj;
            unset($transient->no_update[$basename]);
        } else {
            $obj->new_version = DRWP_VERSION;
            $transient->no_update[$basename] = $obj;
            unset($transient->response[$basename]);
        }
        return $transient;
    }

    /**
     * 「詳細を表示」モーダル用のメタ情報。
     */
    public static function plugins_api($result, $action, $args) {
        if ($action !== 'plugin_information') return $result;
        if (empty($args->slug) || $args->slug !== self::slug()) return $result;

        $release = self::fetch_release();
        if (!$release) return $result;

        return (object) [
            'name'          => '日報マン',
            'slug'          => self::slug(),
            'version'       => (string) $release['version'],
            'requires'      => (string) $release['requires'],
            'tested'        => (string) $release['tested'],
            'requires_php'  => (string) $release['requires_php'],
            'homepage'      => (string) $release['homepage'],
            'download_link' => (string) $release['package'],
            'sections'      => [
                'changelog' => (string) $release['changelog'],
            ],
        ];
    }

    /**
     * 日報マンを WP の自動更新対象にする。他プラグインの判定には影響
     * させない (自分の basename のときだけ true を返す)。
     */
    public static function enable_auto_update($update, $item) {
        if (is_object($item) && isset($item->plugin) && $item->plugin === self::basename()) {
            return true;
        }
        return $update;
    }

    /** 更新完了後 (or 手動チェック時) にリリースキャッシュを捨てる。 */
    public static function clear_cache($upgrader = null, $data = []) {
        delete_transient(self::TRANSIENT);
    }
}
