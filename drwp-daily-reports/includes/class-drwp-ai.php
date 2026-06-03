<?php
if (!defined('ABSPATH')) exit;

/**
 * Local AI integration via Ollama.
 *
 * Calls the Ollama HTTP API (`/api/generate`) running on the user's
 * own machine/server. No data leaves the host. The integration is
 * additive: when disabled or unreachable, the rest of the plugin
 * works exactly as before.
 *
 * Phase 1 surface: "次回訪問ブリーフィング" — given a project, pull
 * the latest N daily reports and ask the LLM for持参物 / 注意点 /
 * 提案ポイント.
 */
class DRWP_AI {
    const OPT_URL         = 'drwp_ai_url';
    const OPT_MODEL       = 'drwp_ai_model';
    const OPT_ENABLED     = 'drwp_ai_enabled';
    const DEFAULT_URL     = 'http://localhost:11434';
    const DEFAULT_MODEL   = 'gemma3:4b';
    const BRIEFING_LIMIT  = 10;

    public static function url() {
        return rtrim((string) get_option(self::OPT_URL, self::DEFAULT_URL), '/');
    }

    public static function model() {
        $m = (string) get_option(self::OPT_MODEL, '');
        return $m !== '' ? $m : self::DEFAULT_MODEL;
    }

    public static function is_enabled() {
        return get_option(self::OPT_ENABLED) === 'yes' && self::url() !== '';
    }

    /**
     * Ping the Ollama server and return the list of installed model
     * names. Returns WP_Error on any failure.
     */
    public static function test_connection() {
        $r = wp_remote_get(self::url() . '/api/tags', ['timeout' => 5]);
        if (is_wp_error($r)) return $r;
        $code = wp_remote_retrieve_response_code($r);
        if ($code !== 200) {
            return new WP_Error('drwp_ai_http', 'HTTP ' . $code);
        }
        $body = json_decode(wp_remote_retrieve_body($r), true);
        $models = [];
        foreach (($body['models'] ?? []) as $m) {
            if (!empty($m['name'])) $models[] = (string) $m['name'];
        }
        return ['models' => $models];
    }

    /**
     * Low-level call: send a prompt to Ollama and return the text.
     * Long timeout because local model inference on CPU can be slow.
     */
    public static function generate($prompt, $opts = []) {
        if (!self::is_enabled()) {
            return new WP_Error('drwp_ai_disabled', 'AI機能が無効です');
        }
        $body = [
            'model'   => self::model(),
            'prompt'  => $prompt,
            'stream'  => false,
            'options' => array_merge(['temperature' => 0.7], $opts),
        ];
        $r = wp_remote_post(self::url() . '/api/generate', [
            'timeout' => 180,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($body),
        ]);
        if (is_wp_error($r)) return $r;
        $code = wp_remote_retrieve_response_code($r);
        if ($code !== 200) {
            return new WP_Error('drwp_ai_http', 'HTTP ' . $code . ': ' . wp_remote_retrieve_body($r));
        }
        $data = json_decode(wp_remote_retrieve_body($r), true);
        return (string) ($data['response'] ?? '');
    }

    public static function briefing_for_project($project_id, $limit = self::BRIEFING_LIMIT) {
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

        return self::generate(self::build_briefing_prompt($project, $reports));
    }

    private static function build_briefing_prompt($project, $reports) {
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
        return <<<PROMPT
あなたは建設・サービス業の現場担当者を支援するアシスタントです。
以下の現場の過去の日報をもとに、次回訪問時に役立つブリーフィングを作成してください。

【出力フォーマット（Markdown）】
## 次回持参すべきもの
- ...

## 注意点・継続案件
- ...

## お客様への提案ポイント
- ...

【現場情報】
{$meta_str}

【過去の日報（新しい順、最大{$count}件）】
{$reports_str}

簡潔に、実用的な内容を箇条書きで出力してください。日報に書かれていない事項は推測せず、根拠が読み取れる項目のみ提案してください。
PROMPT;
    }
}
