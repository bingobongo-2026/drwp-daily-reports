<?php
if (!defined('ABSPATH')) exit;

class DRWP_Audit_Admin {
    const PER_PAGE = 50;

    public static function init() {
        add_action('admin_post_drwp_export_audit_csv', [__CLASS__, 'export_csv']);
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));

        $filters = self::read_filters_from_request();

        $paged = max(1, (int) ($_GET['paged'] ?? 1));
        $offset = ($paged - 1) * self::PER_PAGE;

        $total = DRWP_Audit::count($filters);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $logs = DRWP_Audit::search($filters, self::PER_PAGE, $offset);
        $events = DRWP_Audit::events_map();

        include DRWP_PATH . 'admin/views/audit-page.php';
    }

    public static function export_csv() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        check_admin_referer('drwp_export_audit_csv');

        $filters = self::read_filters_from_request();
        // Cap export volume so a broad filter can't DoS the server.
        $rows = DRWP_Audit::search($filters, 5000, 0);

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="drwp-audit-' . gmdate('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['id', 'created_at', 'event', 'user', 'report_id', 'message', 'meta_json']);
        foreach ($rows as $row) {
            fputcsv($out, [
                (int) $row['id'],
                (string) $row['created_at'],
                (string) $row['event'],
                (string) ($row['display_name'] ?: '#' . (int) $row['user_id']),
                (int) ($row['report_id'] ?? 0),
                (string) $row['message'],
                (string) ($row['meta_json'] ?? ''),
            ]);
        }
        fclose($out);
        exit;
    }

    private static function read_filters_from_request() {
        return [
            'event'     => isset($_REQUEST['event']) ? sanitize_key(wp_unslash($_REQUEST['event'])) : '',
            'report_id' => isset($_REQUEST['report_id']) ? absint($_REQUEST['report_id']) : 0,
            'user_id'   => isset($_REQUEST['user_id']) ? absint($_REQUEST['user_id']) : 0,
            'search'    => isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '',
            'date_from' => isset($_REQUEST['date_from']) ? sanitize_text_field(wp_unslash($_REQUEST['date_from'])) : '',
            'date_to'   => isset($_REQUEST['date_to']) ? sanitize_text_field(wp_unslash($_REQUEST['date_to'])) : '',
        ];
    }
}
