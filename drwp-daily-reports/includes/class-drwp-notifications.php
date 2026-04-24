<?php
if (!defined('ABSPATH')) exit;

class DRWP_Notifications {
    const OPT_ENABLED        = 'drwp_notify_enabled';
    const OPT_ON_PENDING     = 'drwp_notify_on_pending';
    const OPT_ON_REVIEW      = 'drwp_notify_on_review';
    const OPT_ON_COMMENT     = 'drwp_notify_on_comment';
    const OPT_FROM_EMAIL     = 'drwp_notify_from_email';

    public static function init() {
        add_action('drwp_report_submitted', [__CLASS__, 'on_report_submitted'], 10, 2);
        add_action('drwp_review_changed', [__CLASS__, 'on_review_changed'], 10, 4);
        add_action('drwp_comment_added', [__CLASS__, 'on_comment_added'], 10, 3);
    }

    public static function settings() {
        return [
            'enabled'    => self::is_on(self::OPT_ENABLED, true),
            'on_pending' => self::is_on(self::OPT_ON_PENDING, true),
            'on_review'  => self::is_on(self::OPT_ON_REVIEW, true),
            'on_comment' => self::is_on(self::OPT_ON_COMMENT, true),
            'from_email' => (string) get_option(self::OPT_FROM_EMAIL, ''),
        ];
    }

    public static function save_settings($values) {
        update_option(self::OPT_ENABLED,    !empty($values['enabled']) ? '1' : '0');
        update_option(self::OPT_ON_PENDING, !empty($values['on_pending']) ? '1' : '0');
        update_option(self::OPT_ON_REVIEW,  !empty($values['on_review']) ? '1' : '0');
        update_option(self::OPT_ON_COMMENT, !empty($values['on_comment']) ? '1' : '0');
        update_option(self::OPT_FROM_EMAIL, sanitize_email((string) ($values['from_email'] ?? '')));
    }

    private static function is_on($option, $default) {
        $v = get_option($option, null);
        if ($v === null) return (bool) $default;
        return $v === '1' || $v === 1 || $v === true;
    }

    private static function can_send() {
        return self::is_on(self::OPT_ENABLED, true);
    }

    private static function reviewer_emails() {
        $users = get_users([
            'capability' => 'edit_others_posts',
            'fields'     => ['ID', 'user_email'],
        ]);
        $emails = [];
        foreach ($users as $u) {
            $email = (string) $u->user_email;
            if ($email !== '' && is_email($email)) $emails[] = $email;
        }
        return array_values(array_unique($emails));
    }

    private static function owner_email($report) {
        if (empty($report->user_id)) return '';
        $user = get_user_by('id', (int) $report->user_id);
        return $user && is_email($user->user_email) ? (string) $user->user_email : '';
    }

    private static function headers() {
        $from = (string) get_option(self::OPT_FROM_EMAIL, '');
        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        if ($from !== '' && is_email($from)) {
            $headers[] = 'From: ' . get_bloginfo('name') . ' <' . $from . '>';
        }
        return $headers;
    }

    private static function report_url($report_id) {
        return admin_url('admin.php?page=drwp_report_edit&id=' . (int) $report_id);
    }

    public static function on_report_submitted($report_id, $report) {
        if (!self::can_send() || !self::is_on(self::OPT_ON_PENDING, true)) return;
        if (empty($report) || ($report->review_status ?? 'pending') !== 'pending') return;

        $to = self::reviewer_emails();
        if (empty($to)) return;

        $title = $report->public_title ?: ('#' . (int) $report_id);
        $subject = sprintf('[%s] 新しい日報がレビュー待ちです: %s', get_bloginfo('name'), $title);
        $body  = "レビュー待ちの日報が登録されました。\n\n";
        $body .= "タイトル: {$title}\n";
        $body .= "日付: " . ($report->report_date ?: '-') . "\n";
        $body .= "編集: " . self::report_url($report_id) . "\n";
        wp_mail($to, $subject, $body, self::headers());
        DRWP_Audit::log('notification_sent', 'レビュー待ち通知', $report_id, ['recipients' => count($to)]);
    }

    public static function on_review_changed($report_id, $from, $to, $comment) {
        if (!self::can_send() || !self::is_on(self::OPT_ON_REVIEW, true)) return;
        if (!in_array($to, ['approved', 'needs_revision'], true)) return;

        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';
        $report = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", (int) $report_id));
        if (!$report) return;

        $to_email = self::owner_email($report);
        if ($to_email === '') return;

        $label = $to === 'approved' ? '承認されました' : '差し戻されました';
        $title = $report->public_title ?: ('#' . (int) $report_id);
        $subject = sprintf('[%s] 日報が%s: %s', get_bloginfo('name'), $label, $title);
        $body  = "日報のレビュー結果が更新されました。\n\n";
        $body .= "タイトル: {$title}\n";
        $body .= "旧状態: {$from}\n";
        $body .= "新状態: {$to}\n";
        if ($comment !== '') {
            $body .= "\nレビュアコメント:\n{$comment}\n";
        }
        $body .= "\n編集: " . self::report_url($report_id) . "\n";
        wp_mail($to_email, $subject, $body, self::headers());
        DRWP_Audit::log('notification_sent', 'レビュー結果通知', $report_id, ['to' => $to_email]);
    }

    public static function on_comment_added($report_id, $comment_id, $comment_body) {
        if (!self::can_send() || !self::is_on(self::OPT_ON_COMMENT, true)) return;

        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';
        $report = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", (int) $report_id));
        if (!$report) return;

        $author_id = (int) get_current_user_id();
        $recipients = [];
        $owner_email = self::owner_email($report);
        if ($owner_email !== '' && (int) $report->user_id !== $author_id) {
            $recipients[] = $owner_email;
        }
        foreach (self::reviewer_emails() as $email) {
            $user = get_user_by('email', $email);
            if ($user && (int) $user->ID === $author_id) continue;
            $recipients[] = $email;
        }
        $recipients = array_values(array_unique($recipients));
        if (empty($recipients)) return;

        $title = $report->public_title ?: ('#' . (int) $report_id);
        $subject = sprintf('[%s] 日報に新しいコメント: %s', get_bloginfo('name'), $title);
        $body  = "日報に新しいコメントが追加されました。\n\n";
        $body .= "タイトル: {$title}\n";
        $body .= "コメント:\n" . wp_strip_all_tags((string) $comment_body) . "\n";
        $body .= "\n編集: " . self::report_url($report_id) . "\n";
        wp_mail($recipients, $subject, $body, self::headers());
        DRWP_Audit::log('notification_sent', 'コメント通知', $report_id, ['recipients' => count($recipients)]);
    }
}
