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

    public function test_parse_csv_keeps_newlines_inside_quoted_cells() {
        // サイボウズは参加者やメモをセル内改行で書き出す
        $csv = "開始日,参加者\n2026/8/3,\"中村勝俊\n武井亮太\"\n";
        $out = DRWP_Plan_Import::parse_csv($csv);
        $this->assertCount(1, $out['rows']);
        $this->assertSame("中村勝俊\n武井亮太", $out['rows'][0][1]);
    }

    /* ---------- 列の自動対応付け ---------- */

    /** サイボウズ Office の実際の書き出しヘッダー */
    private function office_headers() {
        return ['開始日付', '開始時刻', '終了日付', '終了時刻', '予定', '予定詳細', 'メモ', '参加者', '施設'];
    }

    public function test_guess_mapping_matches_real_cybozu_office_headers() {
        $map = DRWP_Plan_Import::guess_mapping($this->office_headers());
        $this->assertSame(0, $map['planned_date']);
        $this->assertSame(1, $map['started_at']);
        $this->assertSame(2, $map['end_date']);
        $this->assertSame(3, $map['ended_at']);
        $this->assertSame(4, $map['category']);
        $this->assertSame(5, $map['title']);
        $this->assertSame(6, $map['notes']);
        $this->assertSame(7, $map['assignee']);
    }

    public function test_guess_mapping_never_assigns_one_column_twice() {
        $map = DRWP_Plan_Import::guess_mapping($this->office_headers());
        $this->assertSame(count($map), count(array_unique($map)));
    }

    public function test_guess_mapping_omits_fields_with_no_match() {
        $map = DRWP_Plan_Import::guess_mapping(['開始日']);
        $this->assertArrayHasKey('planned_date', $map);
        $this->assertArrayNotHasKey('assignee', $map);
    }

    /* ---------- 参加者の分解 ---------- */

    public function test_split_people_splits_on_newlines() {
        // サイボウズ Office は参加者を改行区切りで書き出す
        $this->assertSame(
            ['中村勝俊', '武井亮太', '田中隆之'],
            DRWP_Plan_Import::split_people("中村勝俊\n武井亮太\n田中隆之")
        );
    }

    public function test_split_people_splits_on_crlf_and_other_separators() {
        $this->assertSame(['A', 'B'], DRWP_Plan_Import::split_people("A\r\nB"));
        $this->assertSame(['A', 'B'], DRWP_Plan_Import::split_people('A, B'));
        $this->assertSame(['A', 'B'], DRWP_Plan_Import::split_people('A、B'));
    }

    public function test_split_people_drops_empties_and_dupes() {
        $this->assertSame([], DRWP_Plan_Import::split_people(''));
        $this->assertSame([], DRWP_Plan_Import::split_people("\n\n"));
        $this->assertSame(['A'], DRWP_Plan_Import::split_people("A\nA"));
    }

    /* ---------- メモの組み立て ---------- */

    public function test_compose_notes_prefixes_category_and_keeps_memo() {
        $this->assertSame(
            "【作業】アルク\n複合機移設",
            DRWP_Plan_Import::compose_notes('作業', 'アルク', '複合機移設')
        );
    }

    public function test_compose_notes_skips_missing_parts() {
        $this->assertSame("アルク\nbeat", DRWP_Plan_Import::compose_notes('', 'アルク', 'beat'));
        $this->assertSame('【休み】夏季休暇', DRWP_Plan_Import::compose_notes('休み', '夏季休暇', ''));
        $this->assertSame('', DRWP_Plan_Import::compose_notes('', '', ''));
    }

    /* ---------- 日付範囲 ---------- */

    public function test_date_range_single_day() {
        $this->assertSame(['2026-08-10'], DRWP_Plan_Import::date_range('2026-08-10', '2026-08-10'));
    }

    public function test_date_range_spans_days_and_months() {
        $this->assertSame(
            ['2026-08-10', '2026-08-11', '2026-08-12'],
            DRWP_Plan_Import::date_range('2026-08-10', '2026-08-12')
        );
        $this->assertSame(
            ['2026-08-31', '2026-09-01'],
            DRWP_Plan_Import::date_range('2026-08-31', '2026-09-01')
        );
    }

    public function test_date_range_returns_null_when_too_long() {
        $this->assertNull(DRWP_Plan_Import::date_range('2026-01-01', '2027-01-01'));
    }

    /* ---------- 行の展開 ---------- */

    /** サイボウズ Office の実際の列順に合わせた対応付け */
    private function mapping() {
        return [
            'planned_date' => 0, 'started_at' => 1, 'end_date' => 2, 'ended_at' => 3,
            'category' => 4, 'title' => 5, 'notes' => 6, 'assignee' => 7,
        ];
    }

    public function test_build_row_expands_one_plan_per_participant() {
        // 参加者3人 → 3件。1件しか作らないと2人分の予定が消える。
        $row = DRWP_Plan_Import::build_row(
            ['2026/8/3', '', '2026/8/3', '', '作業', 'アルク', '複合機移設', "中村勝俊\n武井亮太\n田中隆之", ''],
            $this->mapping()
        );
        $this->assertNull($row['error']);
        $this->assertCount(3, $row['plans']);
        $this->assertSame(['中村勝俊', '武井亮太', '田中隆之'], array_column($row['plans'], 'person'));
        foreach ($row['plans'] as $plan) {
            $this->assertSame('2026-08-03', $plan['planned_date']);
            $this->assertSame("【作業】アルク\n複合機移設", $plan['notes']);
        }
    }

    public function test_build_row_keeps_one_plan_when_nobody_listed() {
        $row = DRWP_Plan_Import::build_row(
            ['2026/8/3', '', '', '', '作業', 'アルク', '', '', ''],
            $this->mapping()
        );
        $this->assertCount(1, $row['plans']);
        $this->assertSame('', $row['plans'][0]['person']);
    }

    public function test_build_row_normalizes_times() {
        $row = DRWP_Plan_Import::build_row(
            ['2026/8/4', '16:00:00', '2026/8/4', '18:00:00', '研修', 'アルク', '', '田中隆之', ''],
            $this->mapping()
        );
        $this->assertSame('16:00:00', $row['plans'][0]['started_at']);
        $this->assertSame('18:00:00', $row['plans'][0]['ended_at']);
    }

    public function test_build_row_treats_missing_times_as_all_day() {
        $row = DRWP_Plan_Import::build_row(
            ['2026/8/10', '', '', '', '休み', '夏季休暇', '', '中村勝俊', ''],
            $this->mapping()
        );
        $this->assertNull($row['plans'][0]['started_at']);
        $this->assertNull($row['plans'][0]['ended_at']);
    }

    public function test_build_row_swaps_reversed_times_on_single_day() {
        $row = DRWP_Plan_Import::build_row(
            ['2026/8/4', '17:00', '2026/8/4', '9:00', '', '', '', 'A', ''],
            $this->mapping()
        );
        $this->assertSame('09:00:00', $row['plans'][0]['started_at']);
        $this->assertSame('17:00:00', $row['plans'][0]['ended_at']);
    }

    public function test_build_row_expands_multi_day_events() {
        // 3日間 × 1人 = 3件。初日は開始時刻、最終日は終了時刻、間は終日。
        $row = DRWP_Plan_Import::build_row(
            ['2026/8/10', '9:00', '2026/8/12', '17:00', '休み', '夏季休暇', '', '中村勝俊', ''],
            $this->mapping()
        );
        $this->assertCount(3, $row['plans']);
        $this->assertSame(
            ['2026-08-10', '2026-08-11', '2026-08-12'],
            array_column($row['plans'], 'planned_date')
        );
        $this->assertSame('09:00:00', $row['plans'][0]['started_at']);
        $this->assertNull($row['plans'][0]['ended_at']);
        $this->assertNull($row['plans'][1]['started_at']);
        $this->assertNull($row['plans'][1]['ended_at']);
        $this->assertNull($row['plans'][2]['started_at']);
        $this->assertSame('17:00:00', $row['plans'][2]['ended_at']);
    }

    public function test_build_row_multiplies_people_by_days() {
        $row = DRWP_Plan_Import::build_row(
            ['2026/8/10', '', '2026/8/11', '', '', '出張', '', "A\nB", ''],
            $this->mapping()
        );
        $this->assertCount(4, $row['plans']); // 2人 × 2日
    }

    public function test_build_row_ignores_end_date_before_start() {
        $row = DRWP_Plan_Import::build_row(
            ['2026/8/10', '', '2026/8/1', '', '', 'X', '', 'A', ''],
            $this->mapping()
        );
        $this->assertCount(1, $row['plans']);
        $this->assertSame('2026-08-10', $row['plans'][0]['planned_date']);
    }

    public function test_build_row_reports_error_when_date_unreadable() {
        $row = DRWP_Plan_Import::build_row(['未定', '', '', '', '', 'A', '', '', ''], $this->mapping());
        $this->assertNotNull($row['error']);
        $this->assertSame([], $row['plans']);
    }

    public function test_build_row_rejects_absurdly_long_span() {
        $row = DRWP_Plan_Import::build_row(
            ['2026/1/1', '', '2027/1/1', '', '', 'X', '', 'A', ''],
            $this->mapping()
        );
        $this->assertNotNull($row['error']);
        $this->assertSame([], $row['plans']);
    }

    /* ---------- 重複防止キー ---------- */

    public function test_external_id_differs_per_person() {
        // 同じ予定を参加者ごとに分けるので、人が違えば別IDでなければならない
        $a = DRWP_Plan_Import::external_id('', '2026-08-03', null, 'X', '中村勝俊');
        $b = DRWP_Plan_Import::external_id('', '2026-08-03', null, 'X', '武井亮太');
        $this->assertNotSame($a, $b);
    }

    public function test_external_id_differs_per_day() {
        $a = DRWP_Plan_Import::external_id('', '2026-08-10', null, 'X', 'A');
        $b = DRWP_Plan_Import::external_id('', '2026-08-11', null, 'X', 'A');
        $this->assertNotSame($a, $b);
    }

    public function test_external_id_is_stable_for_identical_input() {
        $a = DRWP_Plan_Import::external_id('', '2026-08-03', '09:00:00', 'X', 'A');
        $b = DRWP_Plan_Import::external_id('', '2026-08-03', '09:00:00', 'X', 'A');
        $this->assertSame($a, $b);
    }

    public function test_external_id_with_source_id_survives_content_edits() {
        // サイボウズ側のIDがあるときは本文を鍵に含めない。内容を直しても
        // 同じ行として更新できる。
        $a = DRWP_Plan_Import::external_id('evt-123', '2026-08-03', null, '打合せ', 'A');
        $b = DRWP_Plan_Import::external_id('evt-123', '2026-08-03', null, '打合せ(変更)', 'A');
        $this->assertSame($a, $b);
    }

    public function test_external_id_with_source_id_still_differs_per_person() {
        $a = DRWP_Plan_Import::external_id('evt-123', '2026-08-03', null, 'X', 'A');
        $b = DRWP_Plan_Import::external_id('evt-123', '2026-08-03', null, 'X', 'B');
        $this->assertNotSame($a, $b);
    }

    public function test_external_id_always_fits_the_column() {
        $long = str_repeat('x', 300);
        $this->assertLessThanOrEqual(64, strlen(DRWP_Plan_Import::external_id($long, '2026-08-03', null, '', '')));
        $this->assertLessThanOrEqual(64, strlen(DRWP_Plan_Import::external_id('', '2026-08-03', null, '', '')));
    }

    public function test_expanded_plans_all_get_unique_ids() {
        $row = DRWP_Plan_Import::build_row(
            ['2026/8/6', '10:30', '2026/8/6', '', '納品', 'アルク', 'TOKAI', "中村勝俊\n神田博明\n田中隆之\n杉山晴道", ''],
            $this->mapping()
        );
        $ids = array_column($row['plans'], 'external_id');
        $this->assertCount(4, $ids);
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    /* ---------- 段階間トークン ---------- */

    public function test_make_token_survives_sanitize_key() {
        // 受け取り側は sanitize_key() を通す。小文字化されても変わらない
        // トークンでなければ、トランジェントを引けず取り込みが失敗する。
        for ($i = 0; $i < 50; $i++) {
            $token = DRWP_Plan_Import::make_token();
            $this->assertSame($token, sanitize_key($token), "トークン {$token} が sanitize_key で変化した");
            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);
        }
    }

    public function test_make_token_is_reasonably_unique() {
        $tokens = [];
        for ($i = 0; $i < 100; $i++) {
            $tokens[DRWP_Plan_Import::make_token()] = true;
        }
        $this->assertCount(100, $tokens);
    }

    /* ---------- 担当者の照合 ---------- */

    public function test_normalize_person_ignores_spacing() {
        $this->assertSame(
            DRWP_Plan_Import::normalize_person('山田太郎'),
            DRWP_Plan_Import::normalize_person('山田　太郎')
        );
    }

    public function test_user_index_matches_display_name() {
        $uid = self::factory()->user->create([
            'role'         => 'editor',
            'display_name' => '中村勝俊',
        ]);
        $index = DRWP_Plan_Import::user_index();
        $key   = DRWP_Plan_Import::normalize_person('中村勝俊');
        $this->assertSame($uid, $index[$key] ?? 0);
    }

    public function test_every_participant_of_a_row_resolves_to_a_user() {
        $ids = [];
        foreach (['中村勝俊', '武井亮太', '田中隆之'] as $name) {
            $ids[$name] = self::factory()->user->create(['role' => 'editor', 'display_name' => $name]);
        }
        $row = DRWP_Plan_Import::build_row(
            ['2026/8/3', '', '', '', '作業', 'アルク', '', "中村勝俊\n武井亮太\n田中隆之", ''],
            $this->mapping()
        );
        $index = DRWP_Plan_Import::user_index();
        foreach ($row['plans'] as $plan) {
            $key = DRWP_Plan_Import::normalize_person($plan['person']);
            $this->assertSame($ids[$plan['person']], $index[$key] ?? 0);
        }
    }
}
