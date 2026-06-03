<?php
if (!defined('ABSPATH')) exit;

class DRWP_AI_Admin {
    public static function init() {
        add_action('admin_post_drwp_save_ai_settings', [__CLASS__, 'save']);
        add_action('admin_post_drwp_ai_test', [__CLASS__, 'test']);
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        $provider = DRWP_AI::provider();
        $url      = DRWP_AI::url();
        $model    = DRWP_AI::model();
        $api_key  = DRWP_AI::api_key();
        $enabled  = DRWP_AI::is_enabled();
        $defaults = [
            'ollama'    => DRWP_AI::defaults('ollama'),
            'openai'    => DRWP_AI::defaults('openai'),
            'anthropic' => DRWP_AI::defaults('anthropic'),
        ];
        $test = get_transient('drwp_ai_test_result');
        if ($test) delete_transient('drwp_ai_test_result');
        include DRWP_PATH . 'admin/views/ai-settings-page.php';
    }

    public static function save() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        check_admin_referer('drwp_save_ai_settings');
        $provider = sanitize_text_field(wp_unslash($_POST['provider'] ?? 'ollama'));
        if (!in_array($provider, ['ollama', 'openai', 'anthropic'], true)) $provider = 'ollama';
        update_option(DRWP_AI::OPT_PROVIDER, $provider);
        update_option(DRWP_AI::OPT_URL, esc_url_raw(wp_unslash($_POST['url'] ?? '')));
        update_option(DRWP_AI::OPT_MODEL, sanitize_text_field(wp_unslash($_POST['model'] ?? '')));
        // Only overwrite the API key when a value is provided; empty
        // input on a stored-key form means "keep". An explicit
        // `api_key_clear` flag handles deletion.
        if (!empty($_POST['api_key_clear'])) {
            update_option(DRWP_AI::OPT_API_KEY, '');
        } elseif (isset($_POST['api_key']) && (string) $_POST['api_key'] !== '') {
            update_option(DRWP_AI::OPT_API_KEY, sanitize_text_field(wp_unslash($_POST['api_key'])));
        }
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
