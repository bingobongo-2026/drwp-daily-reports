<?php
if (!defined('ABSPATH')) exit;

/**
 * 使い方解説ページ。サイドバーから誰でも開けるシンプルな単一ページ
 * 構成のマニュアル。タブ切替は `?tab=` クエリで持つので、特定セク
 * ションをメールや社内 wiki に直リンクできる。`?print=1` を付けると
 * 全タブを連結した印刷用ビューに切り替わり、自動で印刷ダイアログを
 * 開く（既存の DRWP_Print と同じく「ブラウザで PDF として保存」する
 * 方式 — 日本語フォントの埋め込み問題を避けるため）。
 */
class DRWP_Help {
    const SLUG = 'drwp_help';

    public static function init() {
        // 印刷ビューは <!doctype html> から始まる完全な HTML 文書。
        // admin_init で割り込んで、wp-admin のヘッダ／サイドバーが
        // 出力される前にレスポンスを差し替える。
        add_action('admin_init', [__CLASS__, 'maybe_render_print']);
    }

    public static function maybe_render_print() {
        if (empty($_GET['page']) || $_GET['page'] !== self::SLUG) return;
        if (empty($_GET['print'])) return;
        if (!current_user_can(DRWP_Admin::CAP_EDIT)) return;

        $tabs     = self::tabs();
        $base_url = admin_url('admin.php?page=' . self::SLUG);
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        include DRWP_PATH . 'admin/views/help-print.php';
        exit;
    }

    public static function tabs() {
        return [
            'intro'   => __('はじめに', 'drwp-daily-reports'),
            'report'  => __('日報を書く', 'drwp-daily-reports'),
            'review'  => __('レビュー', 'drwp-daily-reports'),
            'publish' => __('公開記事化', 'drwp-daily-reports'),
            'plans'   => __('予定', 'drwp-daily-reports'),
            'master'  => __('案件・顧客', 'drwp-daily-reports'),
            'ai'      => __('AI機能', 'drwp-daily-reports'),
            'admin'   => __('管理者向け設定', 'drwp-daily-reports'),
            'faq'     => __('よくある質問', 'drwp-daily-reports'),
        ];
    }

    public static function render_page() {
        if (!current_user_can(DRWP_Admin::CAP_EDIT)) {
            wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        }

        $tabs     = self::tabs();
        $current  = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'intro';
        if (!isset($tabs[$current])) $current = 'intro';
        $base_url = admin_url('admin.php?page=' . self::SLUG);

        include DRWP_PATH . 'admin/views/help-page.php';
    }

    /**
     * Render a single help section by slug. Shared between the tabbed
     * admin view and the print/PDF view so content stays in one place.
     */
    public static function render_section($slug) {
        include DRWP_PATH . 'admin/views/help-sections.php';
    }
}
