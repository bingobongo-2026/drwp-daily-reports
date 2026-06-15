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
        if (!current_user_can('manage_options')) wp_die(esc_html__('権限がありません', 'drwp-daily-reports'));
        $filters = [
            'search'   => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
            'group_id' => isset($_GET['group_id']) ? absint($_GET['group_id']) : 0,
        ];
        $customers = self::search($filters['search'], $filters['group_id']);
        list($sort_field, $sort_order) = DRWP_Admin::parse_sort($_GET, ['id'], 'id', 'desc');
        usort($customers, function ($a, $b) use ($sort_order) {
            $cmp = (int) $a->id <=> (int) $b->id;
            return $sort_order === 'desc' ? -$cmp : $cmp;
        });
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
        // Photo data for the listing chip + edit modal preloading.
        // Counts power the "🖼 N" indicator on the list; the per-id
        // map (id, url, caption) seeds the dialog with existing
        // thumbnails when the operator opens an edit row.
        $customer_photo_counts = DRWP_Customer_Media::counts($customer_ids);
        $customer_photos = [];
        foreach ($customer_ids as $cid) {
            $rows = DRWP_Customer_Media::for_customer($cid);
            $payload = [];
            foreach ($rows as $r) {
                $url = wp_get_attachment_image_url((int) $r->attachment_id, 'thumbnail');
                if (!$url) continue;
                $payload[] = [
                    'id'      => (int) $r->attachment_id,
                    'url'     => $url,
                    'caption' => (string) ($r->caption ?? ''),
                ];
            }
            $customer_photos[$cid] = $payload;
        }
        include DRWP_PATH . 'admin/views/customers-page.php';
    }

    /**
     * Text + group-membership search.
     *
     * Free-text query LIKEs across the columns the operator can see
     * on the listing (name / address parts / phone / email / notes).
     * Group filter joins via the customer-group map. Empty inputs
     * fall through to the full set, matching the existing `all()`
     * behaviour.
     */
    public static function search($s = '', $group_id = 0) {
        global $wpdb;
        $where = '1=1';
        $args  = [];
        $s = (string) $s;
        if ($s !== '') {
            $like = '%' . $wpdb->esc_like($s) . '%';
            $where .= ' AND (c.name LIKE %s OR c.address LIKE %s OR c.prefecture LIKE %s'
                   . ' OR c.city LIKE %s OR c.street LIKE %s OR c.building LIKE %s'
                   . ' OR c.phone LIKE %s OR c.email LIKE %s OR c.notes LIKE %s)';
            for ($i = 0; $i < 9; $i++) $args[] = $like;
        }
        $group_id = absint($group_id);
        if ($group_id) {
            $where .= ' AND EXISTS (SELECT 1 FROM '
                   . DRWP_Customer_Group::map_table()
                   . ' m WHERE m.customer_id = c.id AND m.group_id = %d)';
            $args[] = $group_id;
        }
        $sql = 'SELECT c.* FROM ' . self::table() . ' c WHERE ' . $where
             . ' ORDER BY c.name ASC, c.id DESC';
        return $args
            ? $wpdb->get_results($wpdb->prepare($sql, $args))
            : $wpdb->get_results($sql);
    }

    public static function save() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('権限がありません', 'drwp-daily-reports'));
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

        // 画像 — same shape as the report photo picker: parallel
        // attachment_ids[] / attachment_captions[] arrays in the
        // order the operator arranged them in the dialog.
        $attachment_ids = isset($_POST['attachment_ids']) ? (array) $_POST['attachment_ids'] : [];
        $captions       = isset($_POST['attachment_captions']) ? (array) $_POST['attachment_captions'] : [];
        $photo_rows = [];
        foreach ($attachment_ids as $i => $aid) {
            $photo_rows[] = [
                'attachment_id' => (int) $aid,
                'caption'       => (string) ($captions[$i] ?? ''),
            ];
        }
        DRWP_Customer_Media::sync($saved_id, $photo_rows);

        wp_safe_redirect(admin_url('admin.php?page=drwp_customers&saved=1'));
        exit;
    }
}
