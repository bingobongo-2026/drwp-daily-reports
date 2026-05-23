<?php
/**
 * @covers DRWP_CSV_Import
 */
class Test_DRWP_CSV_Import extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'drwp_reports');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'drwp_projects');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'drwp_report_photos');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'drwp_audit_logs');
    }

    private function reports() {
        global $wpdb;
        return $wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . 'drwp_reports ORDER BY id ASC');
    }

    public function test_csv_creates_one_report_per_row() {
        $csv = "report_date,project_name,work_description\n"
             . "2026-04-25,現場A,作業1\n"
             . "2026-04-26,現場B,作業2\n";

        $r = DRWP_CSV_Import::import_csv_string($csv, 1);
        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['created']);
        $this->assertEmpty($r['errors']);

        $reports = $this->reports();
        $this->assertCount(2, $reports);
        $this->assertSame('作業1', (string) $reports[0]->work_description);
    }

    public function test_invalid_date_is_reported_per_line() {
        $csv = "report_date,work_description\n"
             . "2026-04-25,ok\n"
             . "not-a-date,bad\n";
        $r = DRWP_CSV_Import::import_csv_string($csv, 1);
        $this->assertSame(1, $r['created']);
        $this->assertCount(1, $r['errors']);
        $this->assertStringContainsString('行 3', $r['errors'][0]);
        $this->assertStringContainsString('report_date', $r['errors'][0]);
    }

    public function test_missing_required_column_short_circuits() {
        $csv = "report_date\n2026-04-25\n";  // no work_description
        $r = DRWP_CSV_Import::import_csv_string($csv, 1);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('work_description', $r['message']);
    }

    public function test_time_columns_are_persisted() {
        $csv = "report_date,project_name,started_at,ended_at,work_description\n"
             . "2026-04-25,現場A,08:00,10:30,a\n"
             . "2026-04-26,現場B,,,b\n"
             . "2026-04-27,現場C,bogus,11:00,c\n";
        $r = DRWP_CSV_Import::import_csv_string($csv, 1);
        $this->assertTrue($r['ok']);
        $this->assertSame(3, $r['created']);

        $reports = $this->reports();
        // HH:MM input rounds out to HH:MM:SS in the TIME column.
        $this->assertSame('08:00:00', (string) $reports[0]->started_at);
        $this->assertSame('10:30:00', (string) $reports[0]->ended_at);
        // Empty cells store NULL.
        $this->assertNull($reports[1]->started_at);
        $this->assertNull($reports[1]->ended_at);
        // Garbage stores NULL; the valid ended_at survives.
        $this->assertNull($reports[2]->started_at);
        $this->assertSame('11:00:00', (string) $reports[2]->ended_at);
    }

    public function test_utf8_bom_is_stripped() {
        $csv = "\xEF\xBB\xBFreport_date,work_description\n2026-04-25,ok\n";
        $r = DRWP_CSV_Import::import_csv_string($csv, 1);
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['created']);
    }
}
