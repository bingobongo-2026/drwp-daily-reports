<?php
/**
 * @covers DRWP_Report_Entry
 */
class Test_DRWP_Report_Entry extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'drwp_reports');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'drwp_projects');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'drwp_report_entries');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'drwp_report_photos');
    }

    private function make_project($name) {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'drwp_projects', ['name' => $name, 'status' => 'active']);
        return (int) $wpdb->insert_id;
    }

    private function make_report() {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'drwp_reports', [
            'user_id' => 1,
            'report_date' => '2026-04-25',
            'review_status' => 'pending',
        ]);
        return (int) $wpdb->insert_id;
    }

    private function make_attachment() {
        return self::factory()->attachment->create_object('p.jpg', 0, [
            'post_mime_type' => 'image/jpeg',
            'post_type'      => 'attachment',
        ]);
    }

    public function test_sync_writes_entries_in_order_with_per_entry_photos() {
        $r = $this->make_report();
        $pa = $this->make_project('A');
        $pb = $this->make_project('B');
        $a1 = $this->make_attachment();
        $a2 = $this->make_attachment();

        $kept = DRWP_Report_Entry::sync($r, [
            ['project_id' => $pa, 'work_description' => 'first',  'attachment_ids' => [$a1]],
            ['project_id' => $pb, 'work_description' => 'second', 'attachment_ids' => [$a2], 'attachment_captions' => ['cap2']],
        ]);
        $this->assertSame(2, $kept);

        $entries = DRWP_Report_Entry::for_report($r);
        $this->assertCount(2, $entries);
        $this->assertSame(0, (int) $entries[0]->sort_order);
        $this->assertSame(1, (int) $entries[1]->sort_order);
        $this->assertSame('first',  (string) $entries[0]->work_description);
        $this->assertSame('second', (string) $entries[1]->work_description);

        $p1 = DRWP_Media::for_entry((int) $entries[0]->id);
        $p2 = DRWP_Media::for_entry((int) $entries[1]->id);
        $this->assertCount(1, $p1);
        $this->assertSame($a1, (int) $p1[0]->attachment_id);
        $this->assertCount(1, $p2);
        $this->assertSame($a2, (int) $p2[0]->attachment_id);
        $this->assertSame('cap2', (string) $p2[0]->caption);
    }

    public function test_sync_drops_blank_rows() {
        $r = $this->make_report();
        $pa = $this->make_project('A');

        // 3 rows: one valid, two blanks (no project + no text).
        $kept = DRWP_Report_Entry::sync($r, [
            ['project_id' => $pa, 'work_description' => 'real'],
            ['project_id' => null, 'work_description' => '   '],
            ['project_id' => 0, 'work_description' => ''],
        ]);
        $this->assertSame(1, $kept);
        $this->assertCount(1, DRWP_Report_Entry::for_report($r));
    }

    public function test_sync_replaces_previous_set_and_photos() {
        $r = $this->make_report();
        $pa = $this->make_project('A');
        $a1 = $this->make_attachment();
        $a2 = $this->make_attachment();

        DRWP_Report_Entry::sync($r, [
            ['project_id' => $pa, 'work_description' => 'v1', 'attachment_ids' => [$a1]],
        ]);
        $this->assertCount(1, DRWP_Report_Entry::for_report($r));

        // Re-sync with a different set. Previous entries + their
        // photos must be gone.
        DRWP_Report_Entry::sync($r, [
            ['project_id' => $pa, 'work_description' => 'v2', 'attachment_ids' => [$a2]],
        ]);
        $entries = DRWP_Report_Entry::for_report($r);
        $this->assertCount(1, $entries);
        $this->assertSame('v2', (string) $entries[0]->work_description);

        $photos = DRWP_Media::for_entry((int) $entries[0]->id);
        $this->assertCount(1, $photos);
        $this->assertSame($a2, (int) $photos[0]->attachment_id);
    }

    public function test_sanitize_time_accepts_short_and_long_forms() {
        $r = $this->make_report();
        $pa = $this->make_project('A');

        DRWP_Report_Entry::sync($r, [
            ['project_id' => $pa, 'work_description' => 'x', 'started_at' => '08:00', 'ended_at' => '10:30:00'],
            ['project_id' => $pa, 'work_description' => 'y', 'started_at' => 'invalid', 'ended_at' => ''],
        ]);

        $entries = DRWP_Report_Entry::for_report($r);
        $this->assertSame('08:00:00', (string) $entries[0]->started_at);
        $this->assertSame('10:30:00', (string) $entries[0]->ended_at);
        // Garbage and empty inputs both store NULL.
        $this->assertNull($entries[1]->started_at);
        $this->assertNull($entries[1]->ended_at);
    }

    public function test_sync_persists_public_title_and_body() {
        $r = $this->make_report();
        $pa = $this->make_project('A');

        DRWP_Report_Entry::sync($r, [
            [
                'project_id'       => $pa,
                'work_description' => 'raw notes',
                'public_title'     => '外壁洗浄',
                'public_body'      => '<p>本日は北面を実施。</p>',
            ],
        ]);
        $entry = DRWP_Report_Entry::for_report($r)[0];
        $this->assertSame('外壁洗浄', (string) $entry->public_title);
        $this->assertStringContainsString('北面を実施', (string) $entry->public_body);

        // Round-trip: shape() surfaces both so REST clients see them.
        $shape = DRWP_Report_Entry::shape($entry);
        $this->assertSame('外壁洗浄', $shape['public_title']);
        $this->assertStringContainsString('北面を実施', $shape['public_body']);
    }

    public function test_shape_includes_photos_with_urls() {
        $r = $this->make_report();
        $pa = $this->make_project('A');
        $att = $this->make_attachment();

        DRWP_Report_Entry::sync($r, [
            ['project_id' => $pa, 'work_description' => 'x', 'attachment_ids' => [$att], 'attachment_captions' => ['キャプション']],
        ]);
        $entry = DRWP_Report_Entry::for_report($r)[0];
        $shape = DRWP_Report_Entry::shape($entry);

        $this->assertSame((int) $entry->id, $shape['id']);
        $this->assertCount(1, $shape['photos']);
        $this->assertSame($att, $shape['photos'][0]['attachment_id']);
        $this->assertSame('キャプション', $shape['photos'][0]['caption']);
    }
}
