<?php
if (!defined('ABSPATH')) exit;

class DRWP_Project_Controller {
    public static function init() {
        add_action('admin_post_drwp_save_project', [__CLASS__, 'save_project']);
    }

    public static function save_project() {
        if (!(current_user_can('manage_options') || current_user_can('drwp_manage_projects'))) wp_die('Unauthorized');
        check_admin_referer('drwp_save_project');

        global $wpdb;
        $table = $wpdb->prefix . 'drwp_projects';
        $id = intval($_POST['id'] ?? 0);
        $data = [
            'name' => sanitize_text_field($_POST['name'] ?? ''),
            'client_name' => sanitize_text_field($_POST['client_name'] ?? ''),
            'site_address' => sanitize_text_field($_POST['site_address'] ?? ''),
            'status' => sanitize_text_field($_POST['status'] ?? 'active'),
            'updated_at' => current_time('mysql'),
        ];

        if ($id) {
            $wpdb->update($table, $data, ['id' => $id]);
            DRWP_Audit::log('project_updated', '現場を更新しました', null, ['project_id' => $id]);
        } else {
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($table, $data);
            $id = intval($wpdb->insert_id);
            DRWP_Audit::log('project_created', '現場を作成しました', null, ['project_id' => $id]);
        }

        wp_redirect(admin_url('admin.php?page=drwp-projects&saved=1'));
        exit;
    }
}
