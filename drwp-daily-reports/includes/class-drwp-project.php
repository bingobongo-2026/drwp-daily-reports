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

    public static function location_title($id) {
        $project = self::find($id);
        if (!$project) return '';
        $parts = array_filter([(string) ($project->prefecture ?? ''), (string) ($project->city ?? '')]);
        return implode(' ', $parts);
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
            'search'            => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
            'customer_group_id' => isset($_GET['customer_group_id']) ? absint($_GET['customer_group_id']) : (isset($_GET['group_id']) ? absint($_GET['group_id']) : 0),
            'project_group_id'  => isset($_GET['project_group_id']) ? absint($_GET['project_group_id']) : 0,
        ];
        $projects = self::search($filters['search'], $filters['customer_group_id'], $filters['project_group_id']);
        list($sort_field, $sort_order) = DRWP_Admin::parse_sort($_GET, ['id'], 'id', 'desc');
        usort($projects, function ($a, $b) use ($sort_order) {
            $cmp = (int) $a->id <=> (int) $b->id;
            return $sort_order === 'desc' ? -$cmp : $cmp;
        });
        $pager = DRWP_Admin::paginate_array($projects);
        $projects = $pager['items'];
        $total = $pager['total'];
        $paged = $pager['paged'];
        $pages = $pager['pages'];

        $customers = DRWP_Customer::all();
        $customer_groups = DRWP_Customer_Group::all(true);
        $project_groups  = DRWP_Project_Group::all(true);

        // Per-project group memberships for the listing chips +
        // edit modal pre-select. Bulk fetch so the listing avoids
        // an N+1 lookup.
        $project_ids = array_map(fn($p) => (int) $p->id, $projects);
        $project_group_rows = DRWP_Project_Group::groups_by_project($project_ids);
        $project_group_ids  = [];
        foreach ($project_group_rows as $pid => $gs) {
            $project_group_ids[$pid] = array_map(fn($g) => (int) $g->id, $gs);
        }

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

    /**
     * Text + customer-group + project-group search.
     *
     * Free-text LIKEs across the columns shown on the listing plus
     * the joined customer name so operators can search by client
     * even when the case-by-case project name is opaque. The
     * customer-group filter pivots through `p.customer_id`; the
     * project-group filter pivots through the project_group_map
     * directly.
     */
    public static function search($s = '', $customer_group_id = 0, $project_group_id = 0) {
        global $wpdb;
        $cust_t = DRWP_Customer::table();
        $where = '1=1';
        $args  = [];
        $s = (string) $s;
        if ($s !== '') {
            $like = '%' . $wpdb->esc_like($s) . '%';
            $where .= ' AND (p.name LIKE %s OR p.address LIKE %s OR p.prefecture LIKE %s'
                   . ' OR p.city LIKE %s OR p.street LIKE %s OR p.building LIKE %s'
                   . ' OR p.phone LIKE %s OR p.client_name LIKE %s OR p.contact_person LIKE %s'
                   . ' OR p.job_description LIKE %s OR p.notes LIKE %s OR cu.name LIKE %s)';
            for ($i = 0; $i < 12; $i++) $args[] = $like;
        }
        $customer_group_id = absint($customer_group_id);
        if ($customer_group_id) {
            $where .= ' AND EXISTS (SELECT 1 FROM '
                   . DRWP_Customer_Group::map_table()
                   . ' cm WHERE cm.customer_id = p.customer_id AND cm.group_id = %d)';
            $args[] = $customer_group_id;
        }
        $project_group_id = absint($project_group_id);
        if ($project_group_id) {
            $where .= ' AND EXISTS (SELECT 1 FROM '
                   . DRWP_Project_Group::map_table()
                   . ' pm WHERE pm.project_id = p.id AND pm.group_id = %d)';
            $args[] = $project_group_id;
        }
        $sql = 'SELECT p.* FROM ' . self::table() . ' p'
             . ' LEFT JOIN ' . $cust_t . ' cu ON cu.id = p.customer_id'
             . ' WHERE ' . $where
             . ' ORDER BY p.name ASC, p.id DESC';
        return $args
            ? $wpdb->get_results($wpdb->prepare($sql, $args))
            : $wpdb->get_results($sql);
    }

    public static function save() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('権限がありません', 'drwp-daily-reports'));
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

        $prefecture = sanitize_text_field(wp_unslash($_POST['prefecture'] ?? ''));
        $city       = sanitize_text_field(wp_unslash($_POST['city'] ?? ''));
        $street     = sanitize_text_field(wp_unslash($_POST['street'] ?? ''));
        $building   = sanitize_text_field(wp_unslash($_POST['building'] ?? ''));
        $customer_id = absint($_POST['customer_id'] ?? 0);
        $data = [
            'name'           => $name,
            'customer_id'    => $customer_id ?: null,
            'status'         => $status,
            'postal_code'    => sanitize_text_field(wp_unslash($_POST['postal_code'] ?? '')),
            'prefecture'     => $prefecture,
            'city'           => $city,
            'street'         => $street,
            'building'       => $building,
            'address'        => trim($prefecture . $city . $street . ' ' . $building),
            'phone'          => sanitize_text_field(wp_unslash($_POST['phone'] ?? '')),
            'job_description'=> wp_kses_post(wp_unslash($_POST['job_description'] ?? '')),
            'client_name'    => sanitize_text_field(wp_unslash($_POST['client_name'] ?? '')),
            'contact_person' => sanitize_text_field(wp_unslash($_POST['contact_person'] ?? '')),
            'notes'          => wp_kses_post(wp_unslash($_POST['notes'] ?? '')),
        ];

        if ($id) {
            $wpdb->update(self::table(), $data, ['id' => $id]);
            $saved_id = $id;
        } else {
            $wpdb->insert(self::table(), $data);
            $saved_id = (int) $wpdb->insert_id;
        }

        // 案件グループ memberships — replace wholesale with the set in
        // the submitted form. Missing key (no select rendered, or
        // every option deselected) → empty array → existing rows
        // wiped, mirroring the customer-side flow.
        $group_ids = isset($_POST['project_group_ids'])
            ? array_map('absint', (array) $_POST['project_group_ids'])
            : [];
        DRWP_Project_Group::set_for_project($saved_id, $group_ids);

        wp_safe_redirect(admin_url('admin.php?page=drwp_projects&saved=1'));
        exit;
    }
}
