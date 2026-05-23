<?php
if (!defined('ABSPATH')) exit;

/**
 * CSV importer for daily reports.
 *
 * 1 row = 1 report. Each row creates one row in drwp_reports.
 * Required columns: report_date, work_description.
 * Optional columns: project_name (auto-created if missing),
 * started_at, ended_at, issues, next_plan, public_*, post_*.
 *
 * Photos are not imported via CSV — they're attachments, not text.
 *
 * The earlier "entry_group multi-entry" mode that allowed N rows
 * to collapse into one report's entries[] was removed in v1.11
 * alongside the underlying schema. CSVs that still ship an
 * entry_group column simply have it ignored.
 */
class DRWP_CSV_Import {

    const LEGACY_COLUMNS = [
        'report_date',
        'project_name',
        'started_at',
        'ended_at',
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
        if (!DRWP_License::can_write()) {
            wp_die(
                DRWP_License::blocked_message(__('ライセンス状態によりインポートできません。', 'drwp-daily-reports')),
                esc_html__('ライセンス未有効', 'drwp-daily-reports'),
                ['response' => 402]
            );
        }

        if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
            self::flash(['ok' => false, 'message' => __('CSV ファイルが選択されていません。', 'drwp-daily-reports')]);
            self::redirect_back();
        }
        $size = (int) ($_FILES['csv']['size'] ?? 0);
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            self::flash(['ok' => false, 'message' => __('ファイルサイズが不正です（最大 5MB）。', 'drwp-daily-reports')]);
            self::redirect_back();
        }
        $raw = file_get_contents($_FILES['csv']['tmp_name']);
        if ($raw === false || $raw === '') {
            self::flash(['ok' => false, 'message' => __('CSV を開けませんでした。', 'drwp-daily-reports')]);
            self::redirect_back();
        }

        $result = self::import_csv_string($raw, get_current_user_id());
        self::flash($result);
        self::redirect_back();
    }

    /**
     * Parse and import CSV bytes. Public so tests can exercise the
     * full pipeline without going through $_FILES / wp_die.
     *
     * Returns an array shaped like the legacy flash payload:
     *   ['ok' => bool, 'created' => int, 'errors' => [...], 'message' => str]
     */
    public static function import_csv_string($raw, $user_id) {
        $raw = self::to_utf8($raw);
        $parsed = self::parse_csv($raw);
        if (isset($parsed['error'])) {
            return ['ok' => false, 'message' => $parsed['error']];
        }
        $header = $parsed['header'];
        $rows   = $parsed['rows'];

        $missing = array_diff(['report_date', 'work_description'], $header);
        if (!empty($missing)) {
            return [
                'ok' => false,
                'message' => sprintf(
                    /* translators: %s: comma-separated list of missing required CSV columns */
                    __('必須カラムが不足: %s', 'drwp-daily-reports'),
                    implode(', ', $missing)
                ),
            ];
        }

        return self::import_rows($rows, (int) $user_id);
    }

    private static function parse_csv($raw) {
        $fh = fopen('php://temp', 'r+');
        if (!$fh) return ['error' => __('CSV を開けませんでした。', 'drwp-daily-reports')];
        fwrite($fh, $raw);
        rewind($fh);

        $header = fgetcsv($fh);
        if (!$header) {
            fclose($fh);
            return ['error' => __('ヘッダ行が見つかりません。', 'drwp-daily-reports')];
        }
        $header = array_map(function ($v) { return strtolower(trim((string) $v)); }, $header);

        $rows = [];
        $line_no = 1;  // header is line 1
        while (($row = fgetcsv($fh)) !== false) {
            $line_no++;
            if ($row === [null] || (count($row) === 1 && trim((string) $row[0]) === '')) continue;
            $rows[] = ['line' => $line_no, 'data' => self::row_to_assoc($header, $row)];
        }
        fclose($fh);
        return ['header' => $header, 'rows' => $rows];
    }

    private static function import_rows(array $rows, $user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';
        $project_cache = [];
        $created = 0;
        $errors = [];

        foreach ($rows as $r) {
            $line_no = $r['line'];
            $data = $r['data'];

            $report_date = sanitize_text_field((string) ($data['report_date'] ?? ''));
            if (!self::is_iso_date($report_date)) {
                $errors[] = self::line_error($line_no, sprintf(
                    /* translators: %s: invalid date string */
                    __('report_date が不正 (\'%s\')', 'drwp-daily-reports'),
                    $report_date
                ));
                continue;
            }

            $row_data = self::flat_report_row($data, $user_id, $report_date, $project_cache);
            $ok = $wpdb->insert($table, $row_data);
            if ($ok) {
                $report_id = (int) $wpdb->insert_id;
                $created++;
                DRWP_Audit::log('report_imported', 'CSV インポートで作成', $report_id, ['line' => $line_no]);
            } else {
                $errors[] = self::line_error($line_no, __('DB insert に失敗', 'drwp-daily-reports'));
            }
        }

        return self::ok_payload($created, $errors);
    }

    private static function flat_report_row(array $data, $user_id, $report_date, array &$project_cache) {
        $project_id = self::resolve_project($data['project_name'] ?? '', $project_cache);
        return [
            'project_id'       => $project_id,
            'user_id'          => $user_id,
            'report_date'      => $report_date,
            'started_at'       => self::sanitize_time($data['started_at'] ?? ''),
            'ended_at'         => self::sanitize_time($data['ended_at'] ?? ''),
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
    }

    private static function resolve_project($name, array &$cache) {
        $name = trim((string) $name);
        if ($name === '') return null;
        if (!isset($cache[$name])) {
            $cache[$name] = self::find_or_create_project($name);
        }
        return $cache[$name];
    }

    private static function is_iso_date($s) {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $s);
    }

    /** Accept HH:MM or HH:MM:SS; anything else stores NULL. */
    private static function sanitize_time($v) {
        $v = trim((string) $v);
        if ($v === '') return null;
        if (preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $v, $m)) {
            return sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0));
        }
        return null;
    }

    private static function line_error($line_no, $message) {
        return sprintf(
            /* translators: 1: CSV line number, 2: error description */
            __('行 %1$d: %2$s', 'drwp-daily-reports'),
            $line_no,
            $message
        );
    }

    private static function ok_payload($created, $errors) {
        return [
            'ok'       => true,
            'created'  => $created,
            'errors'   => $errors,
            'message'  => sprintf(
                /* translators: %d: number of imported reports */
                _n('%d 件をインポートしました。', '%d 件をインポートしました。', $created, 'drwp-daily-reports'),
                $created
            ),
        ];
    }

    private static function to_utf8($raw) {
        if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
            $raw = substr($raw, 3);
        }
        if (!function_exists('mb_detect_encoding') || !function_exists('mb_convert_encoding')) {
            return $raw;
        }
        $candidates = ['UTF-8', 'SJIS-win', 'SJIS', 'EUC-JP', 'JIS', 'ASCII'];
        $detected = mb_detect_encoding($raw, $candidates, true);
        if ($detected === false || strtoupper($detected) === 'UTF-8' || strtoupper($detected) === 'ASCII') {
            return $raw;
        }
        $converted = @mb_convert_encoding($raw, 'UTF-8', $detected);
        return $converted === false ? $raw : $converted;
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
