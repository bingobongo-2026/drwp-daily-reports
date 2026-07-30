<?php
/**
 * @covers DRWP_Admin::admin_notice
 */
class Test_DRWP_Admin_Notice extends WP_UnitTestCase {

    private function capture($type, $text) {
        ob_start();
        DRWP_Admin::admin_notice($type, $text);
        return ob_get_clean();
    }

    public function test_success_notice_has_standard_markup() {
        $html = $this->capture('success', '保存しました。');
        $this->assertStringContainsString('notice-success', $html);
        $this->assertStringContainsString('is-dismissible', $html);
        $this->assertStringContainsString('<p>保存しました。</p>', $html);
    }

    public function test_type_maps_to_class() {
        $this->assertStringContainsString('notice-error', $this->capture('error', 'x'));
        $this->assertStringContainsString('notice-warning', $this->capture('warning', 'x'));
        $this->assertStringContainsString('notice-info', $this->capture('info', 'x'));
        // 未知の種別は info に倒す
        $this->assertStringContainsString('notice-info', $this->capture('bogus', 'x'));
    }

    public function test_text_is_escaped() {
        $html = $this->capture('success', '<script>alert(1)</script>');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_html_variant_keeps_prebuilt_markup() {
        ob_start();
        DRWP_Admin::admin_notice_html('success', '<a href="/x">見る</a>');
        $html = ob_get_clean();
        $this->assertStringContainsString('is-dismissible', $html);
        $this->assertStringContainsString('<a href="/x">見る</a>', $html);
    }
}
