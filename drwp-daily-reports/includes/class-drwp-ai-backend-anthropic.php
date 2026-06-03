<?php
if (!defined('ABSPATH')) exit;

/**
 * Anthropic Claude backend (`/v1/messages`). Splits system messages
 * out of the array per Anthropic's schema and unwraps the response
 * text from the `content` array.
 */
class DRWP_AI_Backend_Anthropic implements DRWP_AI_Backend {
    const API_VERSION = '2023-06-01';

    private $url;
    private $model;
    private $api_key;

    public function __construct(array $cfg) {
        $this->url     = rtrim((string) ($cfg['url'] ?? ''), '/');
        $this->model   = (string) ($cfg['model'] ?? '');
        $this->api_key = (string) ($cfg['api_key'] ?? '');
    }

    public function chat(array $messages, array $opts = []) {
        if ($this->url === '' || $this->model === '' || $this->api_key === '') {
            return new WP_Error('drwp_ai_config', 'URL・モデル・API キーをすべて設定してください');
        }
        // Anthropic puts system prompts outside the messages array.
        $system   = '';
        $filtered = [];
        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'system') {
                $system .= ($system === '' ? '' : "\n\n") . (string) ($msg['content'] ?? '');
            } else {
                $filtered[] = $msg;
            }
        }
        $body = array_merge([
            'model'      => $this->model,
            'max_tokens' => 2048,
            'messages'   => $filtered,
        ], $opts);
        if ($system !== '') $body['system'] = $system;

        $r = wp_remote_post($this->url . '/v1/messages', [
            'timeout' => 120,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $this->api_key,
                'anthropic-version' => self::API_VERSION,
            ],
            'body' => wp_json_encode($body),
        ]);
        if (is_wp_error($r)) return $r;
        $code = wp_remote_retrieve_response_code($r);
        if ($code !== 200) {
            return new WP_Error('drwp_ai_http', 'HTTP ' . $code . ': ' . wp_remote_retrieve_body($r));
        }
        $data = json_decode(wp_remote_retrieve_body($r), true);
        $text = '';
        foreach (($data['content'] ?? []) as $part) {
            if (($part['type'] ?? '') === 'text') $text .= (string) ($part['text'] ?? '');
        }
        return $text;
    }

    public function test_connection() {
        if ($this->url === '' || $this->api_key === '') {
            return new WP_Error('drwp_ai_config', 'URL と API キーを設定してください');
        }
        // Anthropic doesn't expose /models, so we ping with a tiny
        // request: 1-token max, the cheapest available model.
        $r = wp_remote_post($this->url . '/v1/messages', [
            'timeout' => 10,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $this->api_key,
                'anthropic-version' => self::API_VERSION,
            ],
            'body' => wp_json_encode([
                'model'      => $this->model !== '' ? $this->model : 'claude-haiku-4-5-20251001',
                'max_tokens' => 1,
                'messages'   => [['role' => 'user', 'content' => 'ping']],
            ]),
        ]);
        if (is_wp_error($r)) return $r;
        $code = wp_remote_retrieve_response_code($r);
        if ($code !== 200) {
            return new WP_Error('drwp_ai_http', 'HTTP ' . $code . ': ' . wp_remote_retrieve_body($r));
        }
        // Surface the configured model so the user sees "what worked".
        return ['models' => [$this->model !== '' ? $this->model : 'claude-haiku-4-5-20251001']];
    }
}
