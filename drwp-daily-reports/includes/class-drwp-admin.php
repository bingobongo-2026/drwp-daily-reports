<?php
if (!defined('ABSPATH')) exit;

class DRWP_Admin {
    const CAP_EDIT  = 'edit_posts';
    const CAP_REVIEW = 'edit_others_posts';
    const CAP_CONVERT = 'publish_posts';
    const PER_PAGE = 25;
    // 表示件数の選択肢。25 を既定にしつつ、現場担当者がもう少し
    // 一覧性を上げたい時に 50/75/100 まで広げられるよう用意する。
    const PER_PAGE_CHOICES = [25, 50, 75, 100];

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_menu', [__CLASS__, 'mark_settings_section'], 999);
        add_action('admin_head', [__CLASS__, 'settings_section_css']);
        add_action('admin_post_drwp_save_report', [__CLASS__, 'save_report']);
        add_action('admin_post_drwp_bulk_reports', [__CLASS__, 'bulk_reports']);
        add_action('admin_post_drwp_convert_single', [__CLASS__, 'convert_single']);
        add_action('admin_post_drwp_export_reports_csv', [__CLASS__, 'export_filtered_csv']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
        add_action('admin_notices', [__CLASS__, 'license_notice']);
    }

    /** Validate per_page against the allowed choices (defaults to 25). */
    private static function parse_per_page($req) {
        $raw = isset($req['per_page']) ? (int) $req['per_page'] : self::PER_PAGE;
        return in_array($raw, self::PER_PAGE_CHOICES, true) ? $raw : self::PER_PAGE;
    }

    /**
     * Slugs that belong to the "設定" group inside the 日報管理
     * submenu. Listed in the order they're registered in `menu()`
     * — `mark_settings_section` only uses this set to decide which
     * <li>s get the section class, not to reorder them.
     */
    private static function settings_section_slugs() {
        return [
            'drwp_output',
            'drwp_login_settings',
            'drwp_notifications',
            'drwp_ai',
            'drwp_license',
            'drwp_audit',
        ];
    }

    /**
     * After every plugin has registered its submenus, decorate the
     * settings rows so the sidebar visually separates them from the
     * operational pages above. Pure CSS classes injected via
     * `$submenu[parent][i][4]` (the LI class slot) — the items stay
     * direct children of `drwp_reports` so WP's hover flyout keeps
     * showing every entry.
     */
    public static function mark_settings_section() {
        global $submenu;
        if (empty($submenu['drwp_reports']) || !is_array($submenu['drwp_reports'])) {
            return;
        }
        $settings_set = array_flip(self::settings_section_slugs());
        $first_done = false;
        foreach ($submenu['drwp_reports'] as &$item) {
            $slug = $item[2] ?? '';
            if (!isset($settings_set[$slug])) continue;
            $cls = isset($item[4]) ? trim((string) $item[4]) : '';
            $cls .= ($cls === '' ? '' : ' ') . 'drwp-settings-child';
            if (!$first_done) {
                $cls .= ' drwp-settings-first';
                $first_done = true;
            }
            $item[4] = $cls;
        }
        unset($item);
    }

    /**
     * Sidebar styling for the 設定 group — emitted on every admin
     * page since the 日報管理 submenu renders site-wide (the hover
     * flyout has to look right from the Dashboard too).
     */
    public static function settings_section_css() {
        ?>
        <style id="drwp-settings-section-style">
            #adminmenu .wp-submenu li.drwp-settings-child > a {
                padding-left: 28px;
            }
            #adminmenu .wp-submenu li.drwp-settings-first {
                margin-top: 6px;
                padding-top: 4px;
                border-top: 1px solid rgba(240, 246, 252, 0.13);
            }
            #adminmenu .wp-submenu li.drwp-settings-first::before {
                content: "設定";
                display: block;
                padding: 4px 12px 2px;
                font-size: 11px;
                font-weight: 600;
                color: #a7aaad;
                letter-spacing: 0.04em;
                pointer-events: none;
            }
        </style>
        <?php
    }

    public static function license_notice() {
        if (!current_user_can(self::CAP_EDIT)) return;

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $on_drwp = $screen && is_string($screen->id) && strpos($screen->id, 'drwp_') !== false;
        $on_dashboard = $screen && $screen->id === 'dashboard';
        if (!$on_drwp && !$on_dashboard) return;

        // Already on the license page — no need to nag.
        if ($screen && $screen->id !== false && strpos((string) $screen->id, 'drwp_license') !== false) return;

        $status = DRWP_License::status();
        if ($status === 'active' || $status === 'grace') return;

        $api_url     = (string) get_option(DRWP_License::OPT_API_URL, '');
        $key         = (string) get_option(DRWP_License::OPT_KEY, '');
        $public_key  = (string) get_option(DRWP_License::OPT_PUBLIC_KEY, '');
        $is_configured = $api_url !== '' && $key !== '';

        if (!current_user_can('manage_options')) {
            // Non-admins see a soft notice without the link.
            ?>
            <div class="notice notice-warning"><p>
              <?php esc_html_e('日報マン: ライセンスが有効になっていないため、日報の保存・記事化はできません。サイト管理者に連絡してください。', 'drwp-daily-reports'); ?>
            </p></div>
            <?php
            return;
        }

        $url = admin_url('admin.php?page=drwp_license');
        if (!$is_configured) {
            $msg = __('日報マン: ライセンスが未設定です。日報の保存・記事化を行うには、API URL とライセンスキーを設定してください。', 'drwp-daily-reports');
        } elseif ($public_key === '') {
            $msg = __('日報マン: 公開鍵が未取得のため署名検証が無効です。「公開鍵を取得」を実行してください。', 'drwp-daily-reports');
        } else {
            $msg = __('日報マン: ライセンスがアクティブではありません。「いま照会する」を実行するか、ライセンスサーバの状態を確認してください。', 'drwp-daily-reports');
        }
        ?>
        <div class="notice notice-warning">
          <p>
            <?php echo esc_html($msg); ?>
            <a class="button button-small" href="<?php echo esc_url($url); ?>" style="margin-left:8px;">
              <?php esc_html_e('ライセンス設定を開く', 'drwp-daily-reports'); ?>
            </a>
          </p>
        </div>
        <?php
    }

    public static function enqueue($hook) {
        if (is_string($hook) && strpos($hook, 'drwp_articles') !== false) {
            wp_enqueue_media();
            wp_enqueue_editor();
            return;
        }
        // Customer photo gallery uses wp.media + Sortable inside the
        // edit dialog. Library is loaded on the 顧客 page itself.
        if (is_string($hook) && strpos($hook, 'drwp_customers') !== false) {
            wp_enqueue_media();
            return;
        }
        // 日報一覧 page: the inline edit modal supports adding photos
        // via the REST upload endpoint, mirroring the front-end
        // archive's edit flow. Only the media library helpers are
        // needed (no wp.media picker) but enqueuing media gives us
        // the same affordances regardless.
        if (is_string($hook) && (strpos($hook, 'drwp_reports') !== false || $hook === 'toplevel_page_drwp_reports')) {
            wp_enqueue_media();
        }
        if (!is_string($hook) || strpos($hook, 'drwp_report_edit') === false) return;
        wp_enqueue_media();
        wp_enqueue_style('drwp-admin', DRWP_URL . 'admin/assets/admin.css', [], DRWP_VERSION);
        wp_enqueue_script('drwp-admin', DRWP_URL . 'admin/assets/admin.js', ['jquery'], DRWP_VERSION, true);
        wp_localize_script('drwp-admin', 'drwpRest', [
            'url'            => esc_url_raw(rest_url('drwp/v1')),
            'nonce'          => wp_create_nonce('wp_rest'),
            'admin_edit_url' => admin_url('admin.php?page=drwp_report_edit&id=__ID__'),
            'projects'       => self::project_map(),
            'labels'         => [
                'pending'        => DRWP_Labels::review_status('pending'),
                'approved'       => DRWP_Labels::review_status('approved'),
                'needs_revision' => DRWP_Labels::review_status('needs_revision'),
                'edit_requested' => DRWP_Labels::review_status('edit_requested'),
            ],
            'i18n'           => [
                'uploading' => __('アップロード中…', 'drwp-daily-reports'),
                'failed'    => __('アップロードに失敗しました', 'drwp-daily-reports'),
            ],
        ]);
    }

    public static function menu() {
        // Flat submenu under 日報管理 — keeping every page as a direct
        // child of `drwp_reports` lets WP's default hover flyout
        // surface the entire list (日報一覧 〜 操作履歴) when the
        // sidebar is in its collapsed/non-current state.
        // サイドバー親メニュー: サービス名「日報マン」をそのまま出す。
        // 中の slug (`drwp_reports`) はそのままなので、既存リンクは壊
        // れない。
        $reports = __('日報マン', 'drwp-daily-reports');
        add_menu_page($reports, $reports, self::CAP_EDIT, 'drwp_reports', [__CLASS__, 'reports_page'], 'dashicons-media-spreadsheet');

        $list_label = __('日報一覧', 'drwp-daily-reports');
        add_submenu_page('drwp_reports', $list_label, $list_label, self::CAP_EDIT, 'drwp_reports', [__CLASS__, 'reports_page']);
        $plans = __('予定一覧', 'drwp-daily-reports');
        add_submenu_page('drwp_reports', $plans, $plans, DRWP_Plan::CAP_LIST, 'drwp_plans', ['DRWP_Plan', 'render_page']);
        $articles = __('記事作成', 'drwp-daily-reports');
        add_submenu_page('drwp_reports', $articles, $articles, self::CAP_CONVERT, 'drwp_articles', [__CLASS__, 'articles_page']);
        $proj = __('案件', 'drwp-daily-reports');
        add_submenu_page('drwp_reports', $proj, $proj, 'manage_options', 'drwp_projects', ['DRWP_Project', 'render_page']);
        $cust = __('顧客', 'drwp-daily-reports');
        add_submenu_page('drwp_reports', $cust, $cust, 'manage_options', 'drwp_customers', ['DRWP_Customer', 'render_page']);
        $grp = __('グループ', 'drwp-daily-reports');
        add_submenu_page('drwp_reports', $grp, $grp, 'manage_options', DRWP_Groups_Admin::SLUG, ['DRWP_Groups_Admin', 'render_page']);
        $workers = __('社員', 'drwp-daily-reports');
        add_submenu_page('drwp_reports', $workers, $workers, DRWP_User::CAP_MANAGE, 'drwp_workers', ['DRWP_User', 'render_page']);
        $pdf = __('PDF出力', 'drwp-daily-reports');
        add_submenu_page('drwp_reports', $pdf, $pdf, self::CAP_EDIT, 'drwp_print', ['DRWP_Print', 'render_page']);

        // Settings pages — same parent, registered in the order they
        // should appear in the sidebar. ログイン設定's render callback
        // lives in class-drwp-login.php (`DRWP_Login::render_settings_page`)
        // but the submenu is registered here to keep ordering and
        // capability rules in one place.
        $output = __('公開設定', 'drwp-daily-reports');
        add_submenu_page('drwp_reports', $output, $output, 'manage_options', 'drwp_output', ['DRWP_Output_Admin', 'render_page']);
        $login = __('ログイン設定', 'drwp-daily-reports');
        add_submenu_page('drwp_reports', $login, $login, 'manage_options', 'drwp_login_settings', ['DRWP_Login', 'render_settings_page']);
        $notif = __('通知設定', 'drwp-daily-reports');
        add_submenu_page('drwp_reports', $notif, $notif, 'manage_options', 'drwp_notifications', ['DRWP_Notifications_Admin', 'render_page']);
        $ai = __('AI設定', 'drwp-daily-reports');
        add_submenu_page('drwp_reports', $ai, $ai, 'manage_options', 'drwp_ai', ['DRWP_AI_Admin', 'render_page']);
        $lic = __('ライセンス', 'drwp-daily-reports');
        add_submenu_page('drwp_reports', $lic, $lic, 'manage_options', 'drwp_license', ['DRWP_License_Admin', 'render_page']);
        $audit = __('操作履歴', 'drwp-daily-reports');
        add_submenu_page('drwp_reports', $audit, $audit, 'manage_options', 'drwp_audit', ['DRWP_Audit_Admin', 'render_page']);
        // 使い方ガイドは社員でも開けるように CAP_EDIT で出す。
        $help = __('使い方', 'drwp-daily-reports');
        add_submenu_page('drwp_reports', $help, $help, self::CAP_EDIT, DRWP_Help::SLUG, ['DRWP_Help', 'render_page']);
        // 開発用シードページ — 管理者のみ。本番運用には載せない想定。
        $seed = __('テストデータ', 'drwp-daily-reports');
        add_submenu_page('drwp_reports', $seed, $seed, 'manage_options', DRWP_Seed::SLUG, ['DRWP_Seed', 'render_page']);

        // Hidden pages (parent = null): reachable only via direct links
        // from the dashboard / review redirects / notification emails /
        // audit log, not from the sidebar.
        $edit = __('日報編集', 'drwp-daily-reports');
        add_submenu_page(null, $edit, $edit, self::CAP_EDIT, 'drwp_report_edit', [__CLASS__, 'report_edit_page']);
        $prev = __('公開プレビュー', 'drwp-daily-reports');
        add_submenu_page(null, $prev, $prev, self::CAP_EDIT, 'drwp_report_preview', [__CLASS__, 'report_preview_page']);
    }

    public static function project_map_public() {
        return self::project_map();
    }

    public static function project_location_map() {
        $all = DRWP_Project::all();
        $map = [];
        foreach ($all as $p) {
            $parts = array_filter([(string) ($p->prefecture ?? ''), (string) ($p->city ?? '')]);
            if ($parts) $map[(int) $p->id] = implode(' ', $parts);
        }
        return (object) $map;
    }

    private static function project_map() {
        $all = DRWP_Project::all();
        $map = [];
        foreach ($all as $p) $map[(int) $p->id] = (string) $p->name;
        return (object) $map;
    }

    private static function reports_table() {
        global $wpdb;
        return $wpdb->prefix . 'drwp_reports';
    }

    /**
     * Accept HH:MM or HH:MM:SS for the report's started_at /
     * ended_at TIME columns; anything else (empty, junk) becomes
     * NULL so we don't push garbage into MySQL.
     */
    private static function sanitize_time_input($v) {
        $v = trim((string) $v);
        if ($v === '') return null;
        if (preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $v, $m)) {
            return sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0));
        }
        return null;
    }

    private static function current_user_can_edit_report($report) {
        if (current_user_can(self::CAP_REVIEW)) return true;
        if (!current_user_can(self::CAP_EDIT)) return false;
        if (!$report) return true;
        return (int) $report->user_id === get_current_user_id();
    }

    /** Read 日報一覧 filter values from $_GET / $_POST into a normalized array. */
    private static function read_reports_filters($req) {
        return [
            'search'        => isset($req['s']) ? sanitize_text_field(wp_unslash($req['s'])) : '',
            'review_status' => isset($req['review_status']) ? sanitize_text_field(wp_unslash($req['review_status'])) : '',
            'post_status'   => isset($req['post_status']) ? sanitize_text_field(wp_unslash($req['post_status'])) : '',
            'project_id'    => isset($req['project_id']) ? absint($req['project_id']) : 0,
            'user_id'       => isset($req['user_id']) ? absint($req['user_id']) : 0,
            'customer_group_id' => isset($req['customer_group_id']) ? absint($req['customer_group_id']) : (isset($req['group_id']) ? absint($req['group_id']) : 0),
            'project_group_id'  => isset($req['project_group_id']) ? absint($req['project_group_id']) : 0,
            'date_from'     => isset($req['date_from']) ? sanitize_text_field(wp_unslash($req['date_from'])) : '',
            'date_to'       => isset($req['date_to']) ? sanitize_text_field(wp_unslash($req['date_to'])) : '',
        ];
    }

    /**
     * Build the WHERE clause + bound args used by both 日報一覧 と
     * 絞り込み CSV エクスポート. Honors the same visibility scope as
     * `reports_page()` so a worker can never export rows they
     * couldn't see in the list.
     */
    private static function build_reports_query(array $filters) {
        global $wpdb;
        $where = '1=1';
        $args = [];
        if (!current_user_can(self::CAP_REVIEW)) {
            $where .= ' AND user_id = %d';
            $args[] = get_current_user_id();
        }
        if ($filters['search'] !== '') {
            $where .= ' AND (public_title LIKE %s OR public_body LIKE %s OR work_description LIKE %s OR post_tags LIKE %s)';
            $like = '%' . $wpdb->esc_like($filters['search']) . '%';
            $args[] = $like; $args[] = $like; $args[] = $like; $args[] = $like;
        }
        if ($filters['review_status'] !== '') {
            $where .= ' AND review_status = %s';
            $args[] = $filters['review_status'];
        }
        if (!empty($filters['post_status'])) {
            $where .= ' AND post_status = %s';
            $args[] = $filters['post_status'];
        }
        if ($filters['project_id']) {
            $where .= ' AND project_id = %d';
            $args[] = $filters['project_id'];
        }
        if (!empty($filters['customer_group_id'])) {
            // 顧客グループ resolves to the list of project IDs whose
            // customer belongs to the group. Empty list → no match
            // (`0=1`) so the filter doesn't silently drop and show
            // every report.
            $cg_projects = DRWP_Customer_Group::project_ids_for_group($filters['customer_group_id']);
            if (empty($cg_projects)) {
                $where .= ' AND 0=1';
            } else {
                $placeholders = implode(',', array_fill(0, count($cg_projects), '%d'));
                $where .= ' AND project_id IN (' . $placeholders . ')';
                foreach ($cg_projects as $pid) $args[] = $pid;
            }
        }
        if (!empty($filters['project_group_id'])) {
            // 案件グループ resolves directly off the project_group_map
            // (no JOIN through 顧客). Same zero-row guard.
            $pg_projects = DRWP_Project_Group::project_ids_for_group($filters['project_group_id']);
            if (empty($pg_projects)) {
                $where .= ' AND 0=1';
            } else {
                $placeholders = implode(',', array_fill(0, count($pg_projects), '%d'));
                $where .= ' AND project_id IN (' . $placeholders . ')';
                foreach ($pg_projects as $pid) $args[] = $pid;
            }
        }
        if ($filters['date_from'] !== '') {
            $where .= ' AND report_date >= %s';
            $args[] = $filters['date_from'];
        }
        if ($filters['date_to'] !== '') {
            $where .= ' AND report_date <= %s';
            $args[] = $filters['date_to'];
        }
        if ($filters['user_id']) {
            $where .= ' AND user_id = %d';
            $args[] = $filters['user_id'];
        }
        return [$where, $args];
    }

    public static function reports_page() {
        if (!current_user_can(self::CAP_EDIT)) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        global $wpdb;
        $table = self::reports_table();

        $filters = self::read_reports_filters($_GET);
        list($where, $args) = self::build_reports_query($filters);

        $per_page = self::parse_per_page($_GET);

        $count_sql = "SELECT COUNT(*) FROM $table WHERE $where";
        $total = $args
            ? (int) $wpdb->get_var($wpdb->prepare($count_sql, $args))
            : (int) $wpdb->get_var($count_sql);

        $paged = max(1, (int) ($_GET['paged'] ?? 1));
        $pages = max(1, (int) ceil($total / $per_page));
        if ($paged > $pages) $paged = $pages;
        $offset = ($paged - 1) * $per_page;

        $sql = "SELECT * FROM $table WHERE $where ORDER BY report_date DESC, id DESC LIMIT %d OFFSET %d";
        $query_args = $args;
        $query_args[] = $per_page;
        $query_args[] = $offset;
        $reports = $wpdb->get_results($wpdb->prepare($sql, $query_args));

        // 振り返りアドバイス用に、フィルタ条件にマッチする全 ID を
        // 先頭から最大 DRWP_AI::ADVISE_MAX 件まで集める。pagination
        // を跨いで「絞り込んだ集合全体」を AI に渡せる。
        $advise_max = class_exists('DRWP_AI') ? DRWP_AI::ADVISE_MAX : 60;
        $ids_sql = "SELECT id FROM $table WHERE $where ORDER BY report_date DESC, id DESC LIMIT %d";
        $ids_args = array_merge($args, [$advise_max]);
        $filtered_ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare($ids_sql, $ids_args)));

        // Reporter dropdown — only users who actually wrote a report
        // (DISTINCT user_id). Honors the same visibility scope so
        // workers only see themselves and operators see everyone.
        $reporter_where = '1=1';
        $reporter_args = [];
        if (!current_user_can(self::CAP_REVIEW)) {
            $reporter_where .= ' AND user_id = %d';
            $reporter_args[] = get_current_user_id();
        }
        $reporter_ids = $reporter_args
            ? $wpdb->get_col($wpdb->prepare("SELECT DISTINCT user_id FROM $table WHERE $reporter_where", $reporter_args))
            : $wpdb->get_col("SELECT DISTINCT user_id FROM $table WHERE $reporter_where");
        $reporters = [];
        foreach ($reporter_ids as $uid) {
            $name = DRWP_User::display_name((int) $uid);
            if ($name === '') $name = '#' . (int) $uid;
            $reporters[(int) $uid] = $name;
        }
        natcasesort($reporters);

        $projects = DRWP_Project::all();
        $customer_groups = DRWP_Customer_Group::all(true);
        $project_groups  = DRWP_Project_Group::all(true);

        // $per_page はビュー側でセレクトボックスとページネーション
        // リンクの両方で参照する。
        include DRWP_PATH . 'admin/views/reports-list.php';
    }

    public static function articles_page() {
        if (!current_user_can(self::CAP_CONVERT)) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        global $wpdb;
        $table = self::reports_table();

        $filters = [
            'search'      => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
            'post_status' => isset($_GET['post_status']) ? sanitize_text_field(wp_unslash($_GET['post_status'])) : '',
            'project_id'  => isset($_GET['project_id']) ? absint($_GET['project_id']) : 0,
            'date_from'   => isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '',
            'date_to'     => isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '',
        ];

        $where = "review_status = 'approved'";
        $args = [];
        if (!current_user_can(self::CAP_REVIEW)) {
            $where .= ' AND user_id = %d';
            $args[] = get_current_user_id();
        }
        if ($filters['search'] !== '') {
            $where .= ' AND (public_title LIKE %s OR public_body LIKE %s OR work_description LIKE %s OR post_tags LIKE %s)';
            $like = '%' . $wpdb->esc_like($filters['search']) . '%';
            $args[] = $like; $args[] = $like; $args[] = $like; $args[] = $like;
        }
        if ($filters['post_status'] !== '') {
            $where .= ' AND post_status = %s';
            $args[] = $filters['post_status'];
        }
        if ($filters['project_id']) {
            $where .= ' AND project_id = %d';
            $args[] = $filters['project_id'];
        }
        if ($filters['date_from'] !== '') {
            $where .= ' AND report_date >= %s';
            $args[] = $filters['date_from'];
        }
        if ($filters['date_to'] !== '') {
            $where .= ' AND report_date <= %s';
            $args[] = $filters['date_to'];
        }

        $count_sql = "SELECT COUNT(*) FROM $table WHERE $where";
        $total = $args
            ? (int) $wpdb->get_var($wpdb->prepare($count_sql, $args))
            : (int) $wpdb->get_var($count_sql);

        $paged = max(1, (int) ($_GET['paged'] ?? 1));
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($paged > $pages) $paged = $pages;
        $offset = ($paged - 1) * self::PER_PAGE;

        $sql = "SELECT * FROM $table WHERE $where ORDER BY report_date DESC, id DESC LIMIT %d OFFSET %d";
        $query_args = $args;
        $query_args[] = self::PER_PAGE;
        $query_args[] = $offset;
        $reports = $wpdb->get_results($wpdb->prepare($sql, $query_args));

        $projects = DRWP_Project::all();

        include DRWP_PATH . 'admin/views/articles-list.php';
    }

    public static function report_edit_page() {
        if (!current_user_can(self::CAP_EDIT)) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        DRWP_User::block_write_or_die();
        global $wpdb;
        $table = self::reports_table();
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        $report = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id)) : null;
        if ($report && !self::current_user_can_edit_report($report)) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        $projects = DRWP_Project::all(true);
        $photos = $report ? DRWP_Media::for_report($report->id) : [];
        include DRWP_PATH . 'admin/views/report-edit.php';
    }

    public static function report_preview_page() {
        if (!current_user_can(self::CAP_EDIT)) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        global $wpdb;
        $table = self::reports_table();
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        $report = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id)) : null;
        if ($report && !self::current_user_can_edit_report($report)) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        include DRWP_PATH . 'admin/views/report-preview.php';
    }

    public static function save_report() {
        if (!current_user_can(self::CAP_EDIT)) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        DRWP_User::block_write_or_die();
        check_admin_referer('drwp_save_report');
        if (!DRWP_License::can_write()) {
            wp_die(
                DRWP_License::blocked_message(__('ライセンス状態により保存できません。', 'drwp-daily-reports')),
                esc_html__('ライセンス未有効', 'drwp-daily-reports'),
                ['response' => 402]
            );
        }

        global $wpdb;
        $table = self::reports_table();
        $id = absint($_POST['id'] ?? 0);
        $existing = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id)) : null;
        if ($existing && !self::current_user_can_edit_report($existing)) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));

        $project_id = absint($_POST['project_id'] ?? 0);
        if ($project_id && !DRWP_Project::find($project_id)) $project_id = 0;
        $data = [
            'project_id' => $project_id ?: null,
            'report_date' => sanitize_text_field($_POST['report_date'] ?? current_time('Y-m-d')),
            'started_at' => self::sanitize_time_input($_POST['started_at'] ?? ''),
            'ended_at'   => self::sanitize_time_input($_POST['ended_at'] ?? ''),
            'work_description' => wp_kses_post(wp_unslash($_POST['work_description'] ?? '')),
            'issues' => wp_kses_post(wp_unslash($_POST['issues'] ?? '')),
            'next_plan' => wp_kses_post(wp_unslash($_POST['next_plan'] ?? '')),
            'public_title' => sanitize_text_field($_POST['public_title'] ?? ''),
            'public_intro' => wp_kses_post(wp_unslash($_POST['public_intro'] ?? '')),
            'public_body' => wp_kses_post(wp_unslash($_POST['public_body'] ?? '')),
            'public_next_plan' => wp_kses_post(wp_unslash($_POST['public_next_plan'] ?? '')),
            'post_template' => DRWP_Labels::sanitize_post_template($_POST['post_template'] ?? 'standard'),
            'post_category_id' => absint($_POST['post_category_id'] ?? 0) ?: null,
            'post_tags' => sanitize_text_field($_POST['post_tags'] ?? ''),
            'post_status' => sanitize_text_field($_POST['post_status'] ?? 'draft'),
            'scheduled_at' => sanitize_text_field($_POST['scheduled_at'] ?? '') ?: null,
        ];
        if ($existing) {
            $wpdb->update($table, $data, ['id' => $id]);
            DRWP_Audit::log('report_updated', '日報を更新', $id, ['project_id' => $data['project_id']]);
        } else {
            $data['user_id'] = get_current_user_id();
            $wpdb->insert($table, $data);
            $id = (int) $wpdb->insert_id;
            DRWP_Audit::log('report_created', '日報を作成', $id, ['project_id' => $data['project_id']]);
        }

        $photos = [];
        $attachment_ids = (array) ($_POST['attachment_ids'] ?? []);
        $captions = (array) ($_POST['attachment_captions'] ?? []);
        foreach ($attachment_ids as $i => $att_id) {
            $photos[] = [
                'attachment_id' => (int) $att_id,
                'caption'       => (string) ($captions[$i] ?? ''),
            ];
        }
        $saved_photos = DRWP_Media::sync($id, $photos);
        DRWP_Audit::log('photos_updated', '写真を更新', $id, ['count' => $saved_photos]);

        $fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
        do_action('drwp_report_submitted', $id, $fresh);

        wp_safe_redirect(admin_url('admin.php?page=drwp_reports&updated=1'));
        exit;
    }

    public static function convert_single() {
        if (!current_user_can('publish_posts')) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        check_admin_referer('drwp_convert_single');
        $id = absint($_POST['id'] ?? 0);
        if (!$id) wp_die(esc_html__('ID が指定されていません。', 'drwp-daily-reports'));
        $result = DRWP_Post_Converter::sync_post($id, true);
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }
        wp_safe_redirect(admin_url('admin.php?page=drwp_reports&updated=1'));
        exit;
    }

    public static function bulk_reports() {
        if (!current_user_can(self::CAP_EDIT)) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        check_admin_referer('drwp_bulk_reports');
        global $wpdb;
        $table = self::reports_table();
        $ids = array_values(array_filter(array_map('absint', (array) ($_POST['report_ids'] ?? []))));
        $action = sanitize_text_field($_POST['bulk_action'] ?? '');
        $count = 0;

        if ($action === 'bulk_export_csv') {
            self::export_csv($ids);
            return;
        }

        $review_actions = ['bulk_approve', 'bulk_revision'];
        $convert_actions = ['bulk_convert'];
        if (in_array($action, $review_actions, true) && !current_user_can(self::CAP_REVIEW)) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        if (in_array($action, $convert_actions, true) && !current_user_can(self::CAP_CONVERT)) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));

        foreach ($ids as $id) {
            if (!$id) continue;
            $report = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
            if (!$report) continue;
            if (!self::current_user_can_edit_report($report)) continue;

            if ($action === 'bulk_approve') {
                if ((int) $wpdb->update($table, ['review_status' => 'approved'], ['id' => $id])) {
                    DRWP_Audit::log('review_status_changed', '一括承認', $id, ['from' => $report->review_status, 'to' => 'approved']);
                    do_action('drwp_review_changed', (int) $id, (string) $report->review_status, 'approved', '');
                    $count++;
                }
            } elseif ($action === 'bulk_revision') {
                if ((int) $wpdb->update($table, ['review_status' => 'needs_revision'], ['id' => $id])) {
                    DRWP_Audit::log('review_status_changed', '一括差し戻し', $id, ['from' => $report->review_status, 'to' => 'needs_revision']);
                    do_action('drwp_review_changed', (int) $id, (string) $report->review_status, 'needs_revision', '');
                    $count++;
                }
            } elseif ($action === 'bulk_convert') {
                $result = DRWP_Post_Converter::sync_post($id, true);
                if (!is_wp_error($result)) $count++;
            } elseif ($action === 'bulk_update_publish') {
                $data = [
                    'post_template' => DRWP_Labels::sanitize_post_template($_POST['bulk_post_template'] ?? 'standard'),
                    'post_category_id' => absint($_POST['bulk_post_category_id'] ?? 0) ?: null,
                    'post_tags' => sanitize_text_field($_POST['bulk_post_tags'] ?? ''),
                    'post_status' => sanitize_text_field($_POST['bulk_post_status'] ?? 'draft'),
                    'scheduled_at' => sanitize_text_field($_POST['bulk_scheduled_at'] ?? '') ?: null,
                ];
                if ((int) $wpdb->update($table, $data, ['id' => $id])) {
                    DRWP_Audit::log('publish_settings_updated', '公開設定を一括更新', $id, $data);
                    $count++;
                }
            }
        }
        // 日報操作 (drwp_operations) was merged into the 日報一覧
        // page; the legacy `redirect_page=drwp_operations` field on
        // any cached form silently maps to `drwp_reports` so old
        // submissions still land somewhere sensible.
        $redirect_page = sanitize_text_field($_POST['redirect_page'] ?? 'drwp_reports');
        if (!in_array($redirect_page, ['drwp_reports', 'drwp_articles'], true)) $redirect_page = 'drwp_reports';
        wp_safe_redirect(admin_url('admin.php?page=' . $redirect_page . '&updated=' . $count));
        exit;
    }

    private static function export_csv($ids) {
        if (!current_user_can(self::CAP_EDIT)) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        $ids = array_values(array_filter(array_map('absint', (array) $ids)));
        if (empty($ids)) {
            wp_safe_redirect(admin_url('admin.php?page=drwp_reports'));
            exit;
        }
        global $wpdb;
        $table = self::reports_table();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $scope_args = $ids;
        $scope_sql = "id IN ($placeholders)";
        if (!current_user_can(self::CAP_REVIEW)) {
            $scope_sql .= ' AND user_id = %d';
            $scope_args[] = get_current_user_id();
        }
        $sql = "SELECT id, report_date, review_status, public_title, post_template, post_category_id, post_tags, post_status, scheduled_at, linked_post_id, work_description FROM $table WHERE $scope_sql ORDER BY id DESC";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $scope_args), ARRAY_A);
        nocache_headers();
        // Shift-JIS (CP932) で出力。Excel 日本語版でダブルクリックで
        // 開いた際の文字化けを避けるため、SJIS-win に変換してから書く。
        header('Content-Type: text/csv; charset=Shift_JIS');
        header('Content-Disposition: attachment; filename="drwp-reports-' . gmdate('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        $header = ['id', 'report_date', 'review_status', 'public_title', 'post_template', 'post_category_id', 'post_tags', 'post_status', 'scheduled_at', 'linked_post_id', 'work_description'];
        self::fputcsv_sjis($out, $header);
        foreach ($rows as $row) self::fputcsv_sjis($out, $row);
        fclose($out);
        exit;
    }

    /**
     * 絞り込み条件にマッチする日報全件を Shift-JIS の CSV で出力する。
     * 「選択した行」ではなく「絞り込まれた全件」が対象 — 一括操作
     * ドロップダウンと用途を分けるための独立エンドポイント。
     */
    public static function export_filtered_csv() {
        if (!current_user_can(self::CAP_EDIT)) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        check_admin_referer('drwp_export_reports_csv');

        global $wpdb;
        $table = self::reports_table();
        $filters = self::read_reports_filters($_GET);
        list($where, $args) = self::build_reports_query($filters);

        $sql = "SELECT id, report_date, review_status, public_title, post_template, post_category_id, post_tags, post_status, scheduled_at, linked_post_id, work_description FROM $table WHERE $where ORDER BY report_date DESC, id DESC";
        $rows = $args
            ? $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A)
            : $wpdb->get_results($sql, ARRAY_A);

        nocache_headers();
        header('Content-Type: text/csv; charset=Shift_JIS');
        header('Content-Disposition: attachment; filename="drwp-reports-' . gmdate('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        $header = ['id', 'report_date', 'review_status', 'public_title', 'post_template', 'post_category_id', 'post_tags', 'post_status', 'scheduled_at', 'linked_post_id', 'work_description'];
        self::fputcsv_sjis($out, $header);
        foreach ($rows as $row) self::fputcsv_sjis($out, $row);
        fclose($out);
        exit;
    }

    /**
     * fputcsv のラッパー — 各セルを UTF-8 → SJIS-win (CP932) に変換
     * してから書き出す。SJIS-win は ① ～ 髙 などの Windows 拡張文字
     * も拾えるので Excel での読み込み事故が起きにくい。
     */
    private static function fputcsv_sjis($handle, array $row) {
        $sjis = array_map(static function ($v) {
            return mb_convert_encoding((string) $v, 'SJIS-win', 'UTF-8');
        }, $row);
        fputcsv($handle, $sjis);
    }
}
