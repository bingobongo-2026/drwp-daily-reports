<?php
if (!defined('ABSPATH')) exit;

class DRWP_Review {
    const ALLOWED_STATUSES = ['pending', 'approved', 'needs_revision'];

    public static function init() {
        add_action('admin_post_drwp_review_report', [__CLASS__, 'handle']);
        add_action('admin_post_drwp_add_comment', [__CLASS__, 'add_comment']);
    }

    public static function handle() {
        if (!current_user_can('edit_others_posts')) wp_die('forbidden');
        check_admin_referer('drwp_review_report');

        $id = absint($_POST['id'] ?? 0);
        $status = sanitize_text_field($_POST['review_status'] ?? '');
        if (!in_array($status, self::ALLOWED_STATUSES, true)) wp_die('invalid status');

        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';
        $report = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id)) : null;
        if (!$report) wp_die('not found');

        $wpdb->update($table, ['review_status' => $status], ['id' => $id]);

        $comment_id = 0;
        if (!empty($_POST['comment'])) {
            $comment_id = DRWP_Comment::insert($id, $_POST['comment']);
        }

        DRWP_Audit::log('review_status_changed', 'レビュー状態を変更', $id, [
            'from'       => $report->review_status,
            'to'         => $status,
            'comment_id' => $comment_id ?: null,
        ]);

        do_action(
            'drwp_review_changed',
            $id,
            (string) $report->review_status,
            $status,
            isset($_POST['comment']) ? wp_strip_all_tags(wp_unslash($_POST['comment'])) : ''
        );

        wp_safe_redirect(admin_url('admin.php?page=drwp_report_edit&id=' . $id . '&reviewed=1'));
        exit;
    }

    public static function add_comment() {
        check_admin_referer('drwp_add_comment');
        $id = absint($_POST['id'] ?? 0);
        if (!$id) wp_die('not found');

        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';
        $report = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
        if (!$report) wp_die('not found');

        $is_owner = (int) $report->user_id === get_current_user_id();
        if (!current_user_can('edit_others_posts') && !$is_owner) wp_die('forbidden');

        $raw = (string) ($_POST['comment'] ?? '');
        $comment_id = DRWP_Comment::insert($id, $raw);
        if ($comment_id) {
            DRWP_Audit::log('comment_added', 'コメントを追加', $id, ['comment_id' => $comment_id]);
            do_action('drwp_comment_added', $id, $comment_id, wp_strip_all_tags(wp_unslash($raw)));
        }
        wp_safe_redirect(admin_url('admin.php?page=drwp_report_edit&id=' . $id . '&commented=1'));
        exit;
    }
}
