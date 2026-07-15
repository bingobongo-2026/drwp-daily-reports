<?php
/**
 * @covers DRWP_AI
 */
class Test_DRWP_AI extends WP_UnitTestCase {

    // 差し替えバックエンド (下の匿名クラス) から読むため public。
    // private だと匿名クラスは別クラス扱いでアクセスできず
    // 「Cannot access private property」エラーになる。
    public $fake_response = '';

    // 直近に AI バックエンドへ渡された messages を記録して、プロンプト
    // 内容 (市区町村・イニシャル化ルール等) を検証できるようにする。
    public $last_messages = [];

    public function set_up() {
        parent::set_up();
        update_option(DRWP_AI::OPT_ENABLED, 'yes');
        global $wpdb;
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'drwp_reports');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'drwp_projects');
        // Substitute a backend that returns whatever we stage, so the
        // tests never touch the network.
        $self = $this;
        add_filter('drwp_ai_backend', function () use ($self) {
            return new class($self) implements DRWP_AI_Backend {
                private $t;
                public function __construct($t) { $this->t = $t; }
                public function chat(array $messages, array $opts = []) { $this->t->last_messages = $messages; return $this->t->fake_response; }
                public function test_connection() { return ['models' => ['fake']]; }
            };
        });
    }

    public function tear_down() {
        delete_option(DRWP_AI::OPT_ENABLED);
        parent::tear_down();
    }

    private function make_project($name = '案件X') {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'drwp_projects', ['name' => $name, 'status' => 'active']);
        return (int) $wpdb->insert_id;
    }

    private function make_report($project_id, $date, $fields = []) {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'drwp_reports', array_merge([
            'project_id'  => $project_id,
            'user_id'     => 1,
            'report_date' => $date,
            'review_status' => 'approved',
        ], $fields));
        return (int) $wpdb->insert_id;
    }

    public function test_draft_public_post_parses_delimited_blocks() {
        $pid = $this->make_project();
        $rid = $this->make_report($pid, '2026-06-01', ['work_description' => '外壁を補修した']);
        $this->fake_response = "===TITLE===\n外壁補修レポート\n===INTRO===\n本日の作業です。\n===BODY===\n外壁を丁寧に補修しました。\n===NEXT===\n来週は塗装です。";
        $out = DRWP_AI::draft_public_post($rid);
        $this->assertSame('外壁補修レポート', $out['public_title']);
        $this->assertSame('本日の作業です。', $out['public_intro']);
        $this->assertStringContainsString('補修しました', $out['public_body']);
        $this->assertStringContainsString('塗装', $out['public_next_plan']);
    }

    public function test_draft_public_post_prompt_carries_city_and_initialization_rule() {
        global $wpdb;
        $pid = $this->make_project('斎藤邸');
        // 案件に市区町村をセット (make_project は name/status のみ入れる)。
        $wpdb->update($wpdb->prefix . 'drwp_projects', ['city' => '静岡市'], ['id' => $pid]);
        $rid = $this->make_report($pid, '2026-06-25', ['work_description' => 'クロス張替え']);
        $this->fake_response = "===TITLE===\nx\n===BODY===\ny";
        DRWP_AI::draft_public_post($rid);

        $system = (string) ($this->last_messages[0]['content'] ?? '');
        $user   = (string) ($this->last_messages[1]['content'] ?? '');
        // プロンプトにイニシャル化ルールと市区町村ルールが含まれること。
        $this->assertStringContainsString('イニシャル化', $system);
        $this->assertStringContainsString('市区町村', $system);
        // ユーザーメッセージに案件の市区町村が渡ること。
        $this->assertStringContainsString('静岡市', $user);
    }

    public function test_draft_public_post_without_delimiters_falls_back_to_body() {
        $pid = $this->make_project();
        $rid = $this->make_report($pid, '2026-06-02', ['work_description' => 'x']);
        $this->fake_response = 'プレーンなテキストだけ返ってきた';
        $out = DRWP_AI::draft_public_post($rid);
        $this->assertSame('', $out['public_title']);
        $this->assertSame('プレーンなテキストだけ返ってきた', $out['public_body']);
    }

    public function test_draft_public_post_errors_on_empty_report() {
        $pid = $this->make_project();
        $rid = $this->make_report($pid, '2026-06-03'); // no work/issues/next
        $out = DRWP_AI::draft_public_post($rid);
        $this->assertWPError($out);
        $this->assertSame('drwp_ai_no_source', $out->get_error_code());
    }

    public function test_project_summary_errors_when_no_reports_in_range() {
        $pid = $this->make_project();
        $this->make_report($pid, '2026-01-15', ['work_description' => 'a']);
        $out = DRWP_AI::project_summary($pid, '2026-06-01', '2026-06-30', '2026年6月');
        $this->assertWPError($out);
        $this->assertSame('drwp_ai_no_reports', $out->get_error_code());
    }

    public function test_project_summary_happy_path_returns_text() {
        $pid = $this->make_project();
        $this->make_report($pid, '2026-06-10', ['work_description' => '基礎工事', 'issues' => '雨で中断']);
        $this->fake_response = '## 期間中の主な作業\n- 基礎工事';
        $out = DRWP_AI::project_summary($pid, '2026-06-01', '2026-06-30', '2026年6月');
        $this->assertIsString($out);
        $this->assertStringContainsString('基礎工事', $out);
    }

    public function test_extract_alerts_only_considers_reports_with_issues() {
        $pid = $this->make_project();
        $this->make_report($pid, '2026-06-20', ['work_description' => '通常作業']); // no issues
        $out = DRWP_AI::extract_alerts('2026-06-01', '2026-06-30', $pid);
        $this->assertWPError($out);
        $this->assertSame('drwp_ai_no_reports', $out->get_error_code());
    }

    public function test_extract_alerts_happy_path() {
        $pid = $this->make_project();
        $this->make_report($pid, '2026-06-21', ['issues' => '追加工事の相談あり']);
        $this->fake_response = '- 🟡 追加工事の相談 (日報#1)';
        $out = DRWP_AI::extract_alerts('2026-06-01', '2026-06-30', $pid);
        $this->assertIsString($out);
        $this->assertStringContainsString('追加工事', $out);
    }

    public function test_advise_on_reports_errors_when_ids_are_empty() {
        $out = DRWP_AI::advise_on_reports([]);
        $this->assertWPError($out);
        $this->assertSame('drwp_ai_no_reports', $out->get_error_code());
    }

    public function test_advise_on_reports_returns_string_for_valid_ids() {
        $pid = $this->make_project();
        $r1 = $this->make_report($pid, '2026-07-01', ['work_description' => '丁寧に下処理した']);
        $r2 = $this->make_report($pid, '2026-07-02', ['issues' => '材料が足りず途中で中断']);
        $this->fake_response = "## 成功例から見えるパターン\n- 下処理が成功要因 (日報#" . $r1 . ")";
        $out = DRWP_AI::advise_on_reports([$r1, $r2]);
        $this->assertIsString($out);
        $this->assertStringContainsString('成功例', $out);
    }

    public function test_advise_on_reports_caps_at_ADVISE_MAX() {
        // 60 件以上渡しても先頭 60 件で打ち切られ、エラーにはならない。
        $pid = $this->make_project();
        $ids = [];
        for ($i = 0; $i < DRWP_AI::ADVISE_MAX + 5; $i++) {
            $ids[] = $this->make_report($pid, '2026-08-' . str_pad((($i % 28) + 1), 2, '0', STR_PAD_LEFT), [
                'work_description' => 'x' . $i,
            ]);
        }
        $this->fake_response = '## 成功例から見えるパターン\n- ok';
        $out = DRWP_AI::advise_on_reports($ids);
        $this->assertIsString($out);
    }
}
