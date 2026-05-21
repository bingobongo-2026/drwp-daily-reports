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
 * The rendered form is multi-entry by design (1 day report : N
 * jobsite visits) — workers who hit one site still just have one
 * entry card. The form talks to /wp-json/drwp/v1/ for projects,
 * photo uploads, and the report POST. Submissions land as
 * review_status=pending and feed the existing review queue.
 *
 * Requirements for the visitor:
 *   - logged in (WP cookie auth supplies the REST nonce)
 *   - has the edit_posts capability (Contributor or higher)
 *   - the plugin's license is active or in grace, otherwise the
 *     REST POST returns 402 and we surface the message verbatim
 *
 * Assets:
 *   The JS and CSS live in /public/assets/ and are loaded via
 *   wp_enqueue_script / wp_enqueue_style. Earlier the same code
 *   was emitted inline from the shortcode's return string and
 *   reliably got mangled by wpautop running over the_content
 *   (the inserted <p> / <br /> inside the <script> block produced
 *   "Uncaught SyntaxError: Invalid or unexpected token" in the
 *   browser and the form did nothing). Enqueueing real files
 *   sidesteps wpautop completely.
 */
class DRWP_Report_Form {

    const HANDLE = 'drwp-mform';

    public static function init() {
        add_shortcode('drwp_report_form', [__CLASS__, 'render']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
    }

    /**
     * Pre-register the script/style on wp_enqueue_scripts. We don't
     * enqueue here — only the shortcode knows whether the page
     * actually needs the form — but registering early means
     * render() can pull them in cleanly without needing to do its
     * own register().
     */
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
            true   // footer — wp_localize_script emits the config tag right before the main src
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
                'add_entry'    => __('現場を追加', 'drwp-daily-reports'),
                'remove_entry' => __('この現場を削除', 'drwp-daily-reports'),
                'entry_label'  => __('現場', 'drwp-daily-reports'),
                'pick_project' => __('選択してください', 'drwp-daily-reports'),
                'work'         => __('作業内容', 'drwp-daily-reports'),
                'issues'       => __('問題点 (任意)', 'drwp-daily-reports'),
                'next'         => __('次回予定 (任意)', 'drwp-daily-reports'),
                'photos'       => __('写真', 'drwp-daily-reports'),
                'pick_photos'  => __('カメラで撮影 / 端末から選択', 'drwp-daily-reports'),
                'started'      => __('開始時刻', 'drwp-daily-reports'),
                'ended'        => __('終了時刻', 'drwp-daily-reports'),
                'need_project' => __('現場を選択してください。', 'drwp-daily-reports'),
                'need_work'    => __('作業内容を入力してください。', 'drwp-daily-reports'),
                'need_entry'   => __('現場エントリを 1 つ以上入力してください。', 'drwp-daily-reports'),
                'uploading'    => __('写真をアップロード中…', 'drwp-daily-reports'),
                'sending'      => __('送信中…', 'drwp-daily-reports'),
                'sent'         => __('送信しました。レビュー待ちに入っています。', 'drwp-daily-reports'),
                'send_failed'  => __('送信に失敗しました。', 'drwp-daily-reports'),
            ],
        ];

        wp_enqueue_style(self::HANDLE);
        wp_enqueue_script(self::HANDLE);
        // wp_localize_script emits `var drwpMformConfig = {...};` as
        // its own <script> tag adjacent to the main one. We tried
        // wp_add_inline_script('...','before') first, but a number of
        // page-cache / asset-optimization plugins (Autoptimize,
        // LiteSpeed Cache, etc.) silently drop the "before" inline
        // chunk while still emitting the main src tag. localize_script
        // goes through the older "extra data" code path that those
        // plugins respect, so it lands reliably. The downside (a
        // top-level `var`) is exactly what the mobile-form JS reads
        // as window.drwpMformConfig anyway.
        wp_localize_script(self::HANDLE, 'drwpMformConfig', $config);

        ob_start();
        ?>
        <div class="drwp-mform-wrap">
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

                <div class="drwp-mform-entries" data-role="entries"></div>

                <button type="button" class="drwp-mform-add" data-role="add-entry">
                    + <?php echo esc_html($config['i18n']['add_entry']); ?>
                </button>

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
        $current_url = is_singular() ? get_permalink() : home_url(add_query_arg(null, null));
        $login_url = wp_login_url($current_url);
        return sprintf(
            '<p>%s <a href="%s">%s</a></p>',
            esc_html__('日報を投稿するにはログインしてください。', 'drwp-daily-reports'),
            esc_url($login_url),
            esc_html__('ログイン画面へ', 'drwp-daily-reports')
        );
    }

    private static function wrap($inner) {
        return '<div class="drwp-mform-wrap">' . $inner . '</div>';
    }
}
