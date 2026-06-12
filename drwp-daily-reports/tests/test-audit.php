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

    public function test_retention_default_when_unset() {
        delete_option(DRWP_Audit::OPT_RETENTION_DAYS);
        $this->assertSame(DRWP_Audit::DEFAULT_RETENTION_DAYS, DRWP_Audit::retention_days());
    }

    public function test_retention_zero_means_forever() {
        update_option(DRWP_Audit::OPT_RETENTION_DAYS, 0);
        $this->assertSame(0, DRWP_Audit::retention_days());
    }

    public function test_purge_older_than_keeps_recent_rows() {
        global $wpdb;
        $now    = current_time('mysql', true); // UTC like the column
        $old    = gmdate('Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS);
        $recent = gmdate('Y-m-d H:i:s', time() -  10 * DAY_IN_SECONDS);
        $wpdb->insert(DRWP_Audit::table(), ['event' => 'x', 'user_id' => 1, 'created_at' => $old]);
        $wpdb->insert(DRWP_Audit::table(), ['event' => 'y', 'user_id' => 1, 'created_at' => $recent]);
        $wpdb->insert(DRWP_Audit::table(), ['event' => 'z', 'user_id' => 1, 'created_at' => $now]);
        // 365 日基準 — `x` だけ落ちる。
        $deleted = DRWP_Audit::purge_older_than(365);
        $this->assertSame(1, $deleted);
        $events = $wpdb->get_col('SELECT event FROM ' . DRWP_Audit::table() . ' ORDER BY created_at ASC');
        $this->assertSame(['y', 'z'], $events);
    }

    public function test_purge_with_zero_days_is_noop() {
        global $wpdb;
        $wpdb->insert(DRWP_Audit::table(), [
            'event' => 'x', 'user_id' => 1,
            'created_at' => gmdate('Y-m-d H:i:s', time() - 9999 * DAY_IN_SECONDS),
        ]);
        $this->assertSame(0, DRWP_Audit::purge_older_than(0));
        $this->assertSame(1, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . DRWP_Audit::table()));
    }

    public function test_cron_purge_writes_an_audit_row_when_it_deletes_anything() {
        global $wpdb;
        update_option(DRWP_Audit::OPT_RETENTION_DAYS, 30);
        $wpdb->insert(DRWP_Audit::table(), [
            'event' => 'old', 'user_id' => 1,
            'created_at' => gmdate('Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS),
        ]);
        DRWP_Audit::cron_purge();
        // 削除サマリのログ行が必ず残る — 「いつ・何件消えたか」を
        // 操作員が後追いできるように。
        $purged = $wpdb->get_row('SELECT * FROM ' . DRWP_Audit::table() . " WHERE event = 'audit_purged'");
        $this->assertNotNull($purged);
    }
}
