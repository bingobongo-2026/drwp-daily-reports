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
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'drwp_report_entries');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'drwp_report_photos');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'drwp_audit_logs');
    }

    private function reports() {
        global $wpdb;
        return $wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . 'drwp_reports ORDER BY id ASC');
    }

    public function test_legacy_csv_creates_one_report_per_row() {
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
        $this->assertSame(0, count(DRWP_Report_Entry::for_report((int) $reports[0]->id)));
    }

    public function test_legacy_invalid_date_is_reported_per_line() {
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

    public function test_multi_entry_groups_rows_into_one_report() {
        $csv = "entry_group,report_date,project_name,started_at,ended_at,work_description,entry_public_title\n"
             . "G1,2026-04-25,現場A,08:00,10:00,A での作業,外壁洗浄\n"
             . "G1,2026-04-25,現場B,10:30,12:00,B での作業,\n"
             . "G2,2026-04-26,現場C,,,C での作業,\n";

        $r = DRWP_CSV_Import::import_csv_string($csv, 1);
        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['created']);
        $this->assertEmpty($r['errors']);

        $reports = $this->reports();
        $this->assertCount(2, $reports);

        $r1_entries = DRWP_Report_Entry::for_report((int) $reports[0]->id);
        $this->assertCount(2, $r1_entries);
        $this->assertSame('A での作業', (string) $r1_entries[0]->work_description);
        $this->assertSame('外壁洗浄', (string) $r1_entries[0]->public_title);
        $this->assertSame('08:00:00', (string) $r1_entries[0]->started_at);

        $r2_entries = DRWP_Report_Entry::for_report((int) $reports[1]->id);
        $this->assertCount(1, $r2_entries);
        $this->assertSame('C での作業', (string) $r2_entries[0]->work_description);
    }

    public function test_multi_entry_empty_group_becomes_single_entry_report() {
        // Rows with an empty entry_group still flow through the
        // multi-entry path — each becomes its own 1-entry report so
        // the data shape stays uniform across the file.
        $csv = "entry_group,report_date,project_name,work_description\n"
             . ",2026-04-25,A,row1\n"
             . ",2026-04-26,B,row2\n";

        $r = DRWP_CSV_Import::import_csv_string($csv, 1);
        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['created']);

        foreach ($this->reports() as $report) {
            $this->assertCount(1, DRWP_Report_Entry::for_report((int) $report->id));
        }
    }

    public function test_multi_entry_group_with_only_blank_work_rows_is_rolled_back() {
        // Both rows in the group lack work_description, so there are
        // no valid entries to create. The report row must not survive.
        $csv = "entry_group,report_date,project_name,work_description\n"
             . "G1,2026-04-25,A,\n"
             . "G1,2026-04-25,B,   \n";

        $r = DRWP_CSV_Import::import_csv_string($csv, 1);
        $this->assertSame(0, $r['created']);
        $this->assertNotEmpty($r['errors']);
        $this->assertCount(0, $this->reports());
    }

    public function test_multi_entry_invalid_date_rejects_only_that_group() {
        $csv = "entry_group,report_date,work_description\n"
             . "G1,2026-04-25,ok1\n"
             . "G2,bad,ok2\n"
             . "G3,2026-04-27,ok3\n";

        $r = DRWP_CSV_Import::import_csv_string($csv, 1);
        $this->assertSame(2, $r['created']);
        $this->assertCount(1, $r['errors']);
        $this->assertCount(2, $this->reports());
    }

    public function test_utf8_bom_is_stripped() {
        $csv = "\xEF\xBB\xBFreport_date,work_description\n2026-04-25,ok\n";
        $r = DRWP_CSV_Import::import_csv_string($csv, 1);
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['created']);
    }
}
