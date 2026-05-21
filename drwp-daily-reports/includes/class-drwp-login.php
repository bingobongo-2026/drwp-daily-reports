<?php
if (!defined('ABSPATH')) exit;

/**
 * Optional: route the WordPress login through a custom fixed page
 * and point operators at the Two Factor plugin for Google
 * Authenticator (TOTP) support.
 *
 * Why this lives in the daily-reports plugin
 *   The same field workers who use [drwp_report_form] also need
 *   to sign in. Surfacing a branded login page + 2FA setup from
 *   the same plugin keeps "what to install" to a single item.
 *
 * What this module does NOT do
 *   It deliberately doesn't implement TOTP itself. 2FA done wrong
 *   creates security holes (weak secret storage, narrow replay
 *   windows, missing recovery codes). The Two Factor plugin
 *   (https://wordpress.org/plugins/two-factor/) is maintained by
 *   WordPress core contributors, audited, and handles every sharp
 *   edge. We just nudge the operator to install it and provide
 *   the link to per-user setup.
 *
 * Redirect contract
 *   When enabled + a page is selected, GET /wp-login.php with no
 *   action (or action=login) is redirected to the chosen page.
 *   Everything else — POST submissions, logout, password reset,
 *   email confirmation links, Two Factor's challenge step — flows
 *   through wp-login.php unchanged. That's the integration point
 *   that lets Two Factor's UI work without us needing to know its
 *   internals.
 */
class DRWP_Login {

    const OPT_PAGE     = 'drwp_login_page_id';
    const OPT_ENABLED  = 'drwp_login_redirect_enabled';
    const HANDLE       = 'drwp-login';

    public static function init() {
        add_shortcode('drwp_login_form', [__CLASS__, 'shortcode']);
        add_action('login_init', [__CLASS__, 'maybe_redirect']);
        add_action('admin_menu', [__CLASS__, 'register_admin'], 20);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
    }

    public static function register_assets() {
        wp_register_style(
            self::HANDLE,
            DRWP_URL . 'public/assets/login.css',
            [],
            DRWP_VERSION
        );
    }

    public static function shortcode($atts = []) {
        wp_enqueue_style(self::HANDLE);

        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            return self::wrap(
                '<p class="drwp-login-already">'
                . sprintf(
                    /* translators: 1: display name */
                    esc_html__('%s さんとしてログイン中です。', 'drwp-daily-reports'),
                    esc_html($user->display_name)
                )
                . ' <a href="' . esc_url(wp_logout_url(home_url('/'))) . '">'
                . esc_html__('ログアウト', 'drwp-daily-reports')
                . '</a></p>'
            );
        }

        // Honour redirect_to so the user lands where they were
        // headed when WordPress bounced them through login. Default
        // to admin so field workers reach the dashboard.
        $redirect_to = !empty($_GET['redirect_to'])
            ? esc_url_raw(wp_unslash($_GET['redirect_to']))
            : admin_url('admin.php?page=drwp_reports');

        $form = wp_login_form([
            'echo'           => false,
            'redirect'       => $redirect_to,
            'label_username' => __('ユーザー名 / メールアドレス', 'drwp-daily-reports'),
            'label_password' => __('パスワード', 'drwp-daily-reports'),
            'label_remember' => __('ログイン状態を保持する', 'drwp-daily-reports'),
            'label_log_in'   => __('ログイン', 'drwp-daily-reports'),
        ]);

        $lost = sprintf(
            '<p class="drwp-login-lost"><a href="%s">%s</a></p>',
            esc_url(wp_lostpassword_url($redirect_to)),
            esc_html__('パスワードをお忘れですか?', 'drwp-daily-reports')
        );

        return self::wrap($form . $lost);
    }

    private static function wrap($inner) {
        return '<div class="drwp-login-wrap">' . $inner . '</div>';
    }

    public static function maybe_redirect() {
        if (!get_option(self::OPT_ENABLED)) return;
        $page_id = (int) get_option(self::OPT_PAGE);
        if (!$page_id) return;

        // POSTs are the actual sign-in submission — they must reach
        // wp-login.php. action=anything-not-bare-login means a
        // special flow (logout, lostpassword, resetpass, postpass,
        // confirm_admin_email, confirmaction, register, validate_2fa
        // from Two Factor, etc.) and similarly must pass through.
        if (!empty($_POST)) return;
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';
        if ($action !== '' && $action !== 'login') return;

        // interim-login (the lightbox WP uses when your session
        // expires inside the admin) MUST stay on wp-login.php so
        // the auto-close on success works. Same for loggedout=true
        // (showing the post-logout message inline is fine on our
        // page, but interim-login isn't).
        if (!empty($_GET['interim-login'])) return;

        $url = get_permalink($page_id);
        if (!$url) return;
        if (!empty($_GET['redirect_to'])) {
            $url = add_query_arg('redirect_to', rawurlencode((string) wp_unslash($_GET['redirect_to'])), $url);
        }
        wp_safe_redirect($url);
        exit;
    }

    public static function register_admin() {
        add_submenu_page(
            'drwp_reports',
            __('ログイン設定', 'drwp-daily-reports'),
            __('ログイン設定', 'drwp-daily-reports'),
            'manage_options',
            'drwp_login_settings',
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function register_settings() {
        register_setting('drwp_login', self::OPT_PAGE,    ['type' => 'integer', 'default' => 0]);
        register_setting('drwp_login', self::OPT_ENABLED, ['type' => 'boolean', 'default' => false]);
    }

    public static function render_settings_page() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));

        $pages = get_pages(['post_status' => 'publish']);
        $current_page = (int) get_option(self::OPT_PAGE);
        $enabled = (bool) get_option(self::OPT_ENABLED);
        $two_factor_active = class_exists('Two_Factor_Core');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('ログイン設定', 'drwp-daily-reports'); ?></h1>

            <form method="post" action="options.php">
                <?php settings_fields('drwp_login'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('ログインを固定ページに集約', 'drwp-daily-reports'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPT_ENABLED); ?>" value="1" <?php checked($enabled); ?>>
                                <?php esc_html_e('/wp-login.php へのアクセスを下のページにリダイレクトする', 'drwp-daily-reports'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('POST 送信・パスワード再設定・ログアウト・2 段階認証チャレンジ等の特殊フローは引き続き /wp-login.php を経由します(リダイレクトしません)。', 'drwp-daily-reports'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('ログイン用ページ', 'drwp-daily-reports'); ?></th>
                        <td>
                            <select name="<?php echo esc_attr(self::OPT_PAGE); ?>">
                                <option value="0"><?php esc_html_e('（選択）', 'drwp-daily-reports'); ?></option>
                                <?php foreach ($pages as $page): ?>
                                    <option value="<?php echo esc_attr($page->ID); ?>" <?php selected($current_page, $page->ID); ?>>
                                        <?php echo esc_html($page->post_title); ?> (#<?php echo (int) $page->ID; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e('対象ページの本文に', 'drwp-daily-reports'); ?>
                                <code>[drwp_login_form]</code>
                                <?php esc_html_e('を入れてください。', 'drwp-daily-reports'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2 style="margin-top:32px;"><?php esc_html_e('2 段階認証 (Google Authenticator)', 'drwp-daily-reports'); ?></h2>
            <?php if ($two_factor_active): ?>
                <div class="notice notice-success inline">
                    <p>
                        <strong><?php esc_html_e('Two Factor プラグインが有効です。', 'drwp-daily-reports'); ?></strong>
                        <?php esc_html_e('各ユーザーは', 'drwp-daily-reports'); ?>
                        <a href="<?php echo esc_url(admin_url('profile.php#two-factor-options')); ?>"><?php esc_html_e('プロフィール画面', 'drwp-daily-reports'); ?></a>
                        <?php esc_html_e('から「Time Based One-Time Password (TOTP)」を有効化できます。', 'drwp-daily-reports'); ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="notice notice-warning inline">
                    <p>
                        <strong><?php esc_html_e('Two Factor プラグインが未インストールです。', 'drwp-daily-reports'); ?></strong>
                        <?php esc_html_e('TOTP (Google Authenticator) を有効にするには公式の Two Factor プラグインをインストールしてください。', 'drwp-daily-reports'); ?>
                    </p>
                    <p>
                        <a class="button button-primary" href="<?php echo esc_url(self_admin_url('plugin-install.php?s=two-factor&tab=search&type=term')); ?>">
                            <?php esc_html_e('Two Factor を検索してインストール', 'drwp-daily-reports'); ?>
                        </a>
                    </p>
                </div>
            <?php endif; ?>
            <p class="description" style="max-width:760px;">
                <?php esc_html_e('セットアップ手順: (1) Two Factor プラグインをインストール&有効化 → (2) 各ユーザーのプロフィール画面で TOTP を有効化 → (3) 表示された QR コードを Google Authenticator (または Authy, 1Password 等) で読み取り → (4) 表示された 6 桁コードを入力して登録。リカバリーコードも同時に発行されるので、安全な場所に保管してください。', 'drwp-daily-reports'); ?>
            </p>
        </div>
        <?php
    }
}
