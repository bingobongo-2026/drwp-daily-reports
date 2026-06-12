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

    const OPT_PAGE           = 'drwp_login_page_id';
    const OPT_ENABLED        = 'drwp_login_redirect_enabled';
    const OPT_LOSTPASS_PAGE  = 'drwp_login_lostpass_page_id';
    const OPT_ADMIN_LOCKDOWN = 'drwp_login_admin_lockdown';
    const OPT_LOGIN_LOGO     = 'drwp_login_logo_url';
    const HANDLE             = 'drwp-login';
    const HANDLE_WP_LOGIN    = 'drwp-wp-login';

    public static function init() {
        add_shortcode('drwp_login_form', [__CLASS__, 'shortcode']);
        add_shortcode('drwp_lostpassword_form', [__CLASS__, 'lostpassword_shortcode']);
        add_action('login_init', [__CLASS__, 'maybe_redirect']);
        // ログイン設定 submenu is registered centrally in
        // DRWP_Admin::menu() so the sidebar order stays explicit.
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);

        // Frontend password reset: rewrite the URL embedded in the
        // recovery email so it points at the operator's lost-password
        // page, and process form POSTs on template_redirect (which
        // fires before output, so wp_safe_redirect still works).
        add_filter('retrieve_password_message', [__CLASS__, 'filter_retrieve_password_message'], 10, 4);
        add_action('template_redirect', [__CLASS__, 'handle_lostpassword_post']);

        // wp-login.php branding: a small CSS file matching the
        // front-end look. Two Factor's TOTP challenge screen also
        // lives on wp-login.php, so this picks it up too.
        add_action('login_enqueue_scripts', [__CLASS__, 'enqueue_wp_login_styles']);
        add_action('login_head', [__CLASS__, 'inject_login_logo_css']);
        add_filter('login_headerurl',  [__CLASS__, 'filter_login_header_url']);
        add_filter('login_headertext', [__CLASS__, 'filter_login_header_text']);

        // Admin lockdown: keep contributors (and anyone without
        // edit_others_posts) out of /wp-admin/ entirely, with a
        // tight whitelist for their own profile (TOTP setup) and
        // the admin-ajax / admin-post endpoints (form submissions).
        add_action('admin_init', [__CLASS__, 'maybe_lockdown_admin'], 1);
        add_filter('show_admin_bar', [__CLASS__, 'maybe_hide_admin_bar']);
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
        // 現にログイン中の退職者(init ログアウト前のレース) — フォー
        // ムを出しても意味がないので通知だけ。
        if (is_user_logged_in() && DRWP_User::is_retired()) {
            wp_enqueue_style(self::HANDLE);
            return self::wrap('<p class="drwp-login-flash err drwp-login-retired">'
                . esc_html__('このアカウントは退職状態のため、ログインできません。', 'drwp-daily-reports')
                . '</p>');
        }
        // ログアウト済み + 退職マーカー: 退職者がログアウトさせられた
        // 直後。通知は出すが、ログインフォームも**併せて**出す — 共有
        // 端末で別の社員がそのままログインできるようにするため(通知だ
        // け出してフォームを隠すと、マーカー Cookie が切れる 1 時間
        // まで誰もそのページからログインできなくなる)。
        $retired_marker = (DRWP_User::has_marker_cookie() || !empty($_GET['drwp_retired']))
                       && !is_user_logged_in();
        if ($retired_marker) {
            wp_enqueue_style(self::HANDLE);
            $notice = '<div class="drwp-login-wrap"><p class="drwp-login-flash err drwp-login-retired">'
                . esc_html__('前回のアカウントは退職状態のためログインできません。別のアカウントでログインしてください。', 'drwp-daily-reports')
                . '</p></div>';
            return $notice . self::render_login_box();
        }

        if (is_user_logged_in()) {
            return self::render_logged_in_bar(wp_get_current_user());
        }
        return self::render_login_box();
    }

    /**
     * Render-only helpers used by both `[drwp_login_form]` and
     * `[drwp_report_archive]` so the operator can deploy just one
     * shortcode and get login + data on the same page. Each one
     * dedups via a static flag — if the operator happens to have
     * both shortcodes on the page, only the first occurrence
     * actually emits markup (matters for the bar, which is the
     * one piece that would visually stack as 2 fixed bars
     * otherwise).
     */
    private static $bar_rendered = false;
    private static $box_rendered = false;

    public static function render_logged_in_bar($user) {
        if (self::$bar_rendered) return '';
        self::$bar_rendered = true;
        wp_enqueue_style(self::HANDLE);
        // Fixed-position bar at the top of the page (positioned in
        // CSS, not via flow), so wherever this gets emitted on the
        // page it visually lands at the top.
        return '<div class="drwp-login-bar">'
            . '<span class="drwp-login-bar-text">'
            . sprintf(
                /* translators: 1: display name */
                esc_html__('%s さんとしてログイン中', 'drwp-daily-reports'),
                esc_html(DRWP_User::display_name($user))
            )
            . '</span> '
            . '<a class="drwp-login-bar-logout" href="' . esc_url(wp_logout_url(home_url('/'))) . '">'
            . esc_html__('ログアウト', 'drwp-daily-reports')
            . '</a>'
            . '</div>';
    }

    public static function render_login_box($redirect_to = null) {
        if (self::$box_rendered) return '';
        self::$box_rendered = true;
        wp_enqueue_style(self::HANDLE);

        // redirect_to fallback chain:
        //   1. caller-supplied(`[drwp_report_archive]` passes the
        //      page's own permalink so logged-out workers land back
        //      on the archive view after signing in)
        //   2. ?redirect_to=... query
        //   3. the current page itself
        //   4. the admin reports list as a generic fallback
        if ($redirect_to === null) {
            if (!empty($_GET['redirect_to'])) {
                $redirect_to = esc_url_raw(wp_unslash($_GET['redirect_to']));
            } elseif (is_singular() && ($permalink = get_permalink())) {
                $redirect_to = $permalink;
            } else {
                $redirect_to = admin_url('admin.php?page=drwp_reports');
            }
        }

        $form = wp_login_form([
            'echo'           => false,
            'redirect'       => $redirect_to,
            'label_username' => __('ユーザー名 / メールアドレス', 'drwp-daily-reports'),
            'label_password' => __('パスワード', 'drwp-daily-reports'),
            'label_remember' => __('ログイン状態を保持する', 'drwp-daily-reports'),
            'label_log_in'   => __('ログイン', 'drwp-daily-reports'),
        ]);

        $lost_page_id = (int) get_option(self::OPT_LOSTPASS_PAGE);
        if ($lost_page_id && ($lost_url = get_permalink($lost_page_id))) {
            $lost_href = $lost_url;
        } else {
            $lost_href = wp_lostpassword_url($redirect_to);
        }
        $lost = sprintf(
            '<p class="drwp-login-lost"><a href="%s">%s</a></p>',
            esc_url($lost_href),
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


    public static function register_settings() {
        register_setting('drwp_login', self::OPT_PAGE,           ['type' => 'integer', 'default' => 0]);
        register_setting('drwp_login', self::OPT_ENABLED,        ['type' => 'boolean', 'default' => false]);
        register_setting('drwp_login', self::OPT_LOSTPASS_PAGE,  ['type' => 'integer', 'default' => 0]);
        register_setting('drwp_login', self::OPT_ADMIN_LOCKDOWN, ['type' => 'boolean', 'default' => false]);
        register_setting('drwp_login', self::OPT_LOGIN_LOGO,     ['type' => 'string',  'default' => '']);
    }

    /* ------------------------------------------------------------
     * Frontend password reset
     * ------------------------------------------------------------ */

    /**
     * Replace the wp-login.php URL inside the password-reset email
     * with the operator's frontend page URL. WP composes the email
     * via retrieve_password_message; we pattern-match the embedded
     * URL rather than rewriting the whole message so any
     * translations or admin customizations of the body text survive.
     */
    public static function filter_retrieve_password_message($message, $key, $user_login, $user_data) {
        $page_id = (int) get_option(self::OPT_LOSTPASS_PAGE);
        if (!$page_id) return $message;
        $page_url = get_permalink($page_id);
        if (!$page_url) return $message;
        $new_url = add_query_arg([
            'key'   => $key,
            'login' => rawurlencode($user_login),
        ], $page_url);
        return preg_replace(
            '#https?://\S+wp-login\.php\S*#',
            $new_url,
            (string) $message
        );
    }

    /**
     * Lost-password shortcode. Single shortcode that handles both
     * stages of the WP password reset flow based on URL state:
     *
     *   no params              → "Enter your email" request form
     *   ?lpw=sent              → "Check your email" confirmation
     *   ?key=...&login=...     → "Set new password" form
     *   ?lpw=success           → "Password updated, please log in"
     *   ?lpw=invalid_key       → "This link is invalid or expired"
     *
     * POST handling is done separately in handle_lostpassword_post()
     * on the template_redirect hook so we can wp_safe_redirect after
     * processing without "headers already sent" errors.
     */
    public static function lostpassword_shortcode($atts = []) {
        wp_enqueue_style(self::HANDLE);

        $flash = isset($_GET['lpw']) ? sanitize_key((string) $_GET['lpw']) : '';
        $key   = isset($_GET['key']) ? sanitize_text_field((string) wp_unslash($_GET['key'])) : '';
        $login = isset($_GET['login']) ? sanitize_text_field((string) wp_unslash($_GET['login'])) : '';

        if ($flash === 'success') {
            $login_page = (int) get_option(self::OPT_PAGE);
            $login_url = $login_page ? get_permalink($login_page) : wp_login_url();
            return self::wrap(
                '<p class="drwp-login-flash ok">'
                . esc_html__('パスワードを更新しました。新しいパスワードでログインしてください。', 'drwp-daily-reports')
                . '</p><p class="drwp-login-lost"><a href="' . esc_url($login_url) . '">'
                . esc_html__('ログインページへ', 'drwp-daily-reports')
                . '</a></p>'
            );
        }
        if ($flash === 'sent') {
            return self::wrap(
                '<p class="drwp-login-flash ok">'
                . esc_html__('再設定リンクをメールでお送りしました。受信ボックスをご確認ください(数分かかる場合があります)。', 'drwp-daily-reports')
                . '</p>'
            );
        }
        if ($flash === 'invalid_key') {
            return self::wrap(
                '<p class="drwp-login-flash err">'
                . esc_html__('リンクが無効または期限切れです。もう一度メール送信からやり直してください。', 'drwp-daily-reports')
                . '</p>' . self::lostpassword_request_form('')
            );
        }
        if ($flash === 'mismatch') {
            // Fall through to render the new-password form again
            // with an error notice on top.
            return self::wrap(
                '<p class="drwp-login-flash err">'
                . esc_html__('パスワードが一致しません。もう一度入力してください。', 'drwp-daily-reports')
                . '</p>' . self::lostpassword_set_new_form($key, $login)
            );
        }
        if ($flash === 'weak') {
            return self::wrap(
                '<p class="drwp-login-flash err">'
                . esc_html__('パスワードは 8 文字以上で入力してください。', 'drwp-daily-reports')
                . '</p>' . self::lostpassword_set_new_form($key, $login)
            );
        }
        if ($flash === 'not_found') {
            return self::wrap(
                '<p class="drwp-login-flash err">'
                . esc_html__('該当するユーザーが見つかりませんでした。入力を確認してください。', 'drwp-daily-reports')
                . '</p>' . self::lostpassword_request_form('')
            );
        }

        // Reset-link state — validate the key before showing the
        // set-new-password form so we don't accept anything that's
        // already been used or expired.
        if ($key !== '' && $login !== '') {
            $user = check_password_reset_key($key, $login);
            if (is_wp_error($user)) {
                return self::wrap(
                    '<p class="drwp-login-flash err">'
                    . esc_html__('リンクが無効または期限切れです。もう一度メール送信からやり直してください。', 'drwp-daily-reports')
                    . '</p>' . self::lostpassword_request_form('')
                );
            }
            return self::wrap(self::lostpassword_set_new_form($key, $login));
        }

        // Default: blank request form.
        return self::wrap(self::lostpassword_request_form(''));
    }

    private static function lostpassword_request_form($user_login) {
        $action = esc_url(get_permalink());
        ob_start();
        ?>
        <form method="post" action="<?php echo $action; ?>" class="drwp-lostpass-form">
            <?php wp_nonce_field('drwp_lpw_request'); ?>
            <input type="hidden" name="_drwp_lpw_request" value="1" />
            <p>
                <label for="drwp-lpw-user"><?php esc_html_e('ユーザー名 / メールアドレス', 'drwp-daily-reports'); ?></label>
                <input type="text" id="drwp-lpw-user" name="user_login" autocomplete="username"
                       value="<?php echo esc_attr($user_login); ?>" required />
            </p>
            <p>
                <button type="submit" class="drwp-lostpass-submit">
                    <?php esc_html_e('再設定リンクをメールで受け取る', 'drwp-daily-reports'); ?>
                </button>
            </p>
        </form>
        <?php
        return ob_get_clean();
    }

    private static function lostpassword_set_new_form($key, $login) {
        $action = esc_url(get_permalink());
        ob_start();
        ?>
        <form method="post" action="<?php echo $action; ?>" class="drwp-lostpass-form">
            <?php wp_nonce_field('drwp_lpw_reset'); ?>
            <input type="hidden" name="_drwp_lpw_reset" value="1" />
            <input type="hidden" name="key"   value="<?php echo esc_attr($key); ?>" />
            <input type="hidden" name="login" value="<?php echo esc_attr($login); ?>" />
            <p>
                <label for="drwp-lpw-p1"><?php esc_html_e('新しいパスワード', 'drwp-daily-reports'); ?></label>
                <input type="password" id="drwp-lpw-p1" name="pass1" autocomplete="new-password" minlength="8" required />
            </p>
            <p>
                <label for="drwp-lpw-p2"><?php esc_html_e('新しいパスワード(確認)', 'drwp-daily-reports'); ?></label>
                <input type="password" id="drwp-lpw-p2" name="pass2" autocomplete="new-password" minlength="8" required />
            </p>
            <p>
                <button type="submit" class="drwp-lostpass-submit">
                    <?php esc_html_e('パスワードを更新する', 'drwp-daily-reports'); ?>
                </button>
            </p>
        </form>
        <?php
        return ob_get_clean();
    }

    /**
     * Handle both lost-password form POSTs. Runs on template_redirect
     * so we're before output and can wp_safe_redirect to a flash URL.
     * Each branch self-redirects so the user can refresh / share the
     * URL without re-triggering the action (PRG pattern).
     */
    public static function handle_lostpassword_post() {
        $page_id = (int) get_option(self::OPT_LOSTPASS_PAGE);
        if (!$page_id || !is_page($page_id)) return;
        if (empty($_POST)) return;

        $page_url = get_permalink($page_id);

        if (!empty($_POST['_drwp_lpw_request'])) {
            check_admin_referer('drwp_lpw_request');
            $user_login = sanitize_text_field(wp_unslash((string) ($_POST['user_login'] ?? '')));
            if ($user_login === '') {
                wp_safe_redirect(add_query_arg('lpw', 'not_found', $page_url));
                exit;
            }
            // retrieve_password() reads $_POST['user_login'] in older
            // WordPress versions; new versions take an arg. Set both
            // so we don't depend on a specific WP version's signature.
            $_POST['user_login'] = $user_login;
            $result = retrieve_password();
            if (is_wp_error($result)) {
                wp_safe_redirect(add_query_arg('lpw', 'not_found', $page_url));
                exit;
            }
            wp_safe_redirect(add_query_arg('lpw', 'sent', $page_url));
            exit;
        }

        if (!empty($_POST['_drwp_lpw_reset'])) {
            check_admin_referer('drwp_lpw_reset');
            $key   = sanitize_text_field(wp_unslash((string) ($_POST['key'] ?? '')));
            $login = sanitize_text_field(wp_unslash((string) ($_POST['login'] ?? '')));
            $pass1 = (string) ($_POST['pass1'] ?? '');
            $pass2 = (string) ($_POST['pass2'] ?? '');
            $back  = add_query_arg(['key' => $key, 'login' => rawurlencode($login)], $page_url);

            $user = check_password_reset_key($key, $login);
            if (is_wp_error($user)) {
                wp_safe_redirect(add_query_arg('lpw', 'invalid_key', $page_url));
                exit;
            }
            if ($pass1 === '' || $pass1 !== $pass2) {
                wp_safe_redirect(add_query_arg('lpw', 'mismatch', $back));
                exit;
            }
            if (strlen($pass1) < 8) {
                wp_safe_redirect(add_query_arg('lpw', 'weak', $back));
                exit;
            }
            reset_password($user, $pass1);
            wp_safe_redirect(add_query_arg('lpw', 'success', $page_url));
            exit;
        }
    }

    /* ------------------------------------------------------------
     * wp-login.php branding
     * ------------------------------------------------------------ */

    public static function enqueue_wp_login_styles() {
        wp_enqueue_style(
            self::HANDLE_WP_LOGIN,
            DRWP_URL . 'public/assets/wp-login.css',
            [],
            DRWP_VERSION
        );
    }

    /**
     * When the operator has set a custom logo URL, override the WP
     * logo's CSS background-image via inline <style> in login_head.
     * This runs on wp-login.php (including the Two Factor TOTP
     * challenge screen), so the brand is consistent.
     */
    public static function inject_login_logo_css() {
        $logo = trim((string) get_option(self::OPT_LOGIN_LOGO, ''));
        if ($logo === '') return;
        echo '<style>#login h1 a, .login h1 a { '
            . 'background-image: url(' . esc_url($logo) . ') !important; '
            . 'background-size: contain !important; '
            . 'background-position: center !important; '
            . 'width: 100% !important; '
            . 'max-width: 320px !important; '
            . 'height: 80px !important; '
            . '}</style>';
    }

    /** Logo above the login form links here instead of wordpress.org. */
    public static function filter_login_header_url() {
        return home_url('/');
    }

    /** Title/alt on the same logo. */
    public static function filter_login_header_text() {
        return get_bloginfo('name');
    }

    /* ------------------------------------------------------------
     * Admin lockdown
     * ------------------------------------------------------------ */

    /**
     * For users below Editor (no edit_others_posts), redirect any
     * /wp-admin/ visit to the front-end login page (or home). The
     * whitelist below covers the few admin endpoints those users
     * legitimately need: their own profile (Two Factor TOTP setup,
     * password change), and the form-handler endpoints that
     * shortcodes POST to.
     */
    public static function maybe_lockdown_admin() {
        if (!get_option(self::OPT_ADMIN_LOCKDOWN)) return;
        if (wp_doing_ajax()) return;
        if (defined('DOING_CRON') && DOING_CRON) return;
        if (!is_user_logged_in()) return;
        if (current_user_can('edit_others_posts')) return;

        $pagenow = isset($GLOBALS['pagenow']) ? (string) $GLOBALS['pagenow'] : '';
        $allowed = ['profile.php', 'admin-post.php', 'admin-ajax.php'];
        if (in_array($pagenow, $allowed, true)) return;

        $page_id = (int) get_option(self::OPT_PAGE);
        $target  = $page_id ? get_permalink($page_id) : home_url('/');
        wp_safe_redirect($target ?: home_url('/'));
        exit;
    }

    /**
     * Hide the WP admin bar on the front end for the same users we
     * lock out of /wp-admin/. Otherwise they'd see a bar of admin
     * links that all bounce them back here, which is confusing.
     */
    public static function maybe_hide_admin_bar($show) {
        if (!get_option(self::OPT_ADMIN_LOCKDOWN)) return $show;
        if (!is_user_logged_in()) return $show;
        if (current_user_can('edit_others_posts')) return $show;
        return false;
    }

    public static function render_settings_page() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));

        $pages = get_pages(['post_status' => 'publish']);
        $current_page      = (int) get_option(self::OPT_PAGE);
        $current_lost_page = (int) get_option(self::OPT_LOSTPASS_PAGE);
        $enabled           = (bool) get_option(self::OPT_ENABLED);
        $admin_lockdown    = (bool) get_option(self::OPT_ADMIN_LOCKDOWN);
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
                    <tr>
                        <th scope="row"><?php esc_html_e('パスワード再設定ページ', 'drwp-daily-reports'); ?></th>
                        <td>
                            <select name="<?php echo esc_attr(self::OPT_LOSTPASS_PAGE); ?>">
                                <option value="0"><?php esc_html_e('（未設定: /wp-login.php?action=lostpassword を使う）', 'drwp-daily-reports'); ?></option>
                                <?php foreach ($pages as $page): ?>
                                    <option value="<?php echo esc_attr($page->ID); ?>" <?php selected($current_lost_page, $page->ID); ?>>
                                        <?php echo esc_html($page->post_title); ?> (#<?php echo (int) $page->ID; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e('対象ページの本文に', 'drwp-daily-reports'); ?>
                                <code>[drwp_lostpassword_form]</code>
                                <?php esc_html_e('を入れてください。1 つのショートコードで「再設定リクエスト → メール送信 → 新パスワード入力 → 完了」までフロントで完結します。再設定リンクを含むメールの URL も自動でこのページに差し替わります。', 'drwp-daily-reports'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('ログイン画面のロゴ', 'drwp-daily-reports'); ?></th>
                        <td>
                            <input type="url" name="<?php echo esc_attr(self::OPT_LOGIN_LOGO); ?>" class="regular-text"
                                   value="<?php echo esc_attr(get_option(self::OPT_LOGIN_LOGO, '')); ?>"
                                   placeholder="https://example.com/logo.png" />
                            <p class="description">
                                <?php esc_html_e('wp-login.php の WordPress ロゴを差し替える画像 URL。空欄なら WordPress の標準ロゴのまま。認証コード入力画面(Two Factor)にも適用されます。', 'drwp-daily-reports'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('管理画面ロックダウン', 'drwp-daily-reports'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPT_ADMIN_LOCKDOWN); ?>" value="1" <?php checked($admin_lockdown); ?>>
                                <?php esc_html_e('編集者未満のユーザー(寄稿者・投稿者等)が /wp-admin/ にアクセスしたらフロントに戻す', 'drwp-daily-reports'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('例外: 自分のプロフィール画面 (profile.php) は通します(Two Factor の TOTP 登録のため)。admin-ajax.php / admin-post.php(フォーム送信先)も通します。フロント側ヘッダーの管理バーも同じ条件で非表示にします。Editor 以上(edit_others_posts 保有)はロックダウン対象外。', 'drwp-daily-reports'); ?>
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
