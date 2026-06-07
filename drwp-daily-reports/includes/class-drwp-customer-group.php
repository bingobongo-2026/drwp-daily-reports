<?php
if (!defined('ABSPATH')) exit;

/**
 * Customer group ("グループ") — many-to-many tagging on top of
 * DRWP_Customer. A customer can belong to 0..N groups; a group
 * lists 0..N customers. The submenu page lives next to 顧客 in the
 * 日報管理 sidebar, registered from DRWP_Admin::menu().
 *
 * The colour is only a UI hint (used as a dot/swatch next to the
 * name in the customer list); it has no business meaning and may
 * be blank. notes is free-form HTML, sanitized through
 * wp_kses_post to match the customer-notes column.
 */
class DRWP_Customer_Group {

    public static function init() {
        add_action('admin_post_drwp_save_customer_group', [__CLASS__, 'save']);
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'drwp_customer_groups';
    }

    public static function map_table() {
        global $wpdb;
        return $wpdb->prefix . 'drwp_customer_group_map';
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
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE id = %d',
            $id
        ));
    }

    /**
     * Group IDs assigned to a single customer. Used by the edit
     * modal to pre-select options in the multi-select.
     */
    public static function ids_for_customer($customer_id) {
        global $wpdb;
        $customer_id = absint($customer_id);
        if (!$customer_id) return [];
        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            'SELECT group_id FROM ' . self::map_table() . ' WHERE customer_id = %d',
            $customer_id
        )));
    }

    /**
     * Bulk fetch group rows per customer for listing pages — avoids
     * the N+1 query that would come from calling ids_for_customer
     * per row in a foreach.
     *
     * @param int[] $customer_ids
     * @return array<int, object[]>  customer_id => list of group rows
     */
    public static function groups_by_customer(array $customer_ids) {
        if (empty($customer_ids)) return [];
        global $wpdb;
        $ids = array_filter(array_map('absint', $customer_ids));
        if (empty($ids)) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT m.customer_id, g.id, g.name, g.color, g.status
               FROM ' . self::map_table() . ' m
         INNER JOIN ' . self::table() . ' g ON g.id = m.group_id
              WHERE m.customer_id IN (' . $placeholders . ')
           ORDER BY g.name ASC',
            $ids
        ));
        $out = [];
        foreach ($rows as $row) {
            $cid = (int) $row->customer_id;
            unset($row->customer_id);
            $out[$cid][] = $row;
        }
        return $out;
    }

    /**
     * Replace the set of groups a customer belongs to. Deletes
     * existing rows for this customer and bulk-inserts the new set,
     * which keeps the operation idempotent and atomic from the
     * caller's point of view (no need to compute diffs upstream).
     */
    public static function set_for_customer($customer_id, array $group_ids) {
        global $wpdb;
        $customer_id = absint($customer_id);
        if (!$customer_id) return;
        $wpdb->delete(self::map_table(), ['customer_id' => $customer_id]);
        $clean = array_filter(array_unique(array_map('absint', $group_ids)));
        foreach ($clean as $gid) {
            $wpdb->insert(self::map_table(), [
                'customer_id' => $customer_id,
                'group_id'    => $gid,
            ]);
        }
    }

    /**
     * Project IDs whose customer belongs to the given group.
     *
     * Used by the 日報一覧 and PDF出力 group filters: a daily report
     * carries `project_id`, a project carries `customer_id`, and a
     * customer can have N groups via the map table. Resolving that
     * chain upfront lets the existing reports query stay a single
     * SELECT against `drwp_reports` with an `IN (...)` instead of
     * forcing a 3-way JOIN.
     */
    public static function project_ids_for_group($group_id) {
        global $wpdb;
        $group_id = absint($group_id);
        if (!$group_id) return [];
        $projects_t = $wpdb->prefix . 'drwp_projects';
        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            'SELECT p.id FROM ' . $projects_t . ' p
              INNER JOIN ' . self::map_table() . ' m ON m.customer_id = p.customer_id
              WHERE m.group_id = %d',
            $group_id
        )));
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        }
        $groups = self::all();
        $counts = self::customer_counts();
        include DRWP_PATH . 'admin/views/customer-groups-page.php';
    }

    /**
     * group_id => number of customers in that group. Used by the
     * groups admin page to render "顧客数: N" next to each group.
     */
    public static function customer_counts() {
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT group_id, COUNT(*) AS n FROM ' . self::map_table() . ' GROUP BY group_id'
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->group_id] = (int) $row->n;
        }
        return $out;
    }

    public static function save() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        }
        check_admin_referer('drwp_save_customer_group');
        global $wpdb;
        $id     = absint($_POST['id'] ?? 0);
        $name   = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $color  = self::sanitize_color($_POST['color'] ?? '');
        $notes  = wp_kses_post(wp_unslash($_POST['notes'] ?? ''));
        $status = sanitize_text_field($_POST['status'] ?? 'active');
        if ($name === '') {
            wp_safe_redirect(admin_url('admin.php?page=drwp_customer_groups&error=missing_name'));
            exit;
        }
        if (!in_array($status, ['active', 'inactive'], true)) $status = 'active';

        $data = [
            'name'   => $name,
            'color'  => $color,
            'notes'  => $notes,
            'status' => $status,
        ];
        if ($id) {
            $wpdb->update(self::table(), $data, ['id' => $id]);
        } else {
            $wpdb->insert(self::table(), $data);
        }
        wp_safe_redirect(admin_url('admin.php?page=drwp_customer_groups&saved=1'));
        exit;
    }

    /**
     * Accept the `#RRGGBB` form only — anything else (3-digit hex,
     * named colours, junk) becomes empty so the DB column stays
     * predictable for downstream styling.
     */
    private static function sanitize_color($v) {
        $v = trim((string) $v);
        if ($v === '') return '';
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $v)) {
            return strtolower($v);
        }
        return '';
    }
}
