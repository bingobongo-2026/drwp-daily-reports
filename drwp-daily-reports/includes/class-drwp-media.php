<?php
if (!defined('ABSPATH')) exit;

class DRWP_Media {
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'drwp_report_photos';
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

    /** Allowed values for `photo_kind`. */
    const KIND_NORMAL = 'normal';
    const KIND_BEFORE = 'before';
    const KIND_AFTER  = 'after';

    public static function normalize_kind($value) {
        $v = strtolower(trim((string) $value));
        return in_array($v, [self::KIND_BEFORE, self::KIND_AFTER, self::KIND_NORMAL], true)
            ? $v : self::KIND_NORMAL;
    }

    public static function sync($report_id, array $rows) {
        global $wpdb;
        $report_id = (int) $report_id;
        if (!$report_id) return 0;

        $wpdb->delete(self::table(), ['report_id' => $report_id]);

        $saved = 0;
        $order = 0;
        foreach ($rows as $row) {
            $attachment_id = (int) ($row['attachment_id'] ?? 0);
            if (!$attachment_id) continue;
            if (get_post_type($attachment_id) !== 'attachment') continue;

            $caption = isset($row['caption']) ? sanitize_text_field(wp_unslash($row['caption'])) : '';
            // 'normal' は DB 上 NULL に倒す (デフォルト値と等価)。
            $kind = isset($row['photo_kind']) ? self::normalize_kind($row['photo_kind']) : self::KIND_NORMAL;

            // entry_id is a legacy column from the v1.9–1.10
            // multi-entry model; new rows always write NULL.
            $wpdb->insert(self::table(), [
                'report_id'     => $report_id,
                'entry_id'      => null,
                'attachment_id' => $attachment_id,
                'caption'       => $caption !== '' ? $caption : null,
                'photo_kind'    => $kind === self::KIND_NORMAL ? null : $kind,
                'sort_order'    => $order++,
            ]);
            $saved++;
        }
        return $saved;
    }

    public static function render_figure($photo, $size = 'large') {
        $url = wp_get_attachment_image_url((int) $photo->attachment_id, $size);
        if (!$url) return '';
        $caption = (string) ($photo->caption ?? '');
        $html = '<figure class="drwp-photo">';
        $html .= '<img src="' . esc_url($url) . '" alt="' . esc_attr($caption) . '" />';
        if ($caption !== '') {
            $html .= '<figcaption>' . esc_html($caption) . '</figcaption>';
        }
        $html .= '</figure>';
        return $html;
    }
}
