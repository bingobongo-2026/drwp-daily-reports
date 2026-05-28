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
            'date_from'     => isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '',
            'date_to'       => isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '',
            'project_id'    => isset($_GET['project_id']) ? absint($_GET['project_id']) : 0,
            'review_status' => isset($_GET['review_status']) ? sanitize_text_field(wp_unslash($_GET['review_status'])) : '',
            'ids'           => isset($_GET['ids']) ? sanitize_text_field(wp_unslash($_GET['ids'])) : '',
            'group_by'      => isset($_GET['group_by']) ? sanitize_text_field(wp_unslash($_GET['group_by'])) : 'none',
            'include_photos'=> !empty($_GET['include_photos']),
            'go'            => !empty($_GET['go']),
        ];

        $reports = [];
        if ($filters['go']) {
            $where = '1=1';
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
                if ($filters['review_status']!=='') { $where .= ' AND review_status = %s'; $args[] = $filters['review_status']; }
            }
            $sql = "SELECT * FROM $table WHERE $where ORDER BY report_date ASC, id ASC";
            $reports = $args
                ? $wpdb->get_results($wpdb->prepare($sql, $args))
                : $wpdb->get_results($sql);
        }

        $projects = DRWP_Project::all();
        include DRWP_PATH . 'admin/views/print-page.php';
    }

    public static function group_reports($reports, $group_by) {
        if ($group_by === 'none' || empty($reports)) return ['' => $reports];
        $groups = [];
        foreach ($reports as $r) {
            if ($group_by === 'date') {
                $key = (string) $r->report_date;
            } elseif ($group_by === 'project') {
                $name = '（未設定）';
                if ($r->project_id) {
                    $p = DRWP_Project::find((int) $r->project_id);
                    $name = $p ? (string) $p->name : ('#' . (int) $r->project_id);
                }
                $key = $name;
            } else {
                $key = '';
            }
            if (!isset($groups[$key])) $groups[$key] = [];
            $groups[$key][] = $r;
        }
        return $groups;
    }
}
