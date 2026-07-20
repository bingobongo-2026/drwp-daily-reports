<?php
if (!defined('ABSPATH')) exit;

/**
 * テストデータ投入ユーティリティ。CLI (`wp drwp seed`) と管理画面の
 * ボタンの両方から呼び出せる。挙動を確かめるためのサンプルデータ
 * (顧客・案件・グループ・日報・予定) をまとめて投入し、後から正確に
 * 消せるよう投入した行 ID をすべて `drwp_seed_state` オプションに記録する。
 *
 * 業種プリセットを切り替えられる (工務店 / 美容院 / 設備工事会社)。
 * データの骨格 (顧客25・案件50・日報約250・予定30、進捗ステータス3種) は
 * 共通で、顧客グループ名・案件種別・工程(施術)テンプレートだけが業種ごとに
 * 変わる。
 *
 * 本番運用には載せない想定の「仮」機能なので、管理画面ボタンは
 * `manage_options` を持つ管理者にだけ見せる。
 */
class DRWP_Seed {
    const OPT_STATE        = 'drwp_seed_state';
    const OPT_MENU_VISIBLE = 'drwp_show_seed_menu';
    const SLUG = 'drwp_seed';
    const TAG = 'drwp-seed';

    public static function init() {
        add_action('admin_post_drwp_seed_run',   [__CLASS__, 'handle_run']);
        add_action('admin_post_drwp_seed_reset', [__CLASS__, 'handle_reset']);
    }

    public static function is_menu_visible() {
        $v = get_option(self::OPT_MENU_VISIBLE, '1');
        return $v === '1' || $v === 1 || $v === true;
    }

    public static function set_menu_visible($visible) {
        update_option(self::OPT_MENU_VISIBLE, !empty($visible) ? '1' : '0');
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

    /** 選べる業種プリセット (slug => 表示名)。 */
    public static function industry_options() {
        return [
            'koumuten' => __('工務店', 'drwp-daily-reports'),
            'salon'    => __('美容院', 'drwp-daily-reports'),
            'setsubi'  => __('設備工事会社', 'drwp-daily-reports'),
        ];
    }

    /** 指定業種のプリセットを返す。未知の業種は工務店にフォールバック。 */
    public static function preset($industry) {
        $all = self::presets();
        return $all[$industry] ?? $all['koumuten'];
    }

    /**
     * Run the seeder. Returns a summary array of inserted row counts.
     * `$user_id` is the author for reports / plans / "created_by".
     * `$industry` は industry_options() のキー。
     */
    public static function run($user_id = 0, $industry = 'koumuten') {
        global $wpdb;

        $user_id = (int) $user_id ?: (int) get_current_user_id();
        if (!$user_id) {
            $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
            $user_id = $admins ? (int) $admins[0] : 1;
        }

        $industry = array_key_exists($industry, self::industry_options()) ? $industry : 'koumuten';
        $preset   = self::preset($industry);

        // 既にシード済みなら、まず確実にクリアしてから入れ直す。
        if (self::has_seed()) {
            self::reset();
        }

        // 同じ乱数列で同じデータが出るよう固定シードを切る。
        mt_srand(20260617);

        $state = [
            'industry'           => $industry,
            'customer_group_ids' => [],
            'project_group_ids'  => [],
            'customer_ids'       => [],
            'project_ids'        => [],
            'report_ids'         => [],
            'plan_ids'           => [],
        ];

        // ---- 顧客グループ (業種ごとの受注/来店タイプ) ------------
        $customer_groups = $preset['customer_groups'];
        $cg_ids = [];
        $cg_t = $wpdb->prefix . 'drwp_customer_groups';
        foreach ($customer_groups as $name => $meta) {
            $wpdb->insert($cg_t, [
                'name' => $name, 'color' => $meta[0], 'notes' => $meta[1], 'status' => 'active',
            ]);
            $id = (int) $wpdb->insert_id;
            $cg_ids[$name] = $id;
            $state['customer_group_ids'][] = $id;
        }

        // ---- 案件グループ (進捗ステータス) — キー ongoing/done/planned
        //      は件数割り当てロジックが参照する固定キー。表示名だけ業種で変える。
        $progress = $preset['progress'];
        $pg_ids = [];   // key => group_id
        $pg_name = [];  // key => 表示名
        $pg_t = $wpdb->prefix . 'drwp_project_groups';
        foreach (['ongoing', 'done', 'planned'] as $pkey) {
            $meta = $progress[$pkey];
            $wpdb->insert($pg_t, [
                'name' => $meta[0], 'color' => $meta[1], 'notes' => $meta[2], 'status' => 'active',
            ]);
            $id = (int) $wpdb->insert_id;
            $pg_ids[$pkey]  = $id;
            $pg_name[$pkey] = $meta[0];
            $state['project_group_ids'][] = $id;
        }

        // ---- 名前・住所プール (共通) ----
        $surnames = ['山田', '鈴木', '田中', '佐藤', '高橋', '渡辺', '伊藤', '山本',
                     '中村', '小林', '加藤', '吉田', '山口', '松本', '井上', '木村',
                     '林', '斎藤', '清水', '山崎', '森', '池田', '橋本', '阿部',
                     '石川', '山下', '中島', '石井', '小川', '前田'];
        $first_names = ['太郎', '花子', '一郎', '健', '美香', '健太', '美咲', '翔太',
                        'さくら', '大輔', '由美', '直樹', '美穂', '拓也', '麻衣',
                        '雄太', '真由美', '智子', '浩二', '香織'];
        $localities = [
            ['神奈川県', '相模原市中央区', '252-0231'],
            ['東京都',   '港区',           '108-0014'],
            ['神奈川県', '川崎市麻生区',   '215-0021'],
            ['東京都',   '町田市',         '194-0013'],
            ['神奈川県', '横浜市中区',     '231-0011'],
            ['神奈川県', '横浜市青葉区',   '227-0062'],
            ['神奈川県', '藤沢市',         '251-0052'],
            ['東京都',   '世田谷区',       '154-0024'],
            ['東京都',   '調布市',         '182-0026'],
            ['埼玉県',   'さいたま市浦和区', '330-0061'],
        ];
        $streets = ['中央', '本町', '東町', '西町', '南町', '北町',
                    '駅前', '緑町', '桜ヶ丘', '宮前町'];

        // ---- 顧客 25 件を顧客グループに均等配分 ------------------
        $TARGET_CUSTOMERS = 25;
        $customers = [];
        $group_names = array_keys($customer_groups);
        $ct = $wpdb->prefix . 'drwp_customers';
        $cgm_t = $wpdb->prefix . 'drwp_customer_group_map';
        for ($i = 0; $i < $TARGET_CUSTOMERS; $i++) {
            $sur = $surnames[$i % count($surnames)];
            $fn  = $first_names[mt_rand(0, count($first_names) - 1)];
            $loc = $localities[$i % count($localities)];
            $street = $streets[mt_rand(0, count($streets) - 1)]
                    . ' ' . mt_rand(1, 9) . '-' . mt_rand(1, 30) . '-' . mt_rand(1, 30);
            $address = $loc[0] . $loc[1] . $street;
            $phone_prefix = mt_rand(0, 1) ? '090' : '080';
            $phone = sprintf('%s-%04d-%04d', $phone_prefix, mt_rand(1000, 9999), mt_rand(1000, 9999));
            $group_name = $group_names[$i % count($group_names)];
            $wpdb->insert($ct, [
                'name' => "$sur $fn", 'phone' => $phone,
                'email' => 'customer' . ($i + 1) . '@example.test',
                'postal_code' => $loc[2], 'prefecture' => $loc[0], 'city' => $loc[1],
                'street' => $street, 'address' => $address,
                'notes' => '[DRWP_SEED] ' . sprintf($preset['customer_note'], $group_name),
                'status' => 'active',
            ]);
            $cid = (int) $wpdb->insert_id;
            $state['customer_ids'][] = $cid;
            $wpdb->insert($cgm_t, ['customer_id' => $cid, 'group_id' => $cg_ids[$group_name]]);
            $customers[] = [
                'id' => $cid, 'name' => "$sur $fn", 'surname' => $sur,
                'group' => $group_name,
                'prefecture' => $loc[0], 'city' => $loc[1],
                'street' => $street, 'address' => $address,
            ];
        }

        // ---- 案件種別プール (業種ごと) ----
        $project_kinds = $preset['project_kinds'];
        $name_fn = $preset['project_name'];

        // ---- 案件 50 件: 顧客に最低 1 件、残りをランダム分散 ---
        $TARGET_PROJECTS = 50;
        $alloc = array_fill(0, count($customers), 1);
        $remaining = $TARGET_PROJECTS - count($customers);
        $guard = 0;
        while ($remaining > 0 && $guard++ < 1000) {
            $idx = mt_rand(0, count($customers) - 1);
            if ($alloc[$idx] < 3) { $alloc[$idx]++; $remaining--; }
        }

        $projects = [];
        $pt = $wpdb->prefix . 'drwp_projects';
        $pgm_t = $wpdb->prefix . 'drwp_project_group_map';
        foreach ($customers as $ci => $cust) {
            $kinds_for_group = $project_kinds[$cust['group']];
            for ($p = 0; $p < $alloc[$ci]; $p++) {
                $kind = $kinds_for_group[($ci + $p) % count($kinds_for_group)];
                $name = call_user_func($name_fn, $cust['surname'], $cust['name'], $kind[0]);
                if ($p > 0) $name .= ' #' . ($p + 1);
                // 60% 進行中 / 30% 完了 / 10% 計画 (キーで扱う)
                $r = mt_rand(0, 99);
                $pkey = ($r < 60) ? 'ongoing' : (($r < 90) ? 'done' : 'planned');
                $wpdb->insert($pt, [
                    'name' => $name, 'customer_id' => $cust['id'],
                    'prefecture' => $cust['prefecture'], 'city' => $cust['city'],
                    'street' => $cust['street'], 'address' => $cust['address'],
                    'job_description' => $kind[1], 'contact_person' => $cust['name'],
                    'notes' => '[DRWP_SEED] ' . $pg_name[$pkey],
                    'status' => 'active',
                ]);
                $pid = (int) $wpdb->insert_id;
                $state['project_ids'][] = $pid;
                $wpdb->insert($pgm_t, ['project_id' => $pid, 'group_id' => $pg_ids[$pkey]]);
                $projects[] = [
                    'id' => $pid, 'name' => $name, 'pkey' => $pkey,
                    'kind_title' => $kind[0], 'group_slug' => $cust['group'],
                ];
                if (count($state['project_ids']) >= $TARGET_PROJECTS) break 2;
            }
        }

        // ---- 工程/施術テンプレート (業種ごと) ----
        $sequences = $preset['sequences'];

        // ---- 日報 250 件目安: プロジェクト毎に進捗に応じた件数 -----
        $TARGET_REPORTS_TOTAL = 250;
        $rt = $wpdb->prefix . 'drwp_reports';
        $today = (int) current_time('timestamp');
        $d = function ($offset_days) use ($today) {
            return wp_date('Y-m-d', $today - $offset_days * DAY_IN_SECONDS);
        };
        $status_pool = ['approved', 'approved', 'approved', 'approved',
                        'pending', 'pending', 'needs_revision'];

        $report_count = 0;
        foreach ($projects as $proj) {
            if ($report_count >= $TARGET_REPORTS_TOTAL) break;
            $seq = $sequences[$proj['group_slug']];
            $len = count($seq);
            // 進捗キーで件数を決める。シーケンス長が業種で違うので範囲を丸める。
            if ($proj['pkey'] === 'done') {
                $n = mt_rand(max(1, $len - 2), $len);   // ほぼ全工程
            } elseif ($proj['pkey'] === 'ongoing') {
                $n = mt_rand(2, max(2, min(6, $len)));  // 途中まで
            } else {
                $n = mt_rand(0, 1);                     // 計画/予約 = ほぼ無し
            }
            $n = min($n, $len);
            // 古い工程から順に並ぶよう、起点となる過去日数を決める
            $base = ($proj['pkey'] === 'done') ? mt_rand(30, 70)
                  : (($proj['pkey'] === 'ongoing') ? mt_rand(8, 28) : mt_rand(0, 5));
            for ($i = 0; $i < $n; $i++) {
                if ($report_count >= $TARGET_REPORTS_TOTAL) break;
                $step = $seq[$i];
                $offset = max(0, $base - $i * mt_rand(2, 4));
                $status = $status_pool[mt_rand(0, count($status_pool) - 1)];
                $wpdb->insert($rt, [
                    'project_id'       => $proj['id'],
                    'report_date'      => $d($offset),
                    'started_at'       => $step[1] . ':00',
                    'ended_at'         => $step[2] . ':00',
                    'work_description' => $step[3],
                    'issues'           => $step[4],
                    'next_plan'        => $step[5],
                    'review_status'    => $status,
                    'public_title'     => ($status === 'approved')
                        ? ($proj['name'] . ' — ' . $step[0]) : '',
                    'user_id'          => $user_id,
                    'post_template'    => 'standard',
                    'post_status'      => 'draft',
                    'post_tags'        => self::TAG,
                ]);
                $state['report_ids'][] = (int) $wpdb->insert_id;
                $report_count++;
            }
        }

        // ---- 予定 30 件: 進行中(ongoing)の案件に未来日付で割り振る ----
        $ongoing = [];
        foreach ($projects as $p) {
            if ($p['pkey'] === 'ongoing') $ongoing[] = $p;
        }
        $plt = $wpdb->prefix . 'drwp_plans';
        if ($ongoing) {
            $TARGET_PLANS = 30;
            for ($i = 0; $i < $TARGET_PLANS; $i++) {
                $p = $ongoing[$i % count($ongoing)];
                $offset = -mt_rand(1, 14);    // 未来 1〜14 日
                $start_h = mt_rand(8, 10);
                $end_h = min(18, $start_h + mt_rand(4, 8));
                $wpdb->insert($plt, [
                    'project_id'   => $p['id'],
                    'planned_date' => $d($offset),
                    'started_at'   => sprintf('%02d:00:00', $start_h),
                    'ended_at'     => sprintf('%02d:00:00', $end_h),
                    'notes'        => '[DRWP_SEED] ' . $p['kind_title'] . $preset['plan_suffix'],
                    'user_id'      => $user_id,
                    'created_by'   => $user_id,
                    'status'       => 'active',
                ]);
                $state['plan_ids'][] = (int) $wpdb->insert_id;
            }
        }

        update_option(self::OPT_STATE, $state);

        return [
            'industry'        => $industry,
            'industry_label'  => $preset['label'],
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
        $maps = [
            'customer_ids'       => [$wpdb->prefix . 'drwp_customer_group_map', 'customer_id'],
            'project_ids'        => [$wpdb->prefix . 'drwp_project_group_map', 'project_id'],
            'report_ids'         => [$wpdb->prefix . 'drwp_report_photos',     'report_id'],
        ];
        foreach ($batches as $key => $table) {
            if (empty($state[$key])) continue;
            $ids = array_map('intval', (array) $state[$key]);
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
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

    /**
     * 業種別プリセット定義。progress のキー ongoing/done/planned は
     * run() の件数割り当てが参照する固定キー。
     */
    private static function presets() {
        return [
            // ===== 工務店 =====
            'koumuten' => [
                'label' => __('工務店', 'drwp-daily-reports'),
                'customer_groups' => [
                    '一戸建て新築' => ['#2563eb', '土地から/建替えを含む新築一戸建ての施主'],
                    '外構工事のみ' => ['#16a34a', '門・塀・駐車場・植栽など外構のみ受注'],
                    'リフォーム'   => ['#f59e0b', 'キッチン/水回り/内装などの改修'],
                ],
                'progress' => [
                    'ongoing' => ['進行中',   '#3b82f6', '現在施工中の現場'],
                    'done'    => ['完工済み', '#64748b', '引き渡し済み・アフター対応中'],
                    'planned' => ['計画中',   '#a855f7', '見積/契約段階・着工待ち'],
                ],
                'customer_note' => '%sのお客様',
                'project_name'  => function ($surname, $name, $kind) { return $surname . '邸 ' . $kind; },
                'plan_suffix'   => ' 作業',
                'project_kinds' => [
                    '一戸建て新築' => [
                        ['新築工事',       '木造 2 階建て・延床 28 坪・ガレージ付'],
                        ['平屋新築工事',   '木造平屋・延床 22 坪・夫婦 2 人世帯向け'],
                        ['二世帯住宅 新築', '木造 3 階建て・延床 42 坪・玄関共有 2 世帯'],
                        ['建替え工事',     '既存解体 + 新築 2 階建て・延床 32 坪'],
                        ['ガレージハウス 新築', '木造 2 階建て・1 階ビルトインガレージ 2 台分'],
                    ],
                    '外構工事のみ' => [
                        ['外構リニューアル',     'ブロック塀解体 → アルミフェンス + 植栽更新'],
                        ['駐車場拡張工事',       '既存駐車場 1 台 → 2 台分に拡張・土間コン打設'],
                        ['カーポート設置',       'カーポート 2 台分新設・既存舗装補修'],
                        ['ウッドデッキ施工',     'リビング掃き出しに 3m × 2m のウッドデッキ'],
                        ['玄関アプローチ改修',   'タイル張替え + 階段手摺新設'],
                        ['ブロック塀新設',       'CP マーク認定の組積造ブロック塀 (高さ 1.2 m)'],
                    ],
                    'リフォーム' => [
                        ['キッチンリフォーム',     'システムキッチン入替・床/壁仕上げ・換気扇交換'],
                        ['バスルーム改修',         'ユニットバス入替・脱衣所内装・給湯機交換'],
                        ['洗面所リフォーム',       '洗面化粧台入替・床貼替え・収納増設'],
                        ['トイレ交換工事',         'タンクレストイレ 1 階 / 2 階 同時交換'],
                        ['内装全面リフォーム',     'クロス全室張替え・床フローリング・建具交換'],
                        ['屋根葺き替え工事',       '既存スレート撤去 → ガルバリウム葺き替え'],
                    ],
                ],
                'sequences' => [
                    '一戸建て新築' => [
                        ['現地調査',     '08:30', '11:00', '現地調査・敷地境界確認・地盤目視。',                 '地盤調査の手配要', '見積書提出'],
                        ['地縄張り',     '09:00', '12:00', '地縄張り・遣り方。施主立会いで配置最終確認。',        '',                 '基礎工事へ'],
                        ['基礎工事',     '08:00', '17:30', 'ベタ基礎の鉄筋組み・型枠・コンクリ打設。',           '養生期間 7 日',     '型枠解体'],
                        ['土台敷き',     '08:00', '17:00', '土台敷き・床合板張り完了。',                          '',                 '建方準備'],
                        ['上棟',         '08:30', '16:30', '上棟。クレーン手配・木材組立て当日好天。',           '',                 '屋根工事へ'],
                        ['屋根工事',     '08:00', '17:00', '野地板施工・防水紙・屋根葺き工事。',                  '',                 '外壁工事準備'],
                        ['外壁工事',     '08:30', '17:00', '透湿防水シート・サイディング張り工程。',              '',                 '内装下地'],
                        ['内装工事',     '09:00', '17:30', '間仕切壁・天井下地・断熱施工。',                      '電気設備との取合い確認', 'ボード張り'],
                        ['仕上げ',       '09:00', '17:00', 'クロス張替え・床仕上げ・設備設置。',                  '',                 '完了検査'],
                        ['完了検査',     '10:00', '14:00', '社内検査・施主立会い・是正項目確認。',                '是正なし',         '引渡し'],
                    ],
                    '外構工事のみ' => [
                        ['現地調査',         '10:00', '11:30', '現地調査。既存ブロック塀の高さ実測、撤去経路確認。', '隣家との境界が曖昧。施主様経由で確認依頼中。', '境界確認後、解体着手日確定'],
                        ['境界立会い',       '09:00', '11:00', '隣家施主立会いで境界線最終確認。',                  '',                 '解体段取りへ'],
                        ['ブロック塀解体',   '08:30', '17:00', 'ブロック塀の解体・産廃搬出・整地。',                 '騒音対策で養生シート設置', 'フェンス基礎'],
                        ['フェンス基礎',     '08:30', '16:00', 'アルミフェンス基礎ベース打設。',                    '',                 'フェンス建込'],
                        ['フェンス建込',     '09:00', '16:30', 'アルミフェンス建込・天端確認。',                    '',                 '駐車場整備'],
                        ['駐車場土間打設',   '08:00', '17:30', '土間コン打設・刷毛引き仕上げ・ライン引き。',         '養生 3 日',        '引渡し'],
                        ['完工確認',         '10:00', '12:00', '施主立会いで完工確認・取扱説明。',                  '',                 '1ヶ月点検 (翌月)'],
                    ],
                    'リフォーム' => [
                        ['現地調査',     '10:00', '12:00', '現調 + 採寸。仕様確定。',                              '',                 '解体日確定、機材手配へ'],
                        ['解体・撤去',   '09:00', '16:00', '既存キッチン解体・搬出。給排水切り回し開始。',         '床下配管の劣化が想定より進んでいた。追加見積もり要相談。', '配管入替後、新キッチン搬入'],
                        ['給排水切替',   '09:00', '15:00', '給排水管入替・床下点検口設置。',                       '',                 '床下地補修'],
                        ['内装下地',     '09:00', '17:00', '間仕切調整・下地補修・断熱補強。',                     '',                 '仕上げへ'],
                        ['設備設置',     '09:00', '17:30', '新キッチン搬入・据付・配管接続・通水確認。',            '',                 '仕上げ'],
                        ['仕上げ',       '09:00', '17:00', 'クロス張替え・床貼替え・建具調整。',                   '',                 '引渡し'],
                        ['引渡し',       '10:00', '12:00', '取扱説明・引渡し書類確認。',                          '',                 '1ヶ月点検 (翌月)'],
                    ],
                ],
            ],

            // ===== 美容院 =====
            'salon' => [
                'label' => __('美容院', 'drwp-daily-reports'),
                'customer_groups' => [
                    'カット中心'       => ['#2563eb', 'カット・トリートメント中心の常連客'],
                    'カラー・パーマ'   => ['#db2777', 'カラー/パーマ/縮毛矯正などの施術客'],
                    'ブライダル・特別' => ['#f59e0b', '成人式/ブライダル/七五三など特別施術'],
                ],
                'progress' => [
                    'ongoing' => ['来店中',   '#3b82f6', '継続して来店中のお客様'],
                    'done'    => ['完了',     '#64748b', '一連の施術が完了・フォロー中'],
                    'planned' => ['予約段階', '#a855f7', '次回予約待ち・カウンセリングのみ'],
                ],
                'customer_note' => '%sの来店客',
                'project_name'  => function ($surname, $name, $kind) { return $name . '様 ' . $kind; },
                'plan_suffix'   => ' 予約',
                'project_kinds' => [
                    'カット中心' => [
                        ['カット',                 'カット + シャンプー・ブロー'],
                        ['カット + トリートメント', 'カット + 集中トリートメント'],
                        ['メンズカット',           'メンズカット + フェード仕上げ'],
                        ['前髪・メンテカット',     '前髪 + 表面のメンテナンスカット'],
                        ['キッズカット',           'お子様カット'],
                    ],
                    'カラー・パーマ' => [
                        ['フルカラー',       '根元〜毛先のフルカラー'],
                        ['リタッチカラー',   '根元リタッチ + トーン調整'],
                        ['ハイライト',       'ホイルワーク + トーンオンカラー'],
                        ['デジタルパーマ',   'ロッド巻き + 加温・スタイリング'],
                        ['縮毛矯正',         'ストレートアイロン仕上げ'],
                    ],
                    'ブライダル・特別' => [
                        ['成人式 着付け・ヘアセット', '振袖着付け + ヘアメイク'],
                        ['ブライダルヘアメイク',     '挙式 + お色直しヘアメイク'],
                        ['七五三 ヘアセット',        '着物ヘアセット + 簡単メイク'],
                        ['卒業式 袴着付け',          '袴着付け + ヘアセット'],
                    ],
                ],
                'sequences' => [
                    'カット中心' => [
                        ['初回来店',           '10:00', '11:15', '初回カウンセリング + カット。骨格に合わせて提案。', '',                 '2ヶ月後メンテ提案'],
                        ['2回目来店',          '10:30', '11:30', 'メンテカット。前回スタイルの再現 + 微調整。',       '',                 'トリートメント提案'],
                        ['トリートメント来店', '13:00', '14:15', 'カット + 集中トリートメント。',                    '',                 '次回予約'],
                        ['カラー相談来店',     '10:00', '11:00', 'カット + カラーの相談・パッチテスト案内。',         '',                 'カラー予約'],
                        ['メンテ来店',         '18:00', '19:00', '仕事帰りのメンテカット。',                          '',                 '次回 3 ヶ月後'],
                    ],
                    'カラー・パーマ' => [
                        ['カウンセリング', '10:00', '10:30', '要望ヒアリング・履歴確認・パッチテスト。',   '',                       '施術日予約'],
                        ['カラー施術',     '10:30', '12:30', 'フルカラー施術 + トリートメント。',           '',                       'リタッチは 6 週後目安'],
                        ['リタッチ来店',   '11:00', '12:30', '根元リタッチ + 毛先トーン調整。',             '',                       '次回リタッチ'],
                        ['パーマ施術',     '13:00', '15:30', 'デジタルパーマ。ロッド巻き + 加温・仕上げ。', '',                       'スタイリング指導'],
                        ['縮毛矯正',       '10:00', '13:00', '縮毛矯正 + アイロン仕上げ。',                 'くせ強め。次回は間隔短めを提案。', '3〜4 ヶ月後'],
                    ],
                    'ブライダル・特別' => [
                        ['カウンセリング', '14:00', '15:00', '衣装確認・ヘアメイクイメージのすり合わせ。', '', 'リハーサル予約'],
                        ['リハーサル',     '10:00', '12:00', 'ヘアメイクリハーサル・写真確認。',           '', '本番当日の打合せ'],
                        ['本番施術',       '07:00', '09:00', '本番当日 着付け + ヘアメイク。',             '', 'お色直し対応'],
                        ['お色直し対応',   '12:00', '13:00', '披露宴お色直しのヘアチェンジ。',             '', 'アフターフォロー'],
                    ],
                ],
            ],

            // ===== 設備工事会社 =====
            'setsubi' => [
                'label' => __('設備工事会社', 'drwp-daily-reports'),
                'customer_groups' => [
                    '新築設備'   => ['#2563eb', '新築現場の給排水・電気・空調など一式'],
                    '改修・更新' => ['#16a34a', '既存設備の更新・入替工事'],
                    '保守・点検' => ['#f59e0b', '定期点検・緊急対応・メンテ契約'],
                ],
                'progress' => [
                    'ongoing' => ['進行中',   '#3b82f6', '現在施工中の現場'],
                    'done'    => ['完工済み', '#64748b', '引き渡し済み・保守対応中'],
                    'planned' => ['計画中',   '#a855f7', '見積/契約段階・着工待ち'],
                ],
                'customer_note' => '%sのお客様',
                'project_name'  => function ($surname, $name, $kind) { return $surname . '邸 ' . $kind; },
                'plan_suffix'   => ' 作業',
                'project_kinds' => [
                    '新築設備' => [
                        ['給排水衛生設備工事', '1F/2F 給排水・衛生器具一式'],
                        ['電気設備工事',       '分電盤・幹線・照明・コンセント配線'],
                        ['空調換気設備工事',   'エアコン + 24 時間換気システム'],
                        ['ガス設備工事',       '都市ガス配管 + 給湯器設置'],
                        ['太陽光・蓄電設備',   'パネル設置 + パワコン + 蓄電池'],
                    ],
                    '改修・更新' => [
                        ['給湯器更新工事',   'エコキュート/エコジョーズ入替'],
                        ['分電盤更新工事',   '旧分電盤 → 漏電ブレーカ付へ更新'],
                        ['エアコン入替工事', '家庭用/業務用エアコン更新'],
                        ['給水管更新工事',   '老朽給水管 → 樹脂管へ更新'],
                        ['トイレ設備更新',   '便器 + 温水洗浄便座の入替'],
                    ],
                    '保守・点検' => [
                        ['定期点検',         '設備一式の年次点検'],
                        ['緊急対応（漏水）', '漏水箇所特定 + 応急処置'],
                        ['ボイラー整備',     '業務用ボイラーの分解整備'],
                        ['受水槽清掃',       '受水槽/高架水槽の清掃・点検'],
                    ],
                ],
                'sequences' => [
                    '新築設備' => [
                        ['現地調査',           '09:00', '11:00', '現地調査・図面照合・墨出し確認。',         '',                   'スリーブ位置確定'],
                        ['スリーブ・先行配管', '08:30', '17:00', 'スラブ配管・スリーブ入れ・先行配管。',     '躯体工事との取合い調整', '立上げ配管'],
                        ['立上げ配管',         '08:30', '17:00', '給排水立上げ配管・保温施工。',             '',                   '器具付け'],
                        ['電気配線',           '08:30', '17:00', '幹線・分岐配線・ボックス取付。',           '',                   '器具付け'],
                        ['器具付け',           '09:00', '17:00', '衛生器具・照明器具・盤の取付・接続。',     '',                   '試験・調整'],
                        ['試験・調整',         '09:00', '15:00', '通水・通電・満水試験、動作確認。',         '',                   '完了検査'],
                        ['完了検査',           '10:00', '13:00', '社内検査・是正確認・記録写真。',           '是正なし',           '引渡し'],
                    ],
                    '改修・更新' => [
                        ['現地調査',   '10:00', '11:30', '現調・既存設備確認・搬入経路確認。', '', '施工日確定'],
                        ['既存撤去',   '09:00', '12:00', '既存機器の撤去・養生・搬出。',       '', '新設据付'],
                        ['新設・据付', '13:00', '17:00', '新機器据付・配管/配線接続。',       '', '試運転'],
                        ['試運転・調整', '09:00', '11:00', '試運転・動作確認・取扱説明。',     '', '引渡し'],
                        ['引渡し',     '11:00', '12:00', '完了報告・保証書渡し。',             '', '定期点検の案内'],
                    ],
                    '保守・点検' => [
                        ['点検実施',       '10:00', '12:00', '設備一式の点検・数値記録。',           '',                       '整備見積り'],
                        ['整備・部品交換', '13:00', '16:00', '消耗部品交換・清掃・調整。',           '',                       '報告書作成'],
                        ['緊急対応',       '20:00', '22:00', '漏水通報対応。止水 + 応急処置。',       '夜間対応。恒久対策は後日見積り。', '本復旧工事の提案'],
                        ['報告',           '16:00', '16:30', '点検報告書の作成・所見説明。',         '',                       '次回点検日調整'],
                    ],
                ],
            ],
        ];
    }

    /* ---- admin-post handlers ---- */

    public static function handle_run() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('権限がありません', 'drwp-daily-reports'));
        check_admin_referer('drwp_seed_run');
        $industry = isset($_POST['industry']) ? sanitize_key(wp_unslash($_POST['industry'])) : 'koumuten';
        if (!array_key_exists($industry, self::industry_options())) $industry = 'koumuten';
        $summary = self::run(0, $industry);
        $url = add_query_arg([
            'page'     => self::SLUG,
            'seeded'   => 1,
            'reports'  => (int) ($summary['reports'] ?? 0),
            'industry' => $industry,
        ], admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    public static function handle_reset() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('権限がありません', 'drwp-daily-reports'));
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
        if (!current_user_can('manage_options')) wp_die(esc_html__('権限がありません', 'drwp-daily-reports'));
        $has = self::has_seed();
        $state = self::state();
        $industries = self::industry_options();
        $current_industry = isset($state['industry']) && isset($industries[$state['industry']])
            ? $state['industry'] : 'koumuten';
        ?>
        <div class="wrap">
          <h1><?php esc_html_e('テストデータ投入（開発用）', 'drwp-daily-reports'); ?></h1>

          <?php if (!empty($_GET['seeded'])):
            $seeded_key = isset($_GET['industry']) ? sanitize_key(wp_unslash($_GET['industry'])) : 'koumuten';
            $seeded_label = $industries[$seeded_key] ?? $industries['koumuten'];
            ?>
            <div class="notice notice-success is-dismissible">
              <p><?php
                /* translators: 1: 業種名, 2: 日報件数 */
                printf(
                  esc_html__('テストデータを投入しました。「%1$s」のサンプル (日報 %2$d 件を含む) が入っています。', 'drwp-daily-reports'),
                  esc_html($seeded_label),
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
            <p><?php esc_html_e('選んだ業種に応じたサンプルが入ります（工務店 / 美容院 / 設備工事会社）。データ量の骨格は共通です。', 'drwp-daily-reports'); ?></p>
            <ul style="list-style:disc;padding-left:22px;line-height:1.8;">
              <li><?php esc_html_e('顧客グループ 3 件（業種別の受注/来店タイプ）', 'drwp-daily-reports'); ?></li>
              <li><?php esc_html_e('案件グループ 3 件（進捗ステータス）', 'drwp-daily-reports'); ?></li>
              <li><?php esc_html_e('顧客 25 件 — 姓名・住所がバラけたサンプル、各顧客グループに均等に配分', 'drwp-daily-reports'); ?></li>
              <li><?php esc_html_e('案件 50 件 — 各顧客に 1〜3 件、進行中 60% / 完了 30% / 計画 10% で分布', 'drwp-daily-reports'); ?></li>
              <li><?php esc_html_e('日報 約 250 件 — 進捗に応じた工程/施術順、レビュー状態（承認 / 承認待ち / 差戻し）が散らばる', 'drwp-daily-reports'); ?></li>
              <li><?php esc_html_e('予定 30 件 — 進行中案件の未来 1〜14 日に分散', 'drwp-daily-reports'); ?></li>
            </ul>
            <p class="description">
              <?php esc_html_e('日報・予定の報告者には現在ログイン中のユーザー (あなた) が使われます。乱数シードは固定されているので、同じ業種を再投入すれば同じ並びになります。', 'drwp-daily-reports'); ?>
            </p>
          </div>

          <div style="margin-top:16px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
              <?php wp_nonce_field('drwp_seed_run'); ?>
              <input type="hidden" name="action" value="drwp_seed_run" />
              <label for="drwp-seed-industry" style="font-weight:600;"><?php esc_html_e('業種:', 'drwp-daily-reports'); ?></label>
              <select id="drwp-seed-industry" name="industry">
                <?php foreach ($industries as $slug => $label): ?>
                  <option value="<?php echo esc_attr($slug); ?>" <?php selected($current_industry, $slug); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
              </select>
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
                  esc_html__('現在シード済み: %1$s / 顧客 %2$d / 案件 %3$d / 日報 %4$d / 予定 %5$d', 'drwp-daily-reports'),
                  esc_html($industries[$current_industry] ?? ''),
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
          <pre style="background:#1d2327;color:#e2e8f0;padding:12px;border-radius:6px;max-width:760px;">$ wp drwp seed                     # 工務店を投入
$ wp drwp seed --industry=salon    # 美容院を投入
$ wp drwp seed --industry=setsubi  # 設備工事会社を投入
$ wp drwp seed --reset             # 削除</pre>
        </div>
        <?php
    }
}
