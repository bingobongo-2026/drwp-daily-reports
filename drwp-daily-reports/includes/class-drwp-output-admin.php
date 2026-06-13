<?php
if (!defined('ABSPATH')) exit;

class DRWP_Output_Admin {
    public static function init() {
        add_action('admin_post_drwp_save_output', [__CLASS__, 'save']);
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('権限がありません', 'drwp-daily-reports'));
        $settings = DRWP_Output::settings();
        include DRWP_PATH . 'admin/views/output-page.php';
    }

    public static function save() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('権限がありません', 'drwp-daily-reports'));
        check_admin_referer('drwp_save_output');
        DRWP_Output::save_settings([
            'post_type'      => sanitize_text_field((string) ($_POST['post_type'] ?? 'post')),
            'auto_thumbnail' => isset($_POST['auto_thumbnail']),
        ]);
        wp_safe_redirect(admin_url('admin.php?page=drwp_output&saved=1'));
        exit;
    }
}
