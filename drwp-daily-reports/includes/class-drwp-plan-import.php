<?php
if (!defined('ABSPATH')) exit;

/**
 * 予定のCSV取り込み — サイボウズ Office のスケジュール書き出しが主な対象。
 *
 * サイボウズ Office は製品バージョンや書き出し設定で列名・文字コードが
 * 変わるため、列名を決め打ちにせず「どのCSV列をどの項目に入れるか」を
 * 画面で対応付けさせる。よくある列名は guess_mapping() が初期値として
 * 推測する。
 *
 * 取り込みは2段階:
 *   1. CSVをアップロード → 解析してトランジェントに保持
 *   2. 列の対応付け・担当者の対応付けを確認 → 実行
 *
 * 同じCSVを取り込み直しても増殖しないよう、各行から安定した
 * external_id (ハッシュ) を作り、external_source と合わせた一意キーで
 * 既存行を更新する (upsert)。
 */
class DRWP_Plan_Import {

    /** external_source に入れる値。将来 Garoon 等を足す余地を残す。 */
    const SOURCE = 'cybozu_office';

    /** アップロードしたCSVを段階間で持ち回るトランジェント。 */
    const TRANSIENT_PREFIX = 'drwp_plan_import_';
    const TRANSIENT_TTL    = HOUR_IN_SECONDS;

    /** 1回で取り込める最大行数 (メモリとトランジェント肥大の保険)。 */
    const MAX_ROWS = 2000;

    /** 1予定が何日まで連続できるか (終了日が壊れていた場合の暴走よけ)。 */
    const MAX_SPAN_DAYS = 62;

    /** CSV1行から作る予定の上限 (参加者 × 日数)。 */
    const MAX_PLANS_PER_ROW = 200;

    public static function init() {
        add_action('admin_post_drwp_plan_import_upload', [__CLASS__, 'handle_upload']);
        add_action('admin_post_drwp_plan_import_run',    [__CLASS__, 'handle_run']);
    }

    /** 取り込みは「他人の予定も触れる」運用者だけに許す。 */
    public static function can_import() {
        return current_user_can(DRWP_Plan::CAP_REVIEW) && !DRWP_User::is_retired();
    }

    /* =====================================================================
     * CSV 解析 (副作用なし・テスト対象)
     * ===================================================================== */

    /**
     * CSVの生バイト列をUTF-8に寄せる。
     *
     * サイボウズ Office の書き出しは既定がシフトJISで、UTF-8を選ぶと
     * BOM が付くこともある。どちらで来ても読めるようにする。
     *
     * @param string $raw ファイルの中身
     * @return string UTF-8 文字列
     */
    public static function to_utf8($raw) {
        $raw = (string) $raw;
        // UTF-8 BOM を除去
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            return substr($raw, 3);
        }
        if (!function_exists('mb_convert_encoding')) {
            return $raw;
        }
        $enc = function_exists('mb_detect_encoding')
            ? mb_detect_encoding($raw, ['UTF-8', 'SJIS-win', 'CP932', 'SJIS', 'EUC-JP', 'ISO-2022-JP'], true)
            : false;
        if ($enc === false) {
            // 判定できないときはシフトJIS前提で変換を試す (Office の既定)。
            $enc = 'SJIS-win';
        }
        if (strtoupper($enc) === 'UTF-8') {
            return $raw;
        }
        $out = @mb_convert_encoding($raw, 'UTF-8', $enc);
        return $out === false ? $raw : $out;
    }

    /**
     * CSV文字列を [headers, rows] に分解する。
     *
     * @param string $raw CSV (エンコーディング変換前でよい)
     * @return array{headers: string[], rows: array<int, string[]>, truncated: bool}
     */
    public static function parse_csv($raw) {
        $text = self::to_utf8($raw);
        // 改行コードを LF に統一 (CRLF / CR どちらでも)
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $text);
        rewind($fh);

        $headers   = [];
        $rows      = [];
        $truncated = false;
        while (($cols = fgetcsv($fh)) !== false) {
            // 空行 (全項目が空) は読み飛ばす
            if ($cols === [null] || $cols === false) continue;
            $joined = trim(implode('', array_map('strval', $cols)));
            if ($joined === '') continue;

            $cols = array_map(static function ($v) {
                return trim((string) $v);
            }, $cols);

            if ($headers === []) {
                $headers = $cols;
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                $truncated = true;
                break;
            }
            $rows[] = $cols;
        }
        fclose($fh);

        return ['headers' => $headers, 'rows' => $rows, 'truncated' => $truncated];
    }

    /**
     * 取り込み先の項目定義。キーは mapping 配列のキーでもある。
     *
     * @return array<string, array{label: string, required: bool, hints: string[]}>
     */
    public static function fields() {
        return [
            'planned_date' => [
                'label'    => __('開始日', 'drwp-daily-reports'),
                'required' => true,
                'hints'    => ['開始日付', '開始日', '予定日', '日付', '開始年月日', 'date', 'start date'],
            ],
            'end_date' => [
                'label'    => __('終了日', 'drwp-daily-reports'),
                'required' => false,
                'hints'    => ['終了日付', '終了日', '終了年月日', 'end date'],
            ],
            'started_at' => [
                'label'    => __('開始時刻', 'drwp-daily-reports'),
                'required' => false,
                'hints'    => ['開始時刻', '開始時間', '開始', 'start time'],
            ],
            'ended_at' => [
                'label'    => __('終了時刻', 'drwp-daily-reports'),
                'required' => false,
                'hints'    => ['終了時刻', '終了時間', '終了', 'end time'],
            ],
            'category' => [
                'label'    => __('区分 (予定メニュー)', 'drwp-daily-reports'),
                'required' => false,
                'hints'    => ['予定メニュー', '予定', 'メニュー', '種別', '区分'],
            ],
            'title' => [
                'label'    => __('件名 (予定詳細)', 'drwp-daily-reports'),
                'required' => false,
                'hints'    => ['予定詳細', '件名', 'タイトル', '内容', 'title'],
            ],
            'notes' => [
                'label'    => __('メモ・備考', 'drwp-daily-reports'),
                'required' => false,
                'hints'    => ['メモ', '備考', '詳細', 'ノート', 'memo', 'note'],
            ],
            'assignee' => [
                'label'    => __('担当者 (参加者)', 'drwp-daily-reports'),
                'required' => false,
                'hints'    => ['参加者', '担当者', 'ユーザー', '社員', 'user', 'member'],
            ],
            'external_id' => [
                'label'    => __('サイボウズ側のID', 'drwp-daily-reports'),
                'required' => false,
                'hints'    => ['予定ID', 'ID', 'イベントID', 'event id'],
            ],
        ];
    }

    /**
     * CSVのヘッダーから、各項目に対応しそうな列番号を推測する。
     *
     * 完全一致 → 部分一致 の順に見て、先に当たった列を採用する。
     * すでに他の項目が使った列は再利用しない (同じ列が二重に割り当たると
     * 取り込み結果が分かりにくくなるため)。
     *
     * @param string[] $headers
     * @return array<string, int> 項目キー => 列番号 (見つからない項目は含めない)
     */
    public static function guess_mapping(array $headers) {
        $norm = [];
        foreach ($headers as $i => $h) {
            $norm[$i] = self::normalize_header($h);
        }

        $mapping = [];
        $used    = [];

        foreach (self::fields() as $key => $def) {
            $hit = null;
            // 1. 完全一致
            foreach ($def['hints'] as $hint) {
                $h = self::normalize_header($hint);
                foreach ($norm as $i => $label) {
                    if (isset($used[$i])) continue;
                    if ($label !== '' && $label === $h) { $hit = $i; break 2; }
                }
            }
            // 2. 部分一致
            if ($hit === null) {
                foreach ($def['hints'] as $hint) {
                    $h = self::normalize_header($hint);
                    if ($h === '') continue;
                    foreach ($norm as $i => $label) {
                        if (isset($used[$i])) continue;
                        if ($label !== '' && mb_strpos($label, $h) !== false) { $hit = $i; break 2; }
                    }
                }
            }
            if ($hit !== null) {
                $mapping[$key] = $hit;
                $used[$hit]    = true;
            }
        }
        return $mapping;
    }

    /** ヘッダー比較用の正規化 (全半角・空白・記号ゆれを吸収)。 */
    private static function normalize_header($s) {
        $s = trim((string) $s);
        if ($s === '') return '';
        if (function_exists('mb_convert_kana')) {
            // 全角英数→半角、全角スペース→半角
            $s = mb_convert_kana($s, 'as');
        }
        $s = str_replace(['　', ' ', '_', '-', '(', ')', '（', '）', '[', ']'], '', $s);
        return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
    }

    /* =====================================================================
     * 値の正規化 (副作用なし・テスト対象)
     * ===================================================================== */

    /**
     * 日付を YYYY-MM-DD に正規化する。
     *
     * サイボウズは "2026/7/1"、書き出し設定によっては "2026-07-01" や
     * "2026年7月1日" も出る。日時が1列にまとまっている場合 ("2026/7/1 9:00")
     * にも対応する。
     *
     * @return string|null 変換できなければ null
     */
    public static function normalize_date($v) {
        $v = trim((string) $v);
        if ($v === '') return null;
        if (function_exists('mb_convert_kana')) $v = mb_convert_kana($v, 'as');

        // 先頭の日付部分だけを見る (後ろに時刻や曜日が付いていてもよい)
        if (preg_match('/(\d{4})\s*[\/\-年\.]\s*(\d{1,2})\s*[\/\-月\.]\s*(\d{1,2})/u', $v, $m)) {
            $y = (int) $m[1]; $mo = (int) $m[2]; $d = (int) $m[3];
            if ($mo >= 1 && $mo <= 12 && $d >= 1 && $d <= 31 && checkdate($mo, $d, $y)) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
            return null;
        }
        // 区切りなしの 20260701
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $v, $m)) {
            $y = (int) $m[1]; $mo = (int) $m[2]; $d = (int) $m[3];
            if (checkdate($mo, $d, $y)) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        }
        return null;
    }

    /**
     * 時刻を HH:MM:SS に正規化する。
     *
     * "9:00" / "09:00" / "9:00:00" / "2026/7/1 9:00" (日時混在) に対応。
     * 終日予定などで時刻が無い場合は null。
     *
     * @return string|null
     */
    public static function normalize_time($v) {
        $v = trim((string) $v);
        if ($v === '') return null;
        if (function_exists('mb_convert_kana')) $v = mb_convert_kana($v, 'as');

        // 日付が先頭にある場合は落として時刻部分だけ見る
        $v = preg_replace('/^\d{4}\s*[\/\-年\.]\s*\d{1,2}\s*[\/\-月\.]\s*\d{1,2}\s*(日)?\s*/u', '', $v);
        if (!preg_match('/(\d{1,2})\s*[:時]\s*(\d{1,2})(?:\s*[:分]\s*(\d{1,2}))?/u', $v, $m)) {
            return null;
        }
        $h = (int) $m[1]; $i = (int) $m[2]; $s = isset($m[3]) ? (int) $m[3] : 0;
        // 24:00 は当日の終わりとして 23:59 に丸める (DATE型の TIME に入れるため)
        if ($h === 24 && $i === 0) return '23:59:00';
        if ($h > 23 || $i > 59 || $s > 59) return null;
        return sprintf('%02d:%02d:%02d', $h, $i, $s);
    }

    /**
     * 1行分のCSVを、予定テーブルに入れられる形へ展開する。
     *
     * サイボウズの1予定は「参加者 × 日数」に展開される。予定テーブルは
     * 1行1担当者なので、参加者が3人なら3件の予定を作らないと2人分の
     * 予定が消えてしまう。複数日にまたがる予定も日ごとに分ける。
     *
     * 担当者の解決やDBアクセスはここではやらない (呼び出し側が担当する)。
     *
     * @param string[]          $cols    CSVの1行
     * @param array<string,int> $mapping 項目キー => 列番号
     * @return array{plans: array, people: string[], dates: string[], notes: string, error: string|null}
     */
    public static function build_row(array $cols, array $mapping) {
        $get = static function ($key) use ($cols, $mapping) {
            if (!isset($mapping[$key])) return '';
            $i = (int) $mapping[$key];
            return isset($cols[$i]) ? trim((string) $cols[$i]) : '';
        };
        $fail = static function ($msg) {
            return ['plans' => [], 'people' => [], 'dates' => [], 'notes' => '', 'error' => $msg];
        };

        $date = self::normalize_date($get('planned_date'));
        if ($date === null) {
            return $fail(__('開始日を読み取れませんでした。', 'drwp-daily-reports'));
        }

        // 終了日。未指定・開始日より前なら単日扱い。
        $end_date = self::normalize_date($get('end_date'));
        if ($end_date === null || $end_date < $date) {
            $end_date = $date;
        }
        $dates = self::date_range($date, $end_date);
        if ($dates === null) {
            return $fail(sprintf(
                /* translators: %d: 上限日数 */
                __('期間が長すぎます (上限%d日)。', 'drwp-daily-reports'),
                self::MAX_SPAN_DAYS
            ));
        }

        $started = self::normalize_time($get('started_at'));
        $ended   = self::normalize_time($get('ended_at'));
        // 単日で開始 > 終了 は入れ替わりとみなして交換する (書き出し設定のゆれ対策)。
        // 複数日の場合は「初日の開始 / 最終日の終了」なので入れ替えない。
        if (count($dates) === 1 && $started !== null && $ended !== null && $started > $ended) {
            $tmp = $started; $started = $ended; $ended = $tmp;
        }

        $notes  = self::compose_notes($get('category'), $get('title'), $get('notes'));
        $people = self::split_people($get('assignee'));
        $raw_id = $get('external_id');

        // 参加者が1人も書かれていない予定も取り込む (担当者なしの予定として)
        $slots = $people ?: [''];

        if (count($slots) * count($dates) > self::MAX_PLANS_PER_ROW) {
            return $fail(sprintf(
                /* translators: %d: 上限件数 */
                __('参加者×日数が多すぎます (上限%d件)。', 'drwp-daily-reports'),
                self::MAX_PLANS_PER_ROW
            ));
        }

        $plans = [];
        $last  = count($dates) - 1;
        foreach ($slots as $person) {
            foreach ($dates as $i => $d) {
                // 複数日: 初日は開始時刻のみ、最終日は終了時刻のみ、間は終日。
                if ($last === 0) {
                    $s = $started; $e = $ended;
                } elseif ($i === 0) {
                    $s = $started; $e = null;
                } elseif ($i === $last) {
                    $s = null;     $e = $ended;
                } else {
                    $s = null;     $e = null;
                }
                $plans[] = [
                    'planned_date' => $d,
                    'started_at'   => $s,
                    'ended_at'     => $e,
                    'notes'        => $notes,
                    'person'       => $person,
                    'external_id'  => self::external_id($raw_id, $d, $s, $notes, $person),
                ];
            }
        }

        return ['plans' => $plans, 'people' => $people, 'dates' => $dates, 'notes' => $notes, 'error' => null];
    }

    /**
     * 区分・件名・メモを予定の「メモ」欄1つにまとめる。
     *
     * 予定テーブルに区分や件名の列は無いので、
     *   【区分】件名
     *   メモ本文
     * の形にして一覧で読みやすくする。
     */
    public static function compose_notes($category, $title, $memo) {
        $category = trim((string) $category);
        $title    = trim((string) $title);
        $memo     = trim((string) $memo);

        $head = ($category !== '' ? '【' . $category . '】' : '') . $title;
        $head = trim($head);

        $parts = array_filter([$head, $memo], static function ($v) { return $v !== ''; });
        return implode("\n", $parts);
    }

    /**
     * 開始日〜終了日の日付を配列で返す。上限を超えたら null。
     *
     * @return string[]|null
     */
    public static function date_range($start, $end) {
        if ($end <= $start) return [$start];
        $out = [];
        $cur = $start;
        while ($cur <= $end) {
            $out[] = $cur;
            if (count($out) > self::MAX_SPAN_DAYS) return null;
            $ts = strtotime($cur . ' +1 day');
            if ($ts === false) return null;
            $cur = gmdate('Y-m-d', $ts);
        }
        return $out;
    }

    /**
     * 参加者欄を1人ずつに分解する。
     *
     * サイボウズ Office は参加者を**改行区切り**で書き出す。カンマ・読点・
     * セミコロン・スラッシュ区切りの書き出し設定もありうるので併せて扱う。
     *
     * @return string[] 重複を除いた氏名の配列 (空欄なら空配列)
     */
    public static function split_people($s) {
        $s = trim((string) $s);
        if ($s === '') return [];
        $parts = preg_split('/[\r\n,、;；\/]+/u', $s);
        $out   = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') continue;
            // 同じ人が二重に書かれていても1件にする
            if (!in_array($p, $out, true)) $out[] = $p;
        }
        return $out;
    }

    /**
     * 再取り込みしても同じ予定が同じIDになるよう、安定したキーを作る。
     *
     * 1予定を「参加者 × 日」に展開するため、IDにも担当者と日付を混ぜないと
     * 展開した行同士が同じIDになって1件しか登録されない。
     *
     * サイボウズ側のIDがある場合は本文を鍵に含めない。こうすると
     * サイボウズ側で内容を書き換えても同じ行として更新できる。
     * IDが無い場合だけ、行を見分けるために本文を鍵に含める。
     */
    public static function external_id($raw_id, $date, $started, $notes, $person) {
        $raw_id = trim((string) $raw_id);
        if ($raw_id !== '') {
            return sha1(implode("\x1f", [$raw_id, (string) $date, (string) $person]));
        }
        return sha1(implode("\x1f", [
            (string) $date,
            (string) $started,
            (string) $person,
            (string) $notes,
        ]));
    }

    /* =====================================================================
     * 担当者の対応付け
     * ===================================================================== */

    /**
     * サイボウズの参加者名から WordPress ユーザーを引くための索引を作る。
     *
     * 表示名 / 社員名 (drwp_worker_name) / ログイン名 / メールアドレスの
     * どれでも当たるようにする。
     *
     * @return array<string, int> 正規化した名前 => ユーザーID
     */
    public static function user_index() {
        $index = [];
        $users = get_users([
            'capability__in' => [DRWP_Plan::CAP_LIST],
            'fields'         => ['ID', 'display_name', 'user_login', 'user_email'],
        ]);
        foreach ($users as $u) {
            $id   = (int) $u->ID;
            $keys = [$u->display_name, $u->user_login, $u->user_email];
            $wn   = get_user_meta($id, DRWP_User::META_WORKER_NAME, true);
            if ($wn) $keys[] = $wn;
            foreach ($keys as $k) {
                $k = self::normalize_person($k);
                // 先に登録されたユーザーを優先 (同名は後勝ちにしない)
                if ($k !== '' && !isset($index[$k])) $index[$k] = $id;
            }
        }
        return $index;
    }

    /**
     * 氏名を1件ぶん照合用に正規化する。
     *
     * サイボウズ側は「山田 太郎」「山田太郎」など空白のゆれがあるので
     * 空白を落として比較する。複数人が入っている場合は split_people() で
     * 先に分解しておくこと (ここでは先頭の1人だけを見る)。
     */
    public static function normalize_person($s) {
        $people = self::split_people($s);
        $s = $people ? $people[0] : '';
        if ($s === '') return '';
        if (function_exists('mb_convert_kana')) $s = mb_convert_kana($s, 'as');
        $s = str_replace(['　', ' '], '', $s);
        return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
    }

    /* =====================================================================
     * 画面
     * ===================================================================== */

    public static function render_page() {
        if (!self::can_import()) {
            wp_die(esc_html__('権限がありません', 'drwp-daily-reports'));
        }

        $token   = isset($_GET['token']) ? sanitize_key(wp_unslash($_GET['token'])) : '';
        $payload = $token ? self::load($token) : null;

        $headers    = $payload['headers'] ?? [];
        $rows       = $payload['rows'] ?? [];
        $truncated  = !empty($payload['truncated']);
        $mapping    = $headers ? self::guess_mapping($headers) : [];
        $fields     = self::fields();
        $workers    = DRWP_Plan::worker_options();
        $projects   = DRWP_Project::all_for_filter();
        $preview    = [];   // 展開後の予定 (先頭20件)
        $unmatched  = [];   // 対応するユーザーが見つからない参加者名
        $total      = 0;    // 展開後の予定の総件数
        $bad_rows   = 0;    // 取り込めない行数

        if ($rows) {
            $index = self::user_index();
            $seen  = [];
            foreach ($rows as $cols) {
                $built = self::build_row($cols, $mapping);
                if ($built['error'] !== null) {
                    $bad_rows++;
                    if (count($preview) < 20) {
                        $preview[] = ['error' => $built['error']];
                    }
                    continue;
                }
                $total += count($built['plans']);

                // 未対応の名前は全行から集める (プレビュー20件の外も拾う)
                foreach ($built['people'] as $name) {
                    $key = self::normalize_person($name);
                    if ($key === '' || isset($index[$key]) || isset($seen[$key])) continue;
                    $seen[$key]  = true;
                    $unmatched[] = $name;
                }

                foreach ($built['plans'] as $plan) {
                    if (count($preview) >= 20) continue;
                    $key = self::normalize_person($plan['person']);
                    $preview[] = [
                        'plan'    => $plan,
                        'user_id' => $key !== '' ? ($index[$key] ?? 0) : 0,
                        'error'   => null,
                    ];
                }
            }
        }

        include DRWP_PATH . 'admin/views/plan-import-page.php';
    }

    /* =====================================================================
     * 取り込み処理
     * ===================================================================== */

    /** 手順1: CSVを受け取って解析し、トランジェントに保持する。 */
    public static function handle_upload() {
        if (!self::can_import()) wp_die(esc_html__('権限がありません', 'drwp-daily-reports'));
        check_admin_referer('drwp_plan_import_upload');
        if (!DRWP_License::can_write()) {
            wp_die(
                DRWP_License::blocked_message(__('ライセンス状態により予定を取り込めません。', 'drwp-daily-reports')),
                esc_html__('ライセンス未有効', 'drwp-daily-reports'),
                ['response' => 402]
            );
        }

        if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
            self::redirect(['error' => 'nofile']);
        }
        $raw = file_get_contents($_FILES['csv']['tmp_name']);
        if ($raw === false || trim($raw) === '') {
            self::redirect(['error' => 'empty']);
        }

        $parsed = self::parse_csv($raw);
        if (!$parsed['headers'] || !$parsed['rows']) {
            self::redirect(['error' => 'parse']);
        }

        $token = wp_generate_password(16, false, false);
        set_transient(self::TRANSIENT_PREFIX . $token, [
            'headers'   => $parsed['headers'],
            'rows'      => $parsed['rows'],
            'truncated' => $parsed['truncated'],
            'user'      => get_current_user_id(),
        ], self::TRANSIENT_TTL);

        self::redirect(['token' => $token]);
    }

    /** 手順2: 対応付けを受け取って実際に取り込む。 */
    public static function handle_run() {
        if (!self::can_import()) wp_die(esc_html__('権限がありません', 'drwp-daily-reports'));
        check_admin_referer('drwp_plan_import_run');
        if (!DRWP_License::can_write()) {
            wp_die(
                DRWP_License::blocked_message(__('ライセンス状態により予定を取り込めません。', 'drwp-daily-reports')),
                esc_html__('ライセンス未有効', 'drwp-daily-reports'),
                ['response' => 402]
            );
        }

        $token   = isset($_POST['token']) ? sanitize_key(wp_unslash($_POST['token'])) : '';
        $payload = $token ? self::load($token) : null;
        if (!$payload) self::redirect(['error' => 'expired']);

        // 列の対応付け: 未選択 ('') の項目は取り込まない
        $mapping = [];
        $posted  = isset($_POST['map']) && is_array($_POST['map']) ? wp_unslash($_POST['map']) : [];
        foreach (array_keys(self::fields()) as $key) {
            $v = isset($posted[$key]) ? trim((string) $posted[$key]) : '';
            if ($v === '') continue;
            $mapping[$key] = (int) $v;
        }
        if (!isset($mapping['planned_date'])) {
            self::redirect(['token' => $token, 'error' => 'nodate']);
        }

        // 担当者の手動対応付け (未一致の名前 => ユーザーID)
        $manual = [];
        $posted_users = isset($_POST['user_map']) && is_array($_POST['user_map']) ? wp_unslash($_POST['user_map']) : [];
        foreach ($posted_users as $name => $uid) {
            $uid = absint($uid);
            if ($uid) $manual[self::normalize_person($name)] = $uid;
        }

        $project_id  = absint($_POST['project_id'] ?? 0) ?: null;
        $default_uid = absint($_POST['default_user_id'] ?? 0) ?: null;
        $index       = array_merge(self::user_index(), $manual);

        $created = 0; $updated = 0; $skipped = 0; $errors = [];

        global $wpdb;
        $table = DRWP_Plan::table();
        foreach ($payload['rows'] as $n => $cols) {
            $built = self::build_row($cols, $mapping);
            if ($built['error'] !== null) {
                $skipped++;
                if (count($errors) < 20) {
                    /* translators: 1: CSVの行番号 2: エラー内容 */
                    $errors[] = sprintf(__('%1$d行目: %2$s', 'drwp-daily-reports'), $n + 2, $built['error']);
                }
                continue;
            }

            // 1予定は「参加者 × 日」に展開済み。1件ずつ登録する。
            foreach ($built['plans'] as $plan) {
                $uid = $default_uid;
                $key = self::normalize_person($plan['person']);
                if ($key !== '' && !empty($index[$key])) $uid = (int) $index[$key];

                $data = [
                    'project_id'      => $project_id,
                    'user_id'         => $uid,
                    'planned_date'    => $plan['planned_date'],
                    'started_at'      => $plan['started_at'],
                    'ended_at'        => $plan['ended_at'],
                    'notes'           => wp_kses_post($plan['notes']),
                    'status'          => 'active',
                    'external_source' => self::SOURCE,
                    'external_id'     => $plan['external_id'],
                ];

                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $table WHERE external_source = %s AND external_id = %s",
                    self::SOURCE,
                    $data['external_id']
                ));
                if ($existing) {
                    // 取り込み済みの行は更新する。担当者・現場は画面で
                    // 後から直している可能性があるため、CSV側で決められない
                    // ものは上書きしない。
                    $update = $data;
                    unset($update['external_source'], $update['external_id']);
                    if ($uid === null)        unset($update['user_id']);
                    if ($project_id === null) unset($update['project_id']);
                    $wpdb->update($table, $update, ['id' => (int) $existing]);
                    $updated++;
                } else {
                    $data['created_by'] = get_current_user_id();
                    $wpdb->insert($table, $data);
                    $created++;
                }
            }
        }

        delete_transient(self::TRANSIENT_PREFIX . $token);

        DRWP_Audit::log('plan_imported', sprintf(
            /* translators: 1: 新規件数 2: 更新件数 3: スキップ件数 */
            __('サイボウズCSVから予定を取り込み (新規%1$d件 / 更新%2$d件 / 取り込めず%3$d件)', 'drwp-daily-reports'),
            $created, $updated, $skipped
        ), 0, ['source' => self::SOURCE, 'created' => $created, 'updated' => $updated, 'skipped' => $skipped]);

        if ($errors) {
            set_transient(self::TRANSIENT_PREFIX . 'err_' . get_current_user_id(), $errors, MINUTE_IN_SECONDS * 5);
        }
        self::redirect(['done' => 1, 'created' => $created, 'updated' => $updated, 'skipped' => $skipped]);
    }

    /** トランジェントから読み出す (他人のトークンは読ませない)。 */
    private static function load($token) {
        $payload = get_transient(self::TRANSIENT_PREFIX . $token);
        if (!is_array($payload) || empty($payload['headers'])) return null;
        if ((int) ($payload['user'] ?? 0) !== get_current_user_id()) return null;
        return $payload;
    }

    private static function redirect(array $args) {
        $url = add_query_arg(
            array_merge(['page' => 'drwp_plan_import'], $args),
            admin_url('admin.php')
        );
        wp_safe_redirect($url);
        exit;
    }
}
