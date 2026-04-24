<?php
if (!defined('ABSPATH')) exit;

class DRWP_Audit {
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'drwp_audit_logs';
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
}
