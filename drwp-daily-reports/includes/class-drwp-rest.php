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

        register_rest_route(self::NS, '/upload-photo', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'upload_photo'],
            'permission_callback' => [__CLASS__, 'can_edit'],
        ]);

        register_rest_route(self::NS, '/reports/(?P<id>\d+)/convert', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'convert_report'],
            'permission_callback' => [__CLASS__, 'can_convert'],
        ]);

        register_rest_route(self::NS, '/plans', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'create_plan'],
            'permission_callback' => [__CLASS__, 'can_edit'],
        ]);

        register_rest_route(self::NS, '/ai/briefing', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'ai_briefing'],
            'permission_callback' => [__CLASS__, 'can_edit'],
        ]);
    }

    public static function ai_briefing(WP_REST_Request $request) {
        $project_id = absint(($request->get_json_params() ?: [])['project_id'] ?? 0);
        if (!$project_id) {
            return new WP_Error('drwp_invalid', 'project_id is required', ['status' => 400]);
        }
        if (!DRWP_AI::is_enabled()) {
            return new WP_Error('drwp_ai_disabled', 'AI機能が無効です。AI設定で有効にしてください。', ['status' => 503]);
        }
        $result = DRWP_AI::briefing_for_project($project_id);
        if (is_wp_error($result)) {
            return new WP_Error($result->get_error_code(), $result->get_error_message(), ['status' => 500]);
        }
        return ['response' => $result];
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

    public static function can_convert(WP_REST_Request $request) {
        if (!current_user_can('publish_posts')) return false;
        return self::can_view_one($request);
    }

    private static function find_report($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", (int) $id));
    }

    private static function shape_report($r) {
        if (!$r) return null;
        $photos = [];
        foreach (DRWP_Media::for_report((int) $r->id) as $ph) {
            $thumb = wp_get_attachment_image_url((int) $ph->attachment_id, 'medium');
            if (!$thumb) continue;
            $photos[] = [
                'attachment_id' => (int) $ph->attachment_id,
                'url'           => $thumb,
                'caption'       => (string) ($ph->caption ?? ''),
            ];
        }
        return [
            'id'                => (int) $r->id,
            'project_id'        => $r->project_id ? (int) $r->project_id : null,
            'user_id'           => (int) $r->user_id,
            'report_date'       => (string) $r->report_date,
            'started_at'        => $r->started_at ?: null,
            'ended_at'          => $r->ended_at ?: null,
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
            'photos'            => $photos,
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

    /**
     * Create a 予定 row from the front-end calendar's "+予定を書く"
     * dialog. Workers are always saved as the assignee + creator;
     * the front-end UI doesn't expose an assignee picker.
     */
    public static function create_plan(WP_REST_Request $request) {
        if ($err = DRWP_User::block_write_rest()) return $err;
        if (!DRWP_License::can_write()) {
            return self::license_error();
        }
        $input = $request->get_json_params() ?: [];

        $date = (string) ($input['planned_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return new WP_Error('drwp_invalid', 'planned_date is required (YYYY-MM-DD)', ['status' => 400]);
        }
        $project_id = isset($input['project_id']) ? absint($input['project_id']) : 0;
        $started_at = self::sanitize_time_or_null($input['started_at'] ?? null);
        $ended_at   = self::sanitize_time_or_null($input['ended_at'] ?? null);
        $notes      = wp_kses_post((string) ($input['notes'] ?? ''));

        $uid = get_current_user_id();
        global $wpdb;
        $wpdb->insert(DRWP_Plan::table(), [
            'project_id'   => $project_id ?: null,
            'user_id'      => $uid,
            'planned_date' => $date,
            'started_at'   => $started_at,
            'ended_at'     => $ended_at,
            'notes'        => $notes,
            'status'       => 'active',
            'created_by'   => $uid,
        ]);
        $id = (int) $wpdb->insert_id;
        DRWP_Audit::log('plan_created', '予定を作成 (REST)', $id, ['source' => 'rest']);

        $response = rest_ensure_response(['id' => $id]);
        $response->set_status(201);
        return $response;
    }

    private static function sanitize_time_or_null($v) {
        $v = trim((string) $v);
        if ($v === '') return null;
        if (preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $v, $m)) {
            return sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0));
        }
        return null;
    }

    public static function create_report(WP_REST_Request $request) {
        if ($err = DRWP_User::block_write_rest()) return $err;
        if (!DRWP_License::can_write()) {
            return self::license_error();
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
        self::sync_photos_from_input($id, $input);
        DRWP_Audit::log('report_created', '日報を作成 (REST)', $id, ['source' => 'rest']);

        $response = rest_ensure_response(self::shape_report(self::find_report($id)));
        $response->set_status(201);
        return $response;
    }

    public static function update_report(WP_REST_Request $request) {
        if ($err = DRWP_User::block_write_rest()) return $err;
        if (!DRWP_License::can_write()) {
            return self::license_error();
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
        // Photos are an explicit replacement: only touch the link
        // table when the caller actually sent attachment_ids, so a
        // metadata-only PATCH doesn't wipe out the existing photo set.
        if (array_key_exists('attachment_ids', $input)) {
            self::sync_photos_from_input($id, $input);
        }
        return rest_ensure_response(self::shape_report(self::find_report($id)));
    }

    public static function convert_report(WP_REST_Request $request) {
        if ($err = DRWP_User::block_write_rest()) return $err;
        if (!DRWP_License::can_write()) {
            return self::license_error();
        }
        $id = (int) $request['id'];
        $report = self::find_report($id);
        if (!$report) return new WP_Error('drwp_not_found', 'Report not found', ['status' => 404]);

        $input = $request->get_json_params() ?: [];
        $publish_fields = [];
        $allowed = ['post_template', 'post_category_id', 'post_tags', 'post_status', 'scheduled_at',
                     'public_title', 'public_intro', 'public_body', 'public_next_plan'];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $input)) {
                $publish_fields[$key] = sanitize_text_field((string) $input[$key]);
            }
        }
        if (!empty($publish_fields)) {
            global $wpdb;
            $wpdb->update($wpdb->prefix . 'drwp_reports', $publish_fields, ['id' => $id]);
        }

        $result = DRWP_Post_Converter::sync_post($id, true);
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response(self::shape_report(self::find_report($id)));
    }

    private static function sync_photos_from_input($report_id, array $input) {
        if (!array_key_exists('attachment_ids', $input)) return;
        $ids = (array) $input['attachment_ids'];
        $captions = (array) ($input['attachment_captions'] ?? []);
        $rows = [];
        foreach ($ids as $i => $att_id) {
            $rows[] = [
                'attachment_id' => (int) $att_id,
                'caption'       => (string) ($captions[$i] ?? ''),
            ];
        }
        DRWP_Media::sync((int) $report_id, $rows);
    }

    /**
     * Build a 402 license-inactive response that tells the client what
     * went wrong (license_status) and where to fix it (settings_url).
     * Generic 'License inactive' alone leaves API consumers guessing.
     */
    private static function license_error() {
        $status   = DRWP_License::status();
        $api_url  = (string) get_option(DRWP_License::OPT_API_URL, '');
        $key      = (string) get_option(DRWP_License::OPT_KEY, '');
        $reason   = ($api_url === '' || $key === '') ? 'not_configured' : $status;

        return new WP_Error(
            'drwp_license',
            'License inactive — see data.reason for details',
            [
                'status'         => 402,
                'license_status' => $status,
                'reason'         => $reason,
                'settings_url'   => admin_url('admin.php?page=drwp_license'),
            ]
        );
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
        // started_at / ended_at: accept HH:MM or HH:MM:SS, store as
        // HH:MM:SS (MySQL TIME column). Anything else becomes NULL so
        // garbage doesn't end up in the column.
        foreach (['started_at', 'ended_at'] as $tk) {
            if (!array_key_exists($tk, $input)) continue;
            $raw = trim((string) $input[$tk]);
            if ($raw === '') { $out[$tk] = null; continue; }
            if (preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $raw, $m)) {
                $out[$tk] = sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0));
            } else {
                $out[$tk] = null;
            }
        }
        return $out;
    }

    public static function review_report(WP_REST_Request $request) {
        if ($err = DRWP_User::block_write_rest()) return $err;
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
        if ($err = DRWP_User::block_write_rest()) return $err;
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
        // Strip everything that lets a caller impersonate or pivot:
        //   - license_key  : identifies the site to the license server
        //   - public_key   : 32 bytes of base64; not strictly secret
        //                    but the API caller can fetch it directly
        //                    if they need it
        //   - admin_token  : not in state(), but defensive in case
        //                    something else adds it later
        unset($state['license_key'], $state['public_key']);
        unset($state['admin_token']);
        return rest_ensure_response($state);
    }

    /**
     * Upload a single photo via multipart/form-data.
     *
     * The actual file move + attachment insert is delegated through
     * the `drwp_handle_upload` filter so tests (and any host that
     * wants to plug in S3 etc.) can short-circuit. When the filter
     * returns null we fall back to media_handle_upload(), which is
     * what the WP media library uses.
     */
    public static function upload_photo(WP_REST_Request $request) {
        if ($err = DRWP_User::block_write_rest()) return $err;
        if (!DRWP_License::can_write()) return self::license_error();
        $files = $request->get_file_params();
        if (empty($files['file']) || !is_array($files['file'])) {
            return new WP_Error('drwp_no_file', 'file part is missing', ['status' => 400]);
        }
        if (($files['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return new WP_Error('drwp_upload_error', 'upload error code ' . (int) $files['file']['error'], ['status' => 400]);
        }

        $attachment_id = apply_filters('drwp_handle_upload', null, 'file', $request);
        if ($attachment_id === null) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            // media_handle_upload reads from $_FILES, so mirror what
            // the REST request gave us.
            $_FILES['file'] = $files['file'];
            $attachment_id = media_handle_upload('file', 0);
        }
        if (is_wp_error($attachment_id)) return $attachment_id;
        if (!$attachment_id) {
            return new WP_Error('drwp_upload_failed', 'attachment was not created', ['status' => 500]);
        }
        if (get_post_type((int) $attachment_id) !== 'attachment') {
            return new WP_Error('drwp_not_attachment', 'returned id is not an attachment', ['status' => 500]);
        }

        DRWP_Audit::log('photo_uploaded', '直接アップロード', null, ['attachment_id' => (int) $attachment_id]);

        return rest_ensure_response([
            'id'            => (int) $attachment_id,
            'thumbnail_url' => wp_get_attachment_image_url((int) $attachment_id, 'thumbnail'),
            'full_url'      => wp_get_attachment_url((int) $attachment_id),
            'title'         => get_the_title((int) $attachment_id),
        ]);
    }
}
