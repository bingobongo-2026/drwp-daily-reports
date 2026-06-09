<?php
if (!defined('ABSPATH')) exit;
if (!defined('WP_CLI') || !WP_CLI) return;

/**
 * `wp drwp …` commands. Reuses the same DRWP_* helpers the admin
 * pages and REST API call so behavior stays identical regardless of
 * the entry point.
 */
class DRWP_CLI {

    public static function register() {
        WP_CLI::add_command('drwp report list',     [__CLASS__, 'report_list']);
        WP_CLI::add_command('drwp report show',     [__CLASS__, 'report_show']);
        WP_CLI::add_command('drwp report convert',  [__CLASS__, 'report_convert']);
        WP_CLI::add_command('drwp license check',     [__CLASS__, 'license_check']);
        WP_CLI::add_command('drwp license fetch-key', [__CLASS__, 'license_fetch_key']);
        WP_CLI::add_command('drwp project list',    [__CLASS__, 'project_list']);
        WP_CLI::add_command('drwp audit tail',      [__CLASS__, 'audit_tail']);
    }

    /**
     * List reports.
     *
     * ## OPTIONS
     *
     * [--status=<status>]
     * : Filter by review_status (pending / approved / needs_revision).
     *
     * [--project=<id>]
     * : Filter by project_id.
     *
     * [--limit=<n>]
     * : Max rows. Default 50.
     *
     * [--format=<format>]
     * : table, csv, json, yaml. Default table.
     */
    public static function report_list($args, $assoc) {
        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';
        $where = '1=1';
        $params = [];
        if (!empty($assoc['status'])) {
            $where .= ' AND review_status = %s';
            $params[] = sanitize_text_field((string) $assoc['status']);
        }
        if (!empty($assoc['project'])) {
            $where .= ' AND project_id = %d';
            $params[] = (int) $assoc['project'];
        }
        $limit = max(1, (int) ($assoc['limit'] ?? 50));
        $sql = "SELECT id, report_date, public_title, review_status, post_status, linked_post_id
                FROM $table WHERE $where ORDER BY report_date DESC, id DESC LIMIT %d";
        $params[] = $limit;
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        $format = $assoc['format'] ?? 'table';
        WP_CLI\Utils\format_items($format, $rows, ['id', 'report_date', 'public_title', 'review_status', 'post_status', 'linked_post_id']);
    }

    /**
     * Show one report.
     *
     * ## OPTIONS
     *
     * <id>
     * : Report ID.
     *
     * [--format=<format>]
     * : table, json, yaml. Default table.
     */
    public static function report_show($args, $assoc) {
        $id = (int) ($args[0] ?? 0);
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $wpdb->prefix . 'drwp_reports WHERE id = %d', $id
        ), ARRAY_A);
        if (!$row) WP_CLI::error("Report #$id not found.");

        $format = $assoc['format'] ?? 'table';
        if ($format === 'table') {
            $rows = [];
            foreach ($row as $k => $v) {
                $rows[] = ['field' => $k, 'value' => is_string($v) ? mb_substr((string) $v, 0, 200) : $v];
            }
            WP_CLI\Utils\format_items('table', $rows, ['field', 'value']);
        } else {
            WP_CLI\Utils\format_items($format, [$row], array_keys($row));
        }
    }

    /**
     * Convert a report to a WordPress post.
     *
     * ## OPTIONS
     *
     * <id>
     * : Report ID.
     */
    public static function report_convert($args, $assoc) {
        $id = (int) ($args[0] ?? 0);
        if (!DRWP_License::can_convert()) {
            WP_CLI::error('License is not active. Run `wp drwp license check` first.');
        }
        $post_id = DRWP_Post_Converter::sync_post($id, true);
        if (is_wp_error($post_id)) WP_CLI::error($post_id->get_error_message());
        WP_CLI::success("Report #$id → post #$post_id");
    }

    /**
     * Trigger a license server check_now.
     */
    public static function license_check($args, $assoc) {
        $r = DRWP_License::check_now();
        if (is_wp_error($r)) WP_CLI::error($r->get_error_message());
        WP_CLI::success("status=$r signature_valid=" . get_option(DRWP_License::OPT_SIGNATURE_VALID));
    }

    /**
     * Refresh the cached public key from the license server.
     */
    public static function license_fetch_key($args, $assoc) {
        $r = DRWP_License::fetch_public_key();
        if (is_wp_error($r)) WP_CLI::error($r->get_error_message());
        WP_CLI::success('public key cached: ' . substr($r, 0, 16) . '…');
    }

    /**
     * List projects.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table, csv, json. Default table.
     */
    public static function project_list($args, $assoc) {
        $rows = array_map(function ($p) {
            return ['id' => $p->id, 'name' => $p->name, 'status' => $p->status];
        }, DRWP_Project::all());
        WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $rows, ['id', 'name', 'status']);
    }

    /**
     * Tail the audit log.
     *
     * ## OPTIONS
     *
     * [--event=<event>]
     * : Filter by event key.
     *
     * [--report=<id>]
     * : Filter by report_id.
     *
     * [--n=<count>]
     * : Number of rows. Default 20.
     *
     * [--format=<format>]
     * : table, json, csv. Default table.
     */
    public static function audit_tail($args, $assoc) {
        $filters = [];
        if (!empty($assoc['event']))  $filters['event']     = sanitize_key($assoc['event']);
        if (!empty($assoc['report'])) $filters['report_id'] = (int) $assoc['report'];
        $n = max(1, (int) ($assoc['n'] ?? 20));
        $rows = array_map(function ($r) {
            return [
                'id'         => (int) $r['id'],
                'created_at' => $r['created_at'],
                'event'      => $r['event'],
                'user'       => DRWP_User::display_name((int) $r['user_id']) ?: ($r['display_name'] ?: '#' . (int) $r['user_id']),
                'report_id'  => (int) ($r['report_id'] ?? 0),
                'message'    => $r['message'],
            ];
        }, DRWP_Audit::search($filters, $n, 0));
        WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $rows,
            ['id', 'created_at', 'event', 'user', 'report_id', 'message']);
    }
}

DRWP_CLI::register();
