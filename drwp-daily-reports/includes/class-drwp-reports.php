<?php
if (!defined('ABSPATH')) exit;

/**
 * 日報のアーカイブ (論理削除) / 復元 / 完全削除を扱うヘルパー。
 *
 * 設計方針:
 * - 一般操作 (社員) からは「削除」を一切露出しない。誤って消したり、
 *   バツが悪い結果を隠したりするケースを根本から防ぐ。
 * - レビュアー以上 (`edit_others_posts`) は archive / restore 可能。
 *   archived_at に時刻を入れて一覧から除外。実体は残るので AI や
 *   連携投稿、操作履歴は壊れない。
 * - 管理者 (`manage_options`) は、アーカイブ後一定期間 (既定 30 日)
 *   を経過した行のみ「完全削除」できる。GDPR / 個人情報削除依頼の
 *   ためのエスケープハッチ。
 */
class DRWP_Reports {
    const CAP_ARCHIVE    = 'edit_others_posts';
    const CAP_PURGE      = 'manage_options';
    const PURGE_MIN_DAYS = 30;

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'drwp_reports';
    }

    public static function find($id) {
        global $wpdb;
        $id = (int) $id;
        if (!$id) return null;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id
        ));
    }

    /** Whether the report row is currently archived. */
    public static function is_archived($report) {
        return $report && !empty($report->archived_at);
    }

    /**
     * Mark a report as archived. Idempotent — already-archived rows
     * return true without re-stamping `archived_at`.
     */
    public static function archive($id, $reason = '') {
        global $wpdb;
        $report = self::find($id);
        if (!$report) return false;
        if (self::is_archived($report)) return true;

        $wpdb->update(
            self::table(),
            ['archived_at' => current_time('mysql', true)],
            ['id' => (int) $id]
        );
        DRWP_Audit::log(
            'report_archived',
            sprintf(
                /* translators: %d is the report id */
                __('日報 #%d をアーカイブしました', 'drwp-daily-reports'),
                (int) $id
            ),
            (int) $id,
            $reason !== '' ? ['reason' => $reason] : []
        );
        return true;
    }

    /** Reverse `archive()`. */
    public static function restore($id) {
        global $wpdb;
        $report = self::find($id);
        if (!$report || !self::is_archived($report)) return false;

        $wpdb->update(
            self::table(),
            ['archived_at' => null],
            ['id' => (int) $id]
        );
        DRWP_Audit::log(
            'report_restored',
            sprintf(
                /* translators: %d is the report id */
                __('日報 #%d を復元しました', 'drwp-daily-reports'),
                (int) $id
            ),
            (int) $id
        );
        return true;
    }

    /**
     * Hard-delete a row that's already been archived for at least
     * PURGE_MIN_DAYS. Cascades to drwp_comments and drwp_report_photos.
     * Audit log entries are intentionally kept; their `report_id`
     * becomes a dangling pointer, which is acceptable because the
     * events still describe "what happened to id N at time T".
     */
    public static function purge($id) {
        global $wpdb;
        $report = self::find($id);
        if (!$report || !self::is_archived($report)) {
            return new WP_Error(
                'drwp_not_archived',
                __('完全削除はアーカイブ済みの日報のみ可能です。', 'drwp-daily-reports')
            );
        }
        $archived_ts = strtotime((string) $report->archived_at);
        if ($archived_ts === false || (time() - $archived_ts) < self::PURGE_MIN_DAYS * DAY_IN_SECONDS) {
            return new WP_Error(
                'drwp_purge_too_soon',
                sprintf(
                    /* translators: %d is the retention day count */
                    __('完全削除はアーカイブから %d 日経過後にのみ可能です。', 'drwp-daily-reports'),
                    self::PURGE_MIN_DAYS
                )
            );
        }

        $rid = (int) $id;
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . "drwp_report_photos WHERE report_id = %d", $rid));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . "drwp_comments WHERE report_id = %d", $rid));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table()    . " WHERE id = %d", $rid));

        DRWP_Audit::log(
            'report_purged',
            sprintf(
                /* translators: %d is the report id */
                __('日報 #%d を完全削除しました', 'drwp-daily-reports'),
                $rid
            ),
            $rid
        );
        return true;
    }

    /** Days since archive — used by UI to gate the purge button. */
    public static function days_since_archived($report) {
        if (!self::is_archived($report)) return null;
        $ts = strtotime((string) $report->archived_at);
        if ($ts === false) return null;
        return (int) floor((time() - $ts) / DAY_IN_SECONDS);
    }
}
