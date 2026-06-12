<?php
if (!defined('ABSPATH')) exit;

/**
 * AI backend abstraction.
 *
 * Implementations adapt the plugin's provider-agnostic "chat with
 * messages" call to the wire format of a specific service
 * (OpenAI, Anthropic, etc.). The plugin's high-level features
 * (briefings, etc.) only ever talk to this interface.
 */
interface DRWP_AI_Backend {

    /**
     * Send a chat completion. $messages uses OpenAI-style records:
     *   [ ['role' => 'system'|'user'|'assistant', 'content' => '...'], ... ]
     *
     * Implementations translate to the provider's native format.
     * Returns the assistant's text response on success, or WP_Error.
     */
    public function chat(array $messages, array $opts = []);

    /**
     * Verify credentials / reachability. Returns
     *   ['models' => array of model names]
     * on success, or WP_Error on failure.
     */
    public function test_connection();
}
