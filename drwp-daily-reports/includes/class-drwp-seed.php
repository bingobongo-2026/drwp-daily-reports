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

        // 同じ乱数列で同じデータが出るよう固定シードを切る。
        // テスト時に「あの案件のあの日報」を再現可能にしておく。
        mt_srand(20260617);

        $state = [
            'customer_group_ids' => [],
            'project_group_ids'  => [],
            'customer_ids'       => [],
            'project_ids'        => [],
            'report_ids'         => [],
            'plan_ids'           => [],
        ];

        // ---- 顧客グループ (工務店の受注タイプ) -------------------
        $customer_groups = [
            '一戸建て新築' => ['#2563eb', '土地から/建替えを含む新築一戸建ての施主'],
            '外構工事のみ' => ['#16a34a', '門・塀・駐車場・植栽など外構のみ受注'],
            'リフォーム'   => ['#f59e0b', 'キッチン/水回り/内装などの改修'],
        ];
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

        // ---- 案件グループ (現場の進捗ステータス) -----------------
        $project_groups = [
            '進行中'   => ['#3b82f6', '現在施工中の現場'],
            '完工済み' => ['#64748b', '引き渡し済み・アフター対応中'],
            '計画中'   => ['#a855f7', '見積/契約段階・着工待ち'],
        ];
        $pg_ids = [];
        $pg_t = $wpdb->prefix . 'drwp_project_groups';
        foreach ($project_groups as $name => $meta) {
            $wpdb->insert($pg_t, [
                'name' => $name, 'color' => $meta[0], 'notes' => $meta[1], 'status' => 'active',
            ]);
            $id = (int) $wpdb->insert_id;
            $pg_ids[$name] = $id;
            $state['project_group_ids'][] = $id;
        }

        // ---- 名前・住所プール (一覧画面のフィルタやソート確認用に
        //      バラけた値を出したいので、よくある姓名・住所を多めに用意)
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
                // メールはローマ字化が面倒なので無味乾燥なシリアルで割り当て。
                // 実在性は不要で、検索・編集動作の確認ができれば良い。
                'email' => 'customer' . ($i + 1) . '@example.test',
                'postal_code' => $loc[2], 'prefecture' => $loc[0], 'city' => $loc[1],
                'street' => $street, 'address' => $address,
                'notes' => '[DRWP_SEED] ' . $group_name . 'のお客様',
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

        // ---- 案件種別プール ----
        // 顧客グループごとに「ありそうな案件タイトル + 工事内容」を用意。
        // 1 顧客あたり 1〜3 件の案件を生成するので、種類は多めにしておく。
        $project_kinds = [
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
        ];

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
        $project_pg_names = ['進行中', '完工済み', '計画中'];
        foreach ($customers as $ci => $cust) {
            $kinds_for_group = $project_kinds[$cust['group']];
            for ($p = 0; $p < $alloc[$ci]; $p++) {
                $kind = $kinds_for_group[($ci + $p) % count($kinds_for_group)];
                $name = $cust['surname'] . '邸 ' . $kind[0];
                if ($p > 0) $name .= ' #' . ($p + 1);
                // 60% 進行中 / 30% 完工済み / 10% 計画中
                $r = mt_rand(0, 99);
                $pg_name = ($r < 60) ? '進行中' : (($r < 90) ? '完工済み' : '計画中');
                $wpdb->insert($pt, [
                    'name' => $name, 'customer_id' => $cust['id'],
                    'prefecture' => $cust['prefecture'], 'city' => $cust['city'],
                    'street' => $cust['street'], 'address' => $cust['address'],
                    'job_description' => $kind[1], 'contact_person' => $cust['name'],
                    'notes' => '[DRWP_SEED] ' . $pg_name,
                    'status' => 'active',
                ]);
                $pid = (int) $wpdb->insert_id;
                $state['project_ids'][] = $pid;
                $wpdb->insert($pgm_t, ['project_id' => $pid, 'group_id' => $pg_ids[$pg_name]]);
                $projects[] = [
                    'id' => $pid, 'name' => $name, 'group' => $pg_name,
                    'kind_title' => $kind[0], 'group_slug' => $cust['group'],
                ];
                if (count($state['project_ids']) >= $TARGET_PROJECTS) break 2;
            }
        }

        // ---- 工程テンプレート ----
        // 案件種別ごとの「典型的な工事の流れ」。日報の `report_date` は
        // この順序で過去にさかのぼって割り当てる (古い工程 = より昔)。
        $sequences = [
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
        ];

        // ---- 日報 250 件目安: プロジェクト毎に進捗に応じた件数 -----
        $TARGET_REPORTS_TOTAL = 250;
        $rt = $wpdb->prefix . 'drwp_reports';
        $today = (int) current_time('timestamp');
        $d = function ($offset_days) use ($today) {
            return wp_date('Y-m-d', $today - $offset_days * DAY_IN_SECONDS);
        };
        // 承認比率を高めに偏らせる (実運用で多いのは approved)。
        $status_pool = ['approved', 'approved', 'approved', 'approved',
                        'pending', 'pending', 'needs_revision'];

        $report_count = 0;
        foreach ($projects as $proj) {
            if ($report_count >= $TARGET_REPORTS_TOTAL) break;
            $seq = $sequences[$proj['group_slug']];
            if ($proj['group'] === '完工済み') {
                $n = mt_rand(6, count($seq));     // 完工 = 工程ほぼ全部
            } elseif ($proj['group'] === '進行中') {
                $n = mt_rand(3, 6);                 // 進行中 = 途中まで
            } else {
                $n = mt_rand(0, 1);                 // 計画中 = 現調のみ or なし
            }
            // 古い工程から順に並ぶように、起点となる過去日数を決める
            $base = ($proj['group'] === '完工済み') ? mt_rand(30, 70)
                  : (($proj['group'] === '進行中') ? mt_rand(8, 28) : mt_rand(0, 5));
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
                    // 承認済みのみ public_title を埋めて「記事化候補」に出やすく。
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

        // ---- 予定 30 件: 進行中の案件に未来日付で割り振る ---------
        $ongoing = [];
        foreach ($projects as $p) {
            if ($p['group'] === '進行中') $ongoing[] = $p;
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
                    'notes'        => '[DRWP_SEED] ' . $p['kind_title'] . ' 作業',
                    'user_id'      => $user_id,
                    'created_by'   => $user_id,
                    'status'       => 'active',
                ]);
                $state['plan_ids'][] = (int) $wpdb->insert_id;
            }
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
        if (!current_user_can('manage_options')) wp_die(esc_html__('権限がありません', 'drwp-daily-reports'));
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
              <li><?php esc_html_e('案件グループ 3 件 (進行中 / 完工済み / 計画中)', 'drwp-daily-reports'); ?></li>
              <li><?php esc_html_e('顧客 25 件 — 姓名・住所がバラけたサンプル、各顧客グループに均等に配分', 'drwp-daily-reports'); ?></li>
              <li><?php esc_html_e('案件 50 件 — 各顧客に 1〜3 件、進行中 60% / 完工済み 30% / 計画中 10% で分布', 'drwp-daily-reports'); ?></li>
              <li><?php esc_html_e('日報 約 250 件 — 案件の進捗に応じた工程順、レビュー状態 (承認 / レビュー待ち / 差戻し) が散らばる', 'drwp-daily-reports'); ?></li>
              <li><?php esc_html_e('予定 30 件 — 進行中案件の未来 1〜14 日に分散', 'drwp-daily-reports'); ?></li>
            </ul>
            <p class="description">
              <?php esc_html_e('日報・予定の報告者には現在ログイン中のユーザー (あなた) が使われます。乱数シードは固定されているので、再投入しても同じ並びになります。', 'drwp-daily-reports'); ?>
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
