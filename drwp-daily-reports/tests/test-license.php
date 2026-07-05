<?php
/**
 * @covers DRWP_License
 */
class Test_DRWP_License extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        foreach ([
            DRWP_License::OPT_API_URL,
            DRWP_License::OPT_KEY,
            DRWP_License::OPT_STATUS,
            DRWP_License::OPT_LAST_VALID_AT,
            DRWP_License::OPT_CHECKED_AT,
            DRWP_License::OPT_PLAN,
            DRWP_License::OPT_EXPIRES_AT,
            DRWP_License::OPT_PUBLIC_KEY,
            DRWP_License::OPT_PREVIOUS_KEYS,
            DRWP_License::OPT_SIGNATURE_VALID,
            DRWP_License::OPT_LAST_MESSAGE,
            DRWP_License::OPT_ADMIN_TOKEN,
        ] as $opt) {
            delete_option($opt);
        }
        remove_all_filters('pre_http_request');
    }

    /**
     * Mint an Ed25519 keypair, cache the public key as the active one,
     * and return both halves so a test can produce signed
     * /api/check responses end-to-end.
     */
    private function seed_signing_keypair() {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('libsodium not available');
        }
        $kp = sodium_crypto_sign_keypair();
        $sk = sodium_crypto_sign_secretkey($kp);
        $pk = sodium_crypto_sign_publickey($kp);
        update_option(DRWP_License::OPT_PUBLIC_KEY, base64_encode($pk));
        return ['sk' => $sk, 'pk' => $pk];
    }

    private function signed_check_response(array $payload, $sk) {
        $payload['signature'] = base64_encode(sodium_crypto_sign_detached(
            DRWP_License::canonical($payload), $sk
        ));
        return [
            'response' => ['code' => 200],
            'body'     => wp_json_encode($payload),
            'headers'  => [],
        ];
    }

    public function tear_down() {
        remove_all_filters('pre_http_request');
        parent::tear_down();
    }

    public function test_canonical_matches_python_server_bytes() {
        // Identical to the test_canonical_form_is_sorted_compact_utf8
        // pin in license-server/tests/test_main.py. PHP must produce
        // byte-equal output so signatures roundtrip.
        $payload = ['b' => '2', 'a' => '1', 'c' => '日本', 'url' => 'https://example.test/x'];
        $bytes = DRWP_License::canonical($payload);
        $this->assertSame(
            '{"a":"1","b":"2","c":"日本","url":"https://example.test/x"}',
            $bytes
        );
    }

    public function test_status_unknown_when_never_checked() {
        $this->assertSame('unknown', DRWP_License::status());
        $this->assertFalse(DRWP_License::can_write());
        $this->assertFalse(DRWP_License::can_convert());
    }

    public function test_status_active_passes_through() {
        update_option(DRWP_License::OPT_STATUS, 'active');
        $this->assertSame('active', DRWP_License::status());
        $this->assertTrue(DRWP_License::can_write());
        $this->assertTrue(DRWP_License::can_convert());
    }

    public function test_plan_allows_pro_unlocks_ai() {
        update_option(DRWP_License::OPT_STATUS, 'active');
        update_option(DRWP_License::OPT_PLAN, 'pro');
        $this->assertSame('pro', DRWP_License::plan());
        $this->assertTrue(DRWP_License::plan_allows('ai'));
    }

    public function test_plan_allows_basic_unlocks_ai() {
        update_option(DRWP_License::OPT_STATUS, 'active');
        update_option(DRWP_License::OPT_PLAN, 'basic');
        // basic でも AI 可 (free のみ不可)。managed モードは月次上限、
        // own モードは無制限、という差はプラグイン/サーバ側で制御する。
        $this->assertTrue(DRWP_License::plan_allows('ai'));
    }

    public function test_plan_allows_free_blocks_ai() {
        update_option(DRWP_License::OPT_STATUS, 'active');
        update_option(DRWP_License::OPT_PLAN, 'free');
        $this->assertFalse(DRWP_License::plan_allows('ai'));
    }

    public function test_plan_allows_normalises_case_and_whitespace() {
        update_option(DRWP_License::OPT_STATUS, 'active');
        update_option(DRWP_License::OPT_PLAN, '  PRO ');
        $this->assertSame('pro', DRWP_License::plan());
        $this->assertTrue(DRWP_License::plan_allows('ai'));
    }

    public function test_plan_allows_unknown_plan_falls_back_to_basic() {
        update_option(DRWP_License::OPT_STATUS, 'active');
        update_option(DRWP_License::OPT_PLAN, 'enterprise');
        // 不明プランは basic 扱い。basic は AI 可なので ai は許可される。
        $this->assertTrue(DRWP_License::plan_allows('ai'));
    }

    public function test_plan_allows_blocks_everything_when_license_inactive() {
        update_option(DRWP_License::OPT_STATUS, 'inactive');
        update_option(DRWP_License::OPT_PLAN, 'pro');
        // ライセンス自体が無効 = AI を含め何も許可しない。
        $this->assertFalse(DRWP_License::plan_allows('ai'));
    }

    public function test_grace_window_lets_writes_through_after_recent_active_check() {
        update_option(DRWP_License::OPT_STATUS, 'inactive');
        update_option(DRWP_License::OPT_LAST_VALID_AT, time() - 2 * DAY_IN_SECONDS);
        $this->assertSame('grace', DRWP_License::status());
        $this->assertTrue(DRWP_License::can_write());
        // Convert still requires a fresh active check.
        $this->assertFalse(DRWP_License::can_convert());
    }

    public function test_grace_window_expires_after_seven_days() {
        update_option(DRWP_License::OPT_STATUS, 'inactive');
        update_option(DRWP_License::OPT_LAST_VALID_AT, time() - 8 * DAY_IN_SECONDS);
        $this->assertSame('inactive', DRWP_License::status());
        $this->assertFalse(DRWP_License::can_write());
    }

    public function test_verify_signature_with_real_ed25519_key() {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('libsodium not available');
        }
        $kp = sodium_crypto_sign_keypair();
        $sk = sodium_crypto_sign_secretkey($kp);
        $pk = sodium_crypto_sign_publickey($kp);
        update_option(DRWP_License::OPT_PUBLIC_KEY, base64_encode($pk));

        $payload = ['license_key' => 'X', 'status' => 'active', 'plan' => 'pro'];
        $sig = base64_encode(sodium_crypto_sign_detached(
            DRWP_License::canonical($payload), $sk
        ));

        $this->assertTrue(DRWP_License::verify_signature($payload, $sig));

        // Tampering breaks the signature.
        $payload['status'] = 'expired';
        $this->assertFalse(DRWP_License::verify_signature($payload, $sig));
    }

    public function test_verify_signature_falls_back_to_previous_keys() {
        $kp_old = sodium_crypto_sign_keypair();
        $sk_old = sodium_crypto_sign_secretkey($kp_old);
        $pk_old = sodium_crypto_sign_publickey($kp_old);

        $kp_new = sodium_crypto_sign_keypair();
        $pk_new = sodium_crypto_sign_publickey($kp_new);

        // Plugin has rotated to the new key but cached the old one as previous.
        update_option(DRWP_License::OPT_PUBLIC_KEY, base64_encode($pk_new));
        update_option(DRWP_License::OPT_PREVIOUS_KEYS, [base64_encode($pk_old)]);

        $payload = ['status' => 'active'];
        $sig_under_old = base64_encode(sodium_crypto_sign_detached(
            DRWP_License::canonical($payload), $sk_old
        ));
        $this->assertTrue(DRWP_License::verify_signature($payload, $sig_under_old));
    }

    public function test_verify_signature_returns_wp_error_without_cached_key() {
        $result = DRWP_License::verify_signature(['x' => 1], 'abcd');
        $this->assertWPError($result);
        $this->assertSame('drwp_license_no_key', $result->get_error_code());
    }

    public function test_schedule_cron_registers_twicedaily_event() {
        wp_unschedule_hook(DRWP_License::CRON_HOOK);
        $this->assertFalse(wp_next_scheduled(DRWP_License::CRON_HOOK));
        DRWP_License::schedule_cron();
        $this->assertNotFalse(wp_next_scheduled(DRWP_License::CRON_HOOK));
        DRWP_License::clear_cron();
        $this->assertFalse(wp_next_scheduled(DRWP_License::CRON_HOOK));
    }

    public function test_blocked_message_contains_settings_link() {
        $msg = DRWP_License::blocked_message('テスト');
        $this->assertStringContainsString('テスト', $msg);
        $this->assertStringContainsString('page=drwp_license', $msg);
        $this->assertStringContainsString('<a href=', $msg);
    }

    // --- admin_token + rotate_key ---------------------------------

    public function test_admin_token_falls_back_to_option() {
        if (defined('DRWP_LICENSE_ADMIN_TOKEN')) {
            $this->markTestSkipped('Constant defined; option path not exercisable in this run.');
        }
        $this->assertSame('', DRWP_License::admin_token());
        $this->assertSame('unset', DRWP_License::admin_token_source());

        update_option(DRWP_License::OPT_ADMIN_TOKEN, 'opt-tok');
        $this->assertSame('opt-tok', DRWP_License::admin_token());
        $this->assertSame('option', DRWP_License::admin_token_source());
    }

    public function test_save_settings_with_null_token_leaves_option_alone() {
        update_option(DRWP_License::OPT_ADMIN_TOKEN, 'kept');
        DRWP_License::save_settings('https://x.test', 'KEY', null);
        $this->assertSame('kept', get_option(DRWP_License::OPT_ADMIN_TOKEN));
    }

    public function test_save_settings_with_empty_string_clears_token() {
        update_option(DRWP_License::OPT_ADMIN_TOKEN, 'kept');
        DRWP_License::save_settings('https://x.test', 'KEY', '');
        $this->assertSame('', get_option(DRWP_License::OPT_ADMIN_TOKEN));
    }

    public function test_rotate_key_without_token_returns_error() {
        if (defined('DRWP_LICENSE_ADMIN_TOKEN')) {
            $this->markTestSkipped('Constant defined; no-token state not reachable.');
        }
        update_option(DRWP_License::OPT_API_URL, 'https://x.test');
        $r = DRWP_License::rotate_key();
        $this->assertWPError($r);
        $this->assertSame('drwp_license_no_token', $r->get_error_code());
    }

    public function test_rotate_key_without_api_url_returns_error() {
        update_option(DRWP_License::OPT_ADMIN_TOKEN, 'tok');
        $r = DRWP_License::rotate_key();
        $this->assertWPError($r);
        $this->assertSame('drwp_license_missing', $r->get_error_code());
    }

    public function test_rotate_key_unauthorized_surfaces_401() {
        update_option(DRWP_License::OPT_API_URL, 'https://srv.test');
        update_option(DRWP_License::OPT_ADMIN_TOKEN, 'wrong');
        add_filter('pre_http_request', function ($pre, $args, $url) {
            return ['response' => ['code' => 401], 'body' => '{"detail":"Invalid"}', 'headers' => []];
        }, 10, 3);
        $r = DRWP_License::rotate_key();
        $this->assertWPError($r);
        $this->assertSame('drwp_license_unauthorized', $r->get_error_code());
    }

    public function test_rotate_key_happy_path_refreshes_cached_keys() {
        if (defined('DRWP_LICENSE_ADMIN_TOKEN')) {
            // The constant override test ran first in this process; option
            // path won't be exercisable.
            $this->markTestSkipped('Constant defined; option-only path not exercisable.');
        }
        update_option(DRWP_License::OPT_API_URL, 'https://srv.test');
        update_option(DRWP_License::OPT_ADMIN_TOKEN, 'right');

        $new_pub = base64_encode(random_bytes(32));
        $old_pub = base64_encode(random_bytes(32));

        // Sequence: rotate then fetch_public_key both call wp_remote_*.
        add_filter('pre_http_request', function ($pre, $args, $url) use ($new_pub, $old_pub) {
            if (strpos($url, '/admin/rotate-signing-key') !== false) {
                return [
                    'response' => ['code' => 200],
                    'body' => json_encode(['public_key' => $new_pub, 'previous_keys' => [$old_pub]]),
                    'headers' => [],
                ];
            }
            if (strpos($url, '/api/public-key') !== false) {
                return [
                    'response' => ['code' => 200],
                    'body' => json_encode(['public_key' => $new_pub, 'previous_keys' => [$old_pub], 'algorithm' => 'ed25519']),
                    'headers' => [],
                ];
            }
            return $pre;
        }, 10, 3);

        $r = DRWP_License::rotate_key();
        $this->assertSame($new_pub, $r);
        $this->assertSame($new_pub, get_option(DRWP_License::OPT_PUBLIC_KEY));
        $this->assertSame([$old_pub], get_option(DRWP_License::OPT_PREVIOUS_KEYS));
    }

    // --- check_now() ---------------------------------------------

    public function test_check_now_accepts_fresh_signed_active_response() {
        $keys = $this->seed_signing_keypair();
        update_option(DRWP_License::OPT_API_URL, 'https://srv.test');
        update_option(DRWP_License::OPT_KEY, 'LIC-OK');

        $now_iso = gmdate('Y-m-d\TH:i:s\Z');
        add_filter('pre_http_request', function ($pre, $args, $url) use ($keys, $now_iso) {
            if (strpos($url, '/api/check') === false) return $pre;
            return $this->signed_check_response([
                'license_key'    => 'LIC-OK',
                'allowed_domain' => 'example.org',
                'status'         => 'active',
                'plan'           => 'pro',
                'expires_at'     => '2099-12-31T23:59:59+00:00',
                'issued_at'      => $now_iso,
            ], $keys['sk']);
        }, 10, 3);

        $r = DRWP_License::check_now();
        $this->assertSame('active', $r);
        $this->assertSame('active', get_option(DRWP_License::OPT_STATUS));
        $this->assertSame('valid', get_option(DRWP_License::OPT_SIGNATURE_VALID));
    }

    public function test_check_now_rejects_replayed_response_outside_skew_window() {
        $keys = $this->seed_signing_keypair();
        update_option(DRWP_License::OPT_API_URL, 'https://srv.test');
        update_option(DRWP_License::OPT_KEY, 'LIC-REPLAY');

        // Stale but otherwise valid + signed "active" response — what an
        // attacker would get by replaying a captured payload long after
        // the license has been revoked upstream.
        $stale = gmdate('Y-m-d\TH:i:s\Z', time() - 2 * DRWP_License::ISSUED_AT_SKEW_SECONDS);
        add_filter('pre_http_request', function ($pre, $args, $url) use ($keys, $stale) {
            if (strpos($url, '/api/check') === false) return $pre;
            return $this->signed_check_response([
                'license_key'    => 'LIC-REPLAY',
                'allowed_domain' => 'example.org',
                'status'         => 'active',
                'plan'           => 'pro',
                'expires_at'     => '',
                'issued_at'      => $stale,
            ], $keys['sk']);
        }, 10, 3);

        $r = DRWP_License::check_now();
        $this->assertWPError($r);
        $this->assertSame('drwp_license_stale', $r->get_error_code());
        $this->assertSame('inactive', get_option(DRWP_License::OPT_STATUS));
        $this->assertSame('stale', get_option(DRWP_License::OPT_SIGNATURE_VALID));
    }

    public function test_check_now_fails_closed_when_public_key_unavailable() {
        // No public key cached and the public-key fetch fails: previously
        // check_now() would skip signature verification and accept
        // whatever the server (or a MITM) returned. It must fail instead.
        update_option(DRWP_License::OPT_API_URL, 'https://srv.test');
        update_option(DRWP_License::OPT_KEY, 'LIC-NOKEY');
        delete_option(DRWP_License::OPT_PUBLIC_KEY);

        $check_called = false;
        add_filter('pre_http_request', function ($pre, $args, $url) use (&$check_called) {
            if (strpos($url, '/api/public-key') !== false) {
                return ['response' => ['code' => 500], 'body' => 'boom', 'headers' => []];
            }
            if (strpos($url, '/api/check') !== false) {
                $check_called = true;
                return ['response' => ['code' => 200], 'body' => wp_json_encode([
                    'status' => 'active', 'plan' => 'pro', 'signature' => 'whatever',
                ]), 'headers' => []];
            }
            return $pre;
        }, 10, 3);

        $r = DRWP_License::check_now();
        $this->assertWPError($r);
        $this->assertSame('no_key', get_option(DRWP_License::OPT_SIGNATURE_VALID));
        $this->assertSame('inactive', get_option(DRWP_License::OPT_STATUS));
        $this->assertFalse($check_called, '/api/check must not be called when no public key is available.');
    }

    public function test_check_now_clears_grace_when_server_confirms_inactive() {
        $keys = $this->seed_signing_keypair();
        update_option(DRWP_License::OPT_API_URL, 'https://srv.test');
        update_option(DRWP_License::OPT_KEY, 'LIC-DEACT');
        update_option(DRWP_License::OPT_LAST_VALID_AT, time() - DAY_IN_SECONDS);

        $now_iso = gmdate('Y-m-d\TH:i:s\Z');
        add_filter('pre_http_request', function ($pre, $args, $url) use ($keys, $now_iso) {
            if (strpos($url, '/api/check') === false) return $pre;
            return $this->signed_check_response([
                'license_key'    => 'LIC-DEACT',
                'allowed_domain' => 'example.org',
                'status'         => 'inactive',
                'plan'           => 'pro',
                'expires_at'     => '2026-05-25T00:00:00+00:00',
                'issued_at'      => $now_iso,
            ], $keys['sk']);
        }, 10, 3);

        $r = DRWP_License::check_now();
        $this->assertSame('inactive', $r);
        $this->assertSame('inactive', DRWP_License::status());
        $this->assertFalse(DRWP_License::can_write());
    }

    public function test_check_now_rejects_tampered_status_via_invalid_signature() {
        $keys = $this->seed_signing_keypair();
        update_option(DRWP_License::OPT_API_URL, 'https://srv.test');
        update_option(DRWP_License::OPT_KEY, 'LIC-TAMPER');

        $now_iso = gmdate('Y-m-d\TH:i:s\Z');
        add_filter('pre_http_request', function ($pre, $args, $url) use ($keys, $now_iso) {
            if (strpos($url, '/api/check') === false) return $pre;
            // Sign the "inactive" payload, then ship a body where status
            // has been flipped to "active" without re-signing — exactly
            // what a MITM downgrade-to-upgrade attack looks like.
            $signed = [
                'license_key' => 'LIC-TAMPER',
                'status'      => 'inactive',
                'plan'        => '',
                'expires_at'  => '',
                'issued_at'   => $now_iso,
            ];
            $sig = base64_encode(sodium_crypto_sign_detached(
                DRWP_License::canonical($signed), $keys['sk']
            ));
            $signed['status']    = 'active';
            $signed['signature'] = $sig;
            return ['response' => ['code' => 200], 'body' => wp_json_encode($signed), 'headers' => []];
        }, 10, 3);

        $r = DRWP_License::check_now();
        $this->assertWPError($r);
        $this->assertSame('drwp_license_signature_invalid', $r->get_error_code());
        $this->assertSame('invalid', get_option(DRWP_License::OPT_SIGNATURE_VALID));
        $this->assertSame('inactive', get_option(DRWP_License::OPT_STATUS));
    }

    /**
     * MUST run last: PHP can't undefine constants inside a process,
     * so this test poisons admin_token_source() for everything after it.
     */
    public function test_zz_admin_token_constant_overrides_option() {
        if (defined('DRWP_LICENSE_ADMIN_TOKEN')) {
            $this->markTestSkipped('Constant already defined.');
        }
        update_option(DRWP_License::OPT_ADMIN_TOKEN, 'opt-tok');
        define('DRWP_LICENSE_ADMIN_TOKEN', 'const-tok');
        $this->assertSame('const-tok', DRWP_License::admin_token());
        $this->assertSame('constant', DRWP_License::admin_token_source());
    }
}
