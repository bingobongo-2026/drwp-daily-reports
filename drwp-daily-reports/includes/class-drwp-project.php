<?php
if (!defined('ABSPATH')) exit;

class DRWP_Project {
    public static function init() {
        add_action('admin_post_drwp_save_project', [__CLASS__, 'save']);
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'drwp_projects';
    }

    public static function all($only_active = false) {
        global $wpdb;
        $sql = 'SELECT * FROM ' . self::table();
        if ($only_active) $sql .= " WHERE status = 'active'";
        $sql .= ' ORDER BY name ASC, id DESC';
        return $wpdb->get_results($sql);
    }

    public static function find($id) {
        global $wpdb;
        $id = absint($id);
        if (!$id) return null;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id));
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        $projects = self::all();
        // Edit mode: pre-fill the form from ?edit_id=N. The form's
        // hidden id input is what flips save() between INSERT (no
        // id) and UPDATE (id present) — the back-end already
        // handles both, this just surfaces the UI for it.
        $edit_project = null;
        if (!empty($_GET['edit_id'])) {
            $edit_project = self::find(absint($_GET['edit_id']));
        }
        include DRWP_PATH . 'admin/views/projects-page.php';
    }

    public static function save() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        check_admin_referer('drwp_save_project');
        global $wpdb;
        $id     = absint($_POST['id'] ?? 0);
        $name   = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $status = sanitize_text_field($_POST['status'] ?? 'active');
        if ($name === '') {
            wp_safe_redirect(admin_url('admin.php?page=drwp_projects&error=missing_name'));
            exit;
        }
        $allowed_status = ['active', 'inactive'];
        if (!in_array($status, $allowed_status, true)) $status = 'active';

        $data = [
            'name'           => $name,
            'status'         => $status,
            'postal_code'    => sanitize_text_field(wp_unslash($_POST['postal_code'] ?? '')),
            'address'        => sanitize_text_field(wp_unslash($_POST['address'] ?? '')),
            'phone'          => sanitize_text_field(wp_unslash($_POST['phone'] ?? '')),
            'job_description'=> wp_kses_post(wp_unslash($_POST['job_description'] ?? '')),
            'client_name'    => sanitize_text_field(wp_unslash($_POST['client_name'] ?? '')),
            'contact_person' => sanitize_text_field(wp_unslash($_POST['contact_person'] ?? '')),
            'notes'          => wp_kses_post(wp_unslash($_POST['notes'] ?? '')),
        ];

        if ($id) {
            $wpdb->update(self::table(), $data, ['id' => $id]);
        } else {
            $wpdb->insert(self::table(), $data);
        }
        wp_safe_redirect(admin_url('admin.php?page=drwp_projects&saved=1'));
        exit;
    }
}
