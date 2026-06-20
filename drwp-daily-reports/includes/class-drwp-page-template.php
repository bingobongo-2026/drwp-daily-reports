<?php
if (!defined('ABSPATH')) exit;

/**
 * 日報マン専用の固定ページテンプレート登録。
 *
 * テーマの header.php / footer.php / sidebar.php を完全に迂回した
 * フルワイドのレイアウトを「ページ属性 → テンプレート」のドロップ
 * ダウンに追加する。テーマ側のファイルには一切触らない。
 *
 * 動作原理は WP 標準の 2 つのフィルタを束ねるだけ:
 *   1. `theme_page_templates` で `Page Template` ドロップダウンに
 *      表示名を足す
 *   2. `template_include` でその選択された値の時だけ、自前の
 *      テンプレートファイル (`public/templates/full-width.php`) を
 *      返す
 *
 * 「子テーマを作らないと無理」は古い常識で、プラグインからこの
 * 仕組みを使うのが現代的な作法。
 */
class DRWP_Page_Template {
    /** ページ属性ドロップダウン上の値。WP コアが post meta `_wp_page_template`
     *  に保存する識別子で、ファイル名に縛りはないが拡張子は .php にする。 */
    const SLUG = 'drwp-fullwidth.php';

    public static function init() {
        add_filter('theme_page_templates', [__CLASS__, 'register']);
        add_filter('template_include',     [__CLASS__, 'use_template']);
    }

    /** ページ編集画面の「テンプレート」プルダウンに項目を増やす。 */
    public static function register($templates) {
        $templates[self::SLUG] = __('日報マン フルワイド (ヘッダー/サイドバー無し)', 'drwp-daily-reports');
        return $templates;
    }

    /**
     * 表示直前のテンプレート差し替え。固定ページかつ
     * `_wp_page_template = drwp-fullwidth.php` の時だけ、プラグイン
     * 同梱のテンプレートファイルへ向ける。それ以外は素通し。
     */
    public static function use_template($default_template) {
        if (!is_page()) return $default_template;
        $page_id = (int) get_queried_object_id();
        if (!$page_id) return $default_template;
        $selected = (string) get_post_meta($page_id, '_wp_page_template', true);
        if ($selected !== self::SLUG) return $default_template;
        $path = DRWP_PATH . 'public/templates/full-width.php';
        return file_exists($path) ? $path : $default_template;
    }
}
