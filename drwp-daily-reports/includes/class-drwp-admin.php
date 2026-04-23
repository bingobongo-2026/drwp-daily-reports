<?php
if (!defined('ABSPATH')) exit;

class DRWP_Admin {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_drwp_save_report', [__CLASS__, 'save_report']);
        add_action('admin_post_drwp_bulk_reports', [__CLASS__, 'bulk_reports']);
    }

    public static function menu() {
        add_menu_page('日報管理', '日報管理', 'read', 'drwp_reports', [__CLASS__, 'reports_page'], 'dashicons-media-spreadsheet');
        add_submenu_page('drwp_reports', '日報編集', '日報編集', 'read', 'drwp_report_edit', [__CLASS__, 'report_edit_page']);
        add_submenu_page(null, '公開プレビュー', '公開プレビュー', 'read', 'drwp_report_preview', [__CLASS__, 'report_preview_page']);
    }

    private static function reports_table() {
        global $wpdb;
        return $wpdb->prefix . 'drwp_reports';
    }

    public static function reports_page() {
        global $wpdb;
        $table = self::reports_table();
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $status = isset($_GET['review_status']) ? sanitize_text_field(wp_unslash($_GET['review_status'])) : '';
        $where = 'WHERE 1=1';
        $args = [];
        if ($search !== '') {
            $where .= " AND (public_title LIKE %s OR public_body LIKE %s OR work_description LIKE %s OR post_tags LIKE %s)";
            $like = '%' . $wpdb->esc_like($search) . '%';
            $args[] = $like; $args[] = $like; $args[] = $like; $args[] = $like;
        }
        if ($status !== '') {
            $where .= " AND review_status = %s";
            $args[] = $status;
        }
        $sql = "SELECT * FROM $table $where ORDER BY report_date DESC, id DESC LIMIT 100";
        $reports = $args ? $wpdb->get_results($wpdb->prepare($sql, $args)) : $wpdb->get_results($sql);
        include DRWP_PATH . 'admin/views/reports-list.php';
    }

    public static function report_edit_page() {
        global $wpdb;
        $table = self::reports_table();
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        $report = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id)) : null;
        include DRWP_PATH . 'admin/views/report-edit.php';
    }

    public static function report_preview_page() {
        global $wpdb;
        $table = self::reports_table();
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        $report = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id)) : null;
        include DRWP_PATH . 'admin/views/report-preview.php';
    }

    public static function save_report() {
        if (!current_user_can('read')) wp_die('forbidden');
        check_admin_referer('drwp_save_report');
        global $wpdb;
        $table = self::reports_table();
        $data = [
            'project_id' => isset($_POST['project_id']) ? absint($_POST['project_id']) : null,
            'user_id' => get_current_user_id(),
            'report_date' => sanitize_text_field($_POST['report_date'] ?? date('Y-m-d')),
            'work_description' => wp_kses_post(wp_unslash($_POST['work_description'] ?? '')),
            'issues' => wp_kses_post(wp_unslash($_POST['issues'] ?? '')),
            'next_plan' => wp_kses_post(wp_unslash($_POST['next_plan'] ?? '')),
            'public_title' => sanitize_text_field($_POST['public_title'] ?? ''),
            'public_intro' => wp_kses_post(wp_unslash($_POST['public_intro'] ?? '')),
            'public_body' => wp_kses_post(wp_unslash($_POST['public_body'] ?? '')),
            'public_next_plan' => wp_kses_post(wp_unslash($_POST['public_next_plan'] ?? '')),
            'post_template' => sanitize_text_field($_POST['post_template'] ?? 'standard'),
            'post_category_id' => absint($_POST['post_category_id'] ?? 0) ?: null,
            'post_tags' => sanitize_text_field($_POST['post_tags'] ?? ''),
            'post_status' => sanitize_text_field($_POST['post_status'] ?? 'draft'),
            'scheduled_at' => sanitize_text_field($_POST['scheduled_at'] ?? '') ?: null,
        ];
        $id = absint($_POST['id'] ?? 0);
        if ($id) {
            $wpdb->update($table, $data, ['id' => $id]);
        } else {
            $wpdb->insert($table, $data);
            $id = (int) $wpdb->insert_id;
        }
        wp_safe_redirect(admin_url('admin.php?page=drwp_report_edit&id=' . $id . '&saved=1'));
        exit;
    }

    public static function bulk_reports() {
        if (!current_user_can('read')) wp_die('forbidden');
        check_admin_referer('drwp_bulk_reports');
        global $wpdb;
        $table = self::reports_table();
        $ids = array_map('absint', (array) ($_POST['report_ids'] ?? []));
        $action = sanitize_text_field($_POST['bulk_action'] ?? '');
        $count = 0;
        foreach ($ids as $id) {
            if (!$id) continue;
            if ($action === 'bulk_approve') {
                $count += (int) $wpdb->update($table, ['review_status' => 'approved'], ['id' => $id]);
            } elseif ($action === 'bulk_revision') {
                $count += (int) $wpdb->update($table, ['review_status' => 'needs_revision'], ['id' => $id]);
            } elseif ($action === 'bulk_convert') {
                $result = DRWP_Post_Converter::sync_post($id, true);
                if (!is_wp_error($result)) $count++;
            } elseif ($action === 'bulk_export_csv') {
                self::export_csv($ids);
                return;
            } elseif ($action === 'bulk_update_publish') {
                $data = [
                    'post_template' => sanitize_text_field($_POST['bulk_post_template'] ?? 'standard'),
                    'post_category_id' => absint($_POST['bulk_post_category_id'] ?? 0) ?: null,
                    'post_tags' => sanitize_text_field($_POST['bulk_post_tags'] ?? ''),
                    'post_status' => sanitize_text_field($_POST['bulk_post_status'] ?? 'draft'),
                    'scheduled_at' => sanitize_text_field($_POST['bulk_scheduled_at'] ?? '') ?: null,
                ];
                $count += (int) $wpdb->update($table, $data, ['id' => $id]);
            }
        }
        wp_safe_redirect(admin_url('admin.php?page=drwp_reports&updated=' . $count));
        exit;
    }

    private static function export_csv($ids) {
        global $wpdb;
        $table = self::reports_table();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "SELECT id, report_date, review_status, public_title, post_template, post_category_id, post_tags, post_status, scheduled_at, linked_post_id, work_description FROM $table WHERE id IN ($placeholders) ORDER BY id DESC";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $ids), ARRAY_A);
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename=drwp-reports-' . date('Ymd-His') . '.csv');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, array_keys($rows ? $rows[0] : ['id' => '']));
        foreach ($rows as $row) fputcsv($out, $row);
        fclose($out);
        exit;
    }
}
