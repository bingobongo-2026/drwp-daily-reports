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

    public function test_sync_post_with_entries_creates_one_post_per_entry() {
        update_option(DRWP_License::OPT_STATUS, 'active');
        DRWP_Output::save_settings(['post_type' => 'post', 'auto_thumbnail' => false]);

        global $wpdb;
        $reports = $wpdb->prefix . 'drwp_reports';
        $projects = $wpdb->prefix . 'drwp_projects';
        $wpdb->insert($projects, ['name' => '現場A', 'status' => 'active']);
        $proj_a = (int) $wpdb->insert_id;
        $wpdb->insert($projects, ['name' => '現場B', 'status' => 'active']);
        $proj_b = (int) $wpdb->insert_id;

        $wpdb->insert($reports, [
            'user_id' => 1,
            'report_date' => '2026-04-25',
            'review_status' => 'approved',
            'post_status' => 'draft',
            'post_tags' => 'tag1, tag2',
        ]);
        $report_id = (int) $wpdb->insert_id;

        DRWP_Report_Entry::sync($report_id, [
            [
                'project_id' => $proj_a,
                'work_description' => 'A での作業内容',
                'attachment_ids' => [],
            ],
            [
                'project_id' => $proj_b,
                'work_description' => 'B での作業内容',
                'attachment_ids' => [],
            ],
        ]);

        $result = DRWP_Post_Converter::sync_post($report_id);
        $this->assertIsInt($result, 'sync_post should return the first generated post id');

        $entries = DRWP_Report_Entry::for_report($report_id);
        $this->assertCount(2, $entries);
        $this->assertNotNull($entries[0]->linked_post_id);
        $this->assertNotNull($entries[1]->linked_post_id);
        $this->assertNotSame($entries[0]->linked_post_id, $entries[1]->linked_post_id);

        $post_a = get_post((int) $entries[0]->linked_post_id);
        $this->assertSame('現場A - 2026-04-25', $post_a->post_title);
        $this->assertStringContainsString('A での作業内容', $post_a->post_content);

        // Re-sync uses the same post (no duplicate) for each entry.
        DRWP_Post_Converter::sync_post($report_id, true);
        $entries_after = DRWP_Report_Entry::for_report($report_id);
        $this->assertSame((int) $entries[0]->linked_post_id, (int) $entries_after[0]->linked_post_id);
        $this->assertSame((int) $entries[1]->linked_post_id, (int) $entries_after[1]->linked_post_id);
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
