<?php
if (!defined('ABSPATH')) exit;

class DRWP_License_Admin {
    public static function init() {
        add_action('admin_post_drwp_save_license', [__CLASS__, 'save']);
        add_action('admin_post_drwp_check_license', [__CLASS__, 'check']);
        add_action('admin_post_drwp_fetch_public_key', [__CLASS__, 'fetch_public_key']);
        add_action('admin_post_drwp_rotate_license_key', [__CLASS__, 'rotate']);
        add_action('admin_post_drwp_write_admin_token', [__CLASS__, 'write_admin_token_to_wpconfig']);
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        $license = DRWP_License::state();
        include DRWP_PATH . 'admin/views/license-page.php';
    }

    public static function save() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        check_admin_referer('drwp_save_license');

        // Only update the token when the field is present in the POST body.
        // The license-page renders a placeholder ('•••') when a token is
        // already stored or supplied via constant — we don't want that
        // placeholder to overwrite the real value. The view sends an
        // explicit empty `admin_token_set=1` flag to mean "clear it".
        $admin_token = null;
        if (DRWP_License::admin_token_source() !== 'constant' && isset($_POST['admin_token_present'])) {
            $admin_token = (string) wp_unslash($_POST['admin_token'] ?? '');
        }

        DRWP_License::save_settings(
            wp_unslash($_POST['api_url'] ?? ''),
            wp_unslash($_POST['license_key'] ?? ''),
            $admin_token
        );
        wp_safe_redirect(admin_url('admin.php?page=drwp_license&saved=1'));
        exit;
    }

    public static function check() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        check_admin_referer('drwp_check_license');
        $result = DRWP_License::check_now();
        $flag = is_wp_error($result) ? 'error' : 'checked';
        wp_safe_redirect(admin_url('admin.php?page=drwp_license&' . $flag . '=1'));
        exit;
    }

    public static function fetch_public_key() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        check_admin_referer('drwp_fetch_public_key');
        $result = DRWP_License::fetch_public_key();
        $flag = is_wp_error($result) ? 'error' : 'key_fetched';
        wp_safe_redirect(admin_url('admin.php?page=drwp_license&' . $flag . '=1'));
        exit;
    }

    public static function rotate() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        check_admin_referer('drwp_rotate_license_key');
        $result = DRWP_License::rotate_key();
        if (is_wp_error($result)) {
            // Surface the error message via the existing 'message' option
            // so the license page renders it next to the "error" notice.
            update_option(DRWP_License::OPT_LAST_MESSAGE, $result->get_error_message());
            wp_safe_redirect(admin_url('admin.php?page=drwp_license&error=1'));
            exit;
        }
        DRWP_Audit::log('license_key_rotated', 'ライセンス署名鍵をローテートしました', null, [
            'public_key_prefix' => substr((string) $result, 0, 16),
        ]);
        wp_safe_redirect(admin_url('admin.php?page=drwp_license&rotated=1'));
        exit;
    }

    /**
     * Best-effort attempt to migrate the admin token from the
     * options table into wp-config.php (as a DRWP_LICENSE_ADMIN_TOKEN
     * constant). On success we clear the option row — the constant
     * will take precedence on subsequent reads. On any failure we
     * surface the reason and leave the option untouched so the
     * existing flow keeps working.
     */
    public static function write_admin_token_to_wpconfig() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        check_admin_referer('drwp_write_admin_token');

        if (defined('DRWP_LICENSE_ADMIN_TOKEN')) {
            self::set_token_message('already_defined', __('既に wp-config.php に DRWP_LICENSE_ADMIN_TOKEN が定義されています。', 'drwp-daily-reports'));
            self::redirect_back();
        }

        $token = (string) get_option(DRWP_License::OPT_ADMIN_TOKEN, '');
        if ($token === '') {
            self::set_token_message('no_token', __('保存された管理トークンがありません。先に管理トークン欄に入力して保存してください。', 'drwp-daily-reports'));
            self::redirect_back();
        }

        $config_path = self::locate_wp_config();
        if (!$config_path) {
            self::set_token_message('not_found', __('wp-config.php が見つかりません。', 'drwp-daily-reports'));
            self::redirect_back();
        }
        if (!is_writable($config_path)) {
            self::set_token_message('not_writable', sprintf(
                /* translators: %s: file path */
                __('wp-config.php (%s) に書き込み権限がありません。手動で追加してください。', 'drwp-daily-reports'),
                $config_path
            ));
            self::redirect_back();
        }

        $content = file_get_contents($config_path);
        if ($content === false) {
            self::set_token_message('read_failed', __('wp-config.php の読み込みに失敗しました。', 'drwp-daily-reports'));
            self::redirect_back();
        }

        // Guard against a stale definition we missed via defined() —
        // e.g., if the constant is defined later in the bootstrap.
        if (preg_match('/define\s*\(\s*([\'"])DRWP_LICENSE_ADMIN_TOKEN\1/', $content)) {
            self::set_token_message('already_defined', __('既に wp-config.php に DRWP_LICENSE_ADMIN_TOKEN が定義されています。', 'drwp-daily-reports'));
            self::redirect_back();
        }

        // Single-quoted PHP string — escape backslashes and quotes.
        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $token);
        $line = "define('DRWP_LICENSE_ADMIN_TOKEN', '{$escaped}');\n";
        $marker = "/* That's all, stop editing! Happy publishing. */";

        if (strpos($content, $marker) !== false) {
            // Standard WP layout: insert just above the marker.
            $new = str_replace($marker, $line . $marker, $content);
        } else {
            // Fallback: append before the closing PHP tag if any,
            // otherwise tack on to the very end.
            if (preg_match('/(\s*\?>\s*)$/', $content)) {
                $new = preg_replace('/(\s*\?>\s*)$/', "\n" . $line . '$1', $content);
            } else {
                $new = rtrim($content) . "\n\n" . $line;
            }
        }

        // Atomic write: tmp file then rename so a partial write
        // never leaves wp-config.php in a broken state.
        $tmp = $config_path . '.drwp-tmp-' . wp_generate_password(6, false);
        if (file_put_contents($tmp, $new) === false) {
            self::set_token_message('write_failed', __('wp-config.php の書き込みに失敗しました。', 'drwp-daily-reports'));
            self::redirect_back();
        }
        if (!@rename($tmp, $config_path)) {
            @unlink($tmp);
            self::set_token_message('rename_failed', __('wp-config.php への置き換えに失敗しました。', 'drwp-daily-reports'));
            self::redirect_back();
        }

        // Constant now wins. Clear the DB option so we don't keep a
        // duplicate copy of the secret around.
        delete_option(DRWP_License::OPT_ADMIN_TOKEN);

        self::set_token_message('written', sprintf(
            /* translators: %s: file path */
            __('wp-config.php (%s) に DRWP_LICENSE_ADMIN_TOKEN を書き込みました。データベース上のコピーは削除しました。', 'drwp-daily-reports'),
            $config_path
        ), true);
        self::redirect_back();
    }

    private static function locate_wp_config() {
        // Standard location relative to ABSPATH (matches WordPress
        // core's own probe in wp-load.php).
        $a = ABSPATH . 'wp-config.php';
        if (file_exists($a)) return $a;
        $b = dirname(ABSPATH) . '/wp-config.php';
        if (file_exists($b) && !file_exists(dirname(ABSPATH) . '/wp-settings.php')) return $b;
        return null;
    }

    private static function set_token_message($code, $message, $ok = false) {
        set_transient('drwp_token_write_result', [
            'code'    => $code,
            'message' => $message,
            'ok'      => $ok,
        ], 60);
    }

    private static function redirect_back() {
        wp_safe_redirect(admin_url('admin.php?page=drwp_license'));
        exit;
    }
}
