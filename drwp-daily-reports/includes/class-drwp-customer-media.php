<?php
if (!defined('ABSPATH')) exit;

/**
 * 顧客の登録画像 — multiple images per customer (logo / 名刺 /
 * 外観 / etc). Storage mirrors `drwp_report_photos`: each row is a
 * pointer at an existing WP attachment with an optional caption +
 * sort order so the operator can reorder via drag-and-drop in the
 * edit modal.
 */
class DRWP_Customer_Media {
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'drwp_customer_photos';
    }

    public static function for_customer($customer_id) {
        global $wpdb;
        $customer_id = (int) $customer_id;
        if (!$customer_id) return [];
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table()
            . ' WHERE customer_id = %d ORDER BY sort_order ASC, id ASC',
            $customer_id
        ));
    }

    /**
     * One-shot bulk fetch keyed by customer_id — used by the
     * customer list page to render a "🖼 N" indicator without
     * issuing one query per row.
     */
    public static function counts(array $customer_ids) {
        global $wpdb;
        $customer_ids = array_values(array_unique(array_map('intval', $customer_ids)));
        if (!$customer_ids) return [];
        $place = implode(',', array_fill(0, count($customer_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT customer_id, COUNT(*) AS cnt FROM ' . self::table()
            . " WHERE customer_id IN ($place) GROUP BY customer_id",
            $customer_ids
        ));
        $out = [];
        foreach ($rows as $r) $out[(int) $r->customer_id] = (int) $r->cnt;
        return $out;
    }

    public static function sync($customer_id, array $rows) {
        global $wpdb;
        $customer_id = (int) $customer_id;
        if (!$customer_id) return 0;

        $wpdb->delete(self::table(), ['customer_id' => $customer_id]);

        $saved = 0;
        $order = 0;
        foreach ($rows as $row) {
            $attachment_id = (int) ($row['attachment_id'] ?? 0);
            if (!$attachment_id) continue;
            if (get_post_type($attachment_id) !== 'attachment') continue;

            $caption = isset($row['caption'])
                ? sanitize_text_field(wp_unslash($row['caption']))
                : '';

            $wpdb->insert(self::table(), [
                'customer_id'   => $customer_id,
                'attachment_id' => $attachment_id,
                'caption'       => $caption !== '' ? $caption : null,
                'sort_order'    => $order++,
            ]);
            $saved++;
        }
        return $saved;
    }
}
