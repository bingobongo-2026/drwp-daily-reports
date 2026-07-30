<?php
/**
 * @covers DRWP_Project
 */
class Test_DRWP_Project extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . DRWP_Project::table());
        // find() のリクエスト内キャッシュはテストプロセスをまたいで残る
        // ので、各テストの開始時に必ず捨てる。
        DRWP_Project::flush_find_cache();
    }

    public function test_find_uses_request_cache() {
        global $wpdb;
        $wpdb->insert(DRWP_Project::table(), ['name' => 'Cached', 'status' => 'active']);
        $id = (int) $wpdb->insert_id;
        DRWP_Project::flush_find_cache();

        $first = DRWP_Project::find($id);
        $n = $wpdb->num_queries;
        $second = DRWP_Project::find($id);
        $this->assertSame($n, (int) $wpdb->num_queries, '2回目の find がクエリを発行している');
        $this->assertSame($first, $second);

        // 見つからない ID の null も再クエリしない
        DRWP_Project::find(424242);
        $n2 = $wpdb->num_queries;
        $this->assertNull(DRWP_Project::find(424242));
        $this->assertSame($n2, (int) $wpdb->num_queries);
    }

    public function test_find_returns_null_for_unknown_id() {
        $this->assertNull(DRWP_Project::find(0));
        $this->assertNull(DRWP_Project::find(99999));
    }

    public function test_find_returns_row_for_known_id() {
        global $wpdb;
        $wpdb->insert(DRWP_Project::table(), ['name' => 'Site A', 'status' => 'active']);
        $id = (int) $wpdb->insert_id;
        $row = DRWP_Project::find($id);
        $this->assertSame('Site A', $row->name);
        $this->assertSame('active', $row->status);
    }

    public function test_all_orders_by_name_then_id_desc() {
        global $wpdb;
        $wpdb->insert(DRWP_Project::table(), ['name' => 'Charlie']);
        $wpdb->insert(DRWP_Project::table(), ['name' => 'Alpha']);
        $wpdb->insert(DRWP_Project::table(), ['name' => 'Bravo']);

        $rows = DRWP_Project::all();
        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], wp_list_pluck($rows, 'name'));
    }

    public function test_all_active_filter_excludes_inactive() {
        global $wpdb;
        $wpdb->insert(DRWP_Project::table(), ['name' => 'On',  'status' => 'active']);
        $wpdb->insert(DRWP_Project::table(), ['name' => 'Off', 'status' => 'inactive']);
        $rows = DRWP_Project::all(true);
        $this->assertCount(1, $rows);
        $this->assertSame('On', $rows[0]->name);
    }

    public function test_all_for_filter_excludes_completed_but_keeps_inactive() {
        global $wpdb;
        $wpdb->insert(DRWP_Project::table(), ['name' => 'Act',  'status' => 'active']);
        $wpdb->insert(DRWP_Project::table(), ['name' => 'Inact', 'status' => 'inactive']);
        $wpdb->insert(DRWP_Project::table(), ['name' => 'Done', 'status' => 'completed']);
        $names = array_map(function ($r) { return $r->name; }, DRWP_Project::all_for_filter());
        // 完了だけ除外。稼働中・休止中は残す。
        $this->assertContains('Act', $names);
        $this->assertContains('Inact', $names);
        $this->assertNotContains('Done', $names);
    }

    public function test_completed_is_a_labeled_project_status() {
        $this->assertSame('完了', DRWP_Labels::project_status('completed'));
    }
}
