<?php
/**
 * @covers DRWP_Comment
 */
class Test_DRWP_Comment extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . DRWP_Comment::table());
    }

    public function test_insert_returns_id_and_persists() {
        $user_id = self::factory()->user->create();
        wp_set_current_user($user_id);

        $id = DRWP_Comment::insert(7, 'これはコメント');
        $this->assertGreaterThan(0, $id);

        $rows = DRWP_Comment::for_report(7);
        $this->assertCount(1, $rows);
        $this->assertSame('これはコメント', $rows[0]->body);
        $this->assertSame($user_id, (int) $rows[0]->user_id);
    }

    public function test_insert_rejects_empty_or_whitespace_body() {
        $this->assertSame(0, DRWP_Comment::insert(1, ''));
        $this->assertSame(0, DRWP_Comment::insert(1, '   '));
        $this->assertSame(0, DRWP_Comment::insert(1, "\n\n"));
    }

    public function test_insert_strips_disallowed_html() {
        DRWP_Comment::insert(1, 'safe <strong>bold</strong> <script>alert(1)</script>');
        $rows = DRWP_Comment::for_report(1);
        $this->assertStringContainsString('<strong>bold</strong>', $rows[0]->body);
        $this->assertStringNotContainsString('<script>', $rows[0]->body);
    }

    public function test_for_report_only_returns_matching_report() {
        DRWP_Comment::insert(10, 'A');
        DRWP_Comment::insert(11, 'B');
        DRWP_Comment::insert(10, 'C');

        $rows = DRWP_Comment::for_report(10);
        $this->assertCount(2, $rows);

        $bodies = wp_list_pluck($rows, 'body');
        $this->assertContains('A', $bodies);
        $this->assertContains('C', $bodies);
        $this->assertNotContains('B', $bodies);
    }

    public function test_for_report_returns_oldest_first() {
        DRWP_Comment::insert(5, 'first');
        DRWP_Comment::insert(5, 'second');
        $rows = DRWP_Comment::for_report(5);
        $this->assertSame('first', $rows[0]->body);
        $this->assertSame('second', $rows[1]->body);
    }
}
