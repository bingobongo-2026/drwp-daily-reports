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
        ] as $opt) {
            delete_option($opt);
        }
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
}
