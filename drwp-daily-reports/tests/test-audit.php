<?php
/**
 * @covers DRWP_Audit
 */
class Test_DRWP_Audit extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . DRWP_Audit::table());
    }

    public function test_log_persists_event_message_and_meta() {
        $user_id = self::factory()->user->create();
        wp_set_current_user($user_id);

        DRWP_Audit::log('report_created', '日報を作成', 42, ['project_id' => 7]);

        global $wpdb;
        $row = $wpdb->get_row(
            'SELECT * FROM ' . DRWP_Audit::table() . ' ORDER BY id DESC LIMIT 1',
            ARRAY_A
        );
        $this->assertSame('report_created', $row['event']);
        $this->assertSame('日報を作成', $row['message']);
        $this->assertSame(42, (int) $row['report_id']);
        $this->assertSame($user_id, (int) $row['user_id']);
        $this->assertSame(['project_id' => 7], json_decode($row['meta_json'], true));
    }

    public function test_log_strips_html_from_message() {
        DRWP_Audit::log('report_created', '<script>alert(1)</script>テスト', 1);
        global $wpdb;
        $msg = $wpdb->get_var('SELECT message FROM ' . DRWP_Audit::table() . ' ORDER BY id DESC LIMIT 1');
        $this->assertStringNotContainsString('<script>', $msg);
        $this->assertStringContainsString('テスト', $msg);
    }

    public function test_log_with_empty_meta_stores_null() {
        DRWP_Audit::log('comment_added', 'x', 1);
        global $wpdb;
        $meta = $wpdb->get_var('SELECT meta_json FROM ' . DRWP_Audit::table() . ' ORDER BY id DESC LIMIT 1');
        $this->assertNull($meta);
    }

    public function test_for_report_filters_and_orders_desc() {
        DRWP_Audit::log('report_created', 'A', 1);
        DRWP_Audit::log('report_updated', 'B', 1);
        DRWP_Audit::log('report_created', 'C', 2);

        $rows = DRWP_Audit::for_report(1);
        $this->assertCount(2, $rows);
        // Newest first.
        $this->assertSame('B', $rows[0]->message);
        $this->assertSame('A', $rows[1]->message);
    }

    public function test_search_filters_by_event_and_search_text() {
        DRWP_Audit::log('report_created', 'one apple', 1);
        DRWP_Audit::log('report_updated', 'banana', 1);
        DRWP_Audit::log('report_created', 'three apple', 2);

        $by_event = DRWP_Audit::search(['event' => 'report_created']);
        $this->assertCount(2, $by_event);

        $by_text = DRWP_Audit::search(['search' => 'apple']);
        $this->assertCount(2, $by_text);

        $combined = DRWP_Audit::search(['event' => 'report_created', 'search' => 'three']);
        $this->assertCount(1, $combined);
    }

    public function test_count_matches_search() {
        for ($i = 0; $i < 5; $i++) DRWP_Audit::log('report_created', "row $i", 1);
        DRWP_Audit::log('report_updated', 'other', 1);

        $this->assertSame(6, DRWP_Audit::count());
        $this->assertSame(5, DRWP_Audit::count(['event' => 'report_created']));
        $this->assertSame(1, DRWP_Audit::count(['event' => 'report_updated']));
    }

    public function test_search_paginates() {
        for ($i = 0; $i < 12; $i++) DRWP_Audit::log('report_created', "row $i", 1);

        $page1 = DRWP_Audit::search([], 5, 0);
        $page2 = DRWP_Audit::search([], 5, 5);
        $page3 = DRWP_Audit::search([], 5, 10);

        $this->assertCount(5, $page1);
        $this->assertCount(5, $page2);
        $this->assertCount(2, $page3);
    }

    public function test_events_map_has_known_keys() {
        $map = DRWP_Audit::events_map();
        $this->assertArrayHasKey('report_created', $map);
        $this->assertArrayHasKey('review_status_changed', $map);
        $this->assertArrayHasKey('post_resynced', $map);
    }
}
