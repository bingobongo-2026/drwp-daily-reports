<?php
if (!defined('ABSPATH')) exit;

class DRWP_License {
    const OPT_API_URL         = 'drwp_license_api_url';
    const OPT_KEY             = 'drwp_license_key';
    const OPT_STATUS          = 'drwp_license_status';
    const OPT_PLAN            = 'drwp_license_plan';
    const OPT_EXPIRES_AT      = 'drwp_license_expires_at';
    const OPT_CHECKED_AT      = 'drwp_license_checked_at';
    const OPT_LAST_VALID_AT   = 'drwp_license_last_valid_at';
    const OPT_LAST_MESSAGE    = 'drwp_license_last_message';
    const OPT_PUBLIC_KEY      = 'drwp_license_public_key';
    const OPT_PREVIOUS_KEYS   = 'drwp_license_previous_keys';
    const OPT_SIGNATURE_VALID = 'drwp_license_signature_valid';

    const GRACE_DAYS = 7;

    public static function status() {
        $status = get_option(self::OPT_STATUS, 'unknown');
        if ($status === 'active') return 'active';
        $last_valid = (int) get_option(self::OPT_LAST_VALID_AT, 0);
        if ($last_valid && (time() - $last_valid) <= self::GRACE_DAYS * DAY_IN_SECONDS) {
            return 'grace';
        }
        return $status ?: 'inactive';
    }

    public static function can_write() {
        return in_array(self::status(), ['active', 'grace'], true);
    }

    public static function can_convert() {
        return self::status() === 'active';
    }

    /**
     * HTML for a wp_die() message that explains why the license is
     * blocking the action and links to the settings page. Allows the
     * 'a' tag with href so the user can click through.
     */
    public static function blocked_message($lead) {
        $url = admin_url('admin.php?page=drwp_license');
        $link = '<a href="' . esc_url($url) . '">' . esc_html__('ライセンス設定を開く', 'drwp-daily-reports') . '</a>';
        return wp_kses(
            '<p>' . esc_html($lead) . '</p>' .
            '<p>' . sprintf(
                /* translators: %s: anchor element pointing at the license settings page */
                esc_html__('ライセンスを有効にするには %s をクリックしてください。', 'drwp-daily-reports'),
                $link
            ) . '</p>',
            ['p' => [], 'a' => ['href' => true]]
        );
    }

    public static function state() {
        return [
            'api_url'         => (string) get_option(self::OPT_API_URL, ''),
            'license_key'     => (string) get_option(self::OPT_KEY, ''),
            'status'          => self::status(),
            'raw_status'      => (string) get_option(self::OPT_STATUS, ''),
            'plan'            => (string) get_option(self::OPT_PLAN, ''),
            'expires_at'      => (string) get_option(self::OPT_EXPIRES_AT, ''),
            'checked_at'      => (int) get_option(self::OPT_CHECKED_AT, 0),
            'last_valid_at'   => (int) get_option(self::OPT_LAST_VALID_AT, 0),
            'message'         => (string) get_option(self::OPT_LAST_MESSAGE, ''),
            'public_key'      => (string) get_option(self::OPT_PUBLIC_KEY, ''),
            'signature_valid' => (string) get_option(self::OPT_SIGNATURE_VALID, ''),
        ];
    }

    public static function save_settings($api_url, $license_key) {
        update_option(self::OPT_API_URL, esc_url_raw($api_url));
        update_option(self::OPT_KEY, sanitize_text_field($license_key));
    }

    public static function fetch_public_key() {
        $api_url = rtrim((string) get_option(self::OPT_API_URL, ''), '/');
        if ($api_url === '') {
            return new WP_Error('drwp_license_missing', 'API URL is not set');
        }
        $response = wp_remote_get($api_url . '/api/public-key', ['timeout' => 10]);
        if (is_wp_error($response)) return $response;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['public_key']) || ($body['algorithm'] ?? '') !== 'ed25519') {
            return new WP_Error('drwp_license_public_key', 'Unexpected public key response');
        }
        $raw = base64_decode((string) $body['public_key'], true);
        if ($raw === false || strlen($raw) !== 32) {
            return new WP_Error('drwp_license_public_key', 'Public key must be 32 raw bytes');
        }

        // Validate any archived previous keys; ignore the malformed ones.
        $previous = [];
        foreach ((array) ($body['previous_keys'] ?? []) as $candidate) {
            $candidate = (string) $candidate;
            $bytes = base64_decode($candidate, true);
            if ($bytes !== false && strlen($bytes) === 32) {
                $previous[] = $candidate;
            }
        }

        update_option(self::OPT_PUBLIC_KEY, $body['public_key']);
        update_option(self::OPT_PREVIOUS_KEYS, $previous);
        return $body['public_key'];
    }

    public static function canonical(array $payload) {
        $sorted = self::ksort_recursive($payload);
        return wp_json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function ksort_recursive(array $arr) {
        ksort($arr, SORT_STRING);
        foreach ($arr as $k => $v) {
            if (is_array($v)) $arr[$k] = self::ksort_recursive($v);
        }
        return $arr;
    }

    public static function verify_signature(array $payload, $signature_b64) {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return new WP_Error('drwp_license_sodium', 'libsodium is not available');
        }
        $public_b64 = (string) get_option(self::OPT_PUBLIC_KEY, '');
        if ($public_b64 === '') {
            return new WP_Error('drwp_license_no_key', 'Public key is not cached');
        }
        $sig = base64_decode((string) $signature_b64, true);
        if ($sig === false) {
            return new WP_Error('drwp_license_base64', 'Invalid signature base64');
        }

        $candidates = [$public_b64];
        foreach ((array) get_option(self::OPT_PREVIOUS_KEYS, []) as $prev) {
            $prev = (string) $prev;
            if ($prev !== '' && $prev !== $public_b64) $candidates[] = $prev;
        }

        $message = self::canonical($payload);
        try {
            foreach ($candidates as $candidate) {
                $pub = base64_decode($candidate, true);
                if ($pub === false || strlen($pub) !== 32) continue;
                if (sodium_crypto_sign_verify_detached($sig, $message, $pub)) {
                    return true;
                }
            }
        } catch (Exception $e) {
            return new WP_Error('drwp_license_sodium', $e->getMessage());
        }
        return false;
    }

    public static function check_now() {
        $api_url = rtrim((string) get_option(self::OPT_API_URL, ''), '/');
        $key     = (string) get_option(self::OPT_KEY, '');
        $now     = time();
        update_option(self::OPT_CHECKED_AT, $now);

        if ($api_url === '' || $key === '') {
            update_option(self::OPT_STATUS, 'inactive');
            update_option(self::OPT_LAST_MESSAGE, __('API URL またはライセンスキーが未設定です。', 'drwp-daily-reports'));
            update_option(self::OPT_SIGNATURE_VALID, '');
            return new WP_Error('drwp_license_missing', 'API URL or license key is missing');
        }

        $response = wp_remote_post($api_url . '/api/check', [
            'timeout' => 10,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'license_key' => $key,
                'domain'      => wp_parse_url(home_url(), PHP_URL_HOST),
            ]),
        ]);

        if (is_wp_error($response)) {
            update_option(self::OPT_LAST_MESSAGE, $response->get_error_message());
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($body)) {
            $msg = 'HTTP ' . (int) $code;
            update_option(self::OPT_STATUS, 'inactive');
            update_option(self::OPT_LAST_MESSAGE, $msg);
            return new WP_Error('drwp_license_http', $msg);
        }

        $signature = (string) ($body['signature'] ?? '');
        $payload = $body;
        unset($payload['signature']);

        $sig_valid = 'skipped';
        if ((string) get_option(self::OPT_PUBLIC_KEY, '') !== '') {
            if ($signature === '') {
                update_option(self::OPT_STATUS, 'inactive');
                update_option(self::OPT_LAST_MESSAGE, __('応答に署名がありません。', 'drwp-daily-reports'));
                update_option(self::OPT_SIGNATURE_VALID, 'missing');
                return new WP_Error('drwp_license_signature_missing', 'Response has no signature');
            }
            $verified = self::verify_signature($payload, $signature);
            if (is_wp_error($verified)) {
                update_option(self::OPT_STATUS, 'inactive');
                update_option(self::OPT_LAST_MESSAGE, $verified->get_error_message());
                update_option(self::OPT_SIGNATURE_VALID, 'error');
                return $verified;
            }
            if (!$verified) {
                update_option(self::OPT_STATUS, 'inactive');
                update_option(self::OPT_LAST_MESSAGE, __('署名検証に失敗しました。', 'drwp-daily-reports'));
                update_option(self::OPT_SIGNATURE_VALID, 'invalid');
                return new WP_Error('drwp_license_signature_invalid', 'Signature verification failed');
            }
            $sig_valid = 'valid';
        }
        update_option(self::OPT_SIGNATURE_VALID, $sig_valid);

        $status = sanitize_text_field($payload['status'] ?? 'inactive');
        update_option(self::OPT_STATUS, $status);
        update_option(self::OPT_PLAN, sanitize_text_field($payload['plan'] ?? ''));
        update_option(self::OPT_EXPIRES_AT, sanitize_text_field($payload['expires_at'] ?? ''));
        update_option(self::OPT_LAST_MESSAGE, '');
        if ($status === 'active') {
            update_option(self::OPT_LAST_VALID_AT, $now);
        }
        return $status;
    }
}
