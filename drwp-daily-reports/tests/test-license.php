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
