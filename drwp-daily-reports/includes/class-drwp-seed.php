<?php
if (!defined('ABSPATH')) exit;

/**
 * テストデータ投入ユーティリティ。CLI (`wp drwp seed`) と管理画面の
 * ボタンの両方から呼び出せる。挙動を確かめるためのサンプルデータ
 * (工務店の受注をイメージした顧客・案件・グループ・日報・予定) を
 * まとめて投入し、後から正確に消せるよう投入した行 ID をすべて
 * `drwp_seed_state` オプションに記録する。
 *
 * 本番運用には載せない想定の「仮」機能なので、管理画面ボタンは
 * `manage_options` を持つ管理者にだけ見せる。
 */
class DRWP_Seed {
    const OPT_STATE = 'drwp_seed_state';
    const SLUG = 'drwp_seed';
    const TAG = 'drwp-seed';

    public static function init() {
        add_action('admin_post_drwp_seed_run',   [__CLASS__, 'handle_run']);
        add_action('admin_post_drwp_seed_reset', [__CLASS__, 'handle_reset']);
    }

    /** State accessor — keeps the option shape in one place. */
    public static function state() {
        $s = get_option(self::OPT_STATE, []);
        return is_array($s) ? $s : [];
    }

    public static function has_seed() {
        $s = self::state();
        foreach (['customer_ids', 'project_ids', 'report_ids'] as $k) {
            if (!empty($s[$k])) return true;
        }
        return false;
    }

    /**
     * Run the seeder. Returns a summary array of inserted row counts.
     * `$user_id` is the author for reports / plans / "created_by".
     * Falls back to an admin user when called without a logged-in
     * actor (e.g. CLI under a service account).
     */
    public static function run($user_id = 0) {
        global $wpdb;

        $user_id = (int) $user_id ?: (int) get_current_user_id();
        if (!$user_id) {
            $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
            $user_id = $admins ? (int) $admins[0] : 1;
        }

        // 既にシード済みなら、まず確実にクリアしてから入れ直す。
        // (毎回 fresh な状態を作りたいので reset → run の挙動。)
        if (self::has_seed()) {
            self::reset();
        }

        $state = [
            'customer_group_ids' => [],
            'project_group_ids'  => [],
            'customer_ids'       => [],
            'project_ids'        => [],
            'report_ids'         => [],
            'plan_ids'           => [],
        ];

        // 顧客グループ — 工務店の受注タイプ分け。
        $customer_groups = [
            ['name' => '一戸建て新築', 'color' => '#2563eb', 'notes' => '土地から/建替えを含む新築一戸建ての施主'],
            ['name' => '外構工事のみ', 'color' => '#16a34a', 'notes' => '門・塀・駐車場・植栽など外構のみ受注'],
            ['name' => 'リフォーム',     'color' => '#f59e0b', 'notes' => 'キッチン/水回り/内装などの改修'],
        ];
        $cg = $wpdb->prefix . 'drwp_customer_groups';
        foreach ($customer_groups as $row) {
            $wpdb->insert($cg, $row + ['status' => 'active']);
            $state['customer_group_ids'][] = (int) $wpdb->insert_id;
        }
        list($cg_new, $cg_exterior, $cg_renovation) = $state['customer_group_ids'];

        // 案件グループ — 現場のステータスで分ける。
        $project_groups = [
            ['name' => '進行中',       'color' => '#3b82f6', 'notes' => '現在施工中の現場'],
            ['name' => '完工済み',     'color' => '#64748b', 'notes' => '引き渡し済み・アフター対応中'],
        ];
        $pg = $wpdb->prefix . 'drwp_project_groups';
        foreach ($project_groups as $row) {
            $wpdb->insert($pg, $row + ['status' => 'active']);
            $state['project_group_ids'][] = (int) $wpdb->insert_id;
        }
        list($pg_ongoing, $pg_done) = $state['project_group_ids'];

        // 顧客 — 5 件。施主のタイプは customer_group へのマッピングで分ける。
        $customers = [
            [
                'name' => '山田 太郎', 'phone' => '090-1111-2222', 'email' => 'yamada@example.test',
                'postal_code' => '252-0231', 'prefecture' => '神奈川県', 'city' => '相模原市中央区',
                'street' => '中央 1-2-3', 'address' => '神奈川県相模原市中央区中央1-2-3',
                'notes' => '[DRWP_SEED] 平屋希望・予算 3,500 万', 'group' => $cg_new,
            ],
            [
                'name' => '鈴木 花子', 'phone' => '080-3333-4444', 'email' => 'suzuki@example.test',
                'postal_code' => '108-0014', 'prefecture' => '東京都', 'city' => '港区',
                'street' => '芝 5-6-7', 'address' => '東京都港区芝5-6-7',
                'notes' => '[DRWP_SEED] 子世帯と二世帯住宅を検討中', 'group' => $cg_new,
            ],
            [
                'name' => '田中 一郎', 'phone' => '090-5555-6666', 'email' => 'tanaka@example.test',
                'postal_code' => '215-0021', 'prefecture' => '神奈川県', 'city' => '川崎市麻生区',
                'street' => '上麻生 8-9-10', 'address' => '神奈川県川崎市麻生区上麻生8-9-10',
                'notes' => '[DRWP_SEED] 既存の塀を全面リニューアル希望', 'group' => $cg_exterior,
            ],
            [
                'name' => '佐藤 健',   'phone' => '080-7777-8888', 'email' => 'sato@example.test',
                'postal_code' => '194-0013', 'prefecture' => '東京都', 'city' => '町田市',
                'street' => '原町田 11-12-13', 'address' => '東京都町田市原町田11-12-13',
                'notes' => '[DRWP_SEED] 車 2 台分の駐車場拡張', 'group' => $cg_exterior,
            ],
            [
                'name' => '高橋 美香', 'phone' => '090-9999-0000', 'email' => 'takahashi@example.test',
                'postal_code' => '231-0011', 'prefecture' => '神奈川県', 'city' => '横浜市中区',
                'street' => '太田町 14-15-16', 'address' => '神奈川県横浜市中区太田町14-15-16',
                'notes' => '[DRWP_SEED] 戸建てキッチン全面入替え', 'group' => $cg_renovation,
            ],
        ];
        $ct = $wpdb->prefix . 'drwp_customers';
        $cgm = $wpdb->prefix . 'drwp_customer_group_map';
        foreach ($customers as $c) {
            $group = $c['group'];
            unset($c['group']);
            $wpdb->insert($ct, $c + ['status' => 'active']);
            $cid = (int) $wpdb->insert_id;
            $state['customer_ids'][] = $cid;
            $wpdb->insert($cgm, ['customer_id' => $cid, 'group_id' => $group]);
        }
        list($cust_yamada, $cust_suzuki, $cust_tanaka, $cust_sato, $cust_takahashi) = $state['customer_ids'];

        // 案件 — 顧客と 1:1 で紐付け。住所は顧客と同じ（現場 = 自宅）。
        $projects = [
            [
                'name' => '山田邸 新築工事', 'customer_id' => $cust_yamada,
                'prefecture' => '神奈川県', 'city' => '相模原市中央区', 'street' => '中央 1-2-3',
                'address' => '神奈川県相模原市中央区中央1-2-3',
                'job_description' => '木造 2 階建て・延床 28 坪・ガレージ付', 'contact_person' => '山田 太郎',
                'notes' => '[DRWP_SEED] 着工中', 'group' => $pg_ongoing,
            ],
            [
                'name' => '鈴木邸 新築工事', 'customer_id' => $cust_suzuki,
                'prefecture' => '東京都', 'city' => '港区', 'street' => '芝 5-6-7',
                'address' => '東京都港区芝5-6-7',
                'job_description' => '木造 3 階建て・二世帯住宅・延床 42 坪', 'contact_person' => '鈴木 花子',
                'notes' => '[DRWP_SEED] 基礎工事完了', 'group' => $pg_ongoing,
            ],
            [
                'name' => '田中邸 外構リニューアル', 'customer_id' => $cust_tanaka,
                'prefecture' => '神奈川県', 'city' => '川崎市麻生区', 'street' => '上麻生 8-9-10',
                'address' => '神奈川県川崎市麻生区上麻生8-9-10',
                'job_description' => 'ブロック塀解体 → アルミフェンス + 植栽更新', 'contact_person' => '田中 一郎',
                'notes' => '[DRWP_SEED] 着工待ち', 'group' => $pg_ongoing,
            ],
            [
                'name' => '佐藤邸 駐車場拡張工事', 'customer_id' => $cust_sato,
                'prefecture' => '東京都', 'city' => '町田市', 'street' => '原町田 11-12-13',
                'address' => '東京都町田市原町田11-12-13',
                'job_description' => '既存駐車場 1 台分 → 2 台分に拡張・土間コン打設', 'contact_person' => '佐藤 健',
                'notes' => '[DRWP_SEED] 完了済み', 'group' => $pg_done,
            ],
            [
                'name' => '高橋邸 キッチンリフォーム', 'customer_id' => $cust_takahashi,
                'prefecture' => '神奈川県', 'city' => '横浜市中区', 'street' => '太田町 14-15-16',
                'address' => '神奈川県横浜市中区太田町14-15-16',
                'job_description' => 'システムキッチン入替・床/壁仕上げ・換気扇交換', 'contact_person' => '高橋 美香',
                'notes' => '[DRWP_SEED] 進行中', 'group' => $pg_ongoing,
            ],
        ];
        $pt = $wpdb->prefix . 'drwp_projects';
        $pgm = $wpdb->prefix . 'drwp_project_group_map';
        foreach ($projects as $p) {
            $group = $p['group'];
            unset($p['group']);
            $wpdb->insert($pt, $p + ['status' => 'active']);
            $pid = (int) $wpdb->insert_id;
            $state['project_ids'][] = $pid;
            $wpdb->insert($pgm, ['project_id' => $pid, 'group_id' => $group]);
        }
        list($proj_yamada, $proj_suzuki, $proj_tanaka, $proj_sato, $proj_takahashi) = $state['project_ids'];

        // 日報 — レビュー状態を散らしてフィルタ動作を確認しやすく。
        // 報告日は「今日」基準で過去 0〜21 日に分散させる。
        $today = (int) current_time('timestamp');
        $d = function ($offset_days) use ($today) {
            return wp_date('Y-m-d', $today - $offset_days * DAY_IN_SECONDS);
        };
        $reports = [
            // 山田邸 — 進行中・複数日報
            [
                'project_id' => $proj_yamada, 'report_date' => $d(0),
                'started_at' => '08:30:00', 'ended_at' => '17:00:00',
                'work_description' => "上棟翌日。屋根の野地板施工完了。\n外壁の透湿防水シート張りに着手。",
                'issues'           => "施主様より、コンセント位置の追加希望あり (寝室 2 箇所)。次回打合せで再確認。",
                'next_plan'        => '外壁シート完了、サッシ取付準備',
                'review_status'    => 'pending', 'public_title' => '山田邸 新築 — 屋根+外壁工程に進みました',
            ],
            [
                'project_id' => $proj_yamada, 'report_date' => $d(3),
                'started_at' => '09:00:00', 'ended_at' => '16:30:00',
                'work_description' => "上棟。クレーン手配 OK。組立て当日好天。",
                'issues'           => '',
                'next_plan'        => '屋根工事へ',
                'review_status'    => 'approved', 'public_title' => '山田邸 上棟しました',
            ],
            // 鈴木邸 — 基礎完了
            [
                'project_id' => $proj_suzuki, 'report_date' => $d(2),
                'started_at' => '08:00:00', 'ended_at' => '17:30:00',
                'work_description' => "ベタ基礎の打設完了。養生開始。",
                'issues'           => "二世帯間の建具仕様、まだ最終確定していない。来週までに要確認。",
                'next_plan'        => '型枠解体 → 土台敷きへ',
                'review_status'    => 'approved', 'public_title' => '鈴木邸 基礎工事完了',
            ],
            // 田中邸 — 外構着工待ち
            [
                'project_id' => $proj_tanaka, 'report_date' => $d(5),
                'started_at' => '10:00:00', 'ended_at' => '11:30:00',
                'work_description' => "現地調査。既存ブロック塀の高さ実測、撤去経路確認。",
                'issues'           => "隣家との境界が曖昧。施主様経由で確認依頼中。",
                'next_plan'        => '境界確認後、解体着手日確定',
                'review_status'    => 'needs_revision', 'public_title' => '',
            ],
            // 佐藤邸 — 完工
            [
                'project_id' => $proj_sato, 'report_date' => $d(14),
                'started_at' => '08:00:00', 'ended_at' => '18:00:00',
                'work_description' => "土間コン打設・刷毛引き仕上げ。\n駐車場ライン引き完了。引き渡し。",
                'issues'           => '',
                'next_plan'        => '1 ヶ月点検 (翌月)',
                'review_status'    => 'approved', 'public_title' => '佐藤邸 駐車場拡張工事 完工しました',
            ],
            // 高橋邸 — リフォーム
            [
                'project_id' => $proj_takahashi, 'report_date' => $d(1),
                'started_at' => '09:00:00', 'ended_at' => '16:00:00',
                'work_description' => "既存キッチン解体・搬出。給排水切り回し開始。",
                'issues'           => "床下配管の劣化が想定より進んでいた。追加見積もり要相談。",
                'next_plan'        => '配管入替後、新キッチン搬入',
                'review_status'    => 'pending', 'public_title' => '',
            ],
            [
                'project_id' => $proj_takahashi, 'report_date' => $d(8),
                'started_at' => '10:00:00', 'ended_at' => '12:00:00',
                'work_description' => "現調 + 採寸。仕様確定。",
                'issues'           => '',
                'next_plan'        => '解体日確定、機材手配へ',
                'review_status'    => 'approved', 'public_title' => '高橋邸 キッチンリフォーム 着工前現地調査',
            ],
            // 山田邸 — 古い日報 (一覧の表示件数テスト用に底上げ)
            [
                'project_id' => $proj_yamada, 'report_date' => $d(18),
                'started_at' => '08:00:00', 'ended_at' => '17:00:00',
                'work_description' => "土台敷き・床合板張り完了。",
                'issues'           => '',
                'next_plan'        => '建方準備',
                'review_status'    => 'approved', 'public_title' => '',
            ],
        ];
        $rt = $wpdb->prefix . 'drwp_reports';
        foreach ($reports as $r) {
            $wpdb->insert($rt, $r + [
                'user_id' => $user_id,
                'post_template' => 'standard',
                'post_status' => 'draft',
                'post_tags' => self::TAG,
            ]);
            $state['report_ids'][] = (int) $wpdb->insert_id;
        }

        // 予定 — 直近の訪問予定。
        $plans = [
            ['project_id' => $proj_yamada,     'planned_date' => $d(-1), 'started_at' => '08:30:00', 'ended_at' => '17:00:00', 'notes' => '[DRWP_SEED] サッシ取付'],
            ['project_id' => $proj_suzuki,    'planned_date' => $d(-2), 'started_at' => '08:00:00', 'ended_at' => '17:00:00', 'notes' => '[DRWP_SEED] 土台敷き'],
            ['project_id' => $proj_tanaka,    'planned_date' => $d(-4), 'started_at' => '09:00:00', 'ended_at' => '12:00:00', 'notes' => '[DRWP_SEED] 境界確認 + 解体予定打合せ'],
            ['project_id' => $proj_takahashi, 'planned_date' => $d(-3), 'started_at' => '09:00:00', 'ended_at' => '16:00:00', 'notes' => '[DRWP_SEED] 配管入替'],
        ];
        $plt = $wpdb->prefix . 'drwp_plans';
        foreach ($plans as $pl) {
            $wpdb->insert($plt, $pl + [
                'user_id'    => $user_id,
                'created_by' => $user_id,
                'status'     => 'active',
            ]);
            $state['plan_ids'][] = (int) $wpdb->insert_id;
        }

        update_option(self::OPT_STATE, $state);

        return [
            'customer_groups' => count($state['customer_group_ids']),
            'project_groups'  => count($state['project_group_ids']),
            'customers'       => count($state['customer_ids']),
            'projects'        => count($state['project_ids']),
            'reports'         => count($state['report_ids']),
            'plans'           => count($state['plan_ids']),
        ];
    }

    /** Delete every row that `run()` inserted. Safe to call repeatedly. */
    public static function reset() {
        global $wpdb;
        $state = self::state();
        if (!$state) return ['deleted' => 0];

        $deleted = 0;
        $batches = [
            'plan_ids'           => $wpdb->prefix . 'drwp_plans',
            'report_ids'         => $wpdb->prefix . 'drwp_reports',
            'project_ids'        => $wpdb->prefix . 'drwp_projects',
            'customer_ids'       => $wpdb->prefix . 'drwp_customers',
            'customer_group_ids' => $wpdb->prefix . 'drwp_customer_groups',
            'project_group_ids'  => $wpdb->prefix . 'drwp_project_groups',
        ];
        // 関連 map テーブルは "親の id IN (...)" で道連れに消す。
        $maps = [
            'customer_ids'       => [$wpdb->prefix . 'drwp_customer_group_map', 'customer_id'],
            'project_ids'        => [$wpdb->prefix . 'drwp_project_group_map', 'project_id'],
            'report_ids'         => [$wpdb->prefix . 'drwp_report_photos',     'report_id'],
        ];
        foreach ($batches as $key => $table) {
            if (empty($state[$key])) continue;
            $ids = array_map('intval', (array) $state[$key]);
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            // map 側を先に消す
            if (isset($maps[$key])) {
                list($map_table, $col) = $maps[$key];
                $wpdb->query($wpdb->prepare("DELETE FROM $map_table WHERE $col IN ($placeholders)", $ids));
            }
            $n = $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE id IN ($placeholders)", $ids));
            $deleted += (int) $n;
        }
        delete_option(self::OPT_STATE);
        return ['deleted' => $deleted];
    }

    /* ---- admin-post handlers ---- */

    public static function handle_run() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        check_admin_referer('drwp_seed_run');
        $summary = self::run();
        $url = add_query_arg([
            'page'    => self::SLUG,
            'seeded'  => 1,
            'reports' => (int) ($summary['reports'] ?? 0),
        ], admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    public static function handle_reset() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        check_admin_referer('drwp_seed_reset');
        $r = self::reset();
        $url = add_query_arg([
            'page'    => self::SLUG,
            'reset'   => 1,
            'deleted' => (int) ($r['deleted'] ?? 0),
        ], admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    /** Admin page UI — visible only to manage_options users. */
    public static function render_page() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        $has = self::has_seed();
        $state = self::state();
        ?>
        <div class="wrap">
          <h1><?php esc_html_e('テストデータ投入（開発用）', 'drwp-daily-reports'); ?></h1>

          <?php if (!empty($_GET['seeded'])): ?>
            <div class="notice notice-success is-dismissible">
              <p><?php
                /* translators: %d is the number of reports inserted */
                printf(
                  esc_html__('テストデータを投入しました。日報 %d 件を含む工務店受注のサンプルが入っています。', 'drwp-daily-reports'),
                  (int) ($_GET['reports'] ?? 0)
                );
              ?></p>
            </div>
          <?php endif; ?>
          <?php if (!empty($_GET['reset'])): ?>
            <div class="notice notice-success is-dismissible">
              <p><?php
                printf(
                  esc_html__('シード行を %d 件削除しました。', 'drwp-daily-reports'),
                  (int) ($_GET['deleted'] ?? 0)
                );
              ?></p>
            </div>
          <?php endif; ?>

          <div class="notice notice-warning inline" style="margin:14px 0;">
            <p><strong><?php esc_html_e('注意:', 'drwp-daily-reports'); ?></strong>
            <?php esc_html_e('このページは開発・動作確認用です。本番環境では使わないでください。投入される行はすべて [DRWP_SEED] タグで識別され、「シードを削除」で正確に巻き戻せます。', 'drwp-daily-reports'); ?>
            </p>
          </div>

          <div class="card" style="max-width:760px;">
            <h2><?php esc_html_e('投入される内容', 'drwp-daily-reports'); ?></h2>
            <ul style="list-style:disc;padding-left:22px;line-height:1.8;">
              <li><?php esc_html_e('顧客グループ 3 件 (一戸建て新築 / 外構工事のみ / リフォーム)', 'drwp-daily-reports'); ?></li>
              <li><?php esc_html_e('案件グループ 2 件 (進行中 / 完工済み)', 'drwp-daily-reports'); ?></li>
              <li><?php esc_html_e('顧客 5 件 (山田 / 鈴木 / 田中 / 佐藤 / 高橋) — 各グループに分類済み', 'drwp-daily-reports'); ?></li>
              <li><?php esc_html_e('案件 5 件 — 各顧客に 1 件ずつ、住所・現場内容入り', 'drwp-daily-reports'); ?></li>
              <li><?php esc_html_e('日報 8 件 — レビュー状態がバラけているのでフィルタ動作を確認可', 'drwp-daily-reports'); ?></li>
              <li><?php esc_html_e('予定 4 件 — 直近の訪問予定（未来日付）', 'drwp-daily-reports'); ?></li>
            </ul>
            <p class="description">
              <?php esc_html_e('日報・予定の報告者には現在ログイン中のユーザー (あなた) が使われます。', 'drwp-daily-reports'); ?>
            </p>
          </div>

          <div style="margin-top:16px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
              <?php wp_nonce_field('drwp_seed_run'); ?>
              <input type="hidden" name="action" value="drwp_seed_run" />
              <button type="submit" class="button button-primary"
                      onclick="return confirm('<?php echo esc_js(__('テストデータを投入します。既存のシードがある場合は一度削除されます。よろしいですか？', 'drwp-daily-reports')); ?>');">
                <?php echo $has
                  ? esc_html__('既存シードを上書きして再投入', 'drwp-daily-reports')
                  : esc_html__('テストデータを投入', 'drwp-daily-reports'); ?>
              </button>
            </form>
            <?php if ($has): ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
              <?php wp_nonce_field('drwp_seed_reset'); ?>
              <input type="hidden" name="action" value="drwp_seed_reset" />
              <button type="submit" class="button button-link-delete"
                      onclick="return confirm('<?php echo esc_js(__('投入済みのシードをすべて削除します。よろしいですか？', 'drwp-daily-reports')); ?>');">
                <?php esc_html_e('シードを削除', 'drwp-daily-reports'); ?>
              </button>
            </form>
            <?php endif; ?>
            <span class="description">
              <?php if ($has):
                printf(
                  esc_html__('現在シード済み: 顧客 %1$d / 案件 %2$d / 日報 %3$d / 予定 %4$d', 'drwp-daily-reports'),
                  (int) count($state['customer_ids'] ?? []),
                  (int) count($state['project_ids'] ?? []),
                  (int) count($state['report_ids'] ?? []),
                  (int) count($state['plan_ids'] ?? [])
                );
              else:
                esc_html_e('未投入', 'drwp-daily-reports');
              endif; ?>
            </span>
          </div>

          <h2 style="margin-top:30px;"><?php esc_html_e('WP-CLI', 'drwp-daily-reports'); ?></h2>
          <pre style="background:#1d2327;color:#e2e8f0;padding:12px;border-radius:6px;max-width:760px;">$ wp drwp seed         # 投入
$ wp drwp seed --reset # 削除</pre>
        </div>
        <?php
    }
}
