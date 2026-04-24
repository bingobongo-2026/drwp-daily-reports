<?php
if (!defined('ABSPATH')) exit;

class DRWP_License {
    const OPT_API_URL       = 'drwp_license_api_url';
    const OPT_KEY           = 'drwp_license_key';
    const OPT_STATUS        = 'drwp_license_status';
    const OPT_PLAN          = 'drwp_license_plan';
    const OPT_EXPIRES_AT    = 'drwp_license_expires_at';
    const OPT_CHECKED_AT    = 'drwp_license_checked_at';
    const OPT_LAST_VALID_AT = 'drwp_license_last_valid_at';
    const OPT_LAST_MESSAGE  = 'drwp_license_last_message';

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

    public static function state() {
        return [
            'api_url'       => (string) get_option(self::OPT_API_URL, ''),
            'license_key'   => (string) get_option(self::OPT_KEY, ''),
            'status'        => self::status(),
            'raw_status'    => (string) get_option(self::OPT_STATUS, ''),
            'plan'          => (string) get_option(self::OPT_PLAN, ''),
            'expires_at'    => (string) get_option(self::OPT_EXPIRES_AT, ''),
            'checked_at'    => (int) get_option(self::OPT_CHECKED_AT, 0),
            'last_valid_at' => (int) get_option(self::OPT_LAST_VALID_AT, 0),
            'message'       => (string) get_option(self::OPT_LAST_MESSAGE, ''),
        ];
    }

    public static function save_settings($api_url, $license_key) {
        update_option(self::OPT_API_URL, esc_url_raw($api_url));
        update_option(self::OPT_KEY, sanitize_text_field($license_key));
    }

    public static function check_now() {
        $api_url = rtrim((string) get_option(self::OPT_API_URL, ''), '/');
        $key     = (string) get_option(self::OPT_KEY, '');
        $now     = time();
        update_option(self::OPT_CHECKED_AT, $now);

        if ($api_url === '' || $key === '') {
            update_option(self::OPT_STATUS, 'inactive');
            update_option(self::OPT_LAST_MESSAGE, 'API URL またはライセンスキーが未設定です。');
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

        $status = sanitize_text_field($body['status'] ?? 'inactive');
        update_option(self::OPT_STATUS, $status);
        update_option(self::OPT_PLAN, sanitize_text_field($body['plan'] ?? ''));
        update_option(self::OPT_EXPIRES_AT, sanitize_text_field($body['expires_at'] ?? ''));
        update_option(self::OPT_LAST_MESSAGE, '');
        if ($status === 'active') {
            update_option(self::OPT_LAST_VALID_AT, $now);
        }
        return $status;
    }
}
