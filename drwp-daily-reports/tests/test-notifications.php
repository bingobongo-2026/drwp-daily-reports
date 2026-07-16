<?php
/**
 * @covers DRWP_Notifications
 */
class Test_DRWP_Notifications extends WP_UnitTestCase {

    /** @var array<int, array{to: array, subject: string, message: string, headers: array}> */
    public static $sent;

    public function set_up() {
        parent::set_up();
        self::$sent = [];

        // Capture wp_mail calls instead of actually sending. Normalize 'to'
        // to an array so the same assertions work whether the caller passed
        // a string or a list.
        add_filter('pre_wp_mail', [self::class, 'capture'], 10, 2);

        global $wpdb;
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'drwp_reports');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'drwp_audit_logs');

        foreach ([
            DRWP_Notifications::OPT_ENABLED,
            DRWP_Notifications::OPT_ON_PENDING,
            DRWP_Notifications::OPT_ON_REVIEW,
            DRWP_Notifications::OPT_ON_COMMENT,
            DRWP_Notifications::OPT_FROM_EMAIL,
        ] as $opt) {
            delete_option($opt);
        }
    }

    public function tear_down() {
        remove_filter('pre_wp_mail', [self::class, 'capture'], 10);
        parent::tear_down();
    }

    public static function capture($null, $atts) {
        $atts['to'] = (array) $atts['to'];
        self::$sent[] = $atts;
        return true; // short-circuits wp_mail to a successful send
    }

    private function reviewer() {
        return self::factory()->user->create(['role' => 'editor']);
    }

    private function reporter() {
        $id = self::factory()->user->create(['role' => 'subscriber']);
        $u = new WP_User($id);
        $u->add_cap('edit_posts');
        return $id;
    }

    private function email_of($user_id) {
        return (string) get_user_by('id', $user_id)->user_email;
    }

    private function make_report($author_id, array $extra = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';
        $wpdb->insert($table, array_merge([
            'user_id' => $author_id,
            'report_date' => '2026-04-25',
            'public_title' => 'タイトル',
            'work_description' => 'desc',
            'review_status' => 'pending',
        ], $extra));
        return (int) $wpdb->insert_id;
    }

    public function test_master_switch_off_suppresses_all_paths() {
        update_option(DRWP_Notifications::OPT_ENABLED, '0');
        $this->reviewer();
        $author = $this->reporter();
        $id = $this->make_report($author);
        $report = (object) ['user_id' => $author, 'public_title' => 't', 'review_status' => 'pending', 'report_date' => '2026-04-25'];

        do_action('drwp_report_submitted', $id, $report);
        do_action('drwp_review_changed', $id, 'pending', 'approved', '');
        do_action('drwp_comment_added', $id, 1, 'hi');

        $this->assertSame([], self::$sent);
    }

    public function test_pending_submission_emails_reviewers() {
        $rev = $this->reviewer();
        $author = $this->reporter();
        $id = $this->make_report($author);
        $report = (object) ['user_id' => $author, 'public_title' => 'タイトル', 'review_status' => 'pending', 'report_date' => '2026-04-25'];

        do_action('drwp_report_submitted', $id, $report);

        $this->assertCount(1, self::$sent);
        $this->assertContains($this->email_of($rev), self::$sent[0]['to']);
        $this->assertStringContainsString('承認待ち', self::$sent[0]['subject']);
    }

    public function test_pending_submission_skipped_for_non_pending_state() {
        $this->reviewer();
        $author = $this->reporter();
        $id = $this->make_report($author);
        $report = (object) ['user_id' => $author, 'public_title' => 't', 'review_status' => 'approved'];

        do_action('drwp_report_submitted', $id, $report);

        $this->assertSame([], self::$sent);
    }

    public function test_pending_submission_skipped_when_toggle_off() {
        update_option(DRWP_Notifications::OPT_ON_PENDING, '0');
        $this->reviewer();
        $author = $this->reporter();
        $id = $this->make_report($author);
        $report = (object) ['user_id' => $author, 'public_title' => 't', 'review_status' => 'pending', 'report_date' => '2026-04-25'];

        do_action('drwp_report_submitted', $id, $report);
        $this->assertSame([], self::$sent);
    }

    public function test_review_changed_to_approved_emails_owner() {
        $this->reviewer();
        $author = $this->reporter();
        $id = $this->make_report($author, ['review_status' => 'approved']);

        do_action('drwp_review_changed', $id, 'pending', 'approved', 'Looks good');

        $this->assertCount(1, self::$sent);
        $this->assertContains($this->email_of($author), self::$sent[0]['to']);
        $this->assertStringContainsString('承認', self::$sent[0]['subject']);
        $this->assertStringContainsString('Looks good', self::$sent[0]['message']);
    }

    public function test_review_changed_to_pending_does_not_send() {
        $this->reviewer();
        $author = $this->reporter();
        $id = $this->make_report($author, ['review_status' => 'pending']);

        do_action('drwp_review_changed', $id, 'approved', 'pending', '');
        $this->assertSame([], self::$sent);
    }

    public function test_review_changed_skipped_when_toggle_off() {
        update_option(DRWP_Notifications::OPT_ON_REVIEW, '0');
        $this->reviewer();
        $author = $this->reporter();
        $id = $this->make_report($author, ['review_status' => 'approved']);

        do_action('drwp_review_changed', $id, 'pending', 'approved', '');
        $this->assertSame([], self::$sent);
    }

    public function test_comment_added_emails_owner_and_reviewers_excluding_commenter() {
        $rev = $this->reviewer();
        $author = $this->reporter();
        $id = $this->make_report($author);

        // Reviewer is the commenter — they should NOT be a recipient.
        wp_set_current_user($rev);
        do_action('drwp_comment_added', $id, 1, 'A reviewer comment');

        $this->assertCount(1, self::$sent);
        $to = self::$sent[0]['to'];
        $this->assertContains($this->email_of($author), $to);
        $this->assertNotContains($this->email_of($rev), $to);
    }

    public function test_comment_excludes_owner_when_owner_is_commenter() {
        // Two reviewers + an author. Author posts the comment.
        $r1 = $this->reviewer();
        $r2 = $this->reviewer();
        $author = $this->reporter();
        $id = $this->make_report($author);
        wp_set_current_user($author);

        do_action('drwp_comment_added', $id, 1, 'self');

        $this->assertCount(1, self::$sent);
        $to = self::$sent[0]['to'];
        $this->assertNotContains($this->email_of($author), $to);
        $this->assertContains($this->email_of($r1), $to);
        $this->assertContains($this->email_of($r2), $to);
    }

    public function test_audit_event_recorded_per_send() {
        $this->reviewer();
        $author = $this->reporter();
        $id = $this->make_report($author);
        $report = (object) ['user_id' => $author, 'public_title' => 't', 'review_status' => 'pending', 'report_date' => '2026-04-25'];

        do_action('drwp_report_submitted', $id, $report);

        $logs = DRWP_Audit::for_report($id);
        $events = wp_list_pluck($logs, 'event');
        $this->assertContains('notification_sent', $events);
    }

    public function test_save_and_load_settings_round_trip() {
        DRWP_Notifications::save_settings([
            'enabled'    => true,
            'on_pending' => false,
            'on_review'  => true,
            'on_comment' => false,
            'from_email' => 'noreply@example.test',
        ]);
        $s = DRWP_Notifications::settings();
        $this->assertTrue($s['enabled']);
        $this->assertFalse($s['on_pending']);
        $this->assertTrue($s['on_review']);
        $this->assertFalse($s['on_comment']);
        $this->assertSame('noreply@example.test', $s['from_email']);
    }

    public function test_from_header_only_added_when_email_is_valid() {
        update_option(DRWP_Notifications::OPT_FROM_EMAIL, 'noreply@example.test');
        $this->reviewer();
        $author = $this->reporter();
        $id = $this->make_report($author);
        $report = (object) ['user_id' => $author, 'public_title' => 't', 'review_status' => 'pending', 'report_date' => '2026-04-25'];

        do_action('drwp_report_submitted', $id, $report);
        $headers = self::$sent[0]['headers'];
        $joined = implode("\n", $headers);
        $this->assertStringContainsString('From:', $joined);
        $this->assertStringContainsString('noreply@example.test', $joined);
    }
}
