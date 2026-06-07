<?php
if (!defined('ABSPATH')) exit;

/**
 * Customer ("顧客") — owns the address / phone / email of an
 * organization. A customer has 0..N projects ("案件"). Project-level
 * address/phone fields stay as overrides for the per-project case
 * where the work site differs from the customer's main address.
 */
class DRWP_Customer {
    public static function init() {
        add_action('admin_post_drwp_save_customer', [__CLASS__, 'save']);
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'drwp_customers';
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
        $customers = self::all();
        // Group data for the listing table + edit modal. We bulk
        // fetch group rows per customer (chips in the table), the
        // active group list (options in the multi-select), and a
        // per-customer id array (pre-selecting options on edit).
        $groups = DRWP_Customer_Group::all(true);
        $customer_ids = array_map(fn($c) => (int) $c->id, $customers);
        $customer_groups = DRWP_Customer_Group::groups_by_customer($customer_ids);
        $customer_group_ids = [];
        foreach ($customer_groups as $cid => $gs) {
            $customer_group_ids[$cid] = array_map(fn($g) => (int) $g->id, $gs);
        }
        include DRWP_PATH . 'admin/views/customers-page.php';
    }

    public static function save() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        check_admin_referer('drwp_save_customer');
        global $wpdb;
        $id     = absint($_POST['id'] ?? 0);
        $name   = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $status = sanitize_text_field($_POST['status'] ?? 'active');
        if ($name === '') {
            wp_safe_redirect(admin_url('admin.php?page=drwp_customers&error=missing_name'));
            exit;
        }
        $allowed_status = ['active', 'inactive'];
        if (!in_array($status, $allowed_status, true)) $status = 'active';

        $prefecture = sanitize_text_field(wp_unslash($_POST['prefecture'] ?? ''));
        $city       = sanitize_text_field(wp_unslash($_POST['city'] ?? ''));
        $street     = sanitize_text_field(wp_unslash($_POST['street'] ?? ''));
        $building   = sanitize_text_field(wp_unslash($_POST['building'] ?? ''));
        $data = [
            'name'        => $name,
            'status'      => $status,
            'postal_code' => sanitize_text_field(wp_unslash($_POST['postal_code'] ?? '')),
            'prefecture'  => $prefecture,
            'city'        => $city,
            'street'      => $street,
            'building'    => $building,
            'address'     => trim($prefecture . $city . $street . ' ' . $building),
            'phone'       => sanitize_text_field(wp_unslash($_POST['phone'] ?? '')),
            'email'       => sanitize_email(wp_unslash($_POST['email'] ?? '')),
            'notes'       => wp_kses_post(wp_unslash($_POST['notes'] ?? '')),
        ];

        if ($id) {
            $wpdb->update(self::table(), $data, ['id' => $id]);
            $saved_id = $id;
        } else {
            $wpdb->insert(self::table(), $data);
            $saved_id = (int) $wpdb->insert_id;
        }

        // Group memberships — replace wholesale with the set in the
        // submitted form. An empty array means the operator
        // deselected every option, so we still want to clear the
        // existing rows.
        $group_ids = isset($_POST['group_ids'])
            ? array_map('absint', (array) $_POST['group_ids'])
            : [];
        DRWP_Customer_Group::set_for_customer($saved_id, $group_ids);

        wp_safe_redirect(admin_url('admin.php?page=drwp_customers&saved=1'));
        exit;
    }
}
