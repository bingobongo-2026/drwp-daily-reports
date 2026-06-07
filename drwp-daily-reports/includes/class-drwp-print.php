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
            'group_id'   => isset($_GET['group_id']) ? absint($_GET['group_id']) : 0,
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
                if ($filters['group_id']) {
                    // Group filter resolves to the customer-owned
                    // project IDs. Empty list → no matches (`0=1`)
                    // so the operator gets back the "該当なし"
                    // message rather than the entire set.
                    $group_projects = DRWP_Customer_Group::project_ids_for_group($filters['group_id']);
                    if (empty($group_projects)) {
                        $where .= ' AND 0=1';
                    } else {
                        $placeholders = implode(',', array_fill(0, count($group_projects), '%d'));
                        $where .= ' AND project_id IN (' . $placeholders . ')';
                        foreach ($group_projects as $pid) $args[] = $pid;
                    }
                }
            }
            $sql = "SELECT * FROM $table WHERE $where ORDER BY report_date ASC, id ASC";
            $reports = $args
                ? $wpdb->get_results($wpdb->prepare($sql, $args))
                : $wpdb->get_results($sql);
        }

        // Approver / approved-at, derived from the audit log. There's no
        // dedicated column for it on the report itself — the source of
        // truth is the review_status_changed event whose meta.to is
        // 'approved'. Pull the latest such event per report so the
        // template can render "YYYY年M月D日 確認者：name" inline.
        $approvals = [];
        if ($reports) {
            $audit = $wpdb->prefix . 'drwp_audit_logs';
            $ids = array_map(fn($r) => (int) $r->id, $reports);
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT a.report_id, a.created_at, u.display_name
                   FROM (
                     SELECT report_id, MAX(id) AS id
                       FROM $audit
                      WHERE event = 'review_status_changed'
                        AND report_id IN ($placeholders)
                        AND meta_json LIKE %s
                      GROUP BY report_id
                   ) latest
                   JOIN $audit a ON a.id = latest.id
              LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id",
                array_merge($ids, ['%"to":"approved"%'])
            ));
            foreach ($rows as $row) {
                $approvals[(int) $row->report_id] = $row;
            }
        }

        $projects = DRWP_Project::all();
        $groups = DRWP_Customer_Group::all(true);
        include DRWP_PATH . 'admin/views/print-page.php';
    }
}
