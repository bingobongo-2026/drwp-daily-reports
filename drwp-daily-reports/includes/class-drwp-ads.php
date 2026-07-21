<?php
if (!defined('ABSPATH')) exit;

/**
 * フリープラン向け AdSense 表示。
 *
 * フリープランで稼働しているサイトの「日報から生成された公開記事」に、
 * 運営 (ベンダー) の AdSense アカウントで広告を出す。広告設定 (publisher
 * ID / 広告ユニット / 位置) はライセンスサーバが署名付き /api/check 応答で
 * 配信し、DRWP_License::adsense_config() がキャッシュを返す。
 *
 * 表示条件 (すべて満たしたときだけ広告を出す):
 *   - プランが free
 *   - サーバから配信された adsense 設定が有効 (enabled + 妥当な pub-id)
 *   - 表示中がプラグイン生成の公開記事 (単一表示・メインクエリ)
 *
 * publisher ID だけで自動広告 (Auto ads) が有効になり、広告ユニット ID
 * (ad_slot) を設定すると本文の指定位置に固定のディスプレイ広告枠も出す。
 */
class DRWP_Ads {

    const LOADER_SRC = 'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js';

    /** 同一リクエスト内で「プラグイン記事か」の判定をキャッシュ。 */
    private static $is_plugin_post_cache = [];

    public static function init() {
        add_filter('the_content', [__CLASS__, 'inject_unit'], 20);
        add_action('wp_head', [__CLASS__, 'print_loader'], 20);
        // 各サイトの /ads.txt を運営の publisher ID で応答 (物理ファイルが
        // 無いときのみ)。AdSense の収益化に必要。
        add_action('init', [__CLASS__, 'maybe_serve_ads_txt']);
    }

    /**
     * 表示すべき AdSense 設定 (フリー + 有効時)。出さないなら null。
     */
    public static function config() {
        if (!function_exists('is_admin') || is_admin()) return null;
        return DRWP_License::adsense_config();
    }

    /**
     * `<head>` に AdSense ローダーを出す。プラグイン記事の単一表示のみ。
     * これだけで自動広告が有効になり、固定ユニットの描画にも必要。
     */
    public static function print_loader() {
        $cfg = self::config();
        if (!$cfg) return;
        if (!is_singular() || !self::is_plugin_post(get_queried_object_id())) return;

        printf(
            "\n<!-- 日報マン: フリープラン AdSense -->\n"
            . '<script async src="%s?client=%s" crossorigin="anonymous"></script>' . "\n",
            esc_url(self::LOADER_SRC),
            esc_attr($cfg['publisher_id'])
        );
    }

    /**
     * 本文に固定広告ユニットを差し込む。ad_slot 未設定なら (自動広告に
     * 任せて) 本文はそのまま返す。
     */
    public static function inject_unit($content) {
        if (!in_the_loop() || !is_main_query() || !is_singular()) return $content;
        $cfg = self::config();
        if (!$cfg || $cfg['ad_slot'] === '') return $content;
        if (!self::is_plugin_post(get_the_ID())) return $content;

        $unit = self::unit_html($cfg);
        switch ($cfg['placement']) {
            case 'before': return $unit . $content;
            case 'both':   return $unit . $content . $unit;
            case 'after':
            default:       return $content . $unit;
        }
    }

    /** 単一ディスプレイ広告ユニットの HTML。 */
    private static function unit_html($cfg) {
        return sprintf(
            '<div class="drwp-adsense" style="margin:26px 0;text-align:center;">'
            . '<ins class="adsbygoogle" style="display:block" data-ad-client="%1$s" '
            . 'data-ad-slot="%2$s" data-ad-format="auto" data-full-width-responsive="true"></ins>'
            . '<script>(adsbygoogle = window.adsbygoogle || []).push({});</script>'
            . '</div>',
            esc_attr($cfg['publisher_id']),
            esc_attr($cfg['ad_slot'])
        );
    }

    /**
     * その投稿が日報から生成されたプラグイン記事か。CPT 出力でも通常
     * 投稿出力でも、reports テーブルの linked_post_id で正確に判定する。
     */
    private static function is_plugin_post($post_id) {
        $post_id = (int) $post_id;
        if ($post_id <= 0) return false;
        if (isset(self::$is_plugin_post_cache[$post_id])) {
            return self::$is_plugin_post_cache[$post_id];
        }
        $result = false;
        $pt = get_post_type($post_id);
        if (in_array($pt, DRWP_Output::ALLOWED_TYPES, true)) {
            global $wpdb;
            $table = $wpdb->prefix . 'drwp_reports';
            $found = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE linked_post_id = %d LIMIT 1", $post_id
            ));
            $result = !empty($found);
        }
        self::$is_plugin_post_cache[$post_id] = $result;
        return $result;
    }

    /**
     * /ads.txt を運営の publisher ID で応答する。物理 ads.txt が既に
     * あるサイトでは何もしない (そちらを優先)。
     */
    public static function maybe_serve_ads_txt() {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        if ($path !== '/ads.txt') return;
        $cfg = DRWP_License::adsense_config();  // is_admin ゲート不要 (フロント要求)
        if (!$cfg) return;
        if (@file_exists(ABSPATH . 'ads.txt')) return;

        // ca-pub-XXXX -> pub-XXXX (ads.txt はハイフン以降の pub-～ を使う)
        $pub = preg_replace('/^ca-/', '', $cfg['publisher_id']);
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo 'google.com, ' . $pub . ', DIRECT, f08c47fec0942fa0' . "\n";
        exit;
    }
}
