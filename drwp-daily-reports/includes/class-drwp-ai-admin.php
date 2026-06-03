<?php
if (!defined('ABSPATH')) exit;

class DRWP_AI_Admin {
    public static function init() {
        add_action('admin_post_drwp_save_ai_settings', [__CLASS__, 'save']);
        add_action('admin_post_drwp_ai_test', [__CLASS__, 'test']);
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        $url     = DRWP_AI::url();
        $model   = DRWP_AI::model();
        $enabled = DRWP_AI::is_enabled();
        $test    = get_transient('drwp_ai_test_result');
        if ($test) delete_transient('drwp_ai_test_result');
        include DRWP_PATH . 'admin/views/ai-settings-page.php';
    }

    public static function save() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        check_admin_referer('drwp_save_ai_settings');
        update_option(DRWP_AI::OPT_URL, esc_url_raw(wp_unslash($_POST['url'] ?? '')));
        update_option(DRWP_AI::OPT_MODEL, sanitize_text_field(wp_unslash($_POST['model'] ?? '')));
        update_option(DRWP_AI::OPT_ENABLED, !empty($_POST['enabled']) ? 'yes' : 'no');
        wp_safe_redirect(admin_url('admin.php?page=drwp_ai&saved=1'));
        exit;
    }

    public static function test() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        check_admin_referer('drwp_ai_test');
        $result = DRWP_AI::test_connection();
        if (is_wp_error($result)) {
            set_transient('drwp_ai_test_result', ['error' => $result->get_error_message()], 60);
        } else {
            set_transient('drwp_ai_test_result', ['ok' => $result], 60);
        }
        wp_safe_redirect(admin_url('admin.php?page=drwp_ai'));
        exit;
    }
}
