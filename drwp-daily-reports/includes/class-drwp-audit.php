<?php
if (!defined('ABSPATH')) exit;

class DRWP_Audit {
    const OPT_RETENTION_DAYS = 'drwp_audit_retention_days';
    const DEFAULT_RETENTION_DAYS = 365;
    const CRON_HOOK = 'drwp_audit_purge_daily';

    public static function init() {
        // Daily cron to enforce the retention window. Scheduled lazily
        // here (not at activation time) so a freshly cloned dev
        // checkout that bypasses activation still self-arms after one
        // admin page load.
        add_action(self::CRON_HOOK, [__CLASS__, 'cron_purge']);
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
        add_action('admin_post_drwp_save_audit_retention', [__CLASS__, 'handle_save_retention']);
        add_action('admin_post_drwp_purge_audit_now',      [__CLASS__, 'handle_purge_now']);
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'drwp_audit_logs';
    }

    /**
     * Configured retention window in days. `0` is the explicit
     * "永久保存" sentinel so the operator can opt out of pruning
     * without us guessing intent from an empty value.
     */
    public static function retention_days() {
        $opt = get_option(self::OPT_RETENTION_DAYS, null);
        if ($opt === null || $opt === false || $opt === '') return self::DEFAULT_RETENTION_DAYS;
        return max(0, (int) $opt);
    }

    public static function events_map() {
        return [
            'report_created'           => __('日報作成', 'drwp-daily-reports'),
            'report_updated'           => __('日報更新', 'drwp-daily-reports'),
            'review_status_changed'    => __('レビュー状態変更', 'drwp-daily-reports'),
            'comment_added'            => __('コメント追加', 'drwp-daily-reports'),
            'photos_updated'           => __('写真更新', 'drwp-daily-reports'),
            'publish_settings_updated' => __('公開設定一括更新', 'drwp-daily-reports'),
            'post_created_from_report' => __('記事生成', 'drwp-daily-reports'),
            'post_resynced'            => __('記事再反映', 'drwp-daily-reports'),
            'audit_purged'             => __('操作履歴を自動削除', 'drwp-daily-reports'),
            'report_archived'          => __('日報をアーカイブ', 'drwp-daily-reports'),
            'report_restored'          => __('日報を復元', 'drwp-daily-reports'),
            'report_purged'            => __('日報を完全削除', 'drwp-daily-reports'),
        ];
    }

    public static function log($event, $message = '', $report_id = null, array $meta = []) {
        global $wpdb;
        $wpdb->insert(self::table(), [
            'report_id' => $report_id ? (int) $report_id : null,
            'user_id'   => (int) get_current_user_id(),
            'event'     => sanitize_key($event),
            'message'   => wp_strip_all_tags((string) $message),
            'meta_json' => $meta ? wp_json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
    }

    public static function for_report($report_id, $limit = 50) {
        global $wpdb;
        $report_id = (int) $report_id;
        $limit = max(1, (int) $limit);
        return $wpdb->get_results($wpdb->prepare(
            'SELECT a.*, u.display_name FROM ' . self::table() . ' a
             LEFT JOIN ' . $wpdb->users . ' u ON u.ID = a.user_id
             WHERE a.report_id = %d
             ORDER BY a.id DESC
             LIMIT %d',
            $report_id,
            $limit
        ));
    }

    protected static function build_where(array $args, array &$params) {
        global $wpdb;
        $where = ['1=1'];

        if (!empty($args['event'])) {
            $where[] = 'a.event = %s';
            $params[] = sanitize_key($args['event']);
        }
        if (!empty($args['report_id'])) {
            $where[] = 'a.report_id = %d';
            $params[] = (int) $args['report_id'];
        }
        if (!empty($args['user_id'])) {
            $where[] = 'a.user_id = %d';
            $params[] = (int) $args['user_id'];
        }
        if (!empty($args['date_from'])) {
            $where[] = 'a.created_at >= %s';
            $params[] = $args['date_from'] . ' 00:00:00';
        }
        if (!empty($args['date_to'])) {
            $where[] = 'a.created_at <= %s';
            $params[] = $args['date_to'] . ' 23:59:59';
        }
        if (!empty($args['search'])) {
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[] = '(a.message LIKE %s OR a.meta_json LIKE %s OR u.display_name LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        return implode(' AND ', $where);
    }

    public static function search(array $args = [], $limit = 50, $offset = 0) {
        global $wpdb;
        $params = [];
        $where = self::build_where($args, $params);

        $limit = max(1, (int) $limit);
        $offset = max(0, (int) $offset);

        $sql = 'SELECT a.*, u.display_name FROM ' . self::table() . ' a
                LEFT JOIN ' . $wpdb->users . ' u ON u.ID = a.user_id
                WHERE ' . $where . '
                ORDER BY a.id DESC
                LIMIT %d OFFSET %d';
        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    }

    public static function count(array $args = []) {
        global $wpdb;
        $params = [];
        $where = self::build_where($args, $params);
        $sql = 'SELECT COUNT(*) FROM ' . self::table() . ' a
                LEFT JOIN ' . $wpdb->users . ' u ON u.ID = a.user_id
                WHERE ' . $where;
        return $params
            ? (int) $wpdb->get_var($wpdb->prepare($sql, $params))
            : (int) $wpdb->get_var($sql);
    }

    /** Oldest stored row's created_at, or '' if the table is empty. */
    public static function oldest_at() {
        global $wpdb;
        return (string) $wpdb->get_var('SELECT MIN(created_at) FROM ' . self::table());
    }

    /**
     * Delete rows older than $days. Returns the number of rows
     * deleted. `$days <= 0` is a no-op so the "永久保存" setting can
     * safely call straight through.
     */
    public static function purge_older_than($days) {
        $days = (int) $days;
        if ($days <= 0) return 0;
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        return (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . self::table() . ' WHERE created_at < %s',
            $cutoff
        ));
    }

    /**
     * Cron handler — applies the stored retention setting. The audit
     * row we leave behind documents the cleanup itself so the
     * operator can see "this many were dropped on this day" without
     * having to dig through server logs.
     */
    public static function cron_purge() {
        $days = self::retention_days();
        if ($days <= 0) return;
        $deleted = self::purge_older_than($days);
        if ($deleted > 0) {
            self::log('audit_purged', sprintf(
                /* translators: 1: deleted row count, 2: retention day count */
                __('%1$d 件の古い操作履歴を自動削除しました（保存期間 %2$d 日）', 'drwp-daily-reports'),
                $deleted, $days
            ), null, ['days' => $days, 'deleted' => $deleted]);
        }
    }

    public static function handle_save_retention() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('権限がありません', 'drwp-daily-reports'));
        check_admin_referer('drwp_save_audit_retention');
        // 0 = 永久保存。負の値は禁止、上限は 10 年 (3650 日) — UI で
        // 入力するならこれ以上が必要になることはまずない。
        $days = isset($_POST['retention_days']) ? max(0, (int) $_POST['retention_days']) : self::DEFAULT_RETENTION_DAYS;
        if ($days > 3650) $days = 3650;
        update_option(self::OPT_RETENTION_DAYS, $days);
        self::log('audit_retention_changed', sprintf(
            __('操作履歴の保存期間を %d 日に変更', 'drwp-daily-reports'),
            $days
        ), null, ['days' => $days]);
        wp_safe_redirect(admin_url('admin.php?page=drwp_audit&retention_saved=1'));
        exit;
    }

    public static function handle_purge_now() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('権限がありません', 'drwp-daily-reports'));
        check_admin_referer('drwp_purge_audit_now');
        $days = self::retention_days();
        $deleted = $days > 0 ? self::purge_older_than($days) : 0;
        if ($deleted > 0) {
            self::log('audit_purged', sprintf(
                __('手動実行で %1$d 件を削除（保存期間 %2$d 日）', 'drwp-daily-reports'),
                $deleted, $days
            ), null, ['days' => $days, 'deleted' => $deleted, 'manual' => true]);
        }
        wp_safe_redirect(admin_url('admin.php?page=drwp_audit&purged=' . $deleted));
        exit;
    }
}
