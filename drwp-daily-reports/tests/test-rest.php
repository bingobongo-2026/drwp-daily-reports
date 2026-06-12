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

    private function make_project($name) {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'drwp_projects', ['name' => $name, 'status' => 'active']);
        return (int) $wpdb->insert_id;
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
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'drwp_report_photos');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'drwp_projects');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'drwp_plans');
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

    public function test_create_with_linked_plan_id_completes_the_plan() {
        global $wpdb;
        $uid = $this->make_admin();
        $this->activate_license();
        $plans = $wpdb->prefix . 'drwp_plans';
        $wpdb->insert($plans, [
            'planned_date' => '2026-05-01',
            'user_id'      => $uid,
            'created_by'   => $uid,
            'status'       => 'active',
        ]);
        $plan_id = (int) $wpdb->insert_id;

        $created = $this->call('POST', '/drwp/v1/reports', [
            'report_date'     => '2026-05-01',
            'work_description' => 'やった',
            'linked_plan_id'  => $plan_id,
        ]);
        $this->assertSame(201, $created->get_status());
        $report_id = $created->get_data()['id'];

        $plan = $wpdb->get_row($wpdb->prepare("SELECT * FROM $plans WHERE id = %d", $plan_id));
        $this->assertSame((string) $report_id, (string) $plan->linked_report_id);
        $this->assertSame('completed', $plan->status);
    }

    public function test_create_with_linked_plan_id_skips_already_linked_plan() {
        global $wpdb;
        $uid = $this->make_admin();
        $this->activate_license();
        $plans = $wpdb->prefix . 'drwp_plans';
        $wpdb->insert($plans, [
            'planned_date'     => '2026-05-02',
            'user_id'          => $uid,
            'created_by'       => $uid,
            'status'           => 'active',
            'linked_report_id' => 999, // already linked
        ]);
        $plan_id = (int) $wpdb->insert_id;

        $this->call('POST', '/drwp/v1/reports', [
            'report_date'     => '2026-05-02',
            'work_description' => 'x',
            'linked_plan_id'  => $plan_id,
        ]);

        $plan = $wpdb->get_row($wpdb->prepare("SELECT * FROM $plans WHERE id = %d", $plan_id));
        // Untouched: still points at the original report, still active.
        $this->assertSame('999', (string) $plan->linked_report_id);
        $this->assertSame('active', $plan->status);
    }

    public function test_create_with_attachment_ids_links_photos() {
        $this->make_admin();
        $this->activate_license();

        $a1 = self::factory()->attachment->create_object('photo1.jpg', 0, ['post_mime_type' => 'image/jpeg']);
        $a2 = self::factory()->attachment->create_object('photo2.jpg', 0, ['post_mime_type' => 'image/jpeg']);

        $created = $this->call('POST', '/drwp/v1/reports', [
            'report_date' => '2026-04-25',
            'work_description' => 'with photos',
            'attachment_ids' => [$a1, $a2],
            'attachment_captions' => ['一枚目', ''],
        ]);
        $this->assertSame(201, $created->get_status());
        $id = $created->get_data()['id'];

        $photos = DRWP_Media::for_report($id);
        $this->assertCount(2, $photos);
        $this->assertSame($a1, (int) $photos[0]->attachment_id);
        $this->assertSame('一枚目', (string) $photos[0]->caption);
        $this->assertSame($a2, (int) $photos[1]->attachment_id);
        // Empty caption stored as NULL by DRWP_Media::sync().
        $this->assertNull($photos[1]->caption);
    }

    public function test_patch_without_attachment_ids_does_not_clear_existing_photos() {
        $this->make_admin();
        $this->activate_license();
        $a1 = self::factory()->attachment->create_object('p.jpg', 0, ['post_mime_type' => 'image/jpeg']);

        $created = $this->call('POST', '/drwp/v1/reports', [
            'report_date' => '2026-04-25',
            'work_description' => 'x',
            'attachment_ids' => [$a1],
        ]);
        $id = $created->get_data()['id'];
        $this->assertCount(1, DRWP_Media::for_report($id));

        // Metadata-only PATCH must NOT touch the photo link table.
        $this->call('PATCH', "/drwp/v1/reports/$id", ['public_title' => '更新']);
        $this->assertCount(1, DRWP_Media::for_report($id));

        // But PATCH with an explicit empty array clears photos.
        $this->call('PATCH', "/drwp/v1/reports/$id", ['attachment_ids' => []]);
        $this->assertCount(0, DRWP_Media::for_report($id));
    }

    public function test_create_stores_started_and_ended_times() {
        $this->make_admin();
        $this->activate_license();
        $project = $this->make_project('A');

        $resp = $this->call('POST', '/drwp/v1/reports', [
            'report_date'      => '2026-04-25',
            'project_id'       => $project,
            'started_at'       => '08:00',
            'ended_at'         => '10:30',
            'work_description' => 'A での作業',
        ]);
        $this->assertSame(201, $resp->get_status());
        $body = $resp->get_data();
        // HH:MM input becomes HH:MM:SS in storage.
        $this->assertSame('08:00:00', $body['started_at']);
        $this->assertSame('10:30:00', $body['ended_at']);
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

    public function test_upload_photo_unauth_returns_401() {
        wp_set_current_user(0);
        $resp = $this->call('POST', '/drwp/v1/upload-photo');
        $this->assertSame(401, $resp->get_status());
    }

    public function test_upload_photo_without_license_returns_402() {
        $this->make_admin();
        $resp = $this->call('POST', '/drwp/v1/upload-photo');
        $this->assertSame(402, $resp->get_status());
    }

    public function test_upload_photo_without_file_returns_400() {
        $this->make_admin();
        $this->activate_license();
        $resp = $this->call('POST', '/drwp/v1/upload-photo');
        $this->assertSame(400, $resp->get_status());
        $this->assertSame('drwp_no_file', $resp->get_data()['code']);
    }

    public function test_upload_photo_happy_path_via_filter_hook() {
        $this->make_admin();
        $this->activate_license();

        // Pre-create an attachment so the filter can pretend the upload
        // succeeded — bypasses the $_FILES / is_uploaded_file dance that
        // wp_handle_upload requires in non-CGI contexts.
        $attachment_id = self::factory()->attachment->create_object('upload.jpg', 0, [
            'post_mime_type' => 'image/jpeg',
            'post_type'      => 'attachment',
        ]);
        add_filter('drwp_handle_upload', function () use ($attachment_id) {
            return $attachment_id;
        });

        $req = new WP_REST_Request('POST', '/drwp/v1/upload-photo');
        $req->set_file_params(['file' => [
            'name'     => 'upload.jpg',
            'type'     => 'image/jpeg',
            'tmp_name' => '/tmp/fake',
            'error'    => UPLOAD_ERR_OK,
            'size'     => 1024,
        ]]);
        $resp = rest_do_request($req);
        $this->assertSame(200, $resp->get_status());
        $data = $resp->get_data();
        $this->assertSame((int) $attachment_id, (int) $data['id']);

        // Audit row exists.
        global $wpdb;
        $events = $wpdb->get_col(
            "SELECT event FROM {$wpdb->prefix}drwp_audit_logs ORDER BY id DESC LIMIT 5"
        );
        $this->assertContains('photo_uploaded', $events);
    }

    public function test_upload_photo_rejects_upload_error_code() {
        $this->make_admin();
        $this->activate_license();

        $req = new WP_REST_Request('POST', '/drwp/v1/upload-photo');
        $req->set_file_params(['file' => [
            'name'     => 'oops.jpg',
            'type'     => 'image/jpeg',
            'tmp_name' => '',
            'error'    => UPLOAD_ERR_INI_SIZE,
            'size'     => 0,
        ]]);
        $resp = rest_do_request($req);
        $this->assertSame(400, $resp->get_status());
        $this->assertSame('drwp_upload_error', $resp->get_data()['code']);
    }
}
