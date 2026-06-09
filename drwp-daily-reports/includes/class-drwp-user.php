<?php
if (!defined('ABSPATH')) exit;

/**
 * 社員 — retirement flag + write-gate helpers.
 *
 * "退職した社員は日報を書けないが、過去の日報は残す" を実現するた
 * めの薄いラッパー。WP のロールを書き換えてしまうと wp-admin の他
 * のメニューまで一気に閉じてしまうので、プラグイン内で完結する
 * `drwp_retired` user_meta を 1 つだけ立てて、書き込みのエントリ
 * ポイントすべてにガードを差し込む方式にしている。
 *
 * - 読み(GET /reports, archive 一覧, 単一閲覧)は退職後も通す
 * - 書き(POST /reports, /plans, /upload-photo, /comments, レビュー,
 *   フロント編集, admin 予定 save/delete) は弾く
 *
 * 退職フラグの操作は事務所(`edit_others_posts`) のみ可能。作業員
 * が他の作業員を退職にする経路は無い。
 */
class DRWP_User {
    const META_RETIRED = 'drwp_retired';
    const CAP_MANAGE   = 'edit_others_posts';

    public static function init() {
        add_action('admin_post_drwp_set_retired', [__CLASS__, 'handle_set_retired']);
        // 退職社員は閲覧含めて利用不可。
        // - 新規ログインは `authenticate` フィルタで弾く
        // - 既存セッションは個別の境界 (フロントショートコード /
        //   wp-admin の DRWP ページ / REST) で「退職状態のため利用
        //   できません」を表示する。wp-login.php に飛ばすのは UX が
        //   悪い(現場の社員はそれが何かわからない)ので避ける。
        add_filter('authenticate', [__CLASS__, 'block_retired_login'], 100, 1);
        add_action('admin_init',   [__CLASS__, 'block_drwp_admin_pages_if_retired']);
    }

    /**
     * Reject the login of a user who's flagged 退職. Runs at
     * priority 100 so the stock password/cookie checks have
     * already produced a WP_User before we look at the meta.
     */
    public static function block_retired_login($user) {
        if ($user instanceof WP_User && self::is_retired((int) $user->ID)) {
            return new WP_Error(
                'drwp_retired',
                __('このアカウントは退職状態のため、ログインできません。', 'drwp-daily-reports')
            );
        }
        return $user;
    }

    /**
     * Lock retired users out of every DRWP admin page (`?page=drwp_*`)
     * with an in-place `wp_die` notice. We don't auto-logout because
     * that cascades into a wp-login.php redirect — the operator-side
     * "social" UX requested is "remain on the page they hit, show the
     * notice in place". Other wp-admin pages (Posts, Media, ...) are
     * untouched.
     */
    public static function block_drwp_admin_pages_if_retired() {
        if (!self::is_retired()) return;
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        if ($page === '' || strpos($page, 'drwp_') !== 0) return;
        wp_die(
            esc_html__('このアカウントは退職状態のため、ログインできません。', 'drwp-daily-reports'),
            esc_html__('退職', 'drwp-daily-reports'),
            ['response' => 403, 'back_link' => false]
        );
    }

    /**
     * Resolve a worker's display name as "last_name first_name"
     * (姓 名). Falls through to `display_name` then `user_login`
     * when the user profile hasn't filled in the meta. Accepts a
     * WP_User, user_id int, or stdClass row with `ID`.
     */
    public static function display_name($user_or_id) {
        if (is_object($user_or_id)) {
            $uid = (int) (isset($user_or_id->ID) ? $user_or_id->ID : ($user_or_id->id ?? 0));
            $user = $uid ? get_userdata($uid) : null;
            if (!$user && isset($user_or_id->display_name)) {
                // Fallback for already-shaped rows (e.g. SQL JOIN
                // results) that pre-resolved display_name.
                return (string) $user_or_id->display_name;
            }
        } else {
            $user = get_userdata((int) $user_or_id);
        }
        if (!$user) return '';
        $first = trim((string) get_user_meta($user->ID, 'first_name', true));
        $last  = trim((string) get_user_meta($user->ID, 'last_name', true));
        // 姓 名 — Japanese convention. Falling out cleanly for the
        // common "either-or-neither" data shapes the operator has
        // in the wild.
        $full = trim($last . ' ' . $first);
        if ($full !== '') return $full;
        if (!empty($user->display_name)) return (string) $user->display_name;
        return (string) $user->user_login;
    }

    public static function is_retired($user_id = null) {
        $user_id = (int) ($user_id ?: get_current_user_id());
        if (!$user_id) return false;
        return (bool) get_user_meta($user_id, self::META_RETIRED, true);
    }

    public static function set_retired($user_id, $retired) {
        $user_id = (int) $user_id;
        if (!$user_id) return;
        if ($retired) {
            update_user_meta($user_id, self::META_RETIRED, '1');
        } else {
            delete_user_meta($user_id, self::META_RETIRED);
        }
    }

    /** REST handlers — returns WP_Error if the caller is retired. */
    public static function block_write_rest() {
        if (self::is_retired()) {
            return new WP_Error(
                'drwp_retired',
                __('このアカウントは退職状態のため書き込みできません。', 'drwp-daily-reports'),
                ['status' => 403]
            );
        }
        return null;
    }

    /** admin-post + template_redirect handlers — wp_die on retired. */
    public static function block_write_or_die() {
        if (self::is_retired()) {
            wp_die(
                esc_html__('このアカウントは退職状態のため書き込みできません。', 'drwp-daily-reports'),
                '',
                ['response' => 403]
            );
        }
    }

    /**
     * Workers list for the 社員 admin page. Returns every user that
     * holds `edit_posts` (so all daily-report contributors), in
     * "active first, then retired" order with the last report date
     * pre-joined so the operator can see who's actually still
     * active in the field.
     */
    public static function workers_with_stats() {
        $users = get_users([
            'capability__in' => ['edit_posts'],
            'orderby'        => 'display_name',
            'order'          => 'ASC',
        ]);
        if (!$users) return [];
        global $wpdb;
        $reports_t = $wpdb->prefix . 'drwp_reports';
        $ids = array_map(function ($u) { return (int) $u->ID; }, $users);
        $place = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, MAX(report_date) AS last_date, COUNT(*) AS cnt
               FROM $reports_t WHERE user_id IN ($place) GROUP BY user_id",
            $ids
        ));
        $last_by_uid = [];
        foreach ($rows as $r) {
            $last_by_uid[(int) $r->user_id] = [
                'last' => (string) $r->last_date,
                'cnt'  => (int) $r->cnt,
            ];
        }
        $out = [];
        foreach ($users as $u) {
            $retired = self::is_retired((int) $u->ID);
            $stats = $last_by_uid[(int) $u->ID] ?? ['last' => '', 'cnt' => 0];
            $out[] = (object) [
                'id'         => (int) $u->ID,
                'name'       => self::display_name($u),
                'email'      => (string) $u->user_email,
                'roles'      => $u->roles,
                'retired'    => $retired,
                'last_date'  => $stats['last'],
                'report_cnt' => $stats['cnt'],
            ];
        }
        // Sort: active first (by name), then retired (by name).
        usort($out, function ($a, $b) {
            if ($a->retired !== $b->retired) return $a->retired ? 1 : -1;
            return strcmp($a->name, $b->name);
        });
        return $out;
    }

    public static function render_page() {
        if (!current_user_can(self::CAP_MANAGE)) {
            wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        }
        $workers = self::workers_with_stats();
        $filter  = isset($_GET['view']) ? sanitize_key((string) $_GET['view']) : 'active';
        if (!in_array($filter, ['active', 'retired', 'all'], true)) $filter = 'active';

        include DRWP_PATH . 'admin/views/workers-page.php';
    }

    public static function handle_set_retired() {
        if (!current_user_can(self::CAP_MANAGE)) {
            wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        }
        check_admin_referer('drwp_set_retired');
        $user_id = absint($_POST['user_id'] ?? 0);
        $retired = !empty($_POST['retired']);
        if (!$user_id) {
            wp_safe_redirect(admin_url('admin.php?page=drwp_workers'));
            exit;
        }
        // Defense — don't retire someone who can't possibly be a
        // worker. Stops accidental "退職" on an unrelated user_id
        // POSTed in via curl.
        $u = get_userdata($user_id);
        if (!$u || !user_can($u, 'edit_posts')) {
            wp_safe_redirect(admin_url('admin.php?page=drwp_workers&err=invalid'));
            exit;
        }
        self::set_retired($user_id, $retired);
        DRWP_Audit::log(
            $retired ? 'user_retired' : 'user_reactivated',
            $retired ? '社員を退職に設定' : '社員を在籍に戻す',
            0,
            ['user_id' => $user_id]
        );
        wp_safe_redirect(admin_url('admin.php?page=drwp_workers&saved=1'));
        exit;
    }
}
