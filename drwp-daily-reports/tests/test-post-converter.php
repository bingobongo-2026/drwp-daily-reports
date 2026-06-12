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

    public function test_build_content_unknown_template_falls_back_to_standard() {
        $report = (object) [
            'public_intro'     => 'a',
            'public_body'      => 'b',
            'public_next_plan' => 'c',
            'post_template'    => 'made_up_value',
        ];
        // 不明な値は標準扱い — `本日の作業内容` の h2 が出るのが目印。
        $html = DRWP_Post_Converter::build_content($report);
        $this->assertStringContainsString('本日の作業内容', $html);
        $this->assertStringNotContainsString('案件名', $html);
    }

    public function test_build_content_site_report_emits_meta_table() {
        $report = (object) [
            'public_intro'     => '点検開始',
            'public_body'      => '異常なし',
            'public_next_plan' => '',
            'post_template'    => 'site_report',
            'project_id'       => null,
            'user_id'          => 0,
            'report_date'      => '2026-06-15',
            'started_at'       => '09:00:00',
            'ended_at'         => '12:00:00',
        ];
        $html = DRWP_Post_Converter::build_content($report);
        // メタ表のラベル + 標準テンプレと違って `本日の作業内容` でな
        // く `作業内容` の h2 が出ることを確認。
        $this->assertStringContainsString('案件名', $html);
        $this->assertStringContainsString('報告日', $html);
        $this->assertStringContainsString('作業時間', $html);
        $this->assertStringContainsString('09:00 〜 12:00', $html);
        $this->assertStringContainsString('<h2>作業内容</h2>', $html);
        $this->assertStringNotContainsString('本日の作業内容', $html);
    }

    public function test_build_content_before_after_renders_without_photos() {
        // 写真ゼロのときは Before/After grid は出ないが、テンプレ自体
        // は通常通り intro / body / next_plan を吐く。
        $report = (object) [
            'public_intro'     => '点検レポート',
            'public_body'      => '所見',
            'public_next_plan' => '次回',
            'post_template'    => 'before_after',
            'id'               => 0,
        ];
        $html = DRWP_Post_Converter::build_content($report);
        $this->assertStringContainsString('点検レポート', $html);
        $this->assertStringContainsString('<h2>作業内容</h2>', $html);
        $this->assertStringContainsString('所見', $html);
        $this->assertStringContainsString('<h2>今後の予定</h2>', $html);
        $this->assertStringNotContainsString('Before / After', $html);
    }

    public function test_site_report_meta_table_does_not_leak_admin_only_worker_name() {
        // The admin-only 社員名 (drwp_worker_name) must never reach a
        // published post, even though conversion runs in admin context
        // (admin-post.php → is_admin() true).
        $uid = self::factory()->user->create([
            'first_name'   => '太郎',
            'last_name'    => '山田',
            'display_name' => 'tworker',
        ]);
        update_user_meta($uid, 'drwp_worker_name', '社内呼称ヤマちゃん');
        set_current_screen('toplevel_page_drwp_articles'); // force is_admin() true
        $report = (object) [
            'post_template' => 'site_report',
            'project_id'    => null,
            'user_id'       => $uid,
            'report_date'   => '2026-06-15',
            'started_at'    => '09:00:00',
            'ended_at'      => '12:00:00',
            'public_body'   => 'x',
        ];
        $html = DRWP_Post_Converter::build_content($report);
        $this->assertStringContainsString('山田 太郎', $html);
        $this->assertStringNotContainsString('社内呼称ヤマちゃん', $html);
        set_current_screen('front');
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

    public function test_sync_post_uses_drwp_report_cpt_when_configured() {
        update_option(DRWP_License::OPT_STATUS, 'active');
        DRWP_Output::save_settings(['post_type' => DRWP_CPT::POST_TYPE, 'auto_thumbnail' => false]);

        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';
        $wpdb->insert($table, [
            'user_id' => 1,
            'report_date' => '2026-04-25',
            'public_title' => 'CPT',
            'public_body' => 'body',
            'review_status' => 'approved',
        ]);
        $post_id = DRWP_Post_Converter::sync_post((int) $wpdb->insert_id);

        $this->assertIsInt($post_id);
        $this->assertSame(DRWP_CPT::POST_TYPE, get_post_type($post_id));
    }

    public function test_sync_post_preserves_original_type_on_update() {
        update_option(DRWP_License::OPT_STATUS, 'active');

        // First sync as plain `post`.
        DRWP_Output::save_settings(['post_type' => 'post', 'auto_thumbnail' => false]);

        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';
        $wpdb->insert($table, [
            'user_id' => 1,
            'report_date' => '2026-04-25',
            'public_title' => 't',
            'public_body' => 'b',
            'review_status' => 'approved',
        ]);
        $report_id = (int) $wpdb->insert_id;
        $post_id_1 = DRWP_Post_Converter::sync_post($report_id);
        $this->assertSame('post', get_post_type($post_id_1));

        // Operator flips the setting after the fact — re-sync MUST keep
        // the original post_type so existing permalinks don't break.
        DRWP_Output::save_settings(['post_type' => DRWP_CPT::POST_TYPE, 'auto_thumbnail' => false]);
        $post_id_2 = DRWP_Post_Converter::sync_post($report_id, true);
        $this->assertSame($post_id_1, $post_id_2);
        $this->assertSame('post', get_post_type($post_id_2));
    }

    public function test_sync_post_sets_first_photo_as_featured_image() {
        update_option(DRWP_License::OPT_STATUS, 'active');
        DRWP_Output::save_settings(['post_type' => 'post', 'auto_thumbnail' => true]);

        $att = self::factory()->attachment->create_object('p.jpg', 0, [
            'post_mime_type' => 'image/jpeg',
            'post_type'      => 'attachment',
        ]);

        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';
        $wpdb->insert($table, [
            'user_id' => 1,
            'report_date' => '2026-04-25',
            'public_title' => 'with photo',
            'public_body' => 'b',
            'review_status' => 'approved',
        ]);
        $report_id = (int) $wpdb->insert_id;
        DRWP_Media::sync($report_id, [['attachment_id' => $att, 'caption' => 'cap']]);

        $post_id = DRWP_Post_Converter::sync_post($report_id);
        $this->assertSame((int) $att, (int) get_post_thumbnail_id($post_id));
    }

    public function test_sync_post_does_not_overwrite_existing_thumbnail() {
        update_option(DRWP_License::OPT_STATUS, 'active');
        DRWP_Output::save_settings(['post_type' => 'post', 'auto_thumbnail' => true]);

        $hand_picked = self::factory()->attachment->create_object('hand.jpg', 0, [
            'post_mime_type' => 'image/jpeg', 'post_type' => 'attachment',
        ]);
        $auto = self::factory()->attachment->create_object('auto.jpg', 0, [
            'post_mime_type' => 'image/jpeg', 'post_type' => 'attachment',
        ]);

        // Pre-create the linked post with the hand-picked thumbnail set.
        $linked = wp_insert_post(['post_title' => 'pre', 'post_status' => 'draft']);
        set_post_thumbnail($linked, $hand_picked);

        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';
        $wpdb->insert($table, [
            'user_id' => 1,
            'report_date' => '2026-04-25',
            'public_title' => 't',
            'public_body' => 'b',
            'review_status' => 'approved',
            'linked_post_id' => $linked,
        ]);
        $report_id = (int) $wpdb->insert_id;
        DRWP_Media::sync($report_id, [['attachment_id' => $auto, 'caption' => '']]);

        DRWP_Post_Converter::sync_post($report_id, true);
        // The hand-picked thumbnail must survive.
        $this->assertSame((int) $hand_picked, (int) get_post_thumbnail_id($linked));
    }

    public function test_auto_thumbnail_off_skips_set_post_thumbnail() {
        update_option(DRWP_License::OPT_STATUS, 'active');
        DRWP_Output::save_settings(['post_type' => 'post', 'auto_thumbnail' => false]);

        $att = self::factory()->attachment->create_object('p.jpg', 0, [
            'post_mime_type' => 'image/jpeg', 'post_type' => 'attachment',
        ]);
        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';
        $wpdb->insert($table, [
            'user_id' => 1,
            'report_date' => '2026-04-25',
            'public_title' => 'no-thumb',
            'public_body' => 'b',
            'review_status' => 'approved',
        ]);
        $report_id = (int) $wpdb->insert_id;
        DRWP_Media::sync($report_id, [['attachment_id' => $att]]);
        $post_id = DRWP_Post_Converter::sync_post($report_id);

        $this->assertSame(0, (int) get_post_thumbnail_id($post_id));
    }
}
