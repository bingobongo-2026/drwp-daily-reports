<?php
if (!defined('ABSPATH')) exit;

/**
 * 使い方解説ページ。サイドバーから誰でも開けるシンプルな単一ページ
 * 構成のマニュアル。タブ切替は `?tab=` クエリで持つので、特定セク
 * ションをメールや社内 wiki に直リンクできる。
 */
class DRWP_Help {
    const SLUG = 'drwp_help';

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

        $tabs    = self::tabs();
        $current = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'intro';
        if (!isset($tabs[$current])) $current = 'intro';

        $base_url = admin_url('admin.php?page=' . self::SLUG);

        include DRWP_PATH . 'admin/views/help-page.php';
    }
}
