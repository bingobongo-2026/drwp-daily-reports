<?php
if (!defined('ABSPATH')) exit;

/**
 * Printable / PDF-ready view of reports.
 *
 * Renders a clean print layout with optional filters (date range,
 * project, review status, individual ids). Users press the print
 * button and "Save as PDF" from the browser dialog — no PHP PDF
 * library involved, so no font/encoding headaches for Japanese.
 */
class DRWP_Print {
    const CAP = 'edit_posts';

    public static function init() {
        // No-op; the page is rendered via DRWP_Admin::menu() submenu callback.
    }

    public static function render_page() {
        if (!current_user_can(self::CAP)) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';

        $filters = [
            'date_from'  => isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '',
            'date_to'    => isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '',
            'project_id' => isset($_GET['project_id']) ? absint($_GET['project_id']) : 0,
            'ids'        => isset($_GET['ids']) ? sanitize_text_field(wp_unslash($_GET['ids'])) : '',
            'go'         => !empty($_GET['go']),
        ];

        $reports = [];
        if ($filters['go']) {
            $where = "review_status = 'approved'";
            $args = [];
            if (!current_user_can('edit_others_posts')) {
                $where .= ' AND user_id = %d';
                $args[] = get_current_user_id();
            }
            if ($filters['ids'] !== '') {
                $ids = array_values(array_filter(array_map('absint', preg_split('/[\s,]+/', $filters['ids']))));
                if ($ids) {
                    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                    $where .= " AND id IN ($placeholders)";
                    foreach ($ids as $id) $args[] = $id;
                }
            } else {
                if ($filters['date_from'] !== '') { $where .= ' AND report_date >= %s'; $args[] = $filters['date_from']; }
                if ($filters['date_to'] !== '')   { $where .= ' AND report_date <= %s'; $args[] = $filters['date_to']; }
                if ($filters['project_id'])      { $where .= ' AND project_id = %d'; $args[] = $filters['project_id']; }
            }
            $sql = "SELECT * FROM $table WHERE $where ORDER BY report_date ASC, id ASC";
            $reports = $args
                ? $wpdb->get_results($wpdb->prepare($sql, $args))
                : $wpdb->get_results($sql);
        }

        $projects = DRWP_Project::all();
        include DRWP_PATH . 'admin/views/print-page.php';
    }
}
