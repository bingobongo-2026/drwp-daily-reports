<?php
if (!defined('ABSPATH')) exit;

class DRWP_AI_Admin {
    public static function init() {
        add_action('admin_post_drwp_save_ai_settings', [__CLASS__, 'save']);
        add_action('admin_post_drwp_ai_test', [__CLASS__, 'test']);
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('権限がありません', 'drwp-daily-reports'));
        // free プラン (AI 不可) ではアップセル画面に差し替え。設定値そのもの
        // は触らない(過去に basic/pro だった環境がダウングレードしたとき
        // 設定が消えるとプラン戻したときに再入力させてしまうので)。
        $plan_locked = !DRWP_License::plan_allows('ai');
        if ($plan_locked) {
            include DRWP_PATH . 'admin/views/ai-settings-locked.php';
            return;
        }
        $mode     = DRWP_AI::key_mode();
        $provider = DRWP_AI::provider();
        $url      = DRWP_AI::url();
        $model    = DRWP_AI::model();
        $api_key  = DRWP_AI::api_key();
        $enabled  = DRWP_AI::is_enabled();
        $defaults = [
            'openai'    => DRWP_AI::defaults('openai'),
            'anthropic' => DRWP_AI::defaults('anthropic'),
        ];
        // managed モードの時は今月の残量も取得 (キャッシュ優先で軽量)。
        // 取得に失敗 (ライセンスサーバ未到達 / 未設定など) なら null。
        $managed_quota = null;
        if ($mode === 'managed') {
            $backend = new DRWP_AI_Backend_Managed([
                'server_url' => (string) get_option(DRWP_License::OPT_API_URL, ''),
            ]);
            $managed_quota = $backend->fetch_quota(false);
        }
        $test = get_transient('drwp_ai_test_result');
        if ($test) delete_transient('drwp_ai_test_result');
        include DRWP_PATH . 'admin/views/ai-settings-page.php';
    }

    public static function save() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('権限がありません', 'drwp-daily-reports'));
        // free 以外は admin-post でも書き込みを拒否(UI を出していな
        // くても直接 POST してくる可能性に備える)。
        if (!DRWP_License::plan_allows('ai')) {
            wp_die(
                esc_html__('AI 機能は現在のプランでは利用できません。', 'drwp-daily-reports'),
                '', ['response' => 403, 'back_link' => true]
            );
        }
        check_admin_referer('drwp_save_ai_settings');
        $mode = sanitize_text_field(wp_unslash($_POST['key_mode'] ?? 'own'));
        if (!in_array($mode, ['own', 'managed'], true)) $mode = 'own';
        update_option(DRWP_AI::OPT_KEY_MODE, $mode);
        // own モードのフィールドは mode 切替時にも残しておく ("一時的に
        // managed を試したけど戻したい" を成立させるため)。
        $provider = sanitize_text_field(wp_unslash($_POST['provider'] ?? 'openai'));
        if (!in_array($provider, ['openai', 'anthropic'], true)) $provider = 'openai';
        update_option(DRWP_AI::OPT_PROVIDER, $provider);
        update_option(DRWP_AI::OPT_URL, esc_url_raw(wp_unslash($_POST['url'] ?? '')));
        update_option(DRWP_AI::OPT_MODEL, sanitize_text_field(wp_unslash($_POST['model'] ?? '')));
        if (!empty($_POST['api_key_clear'])) {
            update_option(DRWP_AI::OPT_API_KEY, '');
        } elseif (isset($_POST['api_key']) && (string) $_POST['api_key'] !== '') {
            update_option(DRWP_AI::OPT_API_KEY, sanitize_text_field(wp_unslash($_POST['api_key'])));
        }
        update_option(DRWP_AI::OPT_ENABLED, !empty($_POST['enabled']) ? 'yes' : 'no');
        // モードを切り替えたら quota キャッシュも捨てる(古い値が
        // 残って混乱しないように)。
        if (class_exists('DRWP_AI_Backend_Managed')) {
            DRWP_AI_Backend_Managed::clear_quota_cache();
        }
        wp_safe_redirect(admin_url('admin.php?page=drwp_ai&saved=1'));
        exit;
    }

    public static function test() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('権限がありません', 'drwp-daily-reports'));
        if (!DRWP_License::plan_allows('ai')) {
            wp_die(
                esc_html__('AI 機能は現在のプランでは利用できません。', 'drwp-daily-reports'),
                '', ['response' => 403, 'back_link' => true]
            );
        }
        check_admin_referer('drwp_ai_test');
        // managed モードでは接続テストが quota も取って来るので、
        // キャッシュを強制リフレッシュさせる。
        if (DRWP_AI::key_mode() === 'managed' && class_exists('DRWP_AI_Backend_Managed')) {
            DRWP_AI_Backend_Managed::clear_quota_cache();
        }
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
