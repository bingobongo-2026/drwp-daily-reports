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
    const META_RETIRED  = 'drwp_retired';
    // 任意の社員プロフィール 3 項目。WP の user_meta にそのまま持つ。
    const META_DEPARTMENT = 'drwp_department'; // 所属
    const META_HIRED_AT   = 'drwp_hired_at';   // 入社日 (YYYY-MM-DD)
    const META_NOTES      = 'drwp_notes';      // 備考
    const CAP_MANAGE    = 'edit_others_posts';
    // Short-lived marker cookie set whenever we detect a retired
    // session (either at logout time or right after a login attempt
    // was rejected). The marker survives wp_logout / the redirect
    // chain so the next page render — shortcode or wp-login bounce —
    // can present the "退職" notice instead of a generic
    // "please log in" affordance.
    const COOKIE_MARKER = 'drwp_retired_seen';

    public static function init() {
        add_action('admin_post_drwp_set_retired',  [__CLASS__, 'handle_set_retired']);
        add_action('admin_post_drwp_save_worker',  [__CLASS__, 'handle_save_worker']);
        // 退職社員はそもそもログインできない / 既存セッションも即破棄。
        // - 新規ログイン: `authenticate` フィルタで `WP_Error` 返却
        // - 既存セッション: `init` 早期に `wp_logout` + 退職マーカー
        //   Cookie をセット。続くショートコードがそれを拾って
        //   「ログインできません」を出す
        // - wp-admin の DRWP ページ: `admin_init` で `wp_die`
        // - wp-login.php(既存セッション → 管理画面行き 302 経由 /
        //   ログイン失敗エラーページ): `login_init` で常時
        //   ショートコードページに弾く(wp-login の画面は見せない)
        add_filter('authenticate',     [__CLASS__, 'block_retired_login'], 100, 1);
        add_action('init',             [__CLASS__, 'invalidate_session_if_retired'], 1);
        add_action('admin_init',       [__CLASS__, 'block_drwp_admin_pages_if_retired']);
        add_action('login_init',       [__CLASS__, 'redirect_wp_login_for_retired'], 1);
        add_filter('wp_login_errors',  [__CLASS__, 'redirect_on_retired_error'], 1, 2);
        add_action('wp_login',         [__CLASS__, 'clear_retired_marker_cookie_on_login'], 10, 2);
    }

    /**
     * Reject the login of a user who's flagged 退職. Runs at
     * priority 100 so the stock password/cookie checks have
     * already produced a WP_User before we look at the meta.
     * Also drops the marker cookie so the subsequent redirect
     * (`redirect_wp_login_for_retired`) can present the notice
     * on the shortcode page instead of leaving the worker on
     * wp-login.php's bare error screen.
     */
    public static function block_retired_login($user) {
        if ($user instanceof WP_User && self::is_retired((int) $user->ID)) {
            self::set_marker_cookie((int) $user->ID);
            return new WP_Error(
                'drwp_retired',
                __('このアカウントは退職状態のため、ログインできません。', 'drwp-daily-reports')
            );
        }
        return $user;
    }

    /**
     * Tear down the session of a retired worker who was still
     * logged in when the operator flipped the switch. Fires very
     * early on `init` so by the time auth_redirect / shortcode
     * rendering sees the current user, they're already logged
     * out. Front-end shortcodes pick up the marker cookie and
     * render the "退職" notice; wp-admin requests fall through to
     * the standard not-logged-in flow and get caught by our
     * `login_init` redirect (so wp-login.php's UI never shows).
     */
    public static function invalidate_session_if_retired() {
        if (!is_user_logged_in()) return;
        if (!self::is_retired()) return;
        self::set_marker_cookie(get_current_user_id());
        wp_logout();
    }

    /**
     * Lock retired users out of every DRWP admin page (`?page=drwp_*`)
     * with an in-place `wp_die` notice. Two scenarios:
     * - `is_retired()` true: still-logged-in retired user. Rare
     *   because `invalidate_session_if_retired` runs first, but
     *   defense in depth.
     * - Marker cookie present + not logged in: just-logged-out
     *   retired user landed here via WP's auth_redirect. Bounce
     *   them off wp-admin to the shortcode page (which renders
     *   the notice) instead of WP redirecting on to wp-login.php.
     */
    public static function block_drwp_admin_pages_if_retired() {
        if (self::is_retired()) {
            wp_die(
                esc_html__('このアカウントは退職状態のため、ログインできません。', 'drwp-daily-reports'),
                esc_html__('退職', 'drwp-daily-reports'),
                ['response' => 403, 'back_link' => false]
            );
        }
        if (!is_user_logged_in() && self::has_marker_cookie()) {
            $url = self::landing_url();
            wp_safe_redirect(add_query_arg('drwp_retired', '1', $url));
            exit;
        }
    }

    /**
     * Intercept wp-login.php loads and shove retired-marked
     * visitors at the shortcode page instead. Triggers from both
     * paths that would normally land on wp-login:
     * - wp-admin auth_redirect after the init logout (cookie
     *   carries the retired hint)
     * - failed login attempt by a retired user (the
     *   `authenticate` filter dropped the cookie before WP
     *   re-rendered the form)
     *
     * Form POSTs are left alone — those have to flow through
     * wp-login.php so wp_authenticate can run. We catch the
     * resulting error one redirect later when the response form
     * tries to render.
     */
    public static function redirect_wp_login_for_retired() {
        if (!empty($_POST)) return;
        // Logout / password reset confirmation / interim auth /
        // 2FA challenges have to keep flowing through wp-login.php.
        // Only intercept the bare login-form rendering.
        $action = isset($_GET['action']) ? sanitize_key((string) $_GET['action']) : '';
        if ($action !== '' && $action !== 'login') return;
        if (!empty($_GET['interim-login'])) return;
        $has_marker = self::has_marker_cookie() || !empty($_GET['drwp_retired']);
        if (!$has_marker) return;
        $url = self::landing_url();
        if (!$url) return;
        wp_safe_redirect(add_query_arg('drwp_retired', '1', $url));
        exit;
    }

    /**
     * Post-authentication error path on wp-login.php. When the
     * error includes `drwp_retired`(set by `block_retired_login`),
     * bounce to the landing page instead of letting wp-login.php
     * render the form with the error. `login_init` skips POSTs so
     * this hook is what covers the failed-login-by-retired case.
     */
    public static function redirect_on_retired_error($errors, $redirect_to) {
        unset($redirect_to);
        if (!is_wp_error($errors)) return $errors;
        if (!in_array('drwp_retired', $errors->get_error_codes(), true)) return $errors;
        $url = self::landing_url();
        if ($url) {
            wp_safe_redirect(add_query_arg('drwp_retired', '1', $url));
            exit;
        }
        return $errors;
    }

    /**
     * Successful login by a non-retired user — drop the marker
     * cookie so the "退職" notice doesn't keep appearing for the
     * next person on a shared device.
     */
    public static function clear_retired_marker_cookie_on_login($user_login, $user) {
        unset($user_login);
        if ($user instanceof WP_User && self::is_retired((int) $user->ID)) {
            return; // shouldn't happen — authenticate would reject first
        }
        self::clear_marker_cookie();
    }

    /**
     * Has the request shown signs of being retired-rejected?
     *
     * The marker cookie carries the user_id of the worker who was
     * blocked, not just a "1" flag, so we can verify on every page
     * load that they're still actually retired. When the operator
     * hits "復帰させる" on the 社員 page, the worker's browser
     * still holds the old cookie — without this check it would
     * keep showing the "ログインできません" notice until the cookie
     * naturally expired. Self-clearing on mismatch lets the worker
     * pick up access immediately.
     */
    public static function has_marker_cookie() {
        if (empty($_COOKIE[self::COOKIE_MARKER])) return false;
        $uid = (int) $_COOKIE[self::COOKIE_MARKER];
        if ($uid <= 0) {
            // Legacy "1" cookies from before we tracked the user_id,
            // or otherwise malformed values — drop them.
            self::clear_marker_cookie();
            return false;
        }
        if (!self::is_retired($uid)) {
            self::clear_marker_cookie();
            return false;
        }
        return true;
    }

    private static function set_marker_cookie($user_id) {
        if (headers_sent()) return;
        $user_id = (int) $user_id;
        if ($user_id <= 0) return;
        setcookie(self::COOKIE_MARKER, (string) $user_id, [
            'expires'  => time() + HOUR_IN_SECONDS,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        // Make the cookie available within the current request so
        // shortcodes rendering on this same hit see the marker
        // without waiting for the next round-trip.
        $_COOKIE[self::COOKIE_MARKER] = (string) $user_id;
    }

    private static function clear_marker_cookie() {
        if (headers_sent()) {
            unset($_COOKIE[self::COOKIE_MARKER]);
            return;
        }
        setcookie(self::COOKIE_MARKER, '', [
            'expires'  => time() - HOUR_IN_SECONDS,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::COOKIE_MARKER]);
    }

    /**
     * Where to send a retired user instead of wp-login.php /
     * wp-admin. Prefers the operator-configured login page (which
     * usually hosts `[drwp_login_form]` + the report archive); falls
     * back to the site root so we still avoid wp-login.
     */
    public static function landing_url() {
        $page_id = (int) get_option('drwp_login_page_id');
        if ($page_id && ($url = get_permalink($page_id))) return $url;
        return home_url('/');
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
                'department' => (string) get_user_meta((int) $u->ID, self::META_DEPARTMENT, true),
                'hired_at'   => (string) get_user_meta((int) $u->ID, self::META_HIRED_AT, true),
                'notes'      => (string) get_user_meta((int) $u->ID, self::META_NOTES, true),
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

    /**
     * Save the optional worker-profile trio (所属 / 入社日 / 備考).
     * All three are optional — empty input deletes the meta row so
     * we don't accumulate rows of '' across the userbase. Operator
     * only (`edit_others_posts`), and the target must actually be a
     * worker, mirroring the retire toggle's defenses.
     */
    public static function handle_save_worker() {
        if (!current_user_can(self::CAP_MANAGE)) {
            wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        }
        check_admin_referer('drwp_save_worker');
        $user_id = absint($_POST['user_id'] ?? 0);
        $u = $user_id ? get_userdata($user_id) : null;
        if (!$u || !user_can($u, 'edit_posts')) {
            wp_safe_redirect(admin_url('admin.php?page=drwp_workers&err=invalid'));
            exit;
        }

        $department = sanitize_text_field(wp_unslash((string) ($_POST['department'] ?? '')));
        $hired_at   = sanitize_text_field(wp_unslash((string) ($_POST['hired_at'] ?? '')));
        if ($hired_at !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hired_at)) {
            $hired_at = '';
        }
        $notes = sanitize_textarea_field(wp_unslash((string) ($_POST['notes'] ?? '')));

        $fields = [
            self::META_DEPARTMENT => $department,
            self::META_HIRED_AT   => $hired_at,
            self::META_NOTES      => $notes,
        ];
        foreach ($fields as $key => $value) {
            if ($value === '') {
                delete_user_meta($user_id, $key);
            } else {
                update_user_meta($user_id, $key, $value);
            }
        }

        DRWP_Audit::log('worker_profile_updated', '社員情報を更新', 0, ['user_id' => $user_id]);
        wp_safe_redirect(admin_url('admin.php?page=drwp_workers&saved=1'));
        exit;
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
