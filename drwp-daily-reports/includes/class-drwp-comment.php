<?php
if (!defined('ABSPATH')) exit;

class DRWP_Comment {
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'drwp_comments';
    }

    public static function insert($report_id, $body) {
        global $wpdb;
        $body = trim(wp_kses_post(wp_unslash($body)));
        if ($body === '') return 0;
        $wpdb->insert(self::table(), [
            'report_id' => (int) $report_id,
            'user_id'   => (int) get_current_user_id(),
            'body'      => $body,
        ]);
        return (int) $wpdb->insert_id;
    }

    public static function for_report($report_id, $limit = 100) {
        global $wpdb;
        $report_id = (int) $report_id;
        $limit = max(1, (int) $limit);
        return $wpdb->get_results($wpdb->prepare(
            'SELECT c.*, u.display_name FROM ' . self::table() . ' c
             LEFT JOIN ' . $wpdb->users . ' u ON u.ID = c.user_id
             WHERE c.report_id = %d
             ORDER BY c.id ASC
             LIMIT %d',
            $report_id,
            $limit
        ));
    }
}
