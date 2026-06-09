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
        // 退職社員は閲覧も含めて利用不可。新規ログインは
        // `authenticate` で弾き、既存セッションは `init` で破棄して
        // ログイン画面に戻す。`login_message` 経由で「退職状態」の
        // 説明文を出して再ログインの再試行も諦めてもらう。
        add_filter('authenticate',  [__CLASS__, 'block_retired_login'], 100, 1);
        add_action('init',          [__CLASS__, 'force_logout_if_retired']);
        add_filter('login_message', [__CLASS__, 'login_message_for_retired']);
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
     * Tear down the session of a retired user who was logged in
     * BEFORE the operator flipped the switch. Fires on every
     * request — front, wp-admin, REST, admin-ajax. For non-XHR
     * requests we redirect to the login screen with a marker so
     * the user can see the explanation; XHR/REST just lose the
     * session and let the downstream permission_callback return
     * 401.
     */
    public static function force_logout_if_retired() {
        if (!is_user_logged_in()) return;
        if (!self::is_retired()) return;
        wp_logout();
        $is_xhr  = (function_exists('wp_doing_ajax') && wp_doing_ajax())
                || (defined('REST_REQUEST') && REST_REQUEST)
                || (defined('DOING_CRON')   && DOING_CRON)
                || (defined('WP_CLI')       && WP_CLI);
        if ($is_xhr) return;
        // Avoid an infinite loop if we're already on wp-login.php.
        if (isset($GLOBALS['pagenow']) && $GLOBALS['pagenow'] === 'wp-login.php') return;
        wp_safe_redirect(add_query_arg('drwp_retired', '1', wp_login_url()));
        exit;
    }

    /**
     * Inject the explanation banner above the wp-login.php form
     * when the user landed there via the force-logout redirect or
     * was just rejected by `block_retired_login`.
     */
    public static function login_message_for_retired($message) {
        if (empty($_GET['drwp_retired'])) return $message;
        $notice = '<p class="message" style="border-left:4px solid #6b7280;">'
                . esc_html__('このアカウントは退職状態のため、利用できません。詳しくは管理者にお問い合わせください。', 'drwp-daily-reports')
                . '</p>';
        return $notice . $message;
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
                'name'       => $u->display_name ?: $u->user_login,
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
