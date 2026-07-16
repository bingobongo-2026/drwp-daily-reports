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

    /**
     * Build the post HTML body. Dispatches on `post_template`:
     *
     * - `standard`     : 既定 — public_intro → 作業内容 → 写真 → 今後の予定
     * - `site_report`  : 案件レポート — 冒頭に案件メタ表(案件名 / 報告日
     *                    / 作業時間 / 報告者)、その下は標準と同じ
     * - `before_after` : ビフォーアフター — 写真ギャラリーを Before / After
     *                    の 2 列ペアに並べる。キャプション先頭の
     *                    `B:` `Before:` / `A:` `After:` で振り分け、
     *                    どちらの prefix も無ければ「前半 Before / 後半
     *                    After」に半分割する
     *
     * Each template returns a self-contained HTML string. Inline
     * styles are used for the structural pieces (meta table, paired
     * grid) so the post looks decent in any theme without depending
     * on additional CSS.
     */
    public static function build_content($report) {
        $template = (string) ($report->post_template ?? 'standard');
        switch ($template) {
            case 'site_report':  return self::template_site_report($report);
            case 'before_after': return self::template_before_after($report);
            case 'standard':
            default:             return self::template_standard($report);
        }
    }

    protected static function template_standard($report) {
        $html = '';
        if (!empty($report->public_intro)) {
            $html .= wp_kses_post(wpautop($report->public_intro));
        }
        if (!empty($report->public_body)) {
            $html .= '<h2>' . esc_html__('本日の作業内容', 'drwp-daily-reports') . '</h2>';
            $html .= wp_kses_post(wpautop($report->public_body));
        }
        $html .= self::build_photo_gallery($report);
        if (!empty($report->public_next_plan)) {
            $html .= '<h2>' . esc_html__('今後の予定', 'drwp-daily-reports') . '</h2>';
            $html .= wp_kses_post(wpautop($report->public_next_plan));
        }
        return $html;
    }

    protected static function template_site_report($report) {
        $html = self::build_meta_table($report);
        if (!empty($report->public_intro)) {
            $html .= wp_kses_post(wpautop($report->public_intro));
        }
        if (!empty($report->public_body)) {
            $html .= '<h2>' . esc_html__('作業内容', 'drwp-daily-reports') . '</h2>';
            $html .= wp_kses_post(wpautop($report->public_body));
        }
        $html .= self::build_photo_gallery($report);
        if (!empty($report->public_next_plan)) {
            $html .= '<h2>' . esc_html__('今後の予定', 'drwp-daily-reports') . '</h2>';
            $html .= wp_kses_post(wpautop($report->public_next_plan));
        }
        return $html;
    }

    protected static function template_before_after($report) {
        $html = '';
        if (!empty($report->public_intro)) {
            $html .= wp_kses_post(wpautop($report->public_intro));
        }
        $html .= self::build_before_after_grid($report);
        if (!empty($report->public_body)) {
            $html .= '<h2>' . esc_html__('作業内容', 'drwp-daily-reports') . '</h2>';
            $html .= wp_kses_post(wpautop($report->public_body));
        }
        if (!empty($report->public_next_plan)) {
            $html .= '<h2>' . esc_html__('今後の予定', 'drwp-daily-reports') . '</h2>';
            $html .= wp_kses_post(wpautop($report->public_next_plan));
        }
        return $html;
    }

    /**
     * 案件メタ表 — 案件レポートテンプレートの冒頭ヘッダー。案件名は
     * `drwp_projects` から、報告者は `DRWP_User::public_name()` から
     * 引く。この HTML は公開記事の本文になり、変換は wp-admin
     * (`admin-post.php`、`is_admin()` true) からも走るため、
     * `display_name()` ではなく社員名を絶対に使わない `public_name()`
     * を使う(社内呼称が公開面に漏れないようにする)。
     */
    protected static function build_meta_table($report) {
        $project_name = '';
        if (!empty($report->project_id)) {
            $proj = DRWP_Project::find((int) $report->project_id);
            if ($proj) $project_name = (string) $proj->name;
        }
        $author = !empty($report->user_id)
            ? DRWP_User::public_name((int) $report->user_id)
            : '';
        $report_date = !empty($report->report_date)
            ? date_i18n('Y年n月j日', strtotime((string) $report->report_date))
            : '';
        $time = '';
        $s = substr((string) ($report->started_at ?? ''), 0, 5);
        $e = substr((string) ($report->ended_at ?? ''), 0, 5);
        if ($s !== '' && $e !== '') $time = $s . ' 〜 ' . $e;
        elseif ($s !== '')          $time = $s;
        elseif ($e !== '')          $time = $e;

        $row = function ($label, $value) {
            return '<tr>'
                 . '<th style="background:#f1f5f9;border:1px solid #cbd5e1;padding:6px 10px;text-align:left;width:30%;font-weight:600;">'
                 . esc_html($label) . '</th>'
                 . '<td style="border:1px solid #cbd5e1;padding:6px 10px;">'
                 . esc_html($value !== '' ? $value : '-') . '</td>'
                 . '</tr>';
        };
        return '<table class="drwp-public-meta" style="border-collapse:collapse;width:100%;margin:0 0 16px;font-size:.95em;">'
             . '<tbody>'
             . $row(__('案件名', 'drwp-daily-reports'), $project_name)
             . $row(__('報告日', 'drwp-daily-reports'), $report_date)
             . $row(__('作業時間', 'drwp-daily-reports'), $time)
             . $row(__('報告者', 'drwp-daily-reports'), $author)
             . '</tbody></table>';
    }

    /**
     * Before / After ペアグリッド。キャプション先頭の `B:`
     * `Before:` / `A:` `After:` (全角コロン許容、大小文字無視)で
     * 振り分け、どちらの prefix も無ければ前半 Before / 後半
     * After に半分割。ペアにできなかった残りは末尾に並べる。
     */
    protected static function build_before_after_grid($report) {
        if (empty($report->id)) return '';
        $photos = DRWP_Media::for_report((int) $report->id);
        if (empty($photos)) return '';

        $before = [];
        $after  = [];
        $other  = [];
        foreach ($photos as $p) {
            $caption = (string) ($p->caption ?? '');
            $kind    = DRWP_Media::normalize_kind($p->photo_kind ?? '');
            // 1. 写真側に明示の photo_kind があればそれを最優先。
            //    UI のラジオで Before/After を選んだ場合がこれ。
            if ($kind === DRWP_Media::KIND_BEFORE) {
                $p->display_caption = $caption;
                $before[] = $p;
                continue;
            }
            if ($kind === DRWP_Media::KIND_AFTER) {
                $p->display_caption = $caption;
                $after[] = $p;
                continue;
            }
            // 2. photo_kind が 'normal' or NULL なら、後方互換の
            //    キャプション prefix (Before: / After:) も解釈する。
            if (preg_match('/^(?:Before|B)[:：]\s*(.*)$/iu', $caption, $m)) {
                $p->display_caption = trim($m[1]);
                $before[] = $p;
            } elseif (preg_match('/^(?:After|A)[:：]\s*(.*)$/iu', $caption, $m)) {
                $p->display_caption = trim($m[1]);
                $after[] = $p;
            } else {
                $p->display_caption = $caption;
                $other[] = $p;
            }
        }
        // どちらの分類も無い場合 (photo_kind 未指定 + prefix 無し) の
        // フォールバック: 前半 → Before / 後半 → After。
        // 奇数なら Before 側に 1 枚多めに振る (作業前のほうが基準写真として
        // 多くなりがちな現場感に合わせる)。
        if (!$before && !$after) {
            $half = (int) ceil(count($other) / 2);
            $before = array_slice($other, 0, $half);
            $after  = array_slice($other, $half);
            $other  = [];
        }

        $cell_style = 'flex:1 1 calc(50% - 8px);min-width:0;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:6px;padding:8px;background:#fff;';
        $img_style  = 'display:block;width:100%;height:auto;border-radius:4px;';
        $label_b = 'background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:999px;font-size:.78em;font-weight:700;display:inline-block;margin-bottom:6px;';
        $label_a = 'background:#dcfce7;color:#166534;padding:2px 8px;border-radius:999px;font-size:.78em;font-weight:700;display:inline-block;margin-bottom:6px;';
        $caption_style = 'font-size:.85em;color:#475569;margin-top:4px;line-height:1.4;';
        $row_style = 'display:flex;gap:12px;margin-bottom:12px;flex-wrap:wrap;';

        $render_cell = function ($photo, $label, $label_style) use ($cell_style, $img_style, $caption_style) {
            $url = $photo ? wp_get_attachment_image_url((int) $photo->attachment_id, 'large') : '';
            if (!$photo || !$url) {
                return '<figure style="' . $cell_style . 'opacity:.4;text-align:center;color:#94a3b8;">'
                     . '<span style="' . $label_style . '">' . esc_html($label) . '</span>'
                     . '<div style="padding:24px 0;">—</div></figure>';
            }
            $caption = (string) ($photo->display_caption ?? $photo->caption ?? '');
            $alt = $caption !== '' ? $caption : $label;
            $html = '<figure style="' . $cell_style . 'margin:0;">';
            $html .= '<span style="' . $label_style . '">' . esc_html($label) . '</span>';
            $html .= '<img src="' . esc_url($url) . '" alt="' . esc_attr($alt) . '" style="' . $img_style . '" />';
            if ($caption !== '') {
                $html .= '<figcaption style="' . $caption_style . '">' . esc_html($caption) . '</figcaption>';
            }
            $html .= '</figure>';
            return $html;
        };

        $html  = '<h2>' . esc_html__('Before / After', 'drwp-daily-reports') . '</h2>';
        $html .= '<div class="drwp-public-before-after">';
        $pairs = max(count($before), count($after));
        for ($i = 0; $i < $pairs; $i++) {
            $html .= '<div style="' . $row_style . '">';
            $html .= $render_cell($before[$i] ?? null, __('Before', 'drwp-daily-reports'), $label_b);
            $html .= $render_cell($after[$i] ?? null,  __('After',  'drwp-daily-reports'), $label_a);
            $html .= '</div>';
        }
        $html .= '</div>';

        // ペアに入らなかった "その他" の写真は末尾に通常ギャラリー風で。
        if (!empty($other)) {
            $html .= '<div class="drwp-public-photos" style="margin-top:8px;">';
            foreach ($other as $photo) {
                $html .= DRWP_Media::render_figure($photo, 'large');
            }
            $html .= '</div>';
        }
        return $html;
    }

    protected static function build_photo_gallery($report) {
        if (empty($report->id)) return '';
        $photos = DRWP_Media::for_report((int) $report->id);
        if (empty($photos)) return '';
        $html = '<div class="drwp-public-photos">';
        foreach ($photos as $photo) {
            $html .= DRWP_Media::render_figure($photo, 'large');
        }
        $html .= '</div>';
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
            <p style="margin:0 0 8px;color:#50575e;"><?php esc_html_e('公開プレビュー', 'drwp-daily-reports'); ?></p>
            <h2 style="margin-top:0;"><?php echo esc_html($report->public_title ?: __('（公開タイトル未設定）', 'drwp-daily-reports')); ?></h2>
            <p style="color:#50575e;">
                <?php
                  printf(
                      /* translators: %s: post status label (下書き / 保留中 / 予約) */
                      esc_html__('状態: %s', 'drwp-daily-reports'),
                      esc_html(DRWP_Labels::post_status((string) ($report->post_status ?: 'draft')))
                  );
                ?>
                <?php if (!empty($category_name)): ?>
                  / <?php
                    printf(
                        /* translators: %s: WP category name */
                        esc_html__('カテゴリ: %s', 'drwp-daily-reports'),
                        esc_html($category_name)
                    );
                  ?>
                <?php endif; ?>
                <?php if (!empty($report->scheduled_at)): ?>
                  / <?php
                    printf(
                        /* translators: %s: scheduled publish datetime */
                        esc_html__('公開予定: %s', 'drwp-daily-reports'),
                        esc_html($report->scheduled_at)
                    );
                  ?>
                <?php endif; ?>
            </p>
            <?php if (!empty($tags)): ?>
                <p style="color:#50575e;"><?php
                  printf(
                      /* translators: %s: comma-separated tag list */
                      esc_html__('タグ: %s', 'drwp-daily-reports'),
                      esc_html(implode(', ', $tags))
                  );
                ?></p>
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
        $report = $wpdb->get_row($wpdb->prepare("SELECT $table.* FROM $table WHERE id = %d", $report_id));
        if (!$report) return new WP_Error('drwp_missing', '指定された日報が見つかりませんでした。');
        if (!DRWP_License::can_convert()) return new WP_Error('drwp_license', DRWP_License::convert_blocked_message());

        $is_update = !empty($report->linked_post_id) && $update_existing;

        // If the linked post was deleted (trashed or permanently
        // removed), clear the stale reference and fall through to
        // the insert path. Without this check wp_update_post would
        // silently fail or error on a non-existent ID.
        if ($is_update && !get_post((int) $report->linked_post_id)) {
            $wpdb->update($table, ['linked_post_id' => null], ['id' => $report_id]);
            $is_update = false;
            DRWP_Audit::log('linked_post_cleared', '連携記事が存在しないためリンクを解除', $report_id, [
                'old_post_id' => (int) $report->linked_post_id,
            ]);
        }

        // For updates, keep the original post_type so existing
        // permalinks / taxonomies don't break when an admin flips the
        // output setting later. Only new conversions honor the
        // current setting.
        if ($is_update) {
            $existing_type = get_post_type((int) $report->linked_post_id);
            $post_type = $existing_type ?: DRWP_Output::post_type();
        } else {
            $post_type = DRWP_Output::post_type();
        }

        $post_data = [
            'post_title'   => $report->public_title ?: __('案件レポート', 'drwp-daily-reports'),
            'post_content' => self::build_content($report),
            'post_status'  => $report->post_status ?: 'draft',
            'post_type'    => $post_type,
        ];

        if (!empty($report->scheduled_at) && $report->post_status === 'future') {
            $post_data['post_date'] = $report->scheduled_at;
            $post_data['post_date_gmt'] = get_gmt_from_date($report->scheduled_at);
        }

        if ($is_update) {
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

        // Featured image: take the first attached photo when (a)
        // the operator opted in via DRWP_Output::auto_thumbnail()
        // and (b) the post doesn't already have one — never overwrite
        // a thumbnail the editor may have set by hand.
        if (DRWP_Output::auto_thumbnail() && !has_post_thumbnail($post_id)) {
            $photos = DRWP_Media::for_report((int) $report->id);
            if (!empty($photos)) {
                set_post_thumbnail($post_id, (int) $photos[0]->attachment_id);
            }
        }

        $wpdb->update($table, ['linked_post_id' => $post_id], ['id' => $report_id]);
        DRWP_Audit::log(
            $is_update ? 'post_resynced' : 'post_created_from_report',
            $is_update ? '連携記事へ再反映' : '日報から記事を生成',
            $report_id,
            ['post_id' => (int) $post_id, 'post_type' => $post_type]
        );
        return $post_id;
    }
}
