<?php
if (!defined('ABSPATH')) exit;

class DRWP_Audit {
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'drwp_audit_logs';
    }

    public static function events_map() {
        return [
            'report_created'           => '日報作成',
            'report_updated'           => '日報更新',
            'review_status_changed'    => 'レビュー状態変更',
            'comment_added'            => 'コメント追加',
            'photos_updated'           => '写真更新',
            'publish_settings_updated' => '公開設定一括更新',
            'post_created_from_report' => '記事生成',
            'post_resynced'            => '記事再反映',
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
}
