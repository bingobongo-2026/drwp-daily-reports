<?php
if (!defined('ABSPATH')) exit;

/**
 * AI feature entry point — provider-agnostic. The high-level
 * application code (briefings, future features) stays here; wire
 * format details live in the per-provider backends.
 *
 * Phase 1 surface: "次回訪問ブリーフィング" — given a project, pull
 * the latest N daily reports and ask the configured LLM for 持参物 /
 * 注意点 / 提案ポイント.
 */
class DRWP_AI {
    const OPT_PROVIDER = 'drwp_ai_provider';
    const OPT_URL      = 'drwp_ai_url';
    const OPT_MODEL    = 'drwp_ai_model';
    const OPT_API_KEY  = 'drwp_ai_api_key';
    const OPT_ENABLED  = 'drwp_ai_enabled';

    const BRIEFING_LIMIT = 10;

    /** Provider defaults — used when the user hasn't filled the field. */
    public static function defaults($provider) {
        switch ($provider) {
            case 'anthropic':
                return ['url' => 'https://api.anthropic.com', 'model' => 'claude-haiku-4-5-20251001'];
            case 'openai':
            default:
                return ['url' => 'https://api.openai.com', 'model' => 'gpt-4o-mini'];
        }
    }

    public static function provider() {
        $p = (string) get_option(self::OPT_PROVIDER, 'openai');
        return in_array($p, ['openai', 'anthropic'], true) ? $p : 'openai';
    }

    public static function url() {
        $v = (string) get_option(self::OPT_URL, '');
        return $v !== '' ? $v : self::defaults(self::provider())['url'];
    }

    public static function model() {
        $v = (string) get_option(self::OPT_MODEL, '');
        return $v !== '' ? $v : self::defaults(self::provider())['model'];
    }

    public static function api_key() {
        return (string) get_option(self::OPT_API_KEY, '');
    }

    public static function is_enabled() {
        return get_option(self::OPT_ENABLED) === 'yes';
    }

    /** Factory: build the configured backend implementation. */
    public static function backend() {
        $cfg = [
            'url'     => self::url(),
            'model'   => self::model(),
            'api_key' => self::api_key(),
        ];
        switch (self::provider()) {
            case 'anthropic': $backend = new DRWP_AI_Backend_Anthropic($cfg); break;
            case 'openai':
            default:          $backend = new DRWP_AI_Backend_OpenAI($cfg); break;
        }
        // Lets tests / advanced integrations substitute a backend that
        // implements DRWP_AI_Backend without hitting the network.
        return apply_filters('drwp_ai_backend', $backend, self::provider(), $cfg);
    }

    public static function test_connection() {
        return self::backend()->test_connection();
    }

    public static function briefing_for_project($project_id, $limit = self::BRIEFING_LIMIT) {
        if (!self::is_enabled()) {
            return new WP_Error('drwp_ai_disabled', 'AI機能が無効です');
        }
        $project = DRWP_Project::find($project_id);
        if (!$project) return new WP_Error('drwp_ai_no_project', '案件が見つかりません');

        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';
        $reports = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE project_id = %d ORDER BY report_date DESC, id DESC LIMIT %d",
            $project_id, $limit
        ));

        if (empty($reports)) {
            return new WP_Error('drwp_ai_no_reports', 'この案件の日報がまだありません');
        }

        $messages = self::build_briefing_messages($project, $reports);
        return self::backend()->chat($messages);
    }

    private static function build_briefing_messages($project, $reports) {
        $meta = ['案件名: ' . $project->name];
        if (!empty($project->client_name))     $meta[] = '顧客: ' . $project->client_name;
        if (!empty($project->job_description)) $meta[] = '仕事内容: ' . $project->job_description;
        if (!empty($project->notes))           $meta[] = '備考: ' . $project->notes;
        $meta_str = implode("\n", $meta);

        $reports_str = '';
        foreach ($reports as $i => $r) {
            $reports_str .= "\n--- 日報 #" . ($i + 1) . ' (' . $r->report_date . ") ---\n";
            if (!empty($r->work_description)) $reports_str .= '作業内容: ' . $r->work_description . "\n";
            if (!empty($r->issues))           $reports_str .= '特記事項: ' . $r->issues . "\n";
            if (!empty($r->next_plan))        $reports_str .= '次回予定: ' . $r->next_plan . "\n";
        }
        $count = count($reports);

        $system = 'あなたは建設・サービス業の案件担当者を支援するアシスタントです。'
            . '日報の内容から、次回訪問時に役立つ「持参物」「継続案件・注意点」「お客様への提案ポイント」を箇条書きで提案します。'
            . '日報に書かれていない事項は推測せず、根拠が読み取れる項目のみ挙げてください。'
            . '出力は Markdown 箇条書きで、## 次回持参すべきもの / ## 注意点・継続案件 / ## お客様への提案ポイント の3セクションに分けてください。';

        $user = "【案件情報】\n{$meta_str}\n\n【過去の日報（新しい順、最大{$count}件）】\n{$reports_str}";

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ];
    }

    /* ------------------------------------------------------------
     * 機能2: 公開記事の下書き生成 — 1 件の日報から 公開タイトル /
     * 導入文 / 公開本文 / 公開用の今後の予定 を生成する。返り値は
     * `public_title` / `public_intro` / `public_body` /
     * `public_next_plan` の連想配列(失敗時 WP_Error)。
     * ------------------------------------------------------------ */
    public static function draft_public_post($report_id) {
        if (!self::is_enabled()) {
            return new WP_Error('drwp_ai_disabled', 'AI機能が無効です');
        }
        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';
        $report = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d", (int) $report_id
        ));
        if (!$report) return new WP_Error('drwp_ai_no_report', '日報が見つかりません');

        $project = !empty($report->project_id) ? DRWP_Project::find((int) $report->project_id) : null;
        $captions = [];
        foreach (DRWP_Media::for_report((int) $report->id) as $ph) {
            $c = trim((string) ($ph->caption ?? ''));
            if ($c !== '') $captions[] = $c;
        }

        $src = [];
        if ($project) $src[] = '案件: ' . $project->name;
        if (!empty($report->report_date))      $src[] = '日付: ' . $report->report_date;
        if (!empty($report->work_description))  $src[] = '作業内容: ' . $report->work_description;
        if (!empty($report->issues))           $src[] = '特記事項: ' . $report->issues;
        if (!empty($report->next_plan))        $src[] = '次回予定: ' . $report->next_plan;
        if ($captions)                         $src[] = '写真キャプション: ' . implode(' / ', $captions);
        if (empty($src)) {
            return new WP_Error('drwp_ai_no_source', 'この日報には記事化できる内容がありません');
        }

        // ラベル区切りで返させて確実にパースする(LLM の素の JSON は
        // 崩れやすいので、デリミタ方式を使う)。
        $system = 'あなたは建設・サービス業の会社の広報担当ライターです。'
            . '社内の作業日報をもとに、お客様や一般の読者に向けた公開ブログ記事の下書きを作成します。'
            . '日報に書かれていない事実は創作しないでください（数値・固有名詞の捏造禁止）。'
            . '個人名・電話番号・住所などの個人情報は本文に含めないでください。'
            . '丁寧でわかりやすい日本語で、専門用語には簡単な補足を添えます。'
            . "出力は必ず次の形式（4ブロック）だけにし、各見出し行はそのまま使ってください:\n"
            . "===TITLE===\n（30文字程度の記事タイトル）\n"
            . "===INTRO===\n（2〜3文の導入文）\n"
            . "===BODY===\n（本文。読みやすく段落分け）\n"
            . "===NEXT===\n（今後の予定。無ければ空行）";
        $user = "【作業日報の内容】\n" . implode("\n", $src);

        $result = self::backend()->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ]);
        if (is_wp_error($result)) return $result;

        return self::parse_delimited_draft((string) $result);
    }

    /**
     * Parse the `===TITLE===` / `===INTRO===` / `===BODY===` /
     * `===NEXT===` block format. Tolerates missing blocks; if none
     * of the delimiters are present, the whole text becomes the body
     * (so the operator at least gets something usable).
     */
    private static function parse_delimited_draft($text) {
        $out = ['public_title' => '', 'public_intro' => '', 'public_body' => '', 'public_next_plan' => ''];
        $map = [
            'TITLE' => 'public_title',
            'INTRO' => 'public_intro',
            'BODY'  => 'public_body',
            'NEXT'  => 'public_next_plan',
        ];
        if (!preg_match('/===\s*(TITLE|INTRO|BODY|NEXT)\s*===/u', $text)) {
            $out['public_body'] = trim($text);
            return $out;
        }
        $parts = preg_split('/===\s*(TITLE|INTRO|BODY|NEXT)\s*===/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        // $parts: [pre, KEY, content, KEY, content, ...]
        for ($i = 1; $i < count($parts); $i += 2) {
            $key = strtoupper(trim($parts[$i]));
            $val = trim($parts[$i + 1] ?? '');
            if (isset($map[$key])) $out[$map[$key]] = $val;
        }
        return $out;
    }

    /* ------------------------------------------------------------
     * 機能3: 案件サマリ — ある案件の指定期間(月次/四半期)の日報を
     * まとめて、進捗・対応事項・次の動きを要約する。Markdown 文字列
     * を返す(失敗時 WP_Error)。
     * ------------------------------------------------------------ */
    public static function project_summary($project_id, $date_from, $date_to, $period_label = '') {
        if (!self::is_enabled()) {
            return new WP_Error('drwp_ai_disabled', 'AI機能が無効です');
        }
        $project = DRWP_Project::find($project_id);
        if (!$project) return new WP_Error('drwp_ai_no_project', '案件が見つかりません');

        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';
        $reports = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE project_id = %d AND report_date >= %s AND report_date <= %s
             ORDER BY report_date ASC, id ASC",
            (int) $project_id, $date_from, $date_to
        ));
        if (empty($reports)) {
            return new WP_Error('drwp_ai_no_reports', 'この期間の日報がありません');
        }

        $reports_str = '';
        foreach ($reports as $r) {
            $reports_str .= "\n--- " . $r->report_date . " ---\n";
            if (!empty($r->work_description)) $reports_str .= '作業: ' . $r->work_description . "\n";
            if (!empty($r->issues))           $reports_str .= '特記: ' . $r->issues . "\n";
            if (!empty($r->next_plan))        $reports_str .= '次回: ' . $r->next_plan . "\n";
        }
        $count = count($reports);
        $range = $period_label !== '' ? $period_label : ($date_from . ' 〜 ' . $date_to);

        $system = 'あなたは建設・サービス業の現場管理を支援するアシスタントです。'
            . '指定された期間の複数の日報をまとめ、案件の進捗報告サマリを作成します。'
            . '日報に書かれていない事項は推測しないでください。'
            . "出力は Markdown で、## 期間中の主な作業 / ## 発生した課題・対応 / ## 次の動き・申し送り の3セクションに分け、"
            . '各セクションは簡潔な箇条書きにしてください。';
        $user = "【案件】{$project->name}\n【対象期間】{$range}（日報 {$count} 件）\n【日報（古い順）】{$reports_str}";

        return self::backend()->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ]);
    }

    /* ------------------------------------------------------------
     * 機能4: 対応必須アラート抽出 — 指定範囲の日報の「特記事項」を
     * 横断して、対応が必要な項目(クレーム/危険/要連絡/要見積もり等)
     * を拾い上げる。Markdown 文字列を返す(失敗時 WP_Error)。
     * ------------------------------------------------------------ */
    public static function extract_alerts($date_from, $date_to, $project_id = 0) {
        if (!self::is_enabled()) {
            return new WP_Error('drwp_ai_disabled', 'AI機能が無効です');
        }
        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';
        $where = "report_date >= %s AND report_date <= %s AND issues IS NOT NULL AND issues <> ''";
        $args  = [$date_from, $date_to];
        if ($project_id) {
            $where .= ' AND project_id = %d';
            $args[] = (int) $project_id;
        }
        $reports = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE $where ORDER BY report_date DESC, id DESC LIMIT 100",
            $args
        ));
        if (empty($reports)) {
            return new WP_Error('drwp_ai_no_reports', 'この範囲に特記事項のある日報がありません');
        }

        $lines = '';
        foreach ($reports as $r) {
            $proj = !empty($r->project_id) ? DRWP_Project::find((int) $r->project_id) : null;
            $pname = $proj ? $proj->name : '（案件未設定）';
            $lines .= "\n- [日報#{$r->id} / {$r->report_date} / {$pname}] " . str_replace("\n", ' ', (string) $r->issues);
        }
        $count = count($reports);

        $system = 'あなたは現場の特記事項を確認する管理者アシスタントです。'
            . '複数の日報の「特記事項」から、事務所が対応すべき項目だけを抽出します。'
            . '対象: クレーム・苦情、事故や危険、追加見積もり・追加工事の相談、要連絡・要確認、納期や金銭に関わる相談など。'
            . '単なる感想・日常的な報告は除外します。日報に書かれていないことは作らないでください。'
            . "出力は Markdown の箇条書きで、各項目の先頭に重要度を 🔴(至急) / 🟡(要対応) で付け、"
            . '末尾に該当の「日報#ID」を括弧書きで添えてください。対応必須の項目が無ければ「対応が必要な項目はありません」とだけ返してください。';
        $user = "【特記事項一覧（日報 {$count} 件）】{$lines}";

        return self::backend()->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ]);
    }

    /* ------------------------------------------------------------
     * 機能5: 振り返りアドバイス — 操作員が日報一覧で絞り込んだ
     * 結果(report_ids 配列)を渡すと、作業内容/特記事項/次回予定
     * を横断的に読み、成功例・失敗例・案件への向き合い方を
     * Markdown でまとめる。Markdown 文字列を返す(失敗時 WP_Error)。
     * ------------------------------------------------------------ */
    const ADVISE_MAX = 60;

    public static function advise_on_reports(array $report_ids) {
        if (!self::is_enabled()) {
            return new WP_Error('drwp_ai_disabled', 'AI機能が無効です');
        }
        $report_ids = array_values(array_unique(array_filter(array_map('intval', $report_ids))));
        if (!$report_ids) {
            return new WP_Error('drwp_ai_no_reports', '対象の日報がありません');
        }
        // Cap at ADVISE_MAX so a "クリア" with thousands of rows doesn't
        // blow past the LLM's context. The newest rows survive.
        if (count($report_ids) > self::ADVISE_MAX) {
            $report_ids = array_slice($report_ids, 0, self::ADVISE_MAX);
        }
        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';
        $place = implode(',', array_fill(0, count($report_ids), '%d'));
        $reports = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE id IN ($place) ORDER BY report_date DESC, id DESC",
            $report_ids
        ));
        if (empty($reports)) {
            return new WP_Error('drwp_ai_no_reports', '対象の日報がありません');
        }

        // Resolve project names once per id to avoid N+1 lookups in
        // the prompt-building loop.
        $proj_ids = array_unique(array_filter(array_map(fn($r) => (int) $r->project_id, $reports)));
        $proj_names = [];
        foreach ($proj_ids as $pid) {
            $p = DRWP_Project::find($pid);
            if ($p) $proj_names[$pid] = (string) $p->name;
        }

        $lines = '';
        foreach ($reports as $r) {
            $pname = !empty($r->project_id) && isset($proj_names[(int) $r->project_id])
                ? $proj_names[(int) $r->project_id] : '（案件未設定）';
            $lines .= "\n--- 日報#{$r->id} / {$r->report_date} / {$pname} ---\n";
            if (!empty($r->work_description)) $lines .= '作業: ' . $r->work_description . "\n";
            if (!empty($r->issues))           $lines .= '特記: ' . $r->issues . "\n";
            if (!empty($r->next_plan))        $lines .= '次回: ' . $r->next_plan . "\n";
        }
        $count = count($reports);
        $project_count = count($proj_names);

        $system = 'あなたは建設・サービス業の案件担当を支援するベテランアドバイザーです。'
            . '過去の日報を横断的に読み、現場のパターンを抽出して今後の動き方を助言します。'
            . '日報に書かれていない事実を作らないでください。個人名や個人を特定できる情報は含めないでください。'
            . "出力は Markdown で、必ず次の 4 セクションに分けてください:\n"
            . "## 成功例から見えるパターン\n"
            . "## つまずきや失敗から学べること\n"
            . "## 今後の向き合い方の提案\n"
            . "## 次の一手(具体アクション)\n"
            . '各セクションは簡潔な箇条書き(3〜5項目)で、根拠の日報を「(日報#ID)」で添えてください。';
        $user = "【対象】案件 {$project_count} 件 × 日報 {$count} 件\n【日報（新しい順）】{$lines}";

        return self::backend()->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ]);
    }
}
