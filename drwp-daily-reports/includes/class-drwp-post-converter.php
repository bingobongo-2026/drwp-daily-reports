<?php
if (!defined('ABSPATH')) exit;

class DRWP_Post_Converter {
    public static function normalize_tags($raw_tags) {
        if (empty($raw_tags)) return [];
        if (is_array($raw_tags)) {
            $tags = $raw_tags;
        } else {
            $tags = preg_split('/[,、\n\r]+/u', (string) $raw_tags);
        }
        $tags = array_map('trim', $tags);
        $tags = array_filter($tags, function($tag) { return $tag !== ''; });
        return array_values(array_unique($tags));
    }

    public static function build_content($report) {
        $html = '';
        if (!empty($report->public_intro)) {
            $html .= '<p>' . wp_kses_post(wpautop($report->public_intro)) . '</p>';
        }
        if (!empty($report->public_body)) {
            $html .= '<h2>本日の作業内容</h2>';
            $html .= wp_kses_post(wpautop($report->public_body));
        }
        if (!empty($report->public_next_plan)) {
            $html .= '<h2>今後の予定</h2>';
            $html .= wp_kses_post(wpautop($report->public_next_plan));
        }
        return $html;
    }

    public static function build_preview_html($report) {
        $category_name = '';
        if (!empty($report->post_category_id)) {
            $term = get_term((int) $report->post_category_id, 'category');
            if ($term && !is_wp_error($term)) {
                $category_name = $term->name;
            }
        }
        $tags = self::normalize_tags($report->post_tags ?? '');
        ob_start();
        ?>
        <div class="drwp-preview" style="background:#fff;border:1px solid #dcdcde;padding:16px;margin-top:16px;">
            <p style="margin:0 0 8px;color:#50575e;">公開プレビュー</p>
            <h2 style="margin-top:0;"><?php echo esc_html($report->public_title ?: '（公開タイトル未設定）'); ?></h2>
            <p style="color:#50575e;">
                状態: <?php echo esc_html($report->post_status ?: 'draft'); ?>
                <?php if (!empty($category_name)): ?> / カテゴリ: <?php echo esc_html($category_name); ?><?php endif; ?>
                <?php if (!empty($report->scheduled_at)): ?> / 公開予定: <?php echo esc_html($report->scheduled_at); ?><?php endif; ?>
            </p>
            <?php if (!empty($tags)): ?>
                <p style="color:#50575e;">タグ: <?php echo esc_html(implode(', ', $tags)); ?></p>
            <?php endif; ?>
            <hr />
            <?php echo self::build_content($report); ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function sync_post($report_id, $update_existing = true) {
        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';
        $report = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $report_id));
        if (!$report) return new WP_Error('drwp_missing', 'Report not found');
        if (!DRWP_License::can_convert()) return new WP_Error('drwp_license', 'License inactive');

        $post_data = [
            'post_title'   => $report->public_title ?: '現場レポート',
            'post_content' => self::build_content($report),
            'post_status'  => $report->post_status ?: 'draft',
            'post_type'    => 'post',
        ];

        if (!empty($report->scheduled_at) && $report->post_status === 'future') {
            $post_data['post_date'] = $report->scheduled_at;
        }

        if (!empty($report->linked_post_id) && $update_existing) {
            $post_data['ID'] = (int) $report->linked_post_id;
            $post_id = wp_update_post($post_data, true);
        } else {
            $post_id = wp_insert_post($post_data, true);
        }

        if (is_wp_error($post_id)) return $post_id;

        if (!empty($report->post_category_id)) {
            wp_set_post_categories($post_id, [(int) $report->post_category_id], false);
        }

        $tags = self::normalize_tags($report->post_tags ?? '');
        if (!empty($tags)) {
            wp_set_post_tags($post_id, $tags, false);
        }

        $wpdb->update($table, ['linked_post_id' => $post_id], ['id' => $report_id]);
        return $post_id;
    }
}
