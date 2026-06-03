<?php
if (!defined('ABSPATH')) exit;

class DRWP_AI_Backend_Ollama implements DRWP_AI_Backend {
    private $url;
    private $model;

    public function __construct(array $cfg) {
        $this->url   = rtrim((string) ($cfg['url'] ?? ''), '/');
        $this->model = (string) ($cfg['model'] ?? '');
    }

    public function chat(array $messages, array $opts = []) {
        if ($this->url === '' || $this->model === '') {
            return new WP_Error('drwp_ai_config', 'URL とモデル名を設定してください');
        }
        $body = [
            'model'    => $this->model,
            'messages' => $messages,
            'stream'   => false,
            'options'  => array_merge(['temperature' => 0.7], $opts),
        ];
        $r = wp_remote_post($this->url . '/api/chat', [
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
        return (string) ($data['message']['content'] ?? '');
    }

    public function test_connection() {
        if ($this->url === '') return new WP_Error('drwp_ai_config', 'URL を設定してください');
        $r = wp_remote_get($this->url . '/api/tags', ['timeout' => 5]);
        if (is_wp_error($r)) return $r;
        $code = wp_remote_retrieve_response_code($r);
        if ($code !== 200) return new WP_Error('drwp_ai_http', 'HTTP ' . $code);
        $body = json_decode(wp_remote_retrieve_body($r), true);
        $models = [];
        foreach (($body['models'] ?? []) as $m) {
            if (!empty($m['name'])) $models[] = (string) $m['name'];
        }
        return ['models' => $models];
    }
}
