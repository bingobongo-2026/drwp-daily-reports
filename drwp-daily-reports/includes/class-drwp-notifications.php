<?php
if (!defined('ABSPATH')) exit;

/**
 * Listener that turns plugin actions into wp_mail() notifications.
 * Subscribes to:
 *   - drwp_report_submitted ($id, $report)         — pending submission
 *   - drwp_review_changed   ($id, $from, $to, $c)  — review state change
 *   - drwp_comment_added    ($id, $comment_id, $b) — new comment
 *
 * Each branch has an independent toggle plus a master switch so an
 * operator can silence the plugin without uninstalling.
 */
class DRWP_Notifications {
    const OPT_ENABLED    = 'drwp_notify_enabled';
    const OPT_ON_PENDING = 'drwp_notify_on_pending';
    const OPT_ON_REVIEW  = 'drwp_notify_on_review';
    const OPT_ON_COMMENT = 'drwp_notify_on_comment';
    const OPT_FROM_EMAIL = 'drwp_notify_from_email';

    public static function init() {
        add_action('drwp_report_submitted', [__CLASS__, 'on_report_submitted'], 10, 2);
        add_action('drwp_review_changed',  [__CLASS__, 'on_review_changed'],  10, 4);
        add_action('drwp_comment_added',   [__CLASS__, 'on_comment_added'],   10, 3);
    }

    public static function settings() {
        return [
            'enabled'    => self::flag(self::OPT_ENABLED, true),
            'on_pending' => self::flag(self::OPT_ON_PENDING, true),
            'on_review'  => self::flag(self::OPT_ON_REVIEW, true),
            'on_comment' => self::flag(self::OPT_ON_COMMENT, true),
            'from_email' => (string) get_option(self::OPT_FROM_EMAIL, ''),
        ];
    }

    public static function save_settings(array $values) {
        update_option(self::OPT_ENABLED,    !empty($values['enabled'])    ? '1' : '0');
        update_option(self::OPT_ON_PENDING, !empty($values['on_pending']) ? '1' : '0');
        update_option(self::OPT_ON_REVIEW,  !empty($values['on_review'])  ? '1' : '0');
        update_option(self::OPT_ON_COMMENT, !empty($values['on_comment']) ? '1' : '0');
        update_option(self::OPT_FROM_EMAIL, sanitize_email((string) ($values['from_email'] ?? '')));
    }

    private static function flag($option, $default) {
        $v = get_option($option, null);
        if ($v === null) return (bool) $default;
        return $v === '1' || $v === 1 || $v === true;
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
        if (!self::flag(self::OPT_ENABLED, true)) return;
        if (!self::flag(self::OPT_ON_PENDING, true)) return;
        if (empty($report) || ($report->review_status ?? 'pending') !== 'pending') return;

        $to = self::reviewer_emails();
        if (empty($to)) return;

        $title = $report->public_title ?: ('#' . (int) $report_id);
        $subject = sprintf(
            /* translators: 1: site name 2: report title */
            __('[%1$s] 新しい日報が承認待ちです: %2$s', 'drwp-daily-reports'),
            get_bloginfo('name'),
            $title
        );
        $body  = __('承認待ちの日報が登録されました。', 'drwp-daily-reports') . "\n\n";
        $body .= __('タイトル', 'drwp-daily-reports') . ": $title\n";
        $body .= __('日付', 'drwp-daily-reports') . ": " . ($report->report_date ?: '-') . "\n";
        $body .= __('編集', 'drwp-daily-reports') . ": " . self::report_url($report_id) . "\n";

        if (wp_mail($to, $subject, $body, self::headers())) {
            DRWP_Audit::log('notification_sent', '日報承認待ち通知', $report_id, ['recipients' => count($to)]);
        }
    }

    public static function on_review_changed($report_id, $from, $to, $comment) {
        if (!self::flag(self::OPT_ENABLED, true)) return;
        if (!self::flag(self::OPT_ON_REVIEW, true)) return;
        if (!in_array($to, ['approved', 'needs_revision'], true)) return;

        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';
        $report = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", (int) $report_id));
        if (!$report) return;

        $to_email = self::owner_email($report);
        if ($to_email === '') return;

        $label = $to === 'approved'
            ? __('承認されました', 'drwp-daily-reports')
            : __('差し戻されました', 'drwp-daily-reports');
        $title = $report->public_title ?: ('#' . (int) $report_id);
        $subject = sprintf(
            /* translators: 1: site name 2: review label 3: report title */
            __('[%1$s] 日報が%2$s: %3$s', 'drwp-daily-reports'),
            get_bloginfo('name'),
            $label,
            $title
        );
        $body  = __('日報のレビュー結果が更新されました。', 'drwp-daily-reports') . "\n\n";
        $body .= __('タイトル', 'drwp-daily-reports') . ": $title\n";
        $body .= __('旧状態', 'drwp-daily-reports') . ": $from\n";
        $body .= __('新状態', 'drwp-daily-reports') . ": $to\n";
        if ($comment !== '') {
            $body .= "\n" . __('レビュアコメント', 'drwp-daily-reports') . ":\n$comment\n";
        }
        $body .= "\n" . __('編集', 'drwp-daily-reports') . ": " . self::report_url($report_id) . "\n";

        if (wp_mail($to_email, $subject, $body, self::headers())) {
            DRWP_Audit::log('notification_sent', 'レビュー結果通知', $report_id, ['to' => $to_email]);
        }
    }

    public static function on_comment_added($report_id, $comment_id, $comment_body) {
        if (!self::flag(self::OPT_ENABLED, true)) return;
        if (!self::flag(self::OPT_ON_COMMENT, true)) return;

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
            $u = get_user_by('email', $email);
            if ($u && (int) $u->ID === $author_id) continue;
            $recipients[] = $email;
        }
        $recipients = array_values(array_unique($recipients));
        if (empty($recipients)) return;

        $title = $report->public_title ?: ('#' . (int) $report_id);
        $subject = sprintf(
            /* translators: 1: site name 2: report title */
            __('[%1$s] 日報に新しいコメント: %2$s', 'drwp-daily-reports'),
            get_bloginfo('name'),
            $title
        );
        $body  = __('日報に新しいコメントが追加されました。', 'drwp-daily-reports') . "\n\n";
        $body .= __('タイトル', 'drwp-daily-reports') . ": $title\n";
        $body .= __('コメント', 'drwp-daily-reports') . ":\n" . wp_strip_all_tags((string) $comment_body) . "\n";
        $body .= "\n" . __('編集', 'drwp-daily-reports') . ": " . self::report_url($report_id) . "\n";

        if (wp_mail($recipients, $subject, $body, self::headers())) {
            DRWP_Audit::log('notification_sent', 'コメント通知', $report_id, ['recipients' => count($recipients)]);
        }
    }
}
