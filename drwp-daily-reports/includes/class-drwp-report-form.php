<?php
if (!defined('ABSPATH')) exit;

/**
 * Front-end shortcode that lets a logged-in field worker submit a
 * daily report from their phone without going through /wp-admin.
 *
 * Usage:
 *
 *     [drwp_report_form]
 *
 * Each rendered form = one site visit. A worker who goes to two
 * sites in a day files two reports. The "+ 現場を追加" UI that
 * grouped multiple sites under one report was removed in v1.11 —
 * the per-visit started_at / ended_at fields stay on the form
 * since they're useful regardless of structure.
 *
 * Requirements for the visitor:
 *   - logged in (WP cookie auth supplies the REST nonce)
 *   - has the edit_posts capability (Contributor or higher)
 *   - the plugin's license is active or in grace, otherwise the
 *     REST POST returns 402 and we surface the message verbatim
 *
 * Assets:
 *   The JS and CSS live in /public/assets/ and are loaded via
 *   wp_enqueue_script / wp_enqueue_style; the per-page config is
 *   embedded into the wrapper element's data-drwp-mform-config
 *   attribute (page-cache stacks have been observed to strip
 *   auxiliary <script> chunks from wp_add_inline_script and
 *   wp_localize_script in production).
 */
class DRWP_Report_Form {

    const HANDLE = 'drwp-mform';

    public static function init() {
        add_shortcode('drwp_report_form', [__CLASS__, 'render']);
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
    }

    public static function render($atts = [], $content = '') {
        if (!is_user_logged_in()) {
            return self::wrap(self::login_prompt());
        }
        if (!current_user_can('edit_posts')) {
            return self::wrap('<p>' . esc_html__('日報を投稿する権限がありません。管理者にお問い合わせください。', 'drwp-daily-reports') . '</p>');
        }

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
                'need_project' => __('現場を選択してください。', 'drwp-daily-reports'),
                'need_work'    => __('作業内容を入力してください。', 'drwp-daily-reports'),
                'uploading'    => __('写真をアップロード中…', 'drwp-daily-reports'),
                'sending'      => __('送信中…', 'drwp-daily-reports'),
                'sent'         => __('送信しました。レビュー待ちに入っています。', 'drwp-daily-reports'),
                'send_failed'  => __('送信に失敗しました。', 'drwp-daily-reports'),
            ],
        ];

        wp_enqueue_style(self::HANDLE);
        wp_enqueue_script(self::HANDLE);

        $config_attr = wp_json_encode($config);
        if ($config_attr === false) $config_attr = '{}';

        ob_start();
        ?>
        <div class="drwp-mform-wrap" data-drwp-mform-config="<?php echo esc_attr($config_attr); ?>">
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
                        <?php esc_html_e('現場', 'drwp-daily-reports'); ?> <em>*</em>
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
                    <span class="drwp-mform-label"><?php esc_html_e('問題点 (任意)', 'drwp-daily-reports'); ?></span>
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

    private static function login_prompt() {
        return '<p class="drwp-mform-login-required">'
            . esc_html__('日報を投稿するにはログインしてください。', 'drwp-daily-reports')
            . '</p>';
    }

    private static function wrap($inner) {
        return '<div class="drwp-mform-wrap">' . $inner . '</div>';
    }
}
