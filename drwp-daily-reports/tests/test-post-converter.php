<?php
/**
 * @covers DRWP_Post_Converter
 */
class Test_DRWP_Post_Converter extends WP_UnitTestCase {

    public function test_normalize_tags_splits_on_comma_zenkaku_and_newline() {
        $this->assertSame(
            ['tag1', 'tag2', 'tag3'],
            DRWP_Post_Converter::normalize_tags('tag1, tag2,tag3')
        );
        $this->assertSame(
            ['tag1', 'おはよう', 'tag3'],
            DRWP_Post_Converter::normalize_tags("tag1\nおはよう、tag3")
        );
    }

    public function test_normalize_tags_dedupes_and_drops_empty() {
        $this->assertSame([], DRWP_Post_Converter::normalize_tags(' , , '));
        $this->assertSame(['a', 'b'], DRWP_Post_Converter::normalize_tags('a, b, a, , b'));
    }

    public function test_normalize_tags_passthrough_array_input() {
        $this->assertSame(['x', 'y'], DRWP_Post_Converter::normalize_tags(['x', '', 'y', 'x']));
    }

    public function test_build_content_skips_empty_sections() {
        $report = (object) [
            'public_intro' => '',
            'public_body' => '',
            'public_next_plan' => '',
        ];
        $this->assertSame('', DRWP_Post_Converter::build_content($report));
    }

    public function test_build_content_emits_h2_for_body_and_next_plan() {
        $report = (object) [
            'public_intro' => 'はじめに',
            'public_body' => '本文だよ',
            'public_next_plan' => '次回',
        ];
        $html = DRWP_Post_Converter::build_content($report);
        $this->assertStringContainsString('はじめに', $html);
        $this->assertStringContainsString('本日の作業内容', $html);
        $this->assertStringContainsString('本文だよ', $html);
        $this->assertStringContainsString('今後の予定', $html);
        $this->assertStringContainsString('次回', $html);
    }

    public function test_sync_post_blocks_when_license_inactive() {
        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';
        $wpdb->insert($table, [
            'user_id' => 1,
            'report_date' => '2026-04-25',
            'public_title' => 't',
            'public_body' => 'b',
            'review_status' => 'approved',
        ]);
        delete_option(DRWP_License::OPT_STATUS);

        $result = DRWP_Post_Converter::sync_post((int) $wpdb->insert_id);
        $this->assertWPError($result);
        $this->assertSame('drwp_license', $result->get_error_code());
    }

    public function test_sync_post_creates_post_when_license_active() {
        update_option(DRWP_License::OPT_STATUS, 'active');

        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';
        $wpdb->insert($table, [
            'user_id' => 1,
            'report_date' => '2026-04-25',
            'public_title' => 'タイトル',
            'public_body' => '本文',
            'public_intro' => '導入',
            'review_status' => 'approved',
            'post_status' => 'draft',
            'post_template' => 'standard',
        ]);
        $report_id = (int) $wpdb->insert_id;

        $post_id = DRWP_Post_Converter::sync_post($report_id);
        $this->assertIsInt($post_id);
        $this->assertGreaterThan(0, $post_id);

        $post = get_post($post_id);
        $this->assertSame('タイトル', $post->post_title);
        $this->assertStringContainsString('本文', $post->post_content);

        // linked_post_id is now set on the report row.
        $linked = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT linked_post_id FROM $table WHERE id = %d", $report_id
        ));
        $this->assertSame($post_id, $linked);
    }
}
