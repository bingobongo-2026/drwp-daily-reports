<?php
if (!defined('ABSPATH')) exit;

/**
 * One day's report can list several jobsite visits ("entries"). Each
 * entry stores its own project, time window, work narrative, issues,
 * next plan, photo set, and (after conversion) the WordPress post it
 * generated. This class is the CRUD surface for that table.
 *
 * Legacy single-site reports remain on the flat fields (project_id /
 * work_description / etc.) on drwp_reports itself; the absence of any
 * entry rows is the signal that this is the pre-1.9 shape.
 */
class DRWP_Report_Entry {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'drwp_report_entries';
    }

    public static function for_report($report_id) {
        global $wpdb;
        $report_id = (int) $report_id;
        if (!$report_id) return [];
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE report_id = %d ORDER BY sort_order ASC, id ASC',
            $report_id
        ));
    }

    public static function find($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE id = %d',
            (int) $id
        ));
    }

    /**
     * Replace all entries for a report. The caller hands us a list of
     * associative arrays; each may carry attachment_ids /
     * attachment_captions for that entry. Photos are re-synced under
     * DRWP_Media so they pick up the new entry_ids in one shot — no
     * orphan rows from a delete-then-fail-to-insert sequence.
     *
     * Returns the number of entry rows kept.
     */
    public static function sync($report_id, array $entries) {
        global $wpdb;
        $report_id = (int) $report_id;
        if (!$report_id) return 0;

        // Wipe the previous set. Photos are rebuilt below from the
        // entries we're about to insert, so any orphans clear too.
        $wpdb->delete(self::table(), ['report_id' => $report_id]);

        $photo_rows = [];
        $order = 0;
        foreach ($entries as $raw) {
            $row = self::sanitize_writable((array) $raw);
            if (self::is_empty_row($row)) continue;
            $row['report_id'] = $report_id;
            $row['sort_order'] = $order++;
            $wpdb->insert(self::table(), $row);
            $entry_id = (int) $wpdb->insert_id;
            if (!$entry_id) continue;

            $att_ids = (array) ($raw['attachment_ids'] ?? []);
            $caps    = (array) ($raw['attachment_captions'] ?? []);
            foreach ($att_ids as $i => $att) {
                $photo_rows[] = [
                    'attachment_id' => (int) $att,
                    'caption'       => (string) ($caps[$i] ?? ''),
                    'entry_id'      => $entry_id,
                ];
            }
        }

        DRWP_Media::sync($report_id, $photo_rows);
        return $order;
    }

    public static function delete_for_report($report_id) {
        global $wpdb;
        return (int) $wpdb->delete(self::table(), ['report_id' => (int) $report_id]);
    }

    public static function shape($entry) {
        if (!$entry) return null;
        $photos = array_values(array_filter(
            DRWP_Media::for_report((int) $entry->report_id),
            function ($p) use ($entry) { return (int) $p->entry_id === (int) $entry->id; }
        ));
        return [
            'id'               => (int) $entry->id,
            'report_id'        => (int) $entry->report_id,
            'sort_order'       => (int) $entry->sort_order,
            'project_id'       => $entry->project_id ? (int) $entry->project_id : null,
            'started_at'       => $entry->started_at ?: null,
            'ended_at'         => $entry->ended_at ?: null,
            'work_description' => (string) $entry->work_description,
            'issues'           => (string) $entry->issues,
            'next_plan'        => (string) $entry->next_plan,
            'public_title'     => (string) ($entry->public_title ?? ''),
            'public_body'      => (string) ($entry->public_body ?? ''),
            'linked_post_id'   => $entry->linked_post_id ? (int) $entry->linked_post_id : null,
            'photos'           => array_map(function ($p) {
                return [
                    'attachment_id' => (int) $p->attachment_id,
                    'caption'       => (string) ($p->caption ?? ''),
                    'thumbnail_url' => wp_get_attachment_image_url((int) $p->attachment_id, 'thumbnail'),
                    'full_url'      => wp_get_attachment_url((int) $p->attachment_id),
                ];
            }, $photos),
        ];
    }

    public static function set_linked_post($entry_id, $post_id) {
        global $wpdb;
        $wpdb->update(self::table(), ['linked_post_id' => (int) $post_id], ['id' => (int) $entry_id]);
    }

    private static function sanitize_writable(array $row) {
        $out = [
            'project_id'       => isset($row['project_id']) && $row['project_id'] ? (int) $row['project_id'] : null,
            'started_at'       => self::sanitize_time($row['started_at'] ?? null),
            'ended_at'         => self::sanitize_time($row['ended_at'] ?? null),
            'work_description' => isset($row['work_description']) ? wp_kses_post((string) $row['work_description']) : '',
            'issues'           => isset($row['issues']) ? wp_kses_post((string) $row['issues']) : '',
            'next_plan'        => isset($row['next_plan']) ? wp_kses_post((string) $row['next_plan']) : '',
            'public_title'     => isset($row['public_title']) ? sanitize_text_field((string) $row['public_title']) : '',
            'public_body'      => isset($row['public_body']) ? wp_kses_post((string) $row['public_body']) : '',
        ];
        return $out;
    }

    /**
     * Accept HH:MM and HH:MM:SS (HTML5 <input type=time> emits both
     * depending on browser/step). Anything else becomes NULL so the
     * DB constraint stays clean.
     */
    private static function sanitize_time($v) {
        $v = trim((string) $v);
        if ($v === '') return null;
        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $v)) {
            return strlen($v) === 5 ? $v . ':00' : $v;
        }
        return null;
    }

    private static function is_empty_row(array $row) {
        // Reject blanks that the mobile form may add when the user
        // taps "現場を追加" without filling anything in. A row with
        // no project AND no work text is noise.
        return empty($row['project_id'])
            && trim((string) $row['work_description']) === ''
            && trim((string) $row['issues']) === ''
            && trim((string) $row['next_plan']) === '';
    }
}
