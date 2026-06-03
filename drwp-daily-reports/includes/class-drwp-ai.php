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
            case 'openai':
                return ['url' => 'https://api.openai.com', 'model' => 'gpt-4o-mini'];
            case 'anthropic':
                return ['url' => 'https://api.anthropic.com', 'model' => 'claude-haiku-4-5-20251001'];
            case 'ollama':
            default:
                return ['url' => 'http://localhost:11434', 'model' => 'gemma3:4b'];
        }
    }

    public static function provider() {
        $p = (string) get_option(self::OPT_PROVIDER, 'ollama');
        return in_array($p, ['ollama', 'openai', 'anthropic'], true) ? $p : 'ollama';
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
            case 'openai':    return new DRWP_AI_Backend_OpenAI($cfg);
            case 'anthropic': return new DRWP_AI_Backend_Anthropic($cfg);
            default:          return new DRWP_AI_Backend_Ollama($cfg);
        }
    }

    public static function test_connection() {
        return self::backend()->test_connection();
    }

    public static function briefing_for_project($project_id, $limit = self::BRIEFING_LIMIT) {
        if (!self::is_enabled()) {
            return new WP_Error('drwp_ai_disabled', 'AI機能が無効です');
        }
        $project = DRWP_Project::find($project_id);
        if (!$project) return new WP_Error('drwp_ai_no_project', '現場が見つかりません');

        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';
        $reports = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE project_id = %d ORDER BY report_date DESC, id DESC LIMIT %d",
            $project_id, $limit
        ));

        if (empty($reports)) {
            return new WP_Error('drwp_ai_no_reports', 'この現場の日報がまだありません');
        }

        $messages = self::build_briefing_messages($project, $reports);
        return self::backend()->chat($messages);
    }

    private static function build_briefing_messages($project, $reports) {
        $meta = ['現場名: ' . $project->name];
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

        $system = 'あなたは建設・サービス業の現場担当者を支援するアシスタントです。'
            . '日報の内容から、次回訪問時に役立つ「持参物」「継続案件・注意点」「お客様への提案ポイント」を箇条書きで提案します。'
            . '日報に書かれていない事項は推測せず、根拠が読み取れる項目のみ挙げてください。'
            . '出力は Markdown 箇条書きで、## 次回持参すべきもの / ## 注意点・継続案件 / ## お客様への提案ポイント の3セクションに分けてください。';

        $user = "【現場情報】\n{$meta_str}\n\n【過去の日報（新しい順、最大{$count}件）】\n{$reports_str}";

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ];
    }
}
