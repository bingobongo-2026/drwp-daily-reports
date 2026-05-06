<?php
/**
 * @covers DRWP_Media
 */
class Test_DRWP_Media extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . DRWP_Media::table());
    }

    private function attachment_id() {
        return self::factory()->attachment->create_object('photo.jpg', 0, [
            'post_mime_type' => 'image/jpeg',
            'post_type'      => 'attachment',
        ]);
    }

    public function test_sync_inserts_in_order() {
        $a = $this->attachment_id();
        $b = $this->attachment_id();

        $count = DRWP_Media::sync(1, [
            ['attachment_id' => $a, 'caption' => 'first'],
            ['attachment_id' => $b, 'caption' => 'second'],
        ]);
        $this->assertSame(2, $count);

        $rows = DRWP_Media::for_report(1);
        $this->assertSame('first', $rows[0]->caption);
        $this->assertSame(0, (int) $rows[0]->sort_order);
        $this->assertSame('second', $rows[1]->caption);
        $this->assertSame(1, (int) $rows[1]->sort_order);
    }

    public function test_sync_replaces_previous_set() {
        $a = $this->attachment_id();
        $b = $this->attachment_id();
        DRWP_Media::sync(1, [['attachment_id' => $a]]);
        $this->assertCount(1, DRWP_Media::for_report(1));

        DRWP_Media::sync(1, [['attachment_id' => $b]]);
        $rows = DRWP_Media::for_report(1);
        $this->assertCount(1, $rows);
        $this->assertSame((int) $b, (int) $rows[0]->attachment_id);
    }

    public function test_sync_rejects_non_attachment_ids() {
        $post_id = self::factory()->post->create();  // not an attachment
        $count = DRWP_Media::sync(1, [
            ['attachment_id' => $post_id, 'caption' => 'should be skipped'],
        ]);
        $this->assertSame(0, $count);
        $this->assertCount(0, DRWP_Media::for_report(1));
    }

    public function test_render_figure_returns_empty_when_no_image_url() {
        // Attachment with no actual file → wp_get_attachment_image_url
        // returns false, so render_figure returns ''.
        $bare = self::factory()->post->create(['post_type' => 'attachment']);
        $rows = (object) ['attachment_id' => $bare, 'caption' => null];
        $this->assertSame('', DRWP_Media::render_figure($rows));
    }
}
