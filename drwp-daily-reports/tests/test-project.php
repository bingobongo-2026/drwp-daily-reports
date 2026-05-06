<?php
/**
 * @covers DRWP_Project
 */
class Test_DRWP_Project extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . DRWP_Project::table());
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
}
