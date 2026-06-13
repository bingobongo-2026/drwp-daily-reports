<?php
if (!defined('ABSPATH')) exit;

/**
 * 予定 — planned site visits, sibling to DRWP daily reports.
 *
 * A plan is the lightweight forward-looking version of a daily
 * report: pick a date / project / time window / assignee and
 * optionally a note. No review status, no public-publish lifecycle,
 * no photo gallery. Plans can be linked back to the eventual report
 * row via `linked_report_id` once the visit actually happens.
 *
 * Capabilities:
 *   - List / view: `edit_posts` (workers see their own, operators
 *     see every plan).
 *   - Edit any plan: `edit_others_posts`.
 *   - Edit own plan: `edit_posts` + `user_id` matches OR
 *     `created_by` matches.
 */
class DRWP_Plan {
    const CAP_LIST   = 'edit_posts';
    const CAP_REVIEW = 'edit_others_posts';

    public static function init() {
        add_action('admin_post_drwp_save_plan',   [__CLASS__, 'save']);
        add_action('admin_post_drwp_delete_plan', [__CLASS__, 'delete']);
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'drwp_plans';
    }

    public static function find($id) {
        global $wpdb;
        $id = absint($id);
        if (!$id) return null;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id
        ));
    }

    /**
     * Worker-scoping helper — returns true when the operator can
     * see / touch every plan, false when scoped to their own.
     */
    public static function can_view_all() {
        return current_user_can(self::CAP_REVIEW);
    }

    public static function can_edit($plan) {
        // 退職社員は予定の追加・編集・削除すべて不可。読み(行を見る
        // ことそのもの) は許す。
        if (DRWP_User::is_retired()) return false;
        if (!$plan) return current_user_can(self::CAP_LIST);
        if (self::can_view_all()) return true;
        $uid = get_current_user_id();
        return current_user_can(self::CAP_LIST)
            && ((int) $plan->user_id === $uid || (int) $plan->created_by === $uid);
    }

    /**
     * Plan list for the admin page. Filters mirror the daily-report
     * surface: free text on notes + joined project name, status,
     * project, assignee, date range. Workers are silently scoped to
     * their own rows.
     */
    public static function search(array $filters = []) {
        global $wpdb;
        $proj_t = $wpdb->prefix . 'drwp_projects';
        $where = '1=1';
        $args  = [];
        if (!self::can_view_all()) {
            // Worker scope — show plans they're assigned to OR
            // plans they entered. created_by covers the case where
            // an operator drafted "頼んだぞ" without setting an
            // assignee yet, and the worker still wants visibility.
            $where .= ' AND (p.user_id = %d OR p.created_by = %d)';
            $uid = get_current_user_id();
            $args[] = $uid; $args[] = $uid;
        }
        $s = (string) ($filters['search'] ?? '');
        if ($s !== '') {
            $like = '%' . $wpdb->esc_like($s) . '%';
            $where .= ' AND (p.notes LIKE %s OR pj.name LIKE %s)';
            $args[] = $like; $args[] = $like;
        }
        if (!empty($filters['project_id'])) {
            $where .= ' AND p.project_id = %d';
            $args[] = (int) $filters['project_id'];
        }
        if (!empty($filters['user_id'])) {
            $where .= ' AND p.user_id = %d';
            $args[] = (int) $filters['user_id'];
        }
        if (!empty($filters['status'])) {
            $where .= ' AND p.status = %s';
            $args[] = (string) $filters['status'];
        }
        if (!empty($filters['date_from'])) {
            $where .= ' AND p.planned_date >= %s';
            $args[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where .= ' AND p.planned_date <= %s';
            $args[] = (string) $filters['date_to'];
        }
        $sql = 'SELECT p.* FROM ' . self::table() . ' p'
             . ' LEFT JOIN ' . $proj_t . ' pj ON pj.id = p.project_id'
             . ' WHERE ' . $where
             . ' ORDER BY p.planned_date ASC, p.started_at ASC, p.id ASC';
        return $args
            ? $wpdb->get_results($wpdb->prepare($sql, $args))
            : $wpdb->get_results($sql);
    }

    /**
     * Plans inside a month window for the front-end archive
     * calendar. Defaults to the same worker scope as everywhere
     * else (workers see assigned-or-created plans, operators see
     * all). `$self_only` forces the worker-style scope on top of
     * that — used when the archive's `?drwp_mine=1` toggle is on
     * so the overlay matches the report scoping.
     */
    public static function for_archive_month($month_start, $month_end, $self_only = false) {
        global $wpdb;
        $where = ["status = 'active'", 'planned_date >= %s', 'planned_date <= %s'];
        $args  = [$month_start, $month_end];
        if ($self_only || !self::can_view_all()) {
            $uid = get_current_user_id();
            $where[] = '(user_id = %d OR created_by = %d)';
            $args[] = $uid; $args[] = $uid;
        }
        $sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode(' AND ', $where)
             . ' ORDER BY planned_date ASC, started_at ASC, id ASC';
        return $wpdb->get_results($wpdb->prepare($sql, $args));
    }

    public static function render_page() {
        if (!current_user_can(self::CAP_LIST)) {
            wp_die(esc_html__('権限がありません', 'drwp-daily-reports'));
        }

        $filters = [
            'search'    => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
            'project_id'=> isset($_GET['project_id']) ? absint($_GET['project_id']) : 0,
            'user_id'   => isset($_GET['user_id']) ? absint($_GET['user_id']) : 0,
            'status'    => isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '',
            'date_from' => isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '',
            'date_to'   => isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '',
        ];

        $plans         = self::search($filters);
        $projects      = DRWP_Project::all();
        $can_view_all  = self::can_view_all();
        $workers       = $can_view_all ? self::worker_options() : [];
        $current_user  = wp_get_current_user();
        $statuses      = self::status_labels();

        include DRWP_PATH . 'admin/views/plans-page.php';
    }

    public static function status_labels() {
        return [
            'active'    => __('予定', 'drwp-daily-reports'),
            'completed' => __('完了', 'drwp-daily-reports'),
            'cancelled' => __('キャンセル', 'drwp-daily-reports'),
        ];
    }

    /**
     * Users who can take a plan assignment — anyone with
     * `edit_posts`. Returns id => display_name for the assignee
     * dropdown on the edit modal.
     */
    public static function worker_options() {
        $users = get_users([
            'capability__in' => [self::CAP_LIST],
            'orderby'        => 'display_name',
            'order'          => 'ASC',
            'fields'         => ['ID', 'display_name', 'user_login'],
        ]);
        $out = [];
        foreach ($users as $u) {
            $out[(int) $u->ID] = DRWP_User::display_name((int) $u->ID) ?: $u->user_login;
        }
        return $out;
    }

    public static function save() {
        if (!current_user_can(self::CAP_LIST)) {
            wp_die(esc_html__('権限がありません', 'drwp-daily-reports'));
        }
        DRWP_User::block_write_or_die();
        check_admin_referer('drwp_save_plan');
        // ライセンスが切れている間は新規作成・編集をすべて止める。
        // 日報 (save_report) と同じく 402 で blocked_message を返す。
        if (!DRWP_License::can_write()) {
            wp_die(
                DRWP_License::blocked_message(__('ライセンス状態により予定を保存できません。', 'drwp-daily-reports')),
                esc_html__('ライセンス未有効', 'drwp-daily-reports'),
                ['response' => 402]
            );
        }

        $id = absint($_POST['id'] ?? 0);
        $existing = $id ? self::find($id) : null;
        if ($id && !self::can_edit($existing)) {
            wp_die(esc_html__('権限がありません', 'drwp-daily-reports'));
        }

        $planned_date = sanitize_text_field((string) wp_unslash($_POST['planned_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $planned_date)) {
            wp_safe_redirect(admin_url('admin.php?page=drwp_plans&error=missing_date'));
            exit;
        }

        $project_id = absint($_POST['project_id'] ?? 0) ?: null;
        // Assignee — operators (`edit_others_posts`) can pick any
        // worker; everyone else is locked to themselves so a
        // contributor can't reassign someone else's plan to a third
        // party.
        if (self::can_view_all()) {
            $user_id = absint($_POST['user_id'] ?? 0) ?: null;
        } else {
            $user_id = get_current_user_id();
        }
        $started_at = self::sanitize_time($_POST['started_at'] ?? '');
        $ended_at   = self::sanitize_time($_POST['ended_at'] ?? '');
        $notes      = wp_kses_post(wp_unslash((string) ($_POST['notes'] ?? '')));
        $status     = sanitize_text_field((string) ($_POST['status'] ?? 'active'));
        if (!array_key_exists($status, self::status_labels())) $status = 'active';

        $linked_report_id = absint($_POST['linked_report_id'] ?? 0) ?: null;
        if ($linked_report_id) {
            // Sanity-check the link target exists; otherwise drop
            // it rather than store a dangling pointer.
            global $wpdb;
            $reports_t = $wpdb->prefix . 'drwp_reports';
            $hit = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $reports_t WHERE id = %d",
                $linked_report_id
            ));
            if (!$hit) $linked_report_id = null;
        }

        $data = [
            'project_id'       => $project_id,
            'user_id'          => $user_id,
            'planned_date'     => $planned_date,
            'started_at'       => $started_at,
            'ended_at'         => $ended_at,
            'notes'            => $notes,
            'status'           => $status,
            'linked_report_id' => $linked_report_id,
        ];

        global $wpdb;
        if ($id) {
            $wpdb->update(self::table(), $data, ['id' => $id]);
        } else {
            $data['created_by'] = get_current_user_id();
            $wpdb->insert(self::table(), $data);
        }
        wp_safe_redirect(admin_url('admin.php?page=drwp_plans&saved=1'));
        exit;
    }

    public static function delete() {
        if (!current_user_can(self::CAP_LIST)) {
            wp_die(esc_html__('権限がありません', 'drwp-daily-reports'));
        }
        DRWP_User::block_write_or_die();
        check_admin_referer('drwp_delete_plan');
        $id = absint($_POST['id'] ?? 0);
        $plan = $id ? self::find($id) : null;
        if (!$plan || !self::can_edit($plan)) {
            wp_die(esc_html__('権限がありません', 'drwp-daily-reports'));
        }
        global $wpdb;
        $wpdb->delete(self::table(), ['id' => $id]);
        wp_safe_redirect(admin_url('admin.php?page=drwp_plans&deleted=1'));
        exit;
    }

    private static function sanitize_time($v) {
        $v = trim((string) $v);
        if ($v === '') return null;
        if (preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $v, $m)) {
            return sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0));
        }
        return null;
    }
}
