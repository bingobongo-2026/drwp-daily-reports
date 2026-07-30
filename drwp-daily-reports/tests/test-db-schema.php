<?php
/**
 * @covers DRWP_DB
 */
class Test_DRWP_DB_Schema extends WP_UnitTestCase {

    public function test_reports_table_has_user_and_project_indexes() {
        // 作業者の一覧 (WHERE user_id) と案件別の集計 (WHERE project_id) が
        // 常時使う列。インデックスが無いと日報が増えるほどフルスキャンに
        // なるため、DDL に含まれていることを固定する。
        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';
        $keys = $wpdb->get_col("SHOW INDEX FROM $table", 2); // Key_name 列
        $this->assertContains('user_id', $keys);
        $this->assertContains('project_id', $keys);
    }
}
