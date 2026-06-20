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
    const OPT_ADMIN_TOKEN     = 'drwp_license_admin_token';

    const GRACE_DAYS = 7;
    const CRON_HOOK = 'drwp_license_check';

    /**
     * How far the signed `issued_at` timestamp on a /api/check response
     * may drift from local time before we treat the response as a
     * replay. The server signs `issued_at`, so an attacker who captured
     * an old "active" response can't forge a fresh one — but they could
     * replay the old bytes verbatim to keep a revoked license looking
     * healthy. 15 minutes is wide enough to absorb realistic NTP skew
     * and the round-trip while still cutting off replays.
     */
    const ISSUED_AT_SKEW_SECONDS = 900;

    /**
     * License-server admin token used for /admin/* (rotate, license CRUD).
     * Prefers the DRWP_LICENSE_ADMIN_TOKEN constant (recommended for
     * production: keep secrets out of the database) and falls back to
     * the option set on the license page.
     */
    public static function schedule_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'twicedaily', self::CRON_HOOK);
        }
    }

    public static function clear_cron() {
        wp_unschedule_hook(self::CRON_HOOK);
    }

    public static function admin_token() {
        if (defined('DRWP_LICENSE_ADMIN_TOKEN') && DRWP_LICENSE_ADMIN_TOKEN !== '') {
            return (string) DRWP_LICENSE_ADMIN_TOKEN;
        }
        return (string) get_option(self::OPT_ADMIN_TOKEN, '');
    }

    public static function admin_token_source() {
        if (defined('DRWP_LICENSE_ADMIN_TOKEN') && DRWP_LICENSE_ADMIN_TOKEN !== '') {
            return 'constant';
        }
        return get_option(self::OPT_ADMIN_TOKEN, '') !== '' ? 'option' : 'unset';
    }

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
     * Normalised plan slug — lowercased + trimmed so server-side
     * casing (`Pro`, `PRO`, `pro`) all compare the same way.
     */
    public static function plan() {
        return strtolower(trim((string) get_option(self::OPT_PLAN, '')));
    }

    /**
     * Plan-based feature gate. Returns true iff the license is in
     * a state that allows writes AND the resolved plan grants the
     * requested feature.
     *
     * Plans (extend the matrix as we add tiers):
     *   - `free`  : 30-day trial — same feature set as basic, just
     *               distinguished for billing / expiry handling
     *   - `basic` : everything except AI
     *   - `pro`   : everything including AI
     *
     * Unknown / empty plan = treated as basic (most restrictive
     * thing that still lets the site keep working post-downgrade).
     * No license at all = nothing is allowed (matches can_write).
     */
    public static function plan_allows($feature) {
        if (!self::can_write()) return false;
        $matrix = [
            'free'  => [],
            'basic' => [],
            'pro'   => ['ai'],
        ];
        $plan = self::plan();
        if (!isset($matrix[$plan])) $plan = 'basic';
        return in_array((string) $feature, $matrix[$plan], true);
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
            'api_url'            => (string) get_option(self::OPT_API_URL, ''),
            'license_key'        => (string) get_option(self::OPT_KEY, ''),
            'status'             => self::status(),
            'raw_status'         => (string) get_option(self::OPT_STATUS, ''),
            'plan'               => (string) get_option(self::OPT_PLAN, ''),
            'expires_at'         => (string) get_option(self::OPT_EXPIRES_AT, ''),
            'checked_at'         => (int) get_option(self::OPT_CHECKED_AT, 0),
            'last_valid_at'      => (int) get_option(self::OPT_LAST_VALID_AT, 0),
            'message'            => (string) get_option(self::OPT_LAST_MESSAGE, ''),
            'public_key'         => (string) get_option(self::OPT_PUBLIC_KEY, ''),
            'signature_valid'    => (string) get_option(self::OPT_SIGNATURE_VALID, ''),
            'admin_token_source' => self::admin_token_source(),
        ];
    }

    public static function save_settings($api_url, $license_key, $admin_token = null) {
        update_option(self::OPT_API_URL, esc_url_raw($api_url));
        update_option(self::OPT_KEY, sanitize_text_field($license_key));
        // null means "untouched". Empty string means "clear".
        if ($admin_token !== null) {
            update_option(self::OPT_ADMIN_TOKEN, sanitize_text_field((string) $admin_token));
        }
    }

    /**
     * Rotate the license server's signing key. Requires the admin
     * token (constant or option). On success refreshes the cached
     * public-key set so verification picks up the new key without a
     * separate fetch_public_key() call.
     *
     * Returns the new public key (base64) or WP_Error.
     */
    public static function rotate_key() {
        $api_url = rtrim((string) get_option(self::OPT_API_URL, ''), '/');
        if ($api_url === '') {
            return new WP_Error('drwp_license_missing', __('ライセンスサーバの API URL が未設定です。', 'drwp-daily-reports'));
        }
        $token = self::admin_token();
        if ($token === '') {
            return new WP_Error('drwp_license_no_token', __('管理トークンが未設定です。', 'drwp-daily-reports'));
        }

        $response = wp_remote_post($api_url . '/admin/rotate-signing-key', [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode('admin:' . $token),
            ],
        ]);
        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 401) return new WP_Error('drwp_license_unauthorized', __('管理トークンが拒否されました (401)。', 'drwp-daily-reports'));
        if ($code < 200 || $code >= 300) {
            return new WP_Error('drwp_license_http', sprintf(
                /* translators: %d is the HTTP status code */
                __('ライセンスサーバへの通信に失敗しました (HTTP %d)。', 'drwp-daily-reports'),
                (int) $code
            ));
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['public_key'])) {
            return new WP_Error('drwp_license_unexpected', __('鍵ローテーションの応答が想定外の形式でした。', 'drwp-daily-reports'));
        }

        // Refresh the cached active + previous keys so verify() picks
        // up the rotation without waiting for the next fetch.
        self::fetch_public_key();
        return (string) $body['public_key'];
    }

    public static function fetch_public_key() {
        $api_url = rtrim((string) get_option(self::OPT_API_URL, ''), '/');
        if ($api_url === '') {
            return new WP_Error('drwp_license_missing', __('ライセンスサーバの API URL が未設定です。', 'drwp-daily-reports'));
        }
        $response = wp_remote_get($api_url . '/api/public-key', ['timeout' => 10]);
        if (is_wp_error($response)) return $response;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['public_key']) || ($body['algorithm'] ?? '') !== 'ed25519') {
            return new WP_Error('drwp_license_public_key', __('公開鍵取得の応答が想定外の形式でした。', 'drwp-daily-reports'));
        }
        $raw = base64_decode((string) $body['public_key'], true);
        if ($raw === false || strlen($raw) !== 32) {
            return new WP_Error('drwp_license_public_key', __('公開鍵のバイト長が不正です (32 バイトであるべき)。', 'drwp-daily-reports'));
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
            return new WP_Error('drwp_license_sodium', __('PHP に libsodium 拡張が入っていないため署名検証ができません。', 'drwp-daily-reports'));
        }
        $public_b64 = (string) get_option(self::OPT_PUBLIC_KEY, '');
        if ($public_b64 === '') {
            return new WP_Error('drwp_license_no_key', __('公開鍵がキャッシュされていません。「公開鍵を取得」を先に実行してください。', 'drwp-daily-reports'));
        }
        $sig = base64_decode((string) $signature_b64, true);
        if ($sig === false) {
            return new WP_Error('drwp_license_base64', __('応答に含まれる署名の base64 が不正です。', 'drwp-daily-reports'));
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
            return new WP_Error('drwp_license_missing', __('API URL またはライセンスキーが未設定です。', 'drwp-daily-reports'));
        }

        // Fail closed if we have no public key cached: without it the
        // response can't be verified, and accepting an unsigned/forged
        // payload would let anyone in a network position flip the
        // status to "active". Try to fetch on demand first.
        if ((string) get_option(self::OPT_PUBLIC_KEY, '') === '') {
            $fetched = self::fetch_public_key();
            if (is_wp_error($fetched)) {
                update_option(self::OPT_STATUS, 'inactive');
                update_option(self::OPT_LAST_MESSAGE, $fetched->get_error_message());
                update_option(self::OPT_SIGNATURE_VALID, 'no_key');
                return $fetched;
            }
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
            $msg = sprintf(
                /* translators: %d is the HTTP status code */
                __('ライセンスサーバへの照会に失敗しました (HTTP %d)。', 'drwp-daily-reports'),
                (int) $code
            );
            update_option(self::OPT_STATUS, 'inactive');
            update_option(self::OPT_LAST_MESSAGE, $msg);
            return new WP_Error('drwp_license_http', $msg);
        }

        $signature = (string) ($body['signature'] ?? '');
        $payload = $body;
        unset($payload['signature']);

        if ($signature === '') {
            update_option(self::OPT_STATUS, 'inactive');
            update_option(self::OPT_LAST_MESSAGE, __('応答に署名がありません。', 'drwp-daily-reports'));
            update_option(self::OPT_SIGNATURE_VALID, 'missing');
            return new WP_Error('drwp_license_signature_missing', __('ライセンスサーバの応答に署名が含まれていません。', 'drwp-daily-reports'));
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
            return new WP_Error('drwp_license_signature_invalid', __('ライセンスサーバの応答の署名検証に失敗しました。', 'drwp-daily-reports'));
        }

        // The server includes issued_at inside the signed payload, so
        // we can trust the value once the signature checks out. Reject
        // anything outside the skew window — that catches captured
        // "active" responses being replayed long after the license
        // has been revoked or expired upstream.
        $issued_at = (string) ($payload['issued_at'] ?? '');
        $issued_ts = $issued_at !== '' ? strtotime($issued_at) : false;
        if ($issued_ts === false || abs($now - $issued_ts) > self::ISSUED_AT_SKEW_SECONDS) {
            update_option(self::OPT_STATUS, 'inactive');
            update_option(self::OPT_LAST_MESSAGE, __('応答のタイムスタンプが許容範囲外です。', 'drwp-daily-reports'));
            update_option(self::OPT_SIGNATURE_VALID, 'stale');
            return new WP_Error('drwp_license_stale', __('ライセンスサーバの応答のタイムスタンプが許容範囲外です (リプレイ攻撃の可能性)。', 'drwp-daily-reports'));
        }
        update_option(self::OPT_SIGNATURE_VALID, 'valid');

        $status = sanitize_text_field($payload['status'] ?? 'inactive');
        update_option(self::OPT_STATUS, $status);
        update_option(self::OPT_PLAN, sanitize_text_field($payload['plan'] ?? ''));
        update_option(self::OPT_EXPIRES_AT, sanitize_text_field($payload['expires_at'] ?? ''));
        update_option(self::OPT_LAST_MESSAGE, '');
        if ($status === 'active') {
            update_option(self::OPT_LAST_VALID_AT, $now);
        } else {
            delete_option(self::OPT_LAST_VALID_AT);
        }
        return $status;
    }
}
