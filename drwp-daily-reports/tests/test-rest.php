<?php
/**
 * @covers DRWP_REST
 */
class Test_DRWP_REST extends WP_UnitTestCase {

    private function activate_license() {
        update_option(DRWP_License::OPT_STATUS, 'active');
    }

    private function deactivate_license() {
        delete_option(DRWP_License::OPT_STATUS);
        delete_option(DRWP_License::OPT_LAST_VALID_AT);
    }

    private function make_admin() {
        $id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($id);
        return $id;
    }

    private function make_subscriber_with_edit() {
        $id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($id);
        $user = wp_get_current_user();
        $user->add_cap('edit_posts');
        return $id;
    }

    private function call($method, $route, $body = null) {
        $req = new WP_REST_Request($method, $route);
        if ($body !== null) {
            $req->set_header('Content-Type', 'application/json');
            $req->set_body(wp_json_encode($body));
        }
        return rest_do_request($req);
    }

    public function set_up() {
        parent::set_up();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'drwp_reports');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'drwp_comments');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'drwp_audit_logs');
        $this->deactivate_license();
    }

    public function test_unauthenticated_list_is_rejected() {
        wp_set_current_user(0);
        $resp = $this->call('GET', '/drwp/v1/reports');
        $this->assertSame(401, $resp->get_status());
    }

    public function test_create_without_license_returns_402_with_reason() {
        $this->make_admin();
        $resp = $this->call('POST', '/drwp/v1/reports', [
            'report_date' => '2026-04-25',
            'work_description' => 'x',
        ]);
        $this->assertSame(402, $resp->get_status());

        $data = $resp->get_data();
        $this->assertSame('drwp_license', $data['code']);
        $this->assertSame('not_configured', $data['data']['reason']);
        $this->assertArrayHasKey('settings_url', $data['data']);
        $this->assertArrayHasKey('license_status', $data['data']);
    }

    public function test_create_with_invalid_date_returns_400() {
        $this->make_admin();
        $this->activate_license();
        $resp = $this->call('POST', '/drwp/v1/reports', [
            'report_date' => 'not-a-date',
            'work_description' => 'x',
        ]);
        $this->assertSame(400, $resp->get_status());
        $this->assertSame('drwp_invalid_date', $resp->get_data()['code']);
    }

    public function test_create_then_get_then_update() {
        $this->make_admin();
        $this->activate_license();

        $created = $this->call('POST', '/drwp/v1/reports', [
            'report_date' => '2026-04-25',
            'work_description' => 'desc',
            'public_title' => 'タイトル',
            'public_body' => '本文',
        ]);
        $this->assertSame(201, $created->get_status());
        $id = $created->get_data()['id'];

        $read = $this->call('GET', "/drwp/v1/reports/$id");
        $this->assertSame(200, $read->get_status());
        $this->assertSame('タイトル', $read->get_data()['public_title']);

        $patched = $this->call('PATCH', "/drwp/v1/reports/$id", [
            'public_body' => '更新',
        ]);
        $this->assertSame(200, $patched->get_status());
        $this->assertSame('更新', $patched->get_data()['public_body']);
    }

    public function test_review_endpoint_requires_edit_others_posts() {
        $this->make_admin();
        $this->activate_license();
        $created = $this->call('POST', '/drwp/v1/reports', [
            'report_date' => '2026-04-25',
            'work_description' => 'x',
        ]);
        $id = $created->get_data()['id'];

        // Subscriber-with-edit can't review.
        $this->make_subscriber_with_edit();
        $resp = $this->call('POST', "/drwp/v1/reports/$id/review", [
            'review_status' => 'approved',
        ]);
        $this->assertSame(403, $resp->get_status());

        // Admin can.
        $this->make_admin();
        $ok = $this->call('POST', "/drwp/v1/reports/$id/review", [
            'review_status' => 'approved',
            'comment' => 'good',
        ]);
        $this->assertSame(200, $ok->get_status());
        $this->assertSame('approved', $ok->get_data()['review_status']);

        $comments = $this->call('GET', "/drwp/v1/reports/$id/comments")->get_data();
        $this->assertCount(1, $comments['items']);
        $this->assertSame('good', $comments['items'][0]['body']);
    }

    public function test_subscriber_only_sees_own_reports_in_list() {
        $admin_id = $this->make_admin();
        $this->activate_license();
        // Admin creates one report.
        $this->call('POST', '/drwp/v1/reports', [
            'report_date' => '2026-04-25',
            'work_description' => 'admin row',
        ]);

        // Subscriber-with-edit creates one of their own.
        $this->make_subscriber_with_edit();
        $this->call('POST', '/drwp/v1/reports', [
            'report_date' => '2026-04-25',
            'work_description' => 'sub row',
        ]);

        // Subscriber sees only their own.
        $sub_list = $this->call('GET', '/drwp/v1/reports')->get_data();
        $this->assertSame(1, $sub_list['total']);
        $this->assertSame('sub row', $sub_list['items'][0]['work_description']);

        // Admin (edit_others_posts) sees both.
        wp_set_current_user($admin_id);
        $admin_list = $this->call('GET', '/drwp/v1/reports')->get_data();
        $this->assertSame(2, $admin_list['total']);
    }

    public function test_license_endpoint_redacts_secrets() {
        $this->make_admin();
        update_option(DRWP_License::OPT_KEY, 'SECRET-KEY');
        update_option(DRWP_License::OPT_PUBLIC_KEY, 'BASE64==');
        $data = $this->call('GET', '/drwp/v1/license')->get_data();
        $this->assertArrayNotHasKey('license_key', $data);
        $this->assertArrayNotHasKey('public_key', $data);
        $this->assertArrayHasKey('status', $data);
    }
}
