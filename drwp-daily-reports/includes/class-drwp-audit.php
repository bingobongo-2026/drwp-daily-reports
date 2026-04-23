<?php
if (!defined('ABSPATH')) exit;

class DRWP_Audit {
    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'drwp_audit_logs';
    }

    public static function log($action, $message = '', $report_id = null, $meta = []) {
        global $wpdb;
        $table = self::table_name();
        $wpdb->insert($table, [
            'report_id' => $report_id ? intval($report_id) : null,
            'user_id' => get_current_user_id() ?: 0,
            'action' => sanitize_key($action),
            'message' => wp_strip_all_tags($message),
            'meta_json' => !empty($meta) ? wp_json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'created_at' => current_time('mysql'),
        ]);
    }

    public static function can_view() {
        return current_user_can('manage_options') || current_user_can('drwp_view_audit_logs');
    }

    protected static function build_where($args, &$params) {
        $where = ['1=1'];

        if (!empty($args['report_id'])) {
            $where[] = 'l.report_id = %d';
            $params[] = intval($args['report_id']);
        }
        if (!empty($args['action'])) {
            $where[] = 'l.action = %s';
            $params[] = sanitize_key($args['action']);
        }
        if (!empty($args['user_id'])) {
            $where[] = 'l.user_id = %d';
            $params[] = intval($args['user_id']);
        }
        if (!empty($args['search'])) {
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[] = '(l.message LIKE %s OR l.meta_json LIKE %s OR u.display_name LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        return $where;
    }

    public static function count_logs($args = []) {
        global $wpdb;
        $table = self::table_name();
        $users = $wpdb->users;
        $params = [];
        $where = self::build_where($args, $params);

        $sql = "SELECT COUNT(*) FROM $table l LEFT JOIN $users u ON u.ID = l.user_id WHERE " . implode(' AND ', $where);
        if (!empty($params)) {
            return intval($wpdb->get_var($wpdb->prepare($sql, ...$params)));
        }
        return intval($wpdb->get_var($sql));
    }

    public static function get_logs($args = []) {
        global $wpdb;
        $table = self::table_name();
        $users = $wpdb->users;
        $reports = $wpdb->prefix . 'drwp_reports';

        $params = [];
        $where = self::build_where($args, $params);

        $limit = !empty($args['limit']) ? max(1, intval($args['limit'])) : 50;
        $offset = !empty($args['offset']) ? max(0, intval($args['offset'])) : 0;

        $sql = "SELECT l.*, u.display_name, r.report_date
                FROM $table l
                LEFT JOIN $users u ON u.ID = l.user_id
                LEFT JOIN $reports r ON r.id = l.report_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY l.id DESC
                LIMIT %d OFFSET %d";

        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
    }

    public static function get_report_logs($report_id, $limit = 20) {
        return self::get_logs([
            'report_id' => intval($report_id),
            'limit' => intval($limit),
            'offset' => 0,
        ]);
    }
}
