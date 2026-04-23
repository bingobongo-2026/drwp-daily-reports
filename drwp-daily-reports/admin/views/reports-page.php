<?php
if (!defined('ABSPATH')) exit;
global $wpdb;

$reports_table = $wpdb->prefix . 'drwp_reports';
$projects_table = $wpdb->prefix . 'drwp_projects';
$comments_table = $wpdb->prefix . 'drwp_comments';

$action = sanitize_text_field($_GET['action'] ?? 'list');
$id = intval($_GET['id'] ?? 0);

if ($action === 'edit') {
    $report = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $reports_table WHERE id = %d", $id), ARRAY_A) : null;
    if ($report && !DRWP_Report_Controller::can_edit_report($report) && !DRWP_Report_Controller::can_review_report() && !DRWP_Report_Controller::can_convert_post($report)) {
        wp_die('Unauthorized');
    }
    $photos = $id ? DRWP_Media::get_report_photos($id) : [];
    $comments = $id ? $wpdb->get_results($wpdb->prepare("SELECT c.*, u.display_name FROM $comments_table c LEFT JOIN {$wpdb->users} u ON c.user_id = u.ID WHERE report_id = %d ORDER BY c.id DESC", $id), ARRAY_A) : [];
    $audit_logs = $id ? DRWP_Audit::get_report_logs($id, 20) : [];
    $projects = $wpdb->get_results("SELECT * FROM $projects_table ORDER BY id DESC", ARRAY_A);
    require DRWP_DIR . 'admin/views/report-edit.php';
    return;
}

$where = ['1=1'];
$args = [];

if (!(current_user_can('manage_options') || current_user_can('drwp_edit_all_reports') || current_user_can('drwp_review_reports'))) {
    $where[] = 'r.user_id = %d';
    $args[] = get_current_user_id();
}

$search = sanitize_text_field($_GET['s'] ?? '');
$project_id = intval($_GET['project_id'] ?? 0);
$review_status = sanitize_text_field($_GET['review_status'] ?? '');
$date_from = sanitize_text_field($_GET['date_from'] ?? '');
$date_to = sanitize_text_field($_GET['date_to'] ?? '');

if ($project_id) {
    $where[] = 'r.project_id = %d';
    $args[] = $project_id;
}
if ($review_status !== '') {
    $where[] = 'r.review_status = %s';
    $args[] = $review_status;
}
if ($date_from !== '') {
    $where[] = 'r.report_date >= %s';
    $args[] = $date_from;
}
if ($date_to !== '') {
    $where[] = 'r.report_date <= %s';
    $args[] = $date_to;
}
if ($search !== '') {
    $like = '%' . $wpdb->esc_like($search) . '%';
    $where[] = '(p.name LIKE %s OR u.display_name LIKE %s OR r.work_category LIKE %s OR r.work_description LIKE %s OR r.public_title LIKE %s OR r.public_body LIKE %s)';
    array_push($args, $like, $like, $like, $like, $like, $like);
}

$where_sql = implode(' AND ', $where);
$per_page = 20;
$current_page = max(1, intval($_GET['paged'] ?? 1));
$offset = ($current_page - 1) * $per_page;

$count_sql = "SELECT COUNT(*) FROM $reports_table r
        LEFT JOIN $projects_table p ON p.id = r.project_id
        LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
        WHERE $where_sql";
$total_items = $args ? intval($wpdb->get_var($wpdb->prepare($count_sql, ...$args))) : intval($wpdb->get_var($count_sql));
$total_pages = max(1, (int) ceil($total_items / $per_page));

$sql = "SELECT r.*, p.name AS project_name, u.display_name AS user_name
        FROM $reports_table r
        LEFT JOIN $projects_table p ON p.id = r.project_id
        LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
        WHERE $where_sql
        ORDER BY r.report_date DESC, r.id DESC
        LIMIT %d OFFSET %d";

$query_args = $args;
$query_args[] = $per_page;
$query_args[] = $offset;

$reports = $wpdb->get_results($wpdb->prepare($sql, ...$query_args), ARRAY_A);
$projects = $wpdb->get_results("SELECT * FROM $projects_table ORDER BY id DESC", ARRAY_A);
require DRWP_DIR . 'admin/views/reports-list.php';
