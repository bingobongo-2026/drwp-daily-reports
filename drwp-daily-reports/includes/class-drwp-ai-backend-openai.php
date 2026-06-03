<?php
if (!defined('ABSPATH')) exit;

/**
 * OpenAI-compatible backend. Works against OpenAI itself and the
 * many providers that ship the same `/v1/chat/completions` shape
 * (Groq, Together, vLLM, LM Studio's server mode, etc.) — set the
 * base URL accordingly.
 */
class DRWP_AI_Backend_OpenAI implements DRWP_AI_Backend {
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
        $body = array_merge([
            'model'    => $this->model,
            'messages' => $messages,
        ], $opts);
        $r = wp_remote_post($this->url . '/v1/chat/completions', [
            'timeout' => 120,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ],
            'body' => wp_json_encode($body),
        ]);
        if (is_wp_error($r)) return $r;
        $code = wp_remote_retrieve_response_code($r);
        if ($code !== 200) {
            return new WP_Error('drwp_ai_http', 'HTTP ' . $code . ': ' . wp_remote_retrieve_body($r));
        }
        $data = json_decode(wp_remote_retrieve_body($r), true);
        return (string) ($data['choices'][0]['message']['content'] ?? '');
    }

    public function test_connection() {
        if ($this->url === '' || $this->api_key === '') {
            return new WP_Error('drwp_ai_config', 'URL と API キーを設定してください');
        }
        $r = wp_remote_get($this->url . '/v1/models', [
            'timeout' => 10,
            'headers' => ['Authorization' => 'Bearer ' . $this->api_key],
        ]);
        if (is_wp_error($r)) return $r;
        $code = wp_remote_retrieve_response_code($r);
        if ($code !== 200) return new WP_Error('drwp_ai_http', 'HTTP ' . $code);
        $body = json_decode(wp_remote_retrieve_body($r), true);
        $models = [];
        foreach (($body['data'] ?? []) as $m) {
            if (!empty($m['id'])) $models[] = (string) $m['id'];
        }
        return ['models' => $models];
    }
}
