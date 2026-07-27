<?php
/**
 * @covers DRWP_Plan_Import
 */
class Test_DRWP_Plan_Import extends WP_UnitTestCase {

    /* ---------- 日付の正規化 ---------- */

    public function test_normalize_date_accepts_cybozu_slash_format() {
        $this->assertSame('2026-07-01', DRWP_Plan_Import::normalize_date('2026/7/1'));
        $this->assertSame('2026-07-01', DRWP_Plan_Import::normalize_date('2026/07/01'));
    }

    public function test_normalize_date_accepts_hyphen_and_japanese() {
        $this->assertSame('2026-12-31', DRWP_Plan_Import::normalize_date('2026-12-31'));
        $this->assertSame('2026-12-31', DRWP_Plan_Import::normalize_date('2026年12月31日'));
    }

    public function test_normalize_date_ignores_trailing_time_and_weekday() {
        $this->assertSame('2026-07-01', DRWP_Plan_Import::normalize_date('2026/7/1 9:00'));
        $this->assertSame('2026-07-01', DRWP_Plan_Import::normalize_date('2026/7/1(水)'));
    }

    public function test_normalize_date_accepts_compact_form() {
        $this->assertSame('2026-07-01', DRWP_Plan_Import::normalize_date('20260701'));
    }

    public function test_normalize_date_rejects_garbage_and_impossible_dates() {
        $this->assertNull(DRWP_Plan_Import::normalize_date(''));
        $this->assertNull(DRWP_Plan_Import::normalize_date('未定'));
        // 2月30日は存在しない
        $this->assertNull(DRWP_Plan_Import::normalize_date('2026/2/30'));
    }

    /* ---------- 時刻の正規化 ---------- */

    public function test_normalize_time_pads_and_adds_seconds() {
        $this->assertSame('09:00:00', DRWP_Plan_Import::normalize_time('9:00'));
        $this->assertSame('09:05:30', DRWP_Plan_Import::normalize_time('09:05:30'));
    }

    public function test_normalize_time_strips_leading_date() {
        $this->assertSame('13:30:00', DRWP_Plan_Import::normalize_time('2026/7/1 13:30'));
    }

    public function test_normalize_time_accepts_japanese_notation() {
        $this->assertSame('13:30:00', DRWP_Plan_Import::normalize_time('13時30分'));
    }

    public function test_normalize_time_rounds_24_00_into_range() {
        $this->assertSame('23:59:00', DRWP_Plan_Import::normalize_time('24:00'));
    }

    public function test_normalize_time_returns_null_when_absent_or_invalid() {
        $this->assertNull(DRWP_Plan_Import::normalize_time(''));
        $this->assertNull(DRWP_Plan_Import::normalize_time('終日'));
        $this->assertNull(DRWP_Plan_Import::normalize_time('99:99'));
    }

    /* ---------- CSV 解析 ---------- */

    public function test_parse_csv_reads_headers_and_rows() {
        $csv = "開始日,開始時刻,予定\n2026/7/1,9:00,現場打合せ\n2026/7/2,10:00,検査\n";
        $out = DRWP_Plan_Import::parse_csv($csv);
        $this->assertSame(['開始日', '開始時刻', '予定'], $out['headers']);
        $this->assertCount(2, $out['rows']);
        $this->assertSame(['2026/7/2', '10:00', '検査'], $out['rows'][1]);
    }

    public function test_parse_csv_handles_crlf_and_blank_lines() {
        $csv = "開始日,予定\r\n2026/7/1,A\r\n\r\n2026/7/2,B\r\n";
        $out = DRWP_Plan_Import::parse_csv($csv);
        $this->assertCount(2, $out['rows']);
    }

    public function test_parse_csv_strips_utf8_bom() {
        $csv = "\xEF\xBB\xBF開始日,予定\n2026/7/1,A\n";
        $out = DRWP_Plan_Import::parse_csv($csv);
        $this->assertSame('開始日', $out['headers'][0]);
    }

    public function test_parse_csv_converts_shift_jis() {
        if (!function_exists('mb_convert_encoding')) {
            $this->markTestSkipped('mbstring が無い環境');
        }
        $utf8 = "開始日,予定\n2026/7/1,現場打合せ\n";
        $sjis = mb_convert_encoding($utf8, 'SJIS-win', 'UTF-8');
        $out  = DRWP_Plan_Import::parse_csv($sjis);
        $this->assertSame(['開始日', '予定'], $out['headers']);
        $this->assertSame('現場打合せ', $out['rows'][0][1]);
    }

    /* ---------- 列の自動対応付け ---------- */

    public function test_guess_mapping_matches_japanese_headers() {
        $map = DRWP_Plan_Import::guess_mapping(['開始日', '開始時刻', '終了時刻', '予定', 'メモ', '参加者']);
        $this->assertSame(0, $map['planned_date']);
        $this->assertSame(1, $map['started_at']);
        $this->assertSame(2, $map['ended_at']);
        $this->assertSame(3, $map['title']);
        $this->assertSame(4, $map['notes']);
        $this->assertSame(5, $map['assignee']);
    }

    public function test_guess_mapping_never_assigns_one_column_twice() {
        $map = DRWP_Plan_Import::guess_mapping(['開始日', '開始時刻', '終了時刻']);
        $this->assertSame(count($map), count(array_unique($map)));
    }

    public function test_guess_mapping_omits_fields_with_no_match() {
        $map = DRWP_Plan_Import::guess_mapping(['開始日']);
        $this->assertArrayHasKey('planned_date', $map);
        $this->assertArrayNotHasKey('assignee', $map);
    }

    /* ---------- 行の変換 ---------- */

    private function mapping() {
        return ['planned_date' => 0, 'started_at' => 1, 'ended_at' => 2, 'title' => 3, 'notes' => 4, 'assignee' => 5];
    }

    public function test_build_row_normalizes_values() {
        $row = DRWP_Plan_Import::build_row(
            ['2026/7/1', '9:00', '10:30', '現場打合せ', '図面持参', '山田 太郎'],
            $this->mapping()
        );
        $this->assertNull($row['error']);
        $this->assertSame('2026-07-01', $row['data']['planned_date']);
        $this->assertSame('09:00:00', $row['data']['started_at']);
        $this->assertSame('10:30:00', $row['data']['ended_at']);
        $this->assertSame("現場打合せ\n図面持参", $row['data']['notes']);
        $this->assertSame('山田 太郎', $row['assignee']);
    }

    public function test_build_row_reports_error_when_date_unreadable() {
        $row = DRWP_Plan_Import::build_row(['未定', '', '', 'A', '', ''], $this->mapping());
        $this->assertNotNull($row['error']);
        $this->assertSame([], $row['data']);
    }

    public function test_build_row_treats_missing_times_as_all_day() {
        $row = DRWP_Plan_Import::build_row(['2026/7/1', '', '', '全社会議', '', ''], $this->mapping());
        $this->assertNull($row['data']['started_at']);
        $this->assertNull($row['data']['ended_at']);
    }

    public function test_build_row_swaps_reversed_times() {
        $row = DRWP_Plan_Import::build_row(['2026/7/1', '17:00', '9:00', 'A', '', ''], $this->mapping());
        $this->assertSame('09:00:00', $row['data']['started_at']);
        $this->assertSame('17:00:00', $row['data']['ended_at']);
    }

    /* ---------- 重複防止キー ---------- */

    public function test_external_id_is_stable_for_identical_rows() {
        $a = DRWP_Plan_Import::external_id('', '2026-07-01', '09:00:00', 'A', '山田');
        $b = DRWP_Plan_Import::external_id('', '2026-07-01', '09:00:00', 'A', '山田');
        $this->assertSame($a, $b);
    }

    public function test_external_id_differs_when_content_differs() {
        $a = DRWP_Plan_Import::external_id('', '2026-07-01', '09:00:00', 'A', '山田');
        $b = DRWP_Plan_Import::external_id('', '2026-07-02', '09:00:00', 'A', '山田');
        $this->assertNotSame($a, $b);
    }

    public function test_external_id_prefers_source_id_when_given() {
        $this->assertSame('evt-123', DRWP_Plan_Import::external_id('evt-123', '2026-07-01', '09:00:00', 'A', '山田'));
    }

    public function test_external_id_always_fits_the_column() {
        $long = str_repeat('x', 300);
        $this->assertLessThanOrEqual(64, strlen(DRWP_Plan_Import::external_id($long, '2026-07-01', null, '', '')));
        $this->assertLessThanOrEqual(64, strlen(DRWP_Plan_Import::external_id('', '2026-07-01', null, '', '')));
    }

    /* ---------- 担当者の照合 ---------- */

    public function test_normalize_person_ignores_spacing() {
        $this->assertSame(
            DRWP_Plan_Import::normalize_person('山田太郎'),
            DRWP_Plan_Import::normalize_person('山田　太郎')
        );
    }

    public function test_normalize_person_takes_first_of_multiple_participants() {
        $this->assertSame(
            DRWP_Plan_Import::normalize_person('山田太郎'),
            DRWP_Plan_Import::normalize_person('山田 太郎, 佐藤 花子')
        );
    }

    public function test_user_index_matches_display_name() {
        $uid = self::factory()->user->create([
            'role'         => 'editor',
            'display_name' => '山田 太郎',
        ]);
        $index = DRWP_Plan_Import::user_index();
        $key   = DRWP_Plan_Import::normalize_person('山田太郎');
        $this->assertSame($uid, $index[$key] ?? 0);
    }
}
