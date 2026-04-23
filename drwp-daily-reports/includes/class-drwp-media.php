<?php
if (!defined('ABSPATH')) exit;

class DRWP_Media {
    public static function save_report_photos($report_id, $attachment_ids = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'drwp_report_photos';
        $wpdb->delete($table, ['report_id' => intval($report_id)]);
        $order = 0;
        foreach ($attachment_ids as $id) {
            $id = intval($id);
            if (!$id) continue;
            $wpdb->insert($table, [
                'report_id' => intval($report_id),
                'attachment_id' => $id,
                'sort_order' => $order++,
                'created_at' => current_time('mysql'),
            ]);
        }
    }

    public static function get_report_photos($report_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'drwp_report_photos';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE report_id = %d ORDER BY sort_order ASC, id ASC", $report_id), ARRAY_A);
    }
}
