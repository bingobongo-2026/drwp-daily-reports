<?php
if (!defined('ABSPATH')) exit;

class DRWP_Admin {
    const CAP_EDIT  = 'edit_posts';
    const CAP_REVIEW = 'edit_others_posts';
    const CAP_CONVERT = 'publish_posts';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_drwp_save_report', [__CLASS__, 'save_report']);
        add_action('admin_post_drwp_bulk_reports', [__CLASS__, 'bulk_reports']);
    }

    public static function menu() {
        add_menu_page('日報管理', '日報管理', self::CAP_EDIT, 'drwp_reports', [__CLASS__, 'reports_page'], 'dashicons-media-spreadsheet');
        add_submenu_page('drwp_reports', '日報編集', '日報編集', self::CAP_EDIT, 'drwp_report_edit', [__CLASS__, 'report_edit_page']);
        add_submenu_page('drwp_reports', '現場', '現場', 'manage_options', 'drwp_projects', ['DRWP_Project', 'render_page']);
        add_submenu_page('drwp_reports', 'ライセンス', 'ライセンス', 'manage_options', 'drwp_license', ['DRWP_License_Admin', 'render_page']);
        add_submenu_page(null, '公開プレビュー', '公開プレビュー', self::CAP_EDIT, 'drwp_report_preview', [__CLASS__, 'report_preview_page']);
    }

    private static function reports_table() {
        global $wpdb;
        return $wpdb->prefix . 'drwp_reports';
    }

    private static function current_user_can_edit_report($report) {
        if (current_user_can(self::CAP_REVIEW)) return true;
        if (!current_user_can(self::CAP_EDIT)) return false;
        if (!$report) return true;
        return (int) $report->user_id === get_current_user_id();
    }

    public static function reports_page() {
        if (!current_user_can(self::CAP_EDIT)) wp_die('forbidden');
        global $wpdb;
        $table = self::reports_table();
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $status = isset($_GET['review_status']) ? sanitize_text_field(wp_unslash($_GET['review_status'])) : '';
        $where = 'WHERE 1=1';
        $args = [];
        if (!current_user_can(self::CAP_REVIEW)) {
            $where .= ' AND user_id = %d';
            $args[] = get_current_user_id();
        }
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
        if (!current_user_can(self::CAP_EDIT)) wp_die('forbidden');
        global $wpdb;
        $table = self::reports_table();
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        $report = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id)) : null;
        if ($report && !self::current_user_can_edit_report($report)) wp_die('forbidden');
        $projects = DRWP_Project::all(true);
        include DRWP_PATH . 'admin/views/report-edit.php';
    }

    public static function report_preview_page() {
        if (!current_user_can(self::CAP_EDIT)) wp_die('forbidden');
        global $wpdb;
        $table = self::reports_table();
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        $report = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id)) : null;
        if ($report && !self::current_user_can_edit_report($report)) wp_die('forbidden');
        include DRWP_PATH . 'admin/views/report-preview.php';
    }

    public static function save_report() {
        if (!current_user_can(self::CAP_EDIT)) wp_die('forbidden');
        check_admin_referer('drwp_save_report');
        if (!DRWP_License::can_write()) wp_die('ライセンス状態により保存できません。');

        global $wpdb;
        $table = self::reports_table();
        $id = absint($_POST['id'] ?? 0);
        $existing = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id)) : null;
        if ($existing && !self::current_user_can_edit_report($existing)) wp_die('forbidden');

        $project_id = absint($_POST['project_id'] ?? 0);
        if ($project_id && !DRWP_Project::find($project_id)) $project_id = 0;
        $data = [
            'project_id' => $project_id ?: null,
            'report_date' => sanitize_text_field($_POST['report_date'] ?? current_time('Y-m-d')),
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
        if ($existing) {
            $wpdb->update($table, $data, ['id' => $id]);
            DRWP_Audit::log('report_updated', '日報を更新', $id, ['project_id' => $data['project_id']]);
        } else {
            $data['user_id'] = get_current_user_id();
            $wpdb->insert($table, $data);
            $id = (int) $wpdb->insert_id;
            DRWP_Audit::log('report_created', '日報を作成', $id, ['project_id' => $data['project_id']]);
        }
        wp_safe_redirect(admin_url('admin.php?page=drwp_report_edit&id=' . $id . '&saved=1'));
        exit;
    }

    public static function bulk_reports() {
        if (!current_user_can(self::CAP_EDIT)) wp_die('forbidden');
        check_admin_referer('drwp_bulk_reports');
        global $wpdb;
        $table = self::reports_table();
        $ids = array_values(array_filter(array_map('absint', (array) ($_POST['report_ids'] ?? []))));
        $action = sanitize_text_field($_POST['bulk_action'] ?? '');
        $count = 0;

        if ($action === 'bulk_export_csv') {
            self::export_csv($ids);
            return;
        }

        $review_actions = ['bulk_approve', 'bulk_revision'];
        $convert_actions = ['bulk_convert'];
        if (in_array($action, $review_actions, true) && !current_user_can(self::CAP_REVIEW)) wp_die('forbidden');
        if (in_array($action, $convert_actions, true) && !current_user_can(self::CAP_CONVERT)) wp_die('forbidden');

        foreach ($ids as $id) {
            if (!$id) continue;
            $report = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
            if (!$report) continue;
            if (!self::current_user_can_edit_report($report)) continue;

            if ($action === 'bulk_approve') {
                if ((int) $wpdb->update($table, ['review_status' => 'approved'], ['id' => $id])) {
                    DRWP_Audit::log('review_status_changed', '一括承認', $id, ['from' => $report->review_status, 'to' => 'approved']);
                    $count++;
                }
            } elseif ($action === 'bulk_revision') {
                if ((int) $wpdb->update($table, ['review_status' => 'needs_revision'], ['id' => $id])) {
                    DRWP_Audit::log('review_status_changed', '一括差し戻し', $id, ['from' => $report->review_status, 'to' => 'needs_revision']);
                    $count++;
                }
            } elseif ($action === 'bulk_convert') {
                $result = DRWP_Post_Converter::sync_post($id, true);
                if (!is_wp_error($result)) $count++;
            } elseif ($action === 'bulk_update_publish') {
                $data = [
                    'post_template' => sanitize_text_field($_POST['bulk_post_template'] ?? 'standard'),
                    'post_category_id' => absint($_POST['bulk_post_category_id'] ?? 0) ?: null,
                    'post_tags' => sanitize_text_field($_POST['bulk_post_tags'] ?? ''),
                    'post_status' => sanitize_text_field($_POST['bulk_post_status'] ?? 'draft'),
                    'scheduled_at' => sanitize_text_field($_POST['bulk_scheduled_at'] ?? '') ?: null,
                ];
                if ((int) $wpdb->update($table, $data, ['id' => $id])) {
                    DRWP_Audit::log('publish_settings_updated', '公開設定を一括更新', $id, $data);
                    $count++;
                }
            }
        }
        wp_safe_redirect(admin_url('admin.php?page=drwp_reports&updated=' . $count));
        exit;
    }

    private static function export_csv($ids) {
        if (!current_user_can(self::CAP_EDIT)) wp_die('forbidden');
        $ids = array_values(array_filter(array_map('absint', (array) $ids)));
        if (empty($ids)) {
            wp_safe_redirect(admin_url('admin.php?page=drwp_reports'));
            exit;
        }
        global $wpdb;
        $table = self::reports_table();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $scope_args = $ids;
        $scope_sql = "id IN ($placeholders)";
        if (!current_user_can(self::CAP_REVIEW)) {
            $scope_sql .= ' AND user_id = %d';
            $scope_args[] = get_current_user_id();
        }
        $sql = "SELECT id, report_date, review_status, public_title, post_template, post_category_id, post_tags, post_status, scheduled_at, linked_post_id, work_description FROM $table WHERE $scope_sql ORDER BY id DESC";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $scope_args), ARRAY_A);
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="drwp-reports-' . gmdate('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        $header = ['id', 'report_date', 'review_status', 'public_title', 'post_template', 'post_category_id', 'post_tags', 'post_status', 'scheduled_at', 'linked_post_id', 'work_description'];
        fputcsv($out, $header);
        foreach ($rows as $row) fputcsv($out, $row);
        fclose($out);
        exit;
    }
}
