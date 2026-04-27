<?php
if (!defined('ABSPATH')) exit;

class DRWP_REST {
    const NS = 'drwp/v1';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register']);
    }

    public static function register() {
        register_rest_route(self::NS, '/reports', [
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'list_reports'],
                'permission_callback' => [__CLASS__, 'can_edit'],
                'args'                => self::list_args(),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'create_report'],
                'permission_callback' => [__CLASS__, 'can_edit'],
            ],
        ]);

        register_rest_route(self::NS, '/reports/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'get_report'],
                'permission_callback' => [__CLASS__, 'can_view_one'],
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => [__CLASS__, 'update_report'],
                'permission_callback' => [__CLASS__, 'can_edit_one'],
            ],
        ]);

        register_rest_route(self::NS, '/reports/(?P<id>\d+)/review', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'review_report'],
            'permission_callback' => [__CLASS__, 'can_review'],
            'args'                => [
                'review_status' => [
                    'required' => true,
                    'type'     => 'string',
                    'enum'     => DRWP_Review::ALLOWED_STATUSES,
                ],
                'comment' => ['type' => 'string'],
            ],
        ]);

        register_rest_route(self::NS, '/reports/(?P<id>\d+)/comments', [
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'list_comments'],
                'permission_callback' => [__CLASS__, 'can_view_one'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'add_comment'],
                'permission_callback' => [__CLASS__, 'can_view_one'],
                'args'                => [
                    'body' => ['required' => true, 'type' => 'string'],
                ],
            ],
        ]);

        register_rest_route(self::NS, '/reports/(?P<id>\d+)/audit', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'list_audit'],
            'permission_callback' => [__CLASS__, 'can_view_one'],
        ]);

        register_rest_route(self::NS, '/projects', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'list_projects'],
            'permission_callback' => [__CLASS__, 'can_edit'],
        ]);

        register_rest_route(self::NS, '/license', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'license_state'],
            'permission_callback' => [__CLASS__, 'can_edit'],
        ]);
    }

    private static function list_args() {
        return [
            'page'          => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
            'per_page'      => ['type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100],
            'search'        => ['type' => 'string'],
            'review_status' => ['type' => 'string'],
            'post_status'   => ['type' => 'string'],
            'project_id'    => ['type' => 'integer', 'minimum' => 0],
            'date_from'     => ['type' => 'string'],
            'date_to'       => ['type' => 'string'],
        ];
    }

    public static function can_edit() {
        return current_user_can('edit_posts');
    }

    public static function can_review() {
        return current_user_can('edit_others_posts');
    }

    public static function can_view_one(WP_REST_Request $request) {
        if (!current_user_can('edit_posts')) return false;
        $report = self::find_report((int) $request['id']);
        if (!$report) return new WP_Error('drwp_not_found', 'Report not found', ['status' => 404]);
        if (current_user_can('edit_others_posts')) return true;
        return (int) $report->user_id === get_current_user_id()
            ? true
            : new WP_Error('drwp_forbidden', 'Forbidden', ['status' => 403]);
    }

    public static function can_edit_one(WP_REST_Request $request) {
        return self::can_view_one($request);
    }

    private static function find_report($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", (int) $id));
    }

    private static function shape_report($r) {
        if (!$r) return null;
        return [
            'id'                => (int) $r->id,
            'project_id'        => $r->project_id ? (int) $r->project_id : null,
            'user_id'           => (int) $r->user_id,
            'report_date'       => (string) $r->report_date,
            'review_status'     => (string) $r->review_status,
            'work_description'  => (string) $r->work_description,
            'issues'            => (string) $r->issues,
            'next_plan'         => (string) $r->next_plan,
            'public_title'      => (string) $r->public_title,
            'public_intro'      => (string) $r->public_intro,
            'public_body'       => (string) $r->public_body,
            'public_next_plan'  => (string) $r->public_next_plan,
            'post_template'     => (string) $r->post_template,
            'post_category_id'  => $r->post_category_id ? (int) $r->post_category_id : null,
            'post_tags'         => (string) $r->post_tags,
            'post_status'       => (string) $r->post_status,
            'scheduled_at'      => $r->scheduled_at ?: null,
            'linked_post_id'    => $r->linked_post_id ? (int) $r->linked_post_id : null,
            'created_at'        => (string) $r->created_at,
            'updated_at'        => (string) $r->updated_at,
        ];
    }

    public static function list_reports(WP_REST_Request $request) {
        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';
        $page = max(1, (int) $request->get_param('page'));
        $per_page = min(100, max(1, (int) $request->get_param('per_page')));
        $offset = ($page - 1) * $per_page;

        $where = '1=1';
        $args = [];
        if (!current_user_can('edit_others_posts')) {
            $where .= ' AND user_id = %d';
            $args[] = get_current_user_id();
        }
        $search = (string) $request->get_param('search');
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (public_title LIKE %s OR public_body LIKE %s OR work_description LIKE %s)';
            $args[] = $like; $args[] = $like; $args[] = $like;
        }
        foreach (['review_status', 'post_status'] as $col) {
            $v = (string) $request->get_param($col);
            if ($v !== '') { $where .= " AND $col = %s"; $args[] = $v; }
        }
        $project_id = (int) $request->get_param('project_id');
        if ($project_id) { $where .= ' AND project_id = %d'; $args[] = $project_id; }
        $date_from = (string) $request->get_param('date_from');
        if ($date_from !== '') { $where .= ' AND report_date >= %s'; $args[] = $date_from; }
        $date_to = (string) $request->get_param('date_to');
        if ($date_to !== '') { $where .= ' AND report_date <= %s'; $args[] = $date_to; }

        $count_sql = "SELECT COUNT(*) FROM $table WHERE $where";
        $total = $args
            ? (int) $wpdb->get_var($wpdb->prepare($count_sql, $args))
            : (int) $wpdb->get_var($count_sql);

        $sql = "SELECT * FROM $table WHERE $where ORDER BY report_date DESC, id DESC LIMIT %d OFFSET %d";
        $query_args = $args;
        $query_args[] = $per_page;
        $query_args[] = $offset;
        $rows = $wpdb->get_results($wpdb->prepare($sql, $query_args));

        $response = new WP_REST_Response([
            'items' => array_map([__CLASS__, 'shape_report'], $rows),
            'total' => $total,
            'page'  => $page,
            'pages' => max(1, (int) ceil($total / $per_page)),
        ]);
        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) max(1, (int) ceil($total / $per_page)));
        return $response;
    }

    public static function get_report(WP_REST_Request $request) {
        $report = self::find_report((int) $request['id']);
        return $report
            ? rest_ensure_response(self::shape_report($report))
            : new WP_Error('drwp_not_found', 'Report not found', ['status' => 404]);
    }

    public static function create_report(WP_REST_Request $request) {
        if (!DRWP_License::can_write()) {
            return new WP_Error('drwp_license', 'License inactive', ['status' => 402]);
        }
        $input = $request->get_json_params() ?: [];
        if ($err = self::validate_input($input)) return $err;

        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';

        $data = self::sanitize_writable($input);
        $data['user_id'] = get_current_user_id();
        $data['review_status'] = $data['review_status'] ?? 'pending';

        if (empty($data['report_date'])) $data['report_date'] = current_time('Y-m-d');

        $wpdb->insert($table, $data);
        $id = (int) $wpdb->insert_id;
        DRWP_Audit::log('report_created', '日報を作成 (REST)', $id, ['source' => 'rest']);

        $response = rest_ensure_response(self::shape_report(self::find_report($id)));
        $response->set_status(201);
        return $response;
    }

    public static function update_report(WP_REST_Request $request) {
        if (!DRWP_License::can_write()) {
            return new WP_Error('drwp_license', 'License inactive', ['status' => 402]);
        }
        global $wpdb;
        $id = (int) $request['id'];
        $report = self::find_report($id);
        if (!$report) return new WP_Error('drwp_not_found', 'Report not found', ['status' => 404]);

        $input = $request->get_json_params() ?: [];
        if ($err = self::validate_input($input)) return $err;

        $data = self::sanitize_writable($input);
        if (!empty($data)) {
            $wpdb->update($wpdb->prefix . 'drwp_reports', $data, ['id' => $id]);
            DRWP_Audit::log('report_updated', '日報を更新 (REST)', $id, ['source' => 'rest']);
        }
        return rest_ensure_response(self::shape_report(self::find_report($id)));
    }

    /**
     * Reject explicitly-supplied invalid input rather than letting
     * sanitize_writable silently drop it. Returns a WP_Error or null.
     */
    private static function validate_input(array $input) {
        if (isset($input['report_date'])
            && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $input['report_date'])
        ) {
            return new WP_Error(
                'drwp_invalid_date',
                'report_date must match YYYY-MM-DD',
                ['status' => 400]
            );
        }
        return null;
    }

    private static function sanitize_writable(array $input) {
        $allowed_string = ['public_title', 'post_template', 'post_tags', 'post_status'];
        $allowed_kses   = ['work_description', 'issues', 'next_plan', 'public_intro', 'public_body', 'public_next_plan'];
        $allowed_int    = ['project_id', 'post_category_id'];
        $out = [];
        foreach ($allowed_string as $k) {
            if (isset($input[$k])) $out[$k] = sanitize_text_field((string) $input[$k]);
        }
        foreach ($allowed_kses as $k) {
            if (isset($input[$k])) $out[$k] = wp_kses_post((string) $input[$k]);
        }
        foreach ($allowed_int as $k) {
            if (isset($input[$k])) $out[$k] = (int) $input[$k] ?: null;
        }
        if (isset($input['report_date'])) {
            $d = sanitize_text_field((string) $input['report_date']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $out['report_date'] = $d;
        }
        if (isset($input['scheduled_at'])) {
            $out['scheduled_at'] = sanitize_text_field((string) $input['scheduled_at']) ?: null;
        }
        return $out;
    }

    public static function review_report(WP_REST_Request $request) {
        global $wpdb;
        $id = (int) $request['id'];
        $report = self::find_report($id);
        if (!$report) return new WP_Error('drwp_not_found', 'Report not found', ['status' => 404]);

        $status = sanitize_text_field((string) $request->get_param('review_status'));
        $wpdb->update($wpdb->prefix . 'drwp_reports', ['review_status' => $status], ['id' => $id]);

        $comment = (string) $request->get_param('comment');
        $comment_id = 0;
        if ($comment !== '') $comment_id = DRWP_Comment::insert($id, $comment);

        DRWP_Audit::log('review_status_changed', 'レビュー状態を変更 (REST)', $id, [
            'from'       => $report->review_status,
            'to'         => $status,
            'comment_id' => $comment_id ?: null,
        ]);

        return rest_ensure_response(self::shape_report(self::find_report($id)));
    }

    public static function list_comments(WP_REST_Request $request) {
        $rows = DRWP_Comment::for_report((int) $request['id']);
        $items = array_map(function ($c) {
            return [
                'id'           => (int) $c->id,
                'report_id'    => (int) $c->report_id,
                'user_id'      => (int) $c->user_id,
                'display_name' => (string) ($c->display_name ?? ''),
                'body'         => (string) $c->body,
                'created_at'   => (string) $c->created_at,
            ];
        }, $rows);
        return rest_ensure_response(['items' => $items]);
    }

    public static function add_comment(WP_REST_Request $request) {
        $id = (int) $request['id'];
        $body = (string) $request->get_param('body');
        $comment_id = DRWP_Comment::insert($id, $body);
        if (!$comment_id) {
            return new WP_Error('drwp_empty_comment', 'Comment body required', ['status' => 400]);
        }
        DRWP_Audit::log('comment_added', 'コメントを追加 (REST)', $id, ['comment_id' => $comment_id]);
        $response = rest_ensure_response(['id' => $comment_id]);
        $response->set_status(201);
        return $response;
    }

    public static function list_audit(WP_REST_Request $request) {
        $rows = DRWP_Audit::for_report((int) $request['id']);
        $items = array_map(function ($a) {
            return [
                'id'         => (int) $a->id,
                'event'      => (string) $a->event,
                'user_id'    => (int) $a->user_id,
                'message'    => (string) $a->message,
                'meta'       => $a->meta_json ? json_decode((string) $a->meta_json, true) : null,
                'created_at' => (string) $a->created_at,
            ];
        }, $rows);
        return rest_ensure_response(['items' => $items]);
    }

    public static function list_projects() {
        $rows = DRWP_Project::all();
        $items = array_map(function ($p) {
            return [
                'id'     => (int) $p->id,
                'name'   => (string) $p->name,
                'status' => (string) $p->status,
            ];
        }, $rows);
        return rest_ensure_response(['items' => $items]);
    }

    public static function license_state() {
        $state = DRWP_License::state();
        unset($state['license_key'], $state['public_key']);
        return rest_ensure_response($state);
    }
}
