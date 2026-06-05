<?php
if (!defined('ABSPATH')) exit;

/**
 * New-report form rendering + POST handlers for the front-end.
 *
 * The [drwp_report_form] shortcode is kept registered for backward
 * compatibility but no longer produces any output on its own —
 * [drwp_report_archive] is the single user-facing entry point and:
 *   - calls render_form() to embed the new-report form in a modal
 *   - provides a calendar view of reports
 *   - handles ?drwp_edit=N on its own
 *
 * Users with [drwp_report_form] in an existing page can leave it
 * there (no effect) or remove it. The handle_post() routine still
 * fires on template_redirect for the legacy "編集を依頼" POST.
 */
class DRWP_Report_Form {

    const HANDLE = 'drwp-mform';

    public static function init() {
        add_shortcode('drwp_report_form', [__CLASS__, 'render']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
        add_action('template_redirect', [__CLASS__, 'handle_post']);
    }

    public static function register_assets() {
        wp_register_style(
            self::HANDLE,
            DRWP_URL . 'public/assets/mobile-form.css',
            [],
            DRWP_VERSION
        );
        wp_register_script(
            self::HANDLE,
            DRWP_URL . 'public/assets/mobile-form.js',
            [],
            DRWP_VERSION,
            true
        );
    }

    public static function render($atts = [], $content = '') {
        // This shortcode is kept registered for backward compatibility
        // but no longer renders anything — [drwp_report_archive] is
        // now the single user-facing entry point and handles
        // ?drwp_new / ?drwp_edit on its own.
        return '';
    }

    /* ------------------------------------------------------------
     * Form rendering (?drwp_new=1) — public so [drwp_report_archive]
     * can embed the form inside a modal dialog.
     * ------------------------------------------------------------ */

    /* ------------------------------------------------------------
     * POST handlers (template_redirect)
     * ------------------------------------------------------------ */

    public static function handle_post() {
        if (!is_user_logged_in() || !current_user_can('edit_posts')) return;

        // Handle "編集を依頼" for approved reports.
        if (!empty($_POST['_drwp_request_edit'])) {
            $id = absint($_POST['drwp_id'] ?? 0);
            if (!$id) return;
            check_admin_referer('drwp_request_edit_' . $id);

            global $wpdb;
            $table = $wpdb->prefix . 'drwp_reports';
            $report = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
            if (!$report) return;
            if ((int) $report->user_id !== get_current_user_id()) return;
            if ($report->review_status !== 'approved') return;

            $wpdb->update($table, ['review_status' => 'edit_requested'], ['id' => $id]);
            DRWP_Audit::log('edit_requested', '編集依頼を送信', $id, []);
            do_action('drwp_review_changed', $id, 'approved', 'edit_requested', '');

            wp_safe_redirect(add_query_arg('drwp_requested', '1', get_permalink()));
            exit;
        }

        // Handle edit form save for own pending reports.
        if (!empty($_POST['_drwp_form_edit'])) {
            $id = absint($_POST['drwp_id'] ?? 0);
            if (!$id) return;
            check_admin_referer('drwp_form_edit_' . $id);

            global $wpdb;
            $table = $wpdb->prefix . 'drwp_reports';
            $report = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
            if (!$report) return;
            if ((int) $report->user_id !== get_current_user_id()) return;
            if ($report->review_status !== 'pending') return;
            if (!DRWP_License::can_write()) {
                wp_safe_redirect(add_query_arg(['drwp_edit' => $id, 'drwp_err' => 'license'], get_permalink()));
                exit;
            }

            $project_id = absint($_POST['project_id'] ?? 0);
            $work = trim((string) wp_unslash($_POST['work_description'] ?? ''));
            if (!$project_id) {
                wp_safe_redirect(add_query_arg(['drwp_edit' => $id, 'drwp_err' => 'noproject'], get_permalink()));
                exit;
            }
            if ($work === '') {
                wp_safe_redirect(add_query_arg(['drwp_edit' => $id, 'drwp_err' => 'nowork'], get_permalink()));
                exit;
            }

            $update = [
                'project_id'       => $project_id,
                'started_at'       => self::sanitize_time($_POST['started_at'] ?? ''),
                'ended_at'         => self::sanitize_time($_POST['ended_at'] ?? ''),
                'work_description' => wp_kses_post(wp_unslash($work)),
                'issues'           => wp_kses_post(wp_unslash((string) ($_POST['issues'] ?? ''))),
                'next_plan'        => wp_kses_post(wp_unslash((string) ($_POST['next_plan'] ?? ''))),
            ];
            $report_date = sanitize_text_field((string) wp_unslash($_POST['report_date'] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date)) {
                $update['report_date'] = $report_date;
            }
            $wpdb->update($table, $update, ['id' => $id]);

            $att_ids  = array_map('absint', (array) ($_POST['attachment_ids'] ?? []));
            $captions = array_map('sanitize_text_field', array_map('wp_unslash', (array) ($_POST['attachment_captions'] ?? [])));
            $rows = [];
            foreach ($att_ids as $i => $aid) {
                if (!$aid) continue;
                $rows[] = ['attachment_id' => $aid, 'caption' => (string) ($captions[$i] ?? '')];
            }
            DRWP_Media::sync($id, $rows);
            DRWP_Audit::log('report_edited_frontend', '日報をフロントから編集', $id, []);

            wp_safe_redirect(add_query_arg('drwp_saved', '1', get_permalink()));
            exit;
        }
    }

    private static function sanitize_time($v) {
        $v = trim((string) $v);
        if ($v === '') return null;
        if (preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $v, $m)) {
            return sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0));
        }
        return null;
    }

    /* ------------------------------------------------------------
     * Edit form — ?drwp_edit=N (own pending only)
     * ------------------------------------------------------------ */

    public static function render_edit($id) {
        global $wpdb;
        $report = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . $wpdb->prefix . "drwp_reports WHERE id = %d", $id
        ));
        if (!$report || (int) $report->user_id !== get_current_user_id()
            || $report->review_status !== 'pending') {
            return self::wrap('<p class="drwp-mform-list-empty">'
                . esc_html__('この日報は編集できません(自分のレビュー待ち日報のみ編集可能です)。', 'drwp-daily-reports')
                . '</p>');
        }

        wp_enqueue_script('drwp-archive-edit', DRWP_URL . 'public/assets/archive-edit.js', [], DRWP_VERSION, true);

        $projects = DRWP_Project::all();
        $photos   = DRWP_Media::for_report((int) $report->id);
        $back     = esc_url(remove_query_arg('drwp_edit'));
        $action   = esc_url(get_permalink());

        $flash = isset($_GET['drwp_err']) ? sanitize_key((string) $_GET['drwp_err']) : '';

        ob_start();
        ?>
        <div class="drwp-mform-wrap drwp-mform-edit-wrap">
            <p class="drwp-mform-back">
                <a href="<?php echo $back; ?>">&laquo; <?php esc_html_e('一覧に戻る', 'drwp-daily-reports'); ?></a>
            </p>
            <h2 class="drwp-mform-edit-title"><?php esc_html_e('日報を編集', 'drwp-daily-reports'); ?></h2>

            <?php if ($flash === 'noproject'): ?>
                <p class="drwp-mform-status err"><?php esc_html_e('案件を選択してください。', 'drwp-daily-reports'); ?></p>
            <?php elseif ($flash === 'nowork'): ?>
                <p class="drwp-mform-status err"><?php esc_html_e('作業内容を入力してください。', 'drwp-daily-reports'); ?></p>
            <?php elseif ($flash === 'license'): ?>
                <p class="drwp-mform-status err"><?php esc_html_e('現在保存できない状態です。', 'drwp-daily-reports'); ?></p>
            <?php endif; ?>

            <form method="post" action="<?php echo $action; ?>"
                  class="drwp-mform drwp-archive-edit-form"
                  data-drwp-edit-config="<?php echo esc_attr(wp_json_encode([
                      'rest_root' => esc_url_raw(rest_url('drwp/v1/')),
                      'nonce'     => wp_create_nonce('wp_rest'),
                      'i18n'      => [
                          'uploading'    => __('アップロード中…', 'drwp-daily-reports'),
                          'upload_failed'=> __('アップロード失敗', 'drwp-daily-reports'),
                      ],
                  ])); ?>" novalidate>
                <?php wp_nonce_field('drwp_form_edit_' . $id); ?>
                <input type="hidden" name="_drwp_form_edit" value="1" />
                <input type="hidden" name="drwp_id" value="<?php echo (int) $id; ?>" />

                <label class="drwp-mform-row">
                    <span class="drwp-mform-label"><?php esc_html_e('日付', 'drwp-daily-reports'); ?></span>
                    <input type="date" name="report_date"
                           value="<?php echo esc_attr((string) $report->report_date); ?>" required />
                </label>
                <label class="drwp-mform-row">
                    <span class="drwp-mform-label"><?php esc_html_e('案件', 'drwp-daily-reports'); ?> <em>*</em></span>
                    <select name="project_id" required>
                        <option value=""><?php esc_html_e('選択してください', 'drwp-daily-reports'); ?></option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?php echo (int) $p->id; ?>" <?php selected((int) $report->project_id, (int) $p->id); ?>>
                                <?php echo esc_html($p->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="drwp-mform-times">
                    <label class="col">
                        <?php esc_html_e('開始時刻', 'drwp-daily-reports'); ?>
                        <input type="time" name="started_at"
                               value="<?php echo esc_attr(substr((string) ($report->started_at ?? ''), 0, 5)); ?>" />
                    </label>
                    <label class="col">
                        <?php esc_html_e('終了時刻', 'drwp-daily-reports'); ?>
                        <input type="time" name="ended_at"
                               value="<?php echo esc_attr(substr((string) ($report->ended_at ?? ''), 0, 5)); ?>" />
                    </label>
                </div>
                <label class="drwp-mform-row">
                    <span class="drwp-mform-label"><?php esc_html_e('作業内容', 'drwp-daily-reports'); ?> <em>*</em></span>
                    <textarea name="work_description" rows="4" required><?php echo esc_textarea((string) $report->work_description); ?></textarea>
                </label>
                <label class="drwp-mform-row">
                    <span class="drwp-mform-label"><?php esc_html_e('特記事項（反省・連絡・相談・提案、任意）', 'drwp-daily-reports'); ?></span>
                    <textarea name="issues" rows="2"><?php echo esc_textarea((string) $report->issues); ?></textarea>
                </label>
                <label class="drwp-mform-row">
                    <span class="drwp-mform-label"><?php esc_html_e('次回予定 (任意)', 'drwp-daily-reports'); ?></span>
                    <textarea name="next_plan" rows="2"><?php echo esc_textarea((string) $report->next_plan); ?></textarea>
                </label>

                <div class="drwp-mform-row">
                    <span class="drwp-mform-label"><?php esc_html_e('写真', 'drwp-daily-reports'); ?></span>
                    <div class="drwp-archive-edit-photos" data-role="photos">
                        <?php foreach ($photos as $photo): ?>
                            <?php $thumb = wp_get_attachment_image_url((int) $photo->attachment_id, 'medium'); ?>
                            <div class="drwp-archive-edit-photo-item">
                                <?php if ($thumb): ?>
                                    <img src="<?php echo esc_url($thumb); ?>" alt="" />
                                <?php endif; ?>
                                <input type="hidden" name="attachment_ids[]"
                                       value="<?php echo (int) $photo->attachment_id; ?>" />
                                <input type="text" name="attachment_captions[]"
                                       placeholder="<?php esc_attr_e('キャプション', 'drwp-daily-reports'); ?>"
                                       value="<?php echo esc_attr((string) $photo->caption); ?>" />
                                <button type="button" class="drwp-archive-edit-photo-remove" data-role="remove-photo">×</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <label class="drwp-archive-edit-photo-pick">
                        + <?php esc_html_e('写真を追加', 'drwp-daily-reports'); ?>
                        <input type="file" accept="image/*" multiple data-role="photo-input" />
                    </label>
                    <p class="drwp-archive-edit-photo-status" data-role="photo-status"></p>
                </div>

                <div class="drwp-mform-edit-actions">
                    <button type="submit" class="drwp-mform-submit"><?php esc_html_e('保存する', 'drwp-daily-reports'); ?></button>
                    <a class="drwp-mform-edit-cancel" href="<?php echo $back; ?>"><?php esc_html_e('キャンセル', 'drwp-daily-reports'); ?></a>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ------------------------------------------------------------
     * Input form — ?drwp_new=1
     * ------------------------------------------------------------ */

    public static function render_form() {
        $projects = array_map(function ($p) {
            return ['id' => (int) $p->id, 'name' => (string) $p->name];
        }, DRWP_Project::all());

        $config = [
            'rest_root'   => esc_url_raw(rest_url('drwp/v1/')),
            'nonce'       => wp_create_nonce('wp_rest'),
            'today'       => current_time('Y-m-d'),
            'license_ok'  => DRWP_License::can_write(),
            'projects'    => $projects,
            'i18n'        => [
                'pick_project' => __('選択してください', 'drwp-daily-reports'),
                'need_project' => __('案件を選択してください。', 'drwp-daily-reports'),
                'need_work'    => __('作業内容を入力してください。', 'drwp-daily-reports'),
                'uploading'    => __('写真をアップロード中…', 'drwp-daily-reports'),
                'sending'      => __('送信中…', 'drwp-daily-reports'),
                'sent'         => __('送信しました。レビュー待ちに入っています。', 'drwp-daily-reports'),
                'send_failed'  => __('送信に失敗しました。', 'drwp-daily-reports'),
            ],
        ];

        wp_enqueue_script(self::HANDLE);

        $config_attr = wp_json_encode($config);
        if ($config_attr === false) $config_attr = '{}';

        $back_url = esc_url(remove_query_arg('drwp_new'));

        ob_start();
        ?>
        <div class="drwp-mform-wrap" data-drwp-mform-config="<?php echo esc_attr($config_attr); ?>">
            <p class="drwp-mform-back">
                <a href="<?php echo $back_url; ?>">&laquo; <?php esc_html_e('一覧に戻る', 'drwp-daily-reports'); ?></a>
            </p>
            <?php if (!$config['license_ok']) : ?>
                <p class="drwp-mform-warn">
                    <?php esc_html_e('現在ライセンスが有効ではないため、送信しても保存されません。管理者に確認してください。', 'drwp-daily-reports'); ?>
                </p>
            <?php endif; ?>

            <form class="drwp-mform" id="drwp-mform" novalidate>
                <label class="drwp-mform-row">
                    <span class="drwp-mform-label"><?php esc_html_e('日付', 'drwp-daily-reports'); ?></span>
                    <input type="date" name="report_date" value="<?php echo esc_attr($config['today']); ?>" required>
                </label>

                <label class="drwp-mform-row">
                    <span class="drwp-mform-label">
                        <?php esc_html_e('案件', 'drwp-daily-reports'); ?> <em>*</em>
                    </span>
                    <select name="project_id" required>
                        <option value=""><?php esc_html_e('選択してください', 'drwp-daily-reports'); ?></option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?php echo (int) $p['id']; ?>"><?php echo esc_html($p['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="drwp-mform-times">
                    <label class="col">
                        <?php esc_html_e('開始時刻', 'drwp-daily-reports'); ?>
                        <input type="time" name="started_at">
                    </label>
                    <label class="col">
                        <?php esc_html_e('終了時刻', 'drwp-daily-reports'); ?>
                        <input type="time" name="ended_at">
                    </label>
                </div>

                <label class="drwp-mform-row">
                    <span class="drwp-mform-label">
                        <?php esc_html_e('作業内容', 'drwp-daily-reports'); ?> <em>*</em>
                    </span>
                    <textarea name="work_description" rows="4" required></textarea>
                </label>

                <label class="drwp-mform-row">
                    <span class="drwp-mform-label"><?php esc_html_e('特記事項（反省・連絡・相談・提案、任意）', 'drwp-daily-reports'); ?></span>
                    <textarea name="issues" rows="2"></textarea>
                </label>

                <label class="drwp-mform-row">
                    <span class="drwp-mform-label"><?php esc_html_e('次回予定 (任意)', 'drwp-daily-reports'); ?></span>
                    <textarea name="next_plan" rows="2"></textarea>
                </label>

                <div class="drwp-mform-row">
                    <span class="drwp-mform-label"><?php esc_html_e('写真', 'drwp-daily-reports'); ?></span>
                    <label class="drwp-mform-photo-pick">
                        <span><?php esc_html_e('カメラで撮影 / 端末から選択', 'drwp-daily-reports'); ?></span>
                        <input type="file" accept="image/*" capture="environment" multiple data-role="photo-input">
                    </label>
                    <div class="drwp-mform-photos" data-role="photo-preview"></div>
                </div>

                <button type="submit" class="drwp-mform-submit">
                    <?php esc_html_e('下書きとして送信', 'drwp-daily-reports'); ?>
                </button>

                <p class="drwp-mform-help">
                    <?php esc_html_e('送信した日報は「レビュー待ち」として保存されます。事務所側で内容を確認のうえ、必要に応じて公開されます。', 'drwp-daily-reports'); ?>
                </p>

                <div class="drwp-mform-status" data-role="status" aria-live="polite"></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function wrap($inner) {
        return '<div class="drwp-mform-wrap">' . $inner . '</div>';
    }
}
