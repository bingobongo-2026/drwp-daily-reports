<?php
if (!defined('ABSPATH')) exit;

class DRWP_Report_Controller {
    public static function init() {
        add_action('admin_post_drwp_save_report', [__CLASS__, 'save_report']);
        add_action('admin_post_drwp_change_status', [__CLASS__, 'change_status']);
        add_action('admin_post_drwp_convert_post', [__CLASS__, 'convert_post']);
        add_action('admin_post_drwp_add_comment', [__CLASS__, 'add_comment']);
        add_action('admin_post_drwp_bulk_reports', [__CLASS__, 'bulk_reports']);
    }

    public static function can_edit_report($report = null) {
        if (current_user_can('manage_options') || current_user_can('drwp_edit_all_reports')) return true;
        if (current_user_can('drwp_edit_own_reports')) {
            if (!$report) return true;
            return intval($report['user_id']) === get_current_user_id();
        }
        return false;
    }

    public static function can_review_report() {
        return current_user_can('manage_options') || current_user_can('drwp_review_reports');
    }

    public static function can_convert_post($report = null) {
        if (!(current_user_can('manage_options') || current_user_can('drwp_convert_posts'))) return false;
        if (!$report) return true;
        if (current_user_can('drwp_edit_all_reports') || current_user_can('manage_options')) return true;
        return intval($report['user_id']) === get_current_user_id();
    }

    protected static function get_report($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}drwp_reports WHERE id = %d", $id), ARRAY_A);
    }

    public static function save_report() {
        check_admin_referer('drwp_save_report');

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $existing = $id ? self::get_report($id) : null;

        if (!self::can_edit_report($existing)) wp_die('Unauthorized');

        if (!DRWP_License::can_write()) {
            wp_die('ライセンス状態により保存できません。');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';
        $is_resubmitted = !empty($existing) && ($existing['review_status'] === 'needs_revision');

        $data = [
            'project_id' => intval($_POST['project_id'] ?? 0),
            'report_date' => sanitize_text_field($_POST['report_date'] ?? ''),
            'work_category' => sanitize_text_field($_POST['work_category'] ?? ''),
            'work_description' => wp_kses_post($_POST['work_description'] ?? ''),
            'worker_count' => intval($_POST['worker_count'] ?? 0),
            'issues' => wp_kses_post($_POST['issues'] ?? ''),
            'next_plan' => wp_kses_post($_POST['next_plan'] ?? ''),
            'public_title' => sanitize_text_field($_POST['public_title'] ?? ''),
            'public_intro' => wp_kses_post($_POST['public_intro'] ?? ''),
            'public_body' => wp_kses_post($_POST['public_body'] ?? ''),
            'public_next' => wp_kses_post($_POST['public_next'] ?? ''),
            'public_category_ids' => sanitize_text_field($_POST['public_category_ids'] ?? ''),
            'post_template' => sanitize_text_field($_POST['post_template'] ?? 'standard'),
            'updated_at' => current_time('mysql'),
        ];

        if ($id) {
            if ($is_resubmitted && !self::can_review_report()) {
                $data['review_status'] = 'resubmitted';
            }
            $wpdb->update($table, $data, ['id' => $id]);
        } else {
            $data['user_id'] = get_current_user_id();
            $data['review_status'] = 'pending';
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($table, $data);
            $id = intval($wpdb->insert_id);
        }

        $attachments = isset($_POST['attachment_ids']) ? array_map('intval', (array) $_POST['attachment_ids']) : [];
        DRWP_Media::save_report_photos($id, $attachments);

        DRWP_Audit::log(
            $existing ? 'report_updated' : 'report_created',
            $existing ? '日報を更新しました' : '日報を作成しました',
            $id,
            [
                'review_status' => $data['review_status'] ?? ($existing['review_status'] ?? 'pending'),
                'project_id' => intval($data['project_id']),
            ]
        );

        wp_redirect(admin_url('admin.php?page=drwp-reports&action=edit&id=' . $id . '&saved=1'));
        exit;
    }

    public static function change_status() {
        check_admin_referer('drwp_change_status');
        if (!self::can_review_report()) wp_die('Unauthorized');

        global $wpdb;
        $id = intval($_POST['id'] ?? 0);
        $status = sanitize_text_field($_POST['review_status'] ?? 'pending');
        $allowed = ['pending', 'approved', 'needs_revision', 'resubmitted'];
        if (!in_array($status, $allowed, true)) $status = 'pending';

        $wpdb->update($wpdb->prefix . 'drwp_reports', [
            'review_status' => $status,
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);

        if (!empty($_POST['comment'])) {
            $wpdb->insert($wpdb->prefix . 'drwp_comments', [
                'report_id' => $id,
                'user_id' => get_current_user_id(),
                'comment' => wp_kses_post($_POST['comment']),
                'created_at' => current_time('mysql'),
            ]);
        }

        wp_redirect(admin_url('admin.php?page=drwp-reports&action=edit&id=' . $id));
        exit;
    }

    public static function convert_post() {
        check_admin_referer('drwp_convert_post');

        $id = intval($_POST['id'] ?? 0);
        $report = self::get_report($id);
        if (!$report) wp_die('Report not found');
        if (!self::can_convert_post($report)) wp_die('Unauthorized');

        if (!DRWP_License::can_convert()) {
            wp_die('ライセンス状態により記事化できません。');
        }

        $post_id = DRWP_Post_Converter::upsert_post($id);
        if (is_wp_error($post_id)) wp_die($post_id->get_error_message());

        wp_redirect(admin_url('post.php?post=' . intval($post_id) . '&action=edit'));
        exit;
    }



    protected static function normalize_bulk_category_ids($raw) {
        $ids = array_values(array_filter(array_map('intval', preg_split('/[\s,]+/', (string) $raw))));
        return implode(',', array_slice(array_unique($ids), 0, 20));
    }

    public static function bulk_reports() {
        check_admin_referer('drwp_bulk_reports');

        if (!(current_user_can('manage_options') || current_user_can('drwp_view_reports'))) {
            wp_die('Unauthorized');
        }

        $bulk_action = sanitize_key($_POST['bulk_action'] ?? '');
        $report_ids = isset($_POST['report_ids']) ? array_map('intval', (array) $_POST['report_ids']) : [];
        $redirect = admin_url('admin.php?page=drwp-reports');

        if (empty($report_ids) || empty($bulk_action)) {
            wp_redirect(add_query_arg(['bulk_result' => 'empty'], $redirect));
            exit;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';

        $processed = 0;
        $failed = 0;

        if ($bulk_action === 'export_csv') {
            self::export_reports_csv($report_ids);
        }

        $bulk_template = sanitize_text_field($_POST['bulk_post_template'] ?? '');
        $bulk_categories = self::normalize_bulk_category_ids($_POST['bulk_public_category_ids'] ?? '');

        foreach ($report_ids as $report_id) {
            if (!$report_id) continue;
            $report = self::get_report($report_id);
            if (!$report) {
                $failed++;
                continue;
            }

            if ($bulk_action === 'approve') {
                if (!self::can_review_report()) {
                    $failed++;
                    continue;
                }
                $wpdb->update($table, [
                    'review_status' => 'approved',
                    'updated_at' => current_time('mysql'),
                ], ['id' => $report_id]);
                DRWP_Audit::log('report_bulk_approved', '日報を一括承認しました', $report_id);
                $processed++;
                continue;
            }

            if ($bulk_action === 'needs_revision') {
                if (!self::can_review_report()) {
                    $failed++;
                    continue;
                }
                $wpdb->update($table, [
                    'review_status' => 'needs_revision',
                    'updated_at' => current_time('mysql'),
                ], ['id' => $report_id]);
                DRWP_Audit::log('report_bulk_needs_revision', '日報を一括差し戻ししました', $report_id);
                $processed++;
                continue;
            }

            if ($bulk_action === 'set_publish_settings') {
                if (!(self::can_convert_post($report) && DRWP_License::can_write())) {
                    $failed++;
                    continue;
                }
                $update = ['updated_at' => current_time('mysql')];
                if (!empty($bulk_template)) {
                    $update['post_template'] = $bulk_template;
                }
                $update['public_category_ids'] = $bulk_categories;
                $wpdb->update($table, $update, ['id' => $report_id]);
                DRWP_Audit::log('report_bulk_publish_settings_updated', '日報の公開設定を一括更新しました', $report_id, [
                    'post_template' => $bulk_template,
                    'public_category_ids' => $bulk_categories,
                ]);
                $processed++;
                continue;
            }

            if ($bulk_action === 'sync_linked_post') {
                if (!self::can_convert_post($report) || !DRWP_License::can_convert() || empty($report['linked_post_id'])) {
                    $failed++;
                    continue;
                }
                $post_id = DRWP_Post_Converter::upsert_post($report_id);
                if (is_wp_error($post_id)) {
                    $failed++;
                    continue;
                }
                DRWP_Audit::log('report_bulk_synced_linked_post', '既存の連携記事へ一括再反映しました', $report_id, ['post_id' => intval($post_id)]);
                $processed++;
                continue;
            }

            if ($bulk_action === 'convert_post') {
                if (!self::can_convert_post($report) || !DRWP_License::can_convert()) {
                    $failed++;
                    continue;
                }
                $post_id = DRWP_Post_Converter::upsert_post($report_id);
                if (is_wp_error($post_id)) {
                    $failed++;
                    continue;
                }
                DRWP_Audit::log('report_bulk_converted', '日報から記事を一括生成・更新しました', $report_id, ['post_id' => intval($post_id)]);
                $processed++;
                continue;
            }
        }

        $redirect = add_query_arg([
            'bulk_result' => 'done',
            'bulk_action_name' => $bulk_action,
            'bulk_processed' => $processed,
            'bulk_failed' => $failed,
        ], $redirect);

        wp_redirect($redirect);
        exit;
    }


protected static function export_reports_csv($report_ids = []) {
    if (!(current_user_can('manage_options') || current_user_can('drwp_view_reports'))) {
        wp_die('Unauthorized');
    }

    $report_ids = array_values(array_filter(array_map('intval', (array) $report_ids)));
    if (empty($report_ids)) {
        wp_redirect(add_query_arg(['bulk_result' => 'empty'], admin_url('admin.php?page=drwp-reports')));
        exit;
    }

    global $wpdb;
    $reports_table = $wpdb->prefix . 'drwp_reports';
    $projects_table = $wpdb->prefix . 'drwp_projects';
    $users_table = $wpdb->users;

    $placeholders = implode(',', array_fill(0, count($report_ids), '%d'));
    $where_sql = "r.id IN ($placeholders)";
    $query_args = $report_ids;

    if (!(current_user_can('manage_options') || current_user_can('drwp_edit_all_reports') || current_user_can('drwp_review_reports'))) {
        $where_sql .= " AND r.user_id = %d";
        $query_args[] = get_current_user_id();
    }

    $query = $wpdb->prepare(
        "SELECT r.*, p.name AS project_name, u.display_name AS user_name
         FROM {$reports_table} r
         LEFT JOIN {$projects_table} p ON r.project_id = p.id
         LEFT JOIN {$users_table} u ON r.user_id = u.ID
         WHERE {$where_sql}
         ORDER BY r.report_date DESC, r.id DESC",
        ...$query_args
    );

    $reports = $wpdb->get_results($query, ARRAY_A);

    if (empty($reports)) {
        wp_redirect(add_query_arg(['bulk_result' => 'empty'], admin_url('admin.php?page=drwp-reports')));
        exit;
    }

    DRWP_Audit::log(
        'reports_bulk_csv_exported',
        '日報を一括でCSV出力しました',
        0,
        ['report_ids' => $report_ids, 'count' => count($reports)]
    );

    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="drwp-reports-' . gmdate('Ymd-His') . '.csv"');

    $output = fopen('php://output', 'w');
    if (!$output) {
        wp_die('CSV出力に失敗しました。');
    }

    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($output, [
        'ID',
        '日付',
        '現場',
        '担当者',
        '区分',
        '状態',
        '作業内容',
        '問題点',
        '次回予定',
        '公開タイトル',
        '公開導入',
        '公開本文',
        '公開用今後の予定',
        '記事テンプレート',
        '投稿カテゴリID',
        '連携投稿ID',
        '作成日時',
        '更新日時',
    ]);

    foreach ($reports as $report) {
        fputcsv($output, [
            intval($report['id']),
            (string) ($report['report_date'] ?? ''),
            (string) ($report['project_name'] ?? ''),
            (string) ($report['user_name'] ?? ''),
            (string) ($report['work_category'] ?? ''),
            (string) ($report['review_status'] ?? ''),
            wp_strip_all_tags((string) ($report['work_description'] ?? '')),
            wp_strip_all_tags((string) ($report['issues'] ?? '')),
            wp_strip_all_tags((string) ($report['next_plan'] ?? '')),
            wp_strip_all_tags((string) ($report['public_title'] ?? '')),
            wp_strip_all_tags((string) ($report['public_intro'] ?? '')),
            wp_strip_all_tags((string) ($report['public_body'] ?? '')),
            wp_strip_all_tags((string) ($report['public_next'] ?? '')),
            wp_strip_all_tags((string) ($report['post_template'] ?? '')),
            wp_strip_all_tags((string) ($report['public_category_ids'] ?? '')),
            intval($report['linked_post_id'] ?? 0),
            (string) ($report['created_at'] ?? ''),
            (string) ($report['updated_at'] ?? ''),
        ]);
    }

    fclose($output);
    exit;
}

    public static function add_comment() {
        check_admin_referer('drwp_add_comment');
        $id = intval($_POST['id'] ?? 0);
        $report = self::get_report($id);
        if (!$report) wp_die('Report not found');
        if (!(self::can_review_report() || self::can_edit_report($report))) wp_die('Unauthorized');

        if (!empty($_POST['comment'])) {
            global $wpdb;
            $wpdb->insert($wpdb->prefix . 'drwp_comments', [
                'report_id' => $id,
                'user_id' => get_current_user_id(),
                'comment' => wp_kses_post($_POST['comment']),
                'created_at' => current_time('mysql'),
            ]);
        }

        wp_redirect(admin_url('admin.php?page=drwp-reports&action=edit&id=' . $id));
        exit;
    }
}
