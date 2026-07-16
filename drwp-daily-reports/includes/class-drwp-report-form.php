<?php
if (!defined('ABSPATH')) exit;

/**
 * New-report form rendering for the front-end.
 *
 * `[drwp_report_archive]` is the single user-facing entry point and:
 *   - calls render_form() to embed the new-report form in a modal
 *   - provides a calendar view of reports
 *   - handles ?drwp_edit=N (its own render_edit) + report PATCH via REST
 *
 * This class is now just the mobile new-report form (render_form)
 * + its asset registration. The legacy template_redirect POST
 * handlers (編集を依頼 / pending-edit) were removed once the archive
 * took over editing via PATCH /reports/{id}.
 */
class DRWP_Report_Form {

    const HANDLE      = 'drwp-mform';
    const HANDLE_COMBO = 'drwp-combo';
    const HANDLE_MOSAIC = 'drwp-mosaic';

    public static function init() {
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
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
        // 共有の combobox 拡張。case insensitive な部分一致検索 + 「最近
        // 使った」グループ表示を行う。複数フォームで使い回す。
        wp_register_style(
            self::HANDLE_COMBO,
            DRWP_URL . 'public/assets/combo.css',
            [],
            DRWP_VERSION
        );
        wp_register_script(
            self::HANDLE_COMBO,
            DRWP_URL . 'public/assets/combo.js',
            [],
            DRWP_VERSION,
            true
        );
        // 共有のモザイク編集モーダル (Canvas)。
        wp_register_style(
            self::HANDLE_MOSAIC,
            DRWP_URL . 'public/assets/mosaic.css',
            [],
            DRWP_VERSION
        );
        wp_register_script(
            self::HANDLE_MOSAIC,
            DRWP_URL . 'public/assets/mosaic.js',
            [],
            DRWP_VERSION,
            true
        );
    }

    /* ------------------------------------------------------------
     * Form rendering — public so [drwp_report_archive] can embed the
     * form inside a modal dialog.
     * ------------------------------------------------------------ */

    public static function render_form() {
        // 案件ドロップダウンは active のみ表示。閉鎖済み (inactive) の
        // 案件を出すとリストが膨らんで日報入力時の選び間違いも起きやす
        // いので、新規入力フォームではあえて隠す。
        $projects = array_map(function ($p) {
            return ['id' => (int) $p->id, 'name' => (string) $p->name];
        }, DRWP_Project::all(true));
        // 「最近使った」案件 — 現在のログインユーザが直近で日報を書い
        // た案件を最大 8 件、リスト先頭にピン留めする。
        $recent_ids = DRWP_Project::recent_for_user(get_current_user_id(), 8);
        // 高速参照用に id => index にしておく
        $recent_lookup = array_flip(array_map('intval', $recent_ids));

        $config = [
            'rest_root'   => esc_url_raw(rest_url('drwp/v1/')),
            'nonce'       => wp_create_nonce('wp_rest'),
            'today'       => current_time('Y-m-d'),
            'license_ok'  => DRWP_License::can_write(),
            'projects'    => $projects,
            'i18n'        => [
                'pick_project'        => __('選択してください', 'drwp-daily-reports'),
                'need_project'        => __('案件を選択してください。', 'drwp-daily-reports'),
                'need_work'           => __('作業内容を入力してください。', 'drwp-daily-reports'),
                'uploading'           => __('写真をアップロード中…', 'drwp-daily-reports'),
                'sending'             => __('送信中…', 'drwp-daily-reports'),
                'sent'                => __('送信しました。日報承認待ちに入っています。', 'drwp-daily-reports'),
                'send_failed'         => __('送信に失敗しました。', 'drwp-daily-reports'),
                'remove_photo'        => __('削除', 'drwp-daily-reports'),
                'caption_placeholder' => __('説明文（任意）', 'drwp-daily-reports'),
                'mosaic_button'       => __('ぼかし', 'drwp-daily-reports'),
            ],
        ];

        wp_enqueue_style(self::HANDLE);
        wp_enqueue_script(self::HANDLE);
        wp_enqueue_style(self::HANDLE_COMBO);
        wp_enqueue_script(self::HANDLE_COMBO);
        wp_enqueue_style(self::HANDLE_MOSAIC);
        wp_enqueue_script(self::HANDLE_MOSAIC);

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

                <div class="drwp-mform-row">
                    <span class="drwp-mform-label">
                        <?php esc_html_e('案件', 'drwp-daily-reports'); ?> <em>*</em>
                    </span>
                    <?php
                    // combo.js が拾って検索可能なコンボボックスに昇格させる。
                    // 「最近使った」グループはユーザの直近案件、もう一方の
                    // 「案件」はそれ以外の active 案件全件。
                    $recent_options = [];
                    $other_options  = [];
                    foreach ($projects as $p) {
                        if (isset($recent_lookup[(int) $p['id']])) $recent_options[] = $p;
                        else                                       $other_options[]  = $p;
                    }
                    // recent はリストに登場した順 (recent_for_user は新しい
                    // 順で返す) を保つため $recent_ids の並びで取り直す
                    if (!empty($recent_options)) {
                        $by_id = [];
                        foreach ($recent_options as $p) { $by_id[(int) $p['id']] = $p; }
                        $recent_options = [];
                        foreach ($recent_ids as $rid) {
                            if (isset($by_id[(int) $rid])) $recent_options[] = $by_id[(int) $rid];
                        }
                    }
                    ?>
                    <div class="drwp-combo" data-drwp-combo>
                        <select name="project_id" required>
                            <option value=""><?php esc_html_e('選択してください', 'drwp-daily-reports'); ?></option>
                            <?php if (!empty($recent_options)): ?>
                                <optgroup label="<?php esc_attr_e('最近使った', 'drwp-daily-reports'); ?>">
                                    <?php foreach ($recent_options as $p): ?>
                                        <option value="<?php echo (int) $p['id']; ?>"><?php echo esc_html($p['name']); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                            <?php if (!empty($other_options)): ?>
                                <optgroup label="<?php esc_attr_e('案件', 'drwp-daily-reports'); ?>">
                                    <?php foreach ($other_options as $p): ?>
                                        <option value="<?php echo (int) $p['id']; ?>"><?php echo esc_html($p['name']); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>

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
                    <?php esc_html_e('送信した日報は「日報承認待ち」として保存されます。事務所側で内容を確認のうえ、必要に応じて公開されます。', 'drwp-daily-reports'); ?>
                </p>

                <div class="drwp-mform-status" data-role="status" aria-live="polite"></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

}
