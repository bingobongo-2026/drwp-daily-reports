<?php
/**
 * Plugin Name: jijipom コンテンツビルダー
 * Plugin URI: https://nippoman.example.com/
 * Description: jijipom テーマ向けのコンテンツを画面上で組み立て、ZIP でエクスポート。ZIP を取り込むと各ページの内容(カスタマイザー設定)と固定ページがまとめて反映されます。
 * Version: 1.0.0
 * Author: jijipom
 * Text Domain: jijipom-content-builder
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 *
 * ビルダー UI 自体は assets/builder.html (自己完結・iframe 読み込み) で、
 * 入力内容は ZIP(jijipom-content.json) にエクスポートされます。取り込みは
 * このプラグインがサーバー側で jijipom のテーマ設定(set_theme_mod)と
 * 固定ページへ適用します。テーマ本体の改修は不要です。
 */

if (!defined('ABSPATH')) exit;

define('JCB_VERSION', '1.0.0');
define('JCB_PATH', plugin_dir_path(__FILE__));
define('JCB_URL', plugin_dir_url(__FILE__));

class JCB_Plugin {

    const CAP = 'edit_theme_options';
    const MENU_SLUG = 'jijipom-content-builder';

    /** 対応テーマ (この名前が有効テーマ or その親であること)。 */
    const THEME = 'jijipom';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_jcb_import', [__CLASS__, 'handle_import']);
    }

    public static function menu() {
        add_menu_page(
            __('jijipom コンテンツ', 'jijipom-content-builder'),
            __('jijipom コンテンツ', 'jijipom-content-builder'),
            self::CAP,
            self::MENU_SLUG,
            [__CLASS__, 'render_page'],
            'dashicons-layout',
            59
        );
    }

    public static function theme_ok() {
        return get_template() === self::THEME || get_stylesheet() === self::THEME;
    }

    public static function render_page() {
        if (!current_user_can(self::CAP)) wp_die(esc_html__('権限がありません', 'jijipom-content-builder'));
        include JCB_PATH . 'admin/page.php';
    }

    /* =====================================================================
     * 取り込み (ZIP → jijipom 設定 + 固定ページ)
     * ===================================================================== */

    public static function handle_import() {
        if (!current_user_can(self::CAP)) wp_die(esc_html__('権限がありません', 'jijipom-content-builder'));
        check_admin_referer('jcb_import');

        $back = admin_url('admin.php?page=' . self::MENU_SLUG);

        if (!self::theme_ok()) {
            wp_safe_redirect(add_query_arg('jcb_err', 'no_theme', $back));
            exit;
        }
        if (empty($_FILES['jcb_zip']['tmp_name']) || !is_uploaded_file($_FILES['jcb_zip']['tmp_name'])) {
            wp_safe_redirect(add_query_arg('jcb_err', 'no_file', $back));
            exit;
        }

        $json = self::read_json_from_upload($_FILES['jcb_zip']['tmp_name']);
        if ($json === null) {
            wp_safe_redirect(add_query_arg('jcb_err', 'bad_zip', $back));
            exit;
        }
        $doc = json_decode($json, true);
        if (!is_array($doc) || ($doc['schema'] ?? '') !== 'jijipom-content') {
            wp_safe_redirect(add_query_arg('jcb_err', 'bad_json', $back));
            exit;
        }

        $mods = self::apply_theme_mods(isset($doc['theme_mods']) && is_array($doc['theme_mods']) ? $doc['theme_mods'] : []);

        $pages = 0;
        if (!empty($_POST['jcb_create_pages'])) {
            $pages = self::apply_pages(
                isset($doc['pages']) && is_array($doc['pages']) ? $doc['pages'] : [],
                !empty($_POST['jcb_set_front'])
            );
        }

        wp_safe_redirect(add_query_arg(['jcb_done' => 1, 'jcb_mods' => $mods, 'jcb_pages' => $pages], $back));
        exit;
    }

    /** アップロードされた ZIP から jijipom-content.json を取り出す。生 JSON も許容。 */
    private static function read_json_from_upload($tmp) {
        // まず ZIP として開く
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($tmp) === true) {
                $data = $zip->getFromName('jijipom-content.json');
                $zip->close();
                if ($data !== false) return $data;
            }
        }
        // フォールバック: 中身が JSON そのものなら受け付ける
        $raw = file_get_contents($tmp);
        if (is_string($raw) && strpos(ltrim($raw), '{') === 0) return $raw;
        return null;
    }

    /** 取り込み対象のテーマ設定キーと型 (ホワイトリスト)。 */
    private static function mod_schema() {
        $s = [
            'jijipom_hero_type'        => 'select_hero',
            'jijipom_hero_image'       => 'url',
            'jijipom_hero_video'       => 'url',
            'jijipom_hero_youtube'     => 'text',
            'jijipom_hero_title'       => 'textarea',
            'jijipom_hero_subtitle'    => 'textarea',
            'jijipom_hero_button_text' => 'text',
            'jijipom_hero_button_url'  => 'url',
            'jijipom_service_enable'   => 'bool',
            'jijipom_service_text'     => 'textarea',
            'jijipom_service_button_text' => 'text',
            'jijipom_service_button_url'  => 'url',
            'jijipom_service_image'    => 'url',
            'jijipom_blog_heading'     => 'text',
            'jijipom_about_heading'    => 'text',
            'jijipom_svc_lead'         => 'textarea',
            'jijipom_svc_items_heading' => 'text',
            'jijipom_svc_feature_heading' => 'text',
            'jijipom_svc_feature_text' => 'textarea',
            'jijipom_svc_cta_heading'  => 'text',
            'jijipom_svc_cta_button_text' => 'text',
            'jijipom_svc_cta_button_url'  => 'url',
            'jijipom_company_lead'     => 'textarea',
            'jijipom_company_greeting_text'  => 'textarea',
            'jijipom_company_greeting_name'  => 'text',
            'jijipom_company_greeting_image' => 'url',
            'jijipom_company_access_address' => 'textarea',
            'jijipom_company_access_hours'   => 'text',
            'jijipom_company_access_holiday' => 'text',
            'jijipom_company_map_url'  => 'url',
            'jijipom_contact_lead'     => 'textarea',
            'jijipom_contact_tel'      => 'text',
            'jijipom_contact_tel_note' => 'text',
            'jijipom_contact_email'    => 'text',
            'jijipom_contact_hours'    => 'text',
            'jijipom_contact_holiday'  => 'text',
            'jijipom_contact_area'     => 'textarea',
            'jijipom_contact_map_url'  => 'url',
            'jijipom_privacy_intro'    => 'textarea',
            'jijipom_privacy_operator' => 'text',
            'jijipom_privacy_established' => 'text',
        ];
        for ($n = 1; $n <= 3; $n++) {
            $s["jijipom_about_{$n}_title"] = 'text';
            $s["jijipom_about_{$n}_text"]  = 'textarea';
            $s["jijipom_about_{$n}_image"] = 'url';
        }
        for ($n = 1; $n <= 4; $n++) {
            $s["jijipom_svc_item{$n}_title"] = 'text';
            $s["jijipom_svc_item{$n}_text"]  = 'textarea';
            $s["jijipom_svc_item{$n}_image"] = 'url';
        }
        for ($n = 1; $n <= 8; $n++) {
            $s["jijipom_company_row{$n}_label"] = 'text';
            $s["jijipom_company_row{$n}_value"] = 'textarea';
        }
        return $s;
    }

    private static function sanitize_mod($type, $value) {
        switch ($type) {
            case 'url':      return esc_url_raw((string) $value);
            case 'textarea': return sanitize_textarea_field((string) $value);
            case 'bool':     return !empty($value) && $value !== 'false' && $value !== '0';
            case 'select_hero':
                $v = sanitize_text_field((string) $value);
                return in_array($v, ['image', 'video', 'youtube'], true) ? $v : 'image';
            case 'text':
            default:         return sanitize_text_field((string) $value);
        }
    }

    /** ホワイトリストのキーだけを set_theme_mod で適用。適用件数を返す。 */
    private static function apply_theme_mods(array $incoming) {
        $schema = self::mod_schema();
        $count = 0;
        foreach ($schema as $key => $type) {
            if (!array_key_exists($key, $incoming)) continue;
            set_theme_mod($key, self::sanitize_mod($type, $incoming[$key]));
            $count++;
        }
        return $count;
    }

    /** 固定ページの作成/更新 + トップページ設定。作成/更新した件数を返す。 */
    private static function apply_pages(array $pages, $set_front) {
        $templates = [
            'service' => 'templates/page-service.php',
            'company' => 'templates/page-company.php',
            'contact' => 'templates/page-contact.php',
            'privacy' => 'templates/page-privacy.php',
        ];
        $count = 0;
        $home_id = 0;

        foreach (['home', 'service', 'company', 'contact', 'privacy'] as $key) {
            if (empty($pages[$key]) || !is_array($pages[$key])) continue;
            $title = isset($pages[$key]['title']) ? sanitize_text_field((string) $pages[$key]['title']) : '';
            if ($title === '') $title = ucfirst($key);
            $content = isset($pages[$key]['content']) ? wp_kses_post((string) $pages[$key]['content']) : '';
            $tpl = $templates[$key] ?? '';
            $id = self::ensure_page($key, $title, $tpl, $content);
            if ($id) {
                $count++;
                if ($key === 'home') $home_id = $id;
            }
        }

        if ($set_front && $home_id) {
            update_option('show_on_front', 'page');
            update_option('page_on_front', $home_id);
        }
        return $count;
    }

    /**
     * `_jcb_page` メタで管理する固定ページを 1 つ用意する。既にあれば
     * タイトル(+本文があれば本文)を更新し、テンプレートを設定する。
     */
    private static function ensure_page($slug_key, $title, $template, $content) {
        $existing = get_posts([
            'post_type'   => 'page',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'numberposts' => 1,
            'fields'      => 'ids',
            'meta_key'    => '_jcb_page',
            'meta_value'  => $slug_key,
        ]);

        $postarr = [
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => $title,
        ];
        // 本文はビルダーで入力があるときだけ上書きする(手編集を壊さない)。
        if ($content !== '') $postarr['post_content'] = $content;

        if (!empty($existing)) {
            $postarr['ID'] = (int) $existing[0];
            $id = wp_update_post($postarr, true);
        } else {
            $id = wp_insert_post($postarr, true);
        }
        if (is_wp_error($id) || !$id) return 0;
        $id = (int) $id;

        update_post_meta($id, '_jcb_page', $slug_key);
        if ($template !== '') {
            update_post_meta($id, '_wp_page_template', $template);
        }
        return $id;
    }
}

JCB_Plugin::init();
