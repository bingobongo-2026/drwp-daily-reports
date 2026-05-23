<?php
if (!defined('ABSPATH')) exit;

/**
 * Central place for user-facing label translations of internal
 * status / state strings.
 *
 * The plugin stores statuses as English identifiers in the DB
 * (`pending`, `approved`, `revision_requested`, `active`, `draft`,
 * `publish`, etc.) because those make stable SQL keys, switch
 * targets, and integration points. End-user UI in this Japanese
 * deployment needs Japanese labels though — instead of scattering
 * `__('レビュー待ち', ...)` lookups across every view, every
 * mapping lives here. Views call DRWP_Labels::review_status($row)
 * and never embed the English literal in output.
 *
 * Unknown values pass through as-is so adding a new state in the
 * future doesn't silently disappear from the UI — the dev sees
 * the raw key and knows to update this file.
 */
class DRWP_Labels {

    /** Daily-report review_status column. */
    public static function review_status($value) {
        $map = [
            'pending'            => __('レビュー待ち', 'drwp-daily-reports'),
            'approved'           => __('承認済み', 'drwp-daily-reports'),
            'revision_requested' => __('差戻し', 'drwp-daily-reports'),
            // Some older code paths used `needs_revision` — alias
            // it to the same Japanese label so legacy rows render
            // consistently with the current `revision_requested`.
            'needs_revision'     => __('差戻し', 'drwp-daily-reports'),
        ];
        return $map[(string) $value] ?? (string) $value;
    }

    /** WP-core post_status that this plugin actually uses. */
    public static function post_status($value) {
        $map = [
            'draft'   => __('下書き', 'drwp-daily-reports'),
            'pending' => __('保留中', 'drwp-daily-reports'),
            'future'  => __('予約', 'drwp-daily-reports'),
            'publish' => __('公開', 'drwp-daily-reports'),
        ];
        return $map[(string) $value] ?? (string) $value;
    }

    /** drwp_projects.status column. */
    public static function project_status($value) {
        $map = [
            'active'   => __('稼働中', 'drwp-daily-reports'),
            'inactive' => __('休止中', 'drwp-daily-reports'),
            'archived' => __('アーカイブ', 'drwp-daily-reports'),
        ];
        return $map[(string) $value] ?? (string) $value;
    }

    /** drwp_reports.post_template column (display name for the post template). */
    public static function post_template($value) {
        $map = [
            'standard'     => __('標準', 'drwp-daily-reports'),
            'site_report'  => __('現場レポート', 'drwp-daily-reports'),
            'before_after' => __('ビフォーアフター', 'drwp-daily-reports'),
        ];
        return $map[(string) $value] ?? (string) $value;
    }

    /**
     * Available post templates as id → 日本語ラベル.
     *
     * Returned in display order so an `option` loop / `select` can
     * iterate without having to know the underlying keys. Useful
     * for the edit page and the bulk-action dropdown where the
     * template choices need to be presented as a list.
     */
    public static function post_template_options() {
        return [
            'standard'     => self::post_template('standard'),
            'site_report'  => self::post_template('site_report'),
            'before_after' => self::post_template('before_after'),
        ];
    }
}
