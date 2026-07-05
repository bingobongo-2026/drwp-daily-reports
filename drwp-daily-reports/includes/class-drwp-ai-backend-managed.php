<?php
if (!defined('ABSPATH')) exit;

/**
 * 運営側で契約した AI を「ライセンスサーバ経由で」呼び出すバックエンド。
 *
 * own-key モード (DRWP_AI_Backend_OpenAI / Anthropic) はプラグイン →
 * LLM プロバイダに直接行くが、こちらは:
 *
 *   plugin -> license-server/api/ai/chat -> Anthropic/OpenAI
 *
 * の経路で、ライセンスサーバ側で月次回数カウント + 上限チェックを
 * 行う。利用者は API キーを持たなくてよく、運営が 1 社のキーで
 * 一元管理する代わりに、回数が制限される。
 *
 * 設定値:
 *   - DRWP_License::api_url() : ライセンスサーバの URL
 *   - DRWP_License::OPT_KEY   : ライセンスキー
 *   - サイトドメイン (home_url のホスト名)
 */
class DRWP_AI_Backend_Managed implements DRWP_AI_Backend {

    /** @var string ライセンスサーバの URL (末尾スラッシュ無し) */
    private $server_url;

    public function __construct(array $cfg) {
        $url = (string) ($cfg['server_url'] ?? '');
        $this->server_url = rtrim($url, '/');
    }

    public function chat(array $messages, array $opts = []) {
        $cfg_err = $this->check_config();
        if (is_wp_error($cfg_err)) return $cfg_err;

        $body = [
            'license_key' => (string) get_option(DRWP_License::OPT_KEY, ''),
            'domain'      => self::current_domain(),
            'messages'    => array_map(function ($m) {
                return [
                    'role'    => (string) ($m['role'] ?? 'user'),
                    'content' => (string) ($m['content'] ?? ''),
                ];
            }, $messages),
        ];
        if (isset($opts['max_tokens'])) $body['max_tokens'] = (int) $opts['max_tokens'];

        $r = wp_remote_post($this->server_url . '/api/ai/chat', [
            'timeout' => 120,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($body),
        ]);
        if (is_wp_error($r)) return $r;
        $code = (int) wp_remote_retrieve_response_code($r);
        $raw  = wp_remote_retrieve_body($r);
        $data = json_decode($raw, true);

        if ($code === 200) {
            // 使用量レスポンスを後の UI 表示のため transient にキャッシュ。
            // GET /api/ai/quota を毎回叩かなくて済むよう、最新値を持つ。
            if (isset($data['usage'])) {
                set_transient(self::transient_key(), $data['usage'], MINUTE_IN_SECONDS * 5);
            }
            return (string) ($data['text'] ?? '');
        }

        $detail = (is_array($data) && isset($data['detail'])) ? (string) $data['detail'] : $raw;
        // 429: 月次枠到達。利用者向けに分かりやすいメッセージで包む。
        if ($code === 429) {
            return new WP_Error(
                'drwp_ai_quota',
                __('今月の AI 利用枠を使い切りました。来月リセットされます。', 'drwp-daily-reports'),
                ['detail' => $detail]
            );
        }
        // 403: プラン非対応 / ライセンス無効 / ドメイン不一致 など。
        if ($code === 403) {
            return new WP_Error(
                'drwp_ai_forbidden',
                __('運営契約 AI を利用できません (プランかライセンスを確認してください)。', 'drwp-daily-reports'),
                ['detail' => $detail]
            );
        }
        // 503: 運営側で AI 未設定。
        if ($code === 503) {
            return new WP_Error(
                'drwp_ai_unconfigured',
                __('運営側で AI サービスが未設定です。サイト管理者へお知らせください。', 'drwp-daily-reports'),
                ['detail' => $detail]
            );
        }
        return new WP_Error(
            'drwp_ai_http',
            sprintf(__('AI 呼び出しに失敗しました (HTTP %1$d): %2$s', 'drwp-daily-reports'),
                $code, $detail)
        );
    }

    public function test_connection() {
        $cfg_err = $this->check_config();
        if (is_wp_error($cfg_err)) return $cfg_err;

        $url = add_query_arg([
            'license_key' => (string) get_option(DRWP_License::OPT_KEY, ''),
            'domain'      => self::current_domain(),
        ], $this->server_url . '/api/ai/quota');

        $r = wp_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($r)) return $r;
        $code = (int) wp_remote_retrieve_response_code($r);
        $raw  = wp_remote_retrieve_body($r);
        $data = json_decode($raw, true);
        if ($code !== 200 || !is_array($data)) {
            $detail = (is_array($data) && isset($data['detail'])) ? (string) $data['detail'] : $raw;
            return new WP_Error('drwp_ai_http', sprintf(
                __('クォータ取得に失敗 (HTTP %1$d): %2$s', 'drwp-daily-reports'),
                $code, $detail
            ));
        }
        // 5 分間キャッシュ
        set_transient(self::transient_key(), [
            'used'   => (int) ($data['used'] ?? 0),
            'limit'  => (int) ($data['limit'] ?? 0),
            'period' => (string) ($data['period'] ?? ''),
        ], MINUTE_IN_SECONDS * 5);

        // 設定画面の "接続テスト" の戻りは models 配列を期待しているので、
        // 運営側プロバイダのモデル名を返す。
        $model = (string) ($data['model'] ?? '');
        return ['models' => $model !== '' ? [$model] : []];
    }

    /**
     * UI 用の現在月クォータ取得。キャッシュ優先で、空なら test_connection
     * 経由でリフレッシュ。WP_Error 時は null を返してテンプレ側で素通し。
     *
     * @return array{used:int,limit:int,period:string}|null
     */
    public function fetch_quota($force_refresh = false) {
        if (!$force_refresh) {
            $cached = get_transient(self::transient_key());
            if (is_array($cached)) return $cached;
        }
        $r = $this->test_connection();
        if (is_wp_error($r)) return null;
        $cached = get_transient(self::transient_key());
        return is_array($cached) ? $cached : null;
    }

    /** ライセンスサーバ URL とキーが揃っているかの軽量チェック。 */
    private function check_config() {
        if ($this->server_url === '') {
            return new WP_Error('drwp_ai_no_server',
                __('ライセンスサーバの URL が未設定です。ライセンス設定を確認してください。', 'drwp-daily-reports'));
        }
        if ((string) get_option(DRWP_License::OPT_KEY, '') === '') {
            return new WP_Error('drwp_ai_no_key',
                __('ライセンスキーが未設定です。', 'drwp-daily-reports'));
        }
        return true;
    }

    public static function current_domain() {
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        return is_string($host) ? $host : '';
    }

    public static function transient_key() {
        return 'drwp_ai_managed_quota';
    }

    public static function clear_quota_cache() {
        delete_transient(self::transient_key());
    }
}
