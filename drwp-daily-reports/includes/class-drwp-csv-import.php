<?php
if (!defined('ABSPATH')) exit;

class DRWP_CSV_Import {
    const COLUMNS = [
        'report_date',
        'project_name',
        'work_description',
        'issues',
        'next_plan',
        'public_title',
        'public_intro',
        'public_body',
        'public_next_plan',
        'post_template',
        'post_tags',
    ];

    public static function init() {
        add_action('admin_post_drwp_import_csv', [__CLASS__, 'handle']);
    }

    public static function render_page() {
        if (!current_user_can(DRWP_Admin::CAP_EDIT)) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        $result = get_transient('drwp_csv_import_result_' . get_current_user_id());
        if ($result) delete_transient('drwp_csv_import_result_' . get_current_user_id());
        include DRWP_PATH . 'admin/views/csv-import-page.php';
    }

    public static function handle() {
        if (!current_user_can(DRWP_Admin::CAP_EDIT)) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        check_admin_referer('drwp_import_csv');
        if (!DRWP_License::can_write()) wp_die(esc_html__('ライセンス状態によりインポートできません。', 'drwp-daily-reports'));

        if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
            self::flash(['ok' => false, 'message' => __('CSV ファイルが選択されていません。', 'drwp-daily-reports')]);
            self::redirect_back();
        }

        $size = (int) ($_FILES['csv']['size'] ?? 0);
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            self::flash(['ok' => false, 'message' => __('ファイルサイズが不正です（最大 5MB）。', 'drwp-daily-reports')]);
            self::redirect_back();
        }

        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        if (!$fh) {
            self::flash(['ok' => false, 'message' => __('CSV を開けませんでした。', 'drwp-daily-reports')]);
            self::redirect_back();
        }

        // Strip UTF-8 BOM if present.
        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($fh);

        $header = fgetcsv($fh);
        if (!$header) {
            fclose($fh);
            self::flash(['ok' => false, 'message' => __('ヘッダ行が見つかりません。', 'drwp-daily-reports')]);
            self::redirect_back();
        }
        $header = array_map(function ($v) { return strtolower(trim((string) $v)); }, $header);

        $missing = array_diff(['report_date', 'work_description'], $header);
        if (!empty($missing)) {
            fclose($fh);
            self::flash([
                'ok' => false,
                'message' => sprintf(
                    /* translators: %s: comma-separated list of missing required CSV columns */
                    __('必須カラムが不足: %s', 'drwp-daily-reports'),
                    implode(', ', $missing)
                ),
            ]);
            self::redirect_back();
        }

        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';
        $project_cache = [];

        $created = 0;
        $errors = [];
        $line_no = 1; // header was line 1
        while (($row = fgetcsv($fh)) !== false) {
            $line_no++;
            if ($row === [null] || (count($row) === 1 && trim((string) $row[0]) === '')) continue;

            $data = self::row_to_assoc($header, $row);

            $report_date = sanitize_text_field((string) ($data['report_date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date)) {
                $errors[] = sprintf(
                    /* translators: 1: line number 2: invalid date string */
                    __('行 %1$d: report_date が不正 (\'%2$s\')', 'drwp-daily-reports'),
                    $line_no,
                    $report_date
                );
                continue;
            }

            $project_id = null;
            $project_name = trim((string) ($data['project_name'] ?? ''));
            if ($project_name !== '') {
                if (!isset($project_cache[$project_name])) {
                    $project_cache[$project_name] = self::find_or_create_project($project_name);
                }
                $project_id = $project_cache[$project_name];
            }

            $row_data = [
                'project_id'       => $project_id,
                'user_id'          => get_current_user_id(),
                'report_date'      => $report_date,
                'work_description' => wp_kses_post((string) ($data['work_description'] ?? '')),
                'issues'           => wp_kses_post((string) ($data['issues'] ?? '')),
                'next_plan'        => wp_kses_post((string) ($data['next_plan'] ?? '')),
                'public_title'     => sanitize_text_field((string) ($data['public_title'] ?? '')),
                'public_intro'     => wp_kses_post((string) ($data['public_intro'] ?? '')),
                'public_body'      => wp_kses_post((string) ($data['public_body'] ?? '')),
                'public_next_plan' => wp_kses_post((string) ($data['public_next_plan'] ?? '')),
                'post_template'    => sanitize_text_field((string) ($data['post_template'] ?? 'standard')),
                'post_tags'        => sanitize_text_field((string) ($data['post_tags'] ?? '')),
                'review_status'    => 'pending',
            ];

            $ok = $wpdb->insert($table, $row_data);
            if ($ok) {
                $report_id = (int) $wpdb->insert_id;
                $created++;
                DRWP_Audit::log('report_imported', 'CSV インポートで作成', $report_id, ['line' => $line_no]);
            } else {
                $errors[] = sprintf(
                    /* translators: %d: source CSV line number */
                    __('行 %d: DB insert に失敗', 'drwp-daily-reports'),
                    $line_no
                );
            }
        }
        fclose($fh);

        self::flash([
            'ok'       => true,
            'created'  => $created,
            'errors'   => $errors,
            'message'  => sprintf(
                /* translators: %d: number of imported reports */
                _n('%d 件をインポートしました。', '%d 件をインポートしました。', $created, 'drwp-daily-reports'),
                $created
            ),
        ]);
        self::redirect_back();
    }

    private static function row_to_assoc($header, $row) {
        $data = [];
        foreach ($header as $i => $key) {
            if ($key === '') continue;
            $data[$key] = $row[$i] ?? '';
        }
        return $data;
    }

    private static function find_or_create_project($name) {
        global $wpdb;
        $table = $wpdb->prefix . 'drwp_projects';
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE name = %s LIMIT 1", $name
        ));
        if ($existing) return (int) $existing;
        $wpdb->insert($table, ['name' => sanitize_text_field($name), 'status' => 'active']);
        return (int) $wpdb->insert_id;
    }

    private static function flash($data) {
        set_transient('drwp_csv_import_result_' . get_current_user_id(), $data, 60);
    }

    private static function redirect_back() {
        wp_safe_redirect(admin_url('admin.php?page=drwp_csv_import'));
        exit;
    }
}
