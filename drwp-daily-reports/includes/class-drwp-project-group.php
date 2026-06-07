<?php
if (!defined('ABSPATH')) exit;

/**
 * Project group ("案件グループ") — many-to-many tagging on top of
 * DRWP_Project. Parallel structure to DRWP_Customer_Group: same
 * column shape, same API contract, just anchored on `project_id`
 * instead of `customer_id`. The submenu page lives next to 顧客
 * グループ in the 日報管理 sidebar, registered from
 * DRWP_Admin::menu().
 *
 * The colour is only a UI hint (used as a dot/swatch next to the
 * name in the project list); it has no business meaning and may
 * be blank. notes is free-form HTML, sanitized through
 * wp_kses_post to match the customer-notes column.
 */
class DRWP_Project_Group {

    public static function init() {
        add_action('admin_post_drwp_save_project_group', [__CLASS__, 'save']);
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'drwp_project_groups';
    }

    public static function map_table() {
        global $wpdb;
        return $wpdb->prefix . 'drwp_project_group_map';
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
     * Group IDs assigned to a single project. Used by the edit
     * modal to pre-select options in the multi-select.
     */
    public static function ids_for_project($project_id) {
        global $wpdb;
        $project_id = absint($project_id);
        if (!$project_id) return [];
        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            'SELECT group_id FROM ' . self::map_table() . ' WHERE project_id = %d',
            $project_id
        )));
    }

    /**
     * Bulk fetch group rows per project for listing pages — avoids
     * the N+1 query that would come from calling ids_for_project
     * per row in a foreach.
     *
     * @param int[] $project_ids
     * @return array<int, object[]>  project_id => list of group rows
     */
    public static function groups_by_project(array $project_ids) {
        if (empty($project_ids)) return [];
        global $wpdb;
        $ids = array_filter(array_map('absint', $project_ids));
        if (empty($ids)) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT m.project_id, g.id, g.name, g.color, g.status
               FROM ' . self::map_table() . ' m
         INNER JOIN ' . self::table() . ' g ON g.id = m.group_id
              WHERE m.project_id IN (' . $placeholders . ')
           ORDER BY g.name ASC',
            $ids
        ));
        $out = [];
        foreach ($rows as $row) {
            $pid = (int) $row->project_id;
            unset($row->project_id);
            $out[$pid][] = $row;
        }
        return $out;
    }

    /**
     * Replace the set of groups a project belongs to. Deletes
     * existing rows for this project and bulk-inserts the new set,
     * matching DRWP_Customer_Group::set_for_customer.
     */
    public static function set_for_project($project_id, array $group_ids) {
        global $wpdb;
        $project_id = absint($project_id);
        if (!$project_id) return;
        $wpdb->delete(self::map_table(), ['project_id' => $project_id]);
        $clean = array_filter(array_unique(array_map('absint', $group_ids)));
        foreach ($clean as $gid) {
            $wpdb->insert(self::map_table(), [
                'project_id' => $project_id,
                'group_id'   => $gid,
            ]);
        }
    }

    /**
     * Project IDs in the given group. Used by the 日報一覧 / PDF出力
     * filters: this resolves directly off the map table (no JOIN
     * through 顧客 like the customer-group variant) since 案件
     * グループ attaches to project rows themselves.
     */
    public static function project_ids_for_group($group_id) {
        global $wpdb;
        $group_id = absint($group_id);
        if (!$group_id) return [];
        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            'SELECT project_id FROM ' . self::map_table() . ' WHERE group_id = %d',
            $group_id
        )));
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        }
        $groups = self::all();
        $counts = self::project_counts();
        include DRWP_PATH . 'admin/views/project-groups-page.php';
    }

    /**
     * group_id => number of projects in that group. Used by the
     * groups admin page to render "案件数: N" next to each group.
     */
    public static function project_counts() {
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
        check_admin_referer('drwp_save_project_group');
        global $wpdb;
        $id     = absint($_POST['id'] ?? 0);
        $name   = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $color  = self::sanitize_color($_POST['color'] ?? '');
        $notes  = wp_kses_post(wp_unslash($_POST['notes'] ?? ''));
        $status = sanitize_text_field($_POST['status'] ?? 'active');
        if ($name === '') {
            wp_safe_redirect(admin_url('admin.php?page=drwp_groups&tab=project&error=missing_name'));
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
        wp_safe_redirect(admin_url('admin.php?page=drwp_groups&tab=project&saved=1'));
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
