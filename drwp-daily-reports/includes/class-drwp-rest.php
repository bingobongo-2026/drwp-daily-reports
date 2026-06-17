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

        register_rest_route(self::NS, '/reports/(?P<id>\d+)/archive', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'archive_report'],
            'permission_callback' => [__CLASS__, 'can_archive_report'],
        ]);

        register_rest_route(self::NS, '/reports/(?P<id>\d+)/restore', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'restore_report'],
            'permission_callback' => [__CLASS__, 'can_archive_report'],
        ]);

        register_rest_route(self::NS, '/reports/(?P<id>\d+)/purge', [
            'methods'             => 'DELETE',
            'callback'            => [__CLASS__, 'purge_report'],
            'permission_callback' => [__CLASS__, 'can_purge_report'],
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

        register_rest_route(self::NS, '/plans/(?P<id>\d+)', [
            'methods'             => 'PATCH',
            'callback'            => [__CLASS__, 'update_plan'],
            'permission_callback' => [__CLASS__, 'can_edit'],
        ]);

        register_rest_route(self::NS, '/ai/briefing', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'ai_briefing'],
            'permission_callback' => [__CLASS__, 'can_use_ai'],
        ]);

        register_rest_route(self::NS, '/ai/draft-report', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'ai_draft_report'],
            'permission_callback' => [__CLASS__, 'can_use_ai'],
        ]);

        register_rest_route(self::NS, '/ai/project-summary', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'ai_project_summary'],
            'permission_callback' => [__CLASS__, 'can_use_ai'],
        ]);

        register_rest_route(self::NS, '/ai/alerts', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'ai_alerts'],
            'permission_callback' => [__CLASS__, 'can_use_ai'],
        ]);

        register_rest_route(self::NS, '/ai/advise', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'ai_advise'],
            'permission_callback' => [__CLASS__, 'can_use_ai'],
        ]);
    }

    public static function ai_briefing(WP_REST_Request $request) {
        $project_id = absint(($request->get_json_params() ?: [])['project_id'] ?? 0);
        if (!$project_id) {
            return new WP_Error('drwp_invalid', '案件 ID (project_id) が指定されていません。', ['status' => 400]);
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

    public static function ai_draft_report(WP_REST_Request $request) {
        $report_id = absint(($request->get_json_params() ?: [])['report_id'] ?? 0);
        if (!$report_id) {
            return new WP_Error('drwp_invalid', '日報 ID (report_id) が指定されていません。', ['status' => 400]);
        }
        if (!DRWP_AI::is_enabled()) {
            return new WP_Error('drwp_ai_disabled', 'AI機能が無効です。AI設定で有効にしてください。', ['status' => 503]);
        }
        $result = DRWP_AI::draft_public_post($report_id);
        if (is_wp_error($result)) {
            return new WP_Error($result->get_error_code(), $result->get_error_message(), ['status' => 500]);
        }
        return $result; // public_title / public_intro / public_body / public_next_plan
    }

    public static function ai_project_summary(WP_REST_Request $request) {
        $input = $request->get_json_params() ?: [];
        $project_id = absint($input['project_id'] ?? 0);
        $period = sanitize_text_field((string) ($input['period'] ?? 'month'));
        $anchor = sanitize_text_field((string) ($input['anchor'] ?? ''));
        if (!$project_id) {
            return new WP_Error('drwp_invalid', '案件 ID (project_id) が指定されていません。', ['status' => 400]);
        }
        if (!DRWP_AI::is_enabled()) {
            return new WP_Error('drwp_ai_disabled', 'AI機能が無効です。AI設定で有効にしてください。', ['status' => 503]);
        }
        [$from, $to, $label] = self::resolve_period($period, $anchor);
        $result = DRWP_AI::project_summary($project_id, $from, $to, $label);
        if (is_wp_error($result)) {
            return new WP_Error($result->get_error_code(), $result->get_error_message(), ['status' => 500]);
        }
        return ['response' => $result, 'range' => $label];
    }

    public static function ai_alerts(WP_REST_Request $request) {
        $input = $request->get_json_params() ?: [];
        $from = sanitize_text_field((string) ($input['date_from'] ?? ''));
        $to   = sanitize_text_field((string) ($input['date_to'] ?? ''));
        $project_id = absint($input['project_id'] ?? 0);
        // Default to the last 30 days when no range is supplied.
        // current_time() is site-TZ aware; raw date() / strtotime()
        // would run in the server TZ.
        $now_ts = (int) current_time('timestamp');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', $now_ts - 30 * DAY_IN_SECONDS);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d', $now_ts);
        if (!DRWP_AI::is_enabled()) {
            return new WP_Error('drwp_ai_disabled', 'AI機能が無効です。AI設定で有効にしてください。', ['status' => 503]);
        }
        $result = DRWP_AI::extract_alerts($from, $to, $project_id);
        if (is_wp_error($result)) {
            return new WP_Error($result->get_error_code(), $result->get_error_message(), ['status' => 500]);
        }
        return ['response' => $result, 'date_from' => $from, 'date_to' => $to];
    }

    /**
     * 振り返りアドバイス — 操作員が日報一覧で絞り込んだ結果の
     * report_ids 配列を受け取って AI のアドバイスを返す。可視性
     * は AI 側でなく呼び出し側(reports-list)が絞り込み済みと
     *想定するが、念のため non-operator は自分の日報以外を弾く。
     */
    public static function ai_advise(WP_REST_Request $request) {
        $input = $request->get_json_params() ?: [];
        $ids = array_values(array_filter(array_map('intval', (array) ($input['report_ids'] ?? []))));
        if (!$ids) {
            return new WP_Error('drwp_invalid', '日報 ID 配列 (report_ids) が指定されていません。', ['status' => 400]);
        }
        if (!DRWP_AI::is_enabled()) {
            return new WP_Error('drwp_ai_disabled', 'AI機能が無効です。AI設定で有効にしてください。', ['status' => 503]);
        }
        if (!current_user_can('edit_others_posts')) {
            global $wpdb;
            $place = implode(',', array_fill(0, count($ids), '%d'));
            $allowed = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}drwp_reports WHERE id IN ($place) AND user_id = %d",
                array_merge($ids, [get_current_user_id()])
            )));
            $ids = array_values(array_intersect($ids, $allowed));
            if (!$ids) {
                return new WP_Error('drwp_forbidden', '対象の日報を閲覧する権限がありません', ['status' => 403]);
            }
        }
        $result = DRWP_AI::advise_on_reports($ids);
        if (is_wp_error($result)) {
            return new WP_Error($result->get_error_code(), $result->get_error_message(), ['status' => 500]);
        }
        return ['response' => $result, 'count' => count($ids)];
    }

    /**
     * Resolve a `month`/`quarter` period + `YYYY-MM` anchor into a
     * concrete from/to date pair + a human label. Falls back to the
     * current month when the anchor is malformed.
     */
    private static function resolve_period($period, $anchor) {
        if (!preg_match('/^(\d{4})-(\d{2})$/', $anchor, $m)) {
            $year = (int) date('Y');
            $month = (int) date('n');
        } else {
            $year = (int) $m[1];
            $month = (int) $m[2];
        }
        if ($period === 'quarter') {
            $q = (int) floor(($month - 1) / 3); // 0..3
            $start_month = $q * 3 + 1;
            $from = sprintf('%04d-%02d-01', $year, $start_month);
            $to = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $start_month + 2)));
            $label = sprintf('%d年 第%d四半期', $year, $q + 1);
        } else {
            $from = sprintf('%04d-%02d-01', $year, $month);
            $to = date('Y-m-t', strtotime($from));
            $label = sprintf('%d年%d月', $year, $month);
        }
        return [$from, $to, $label];
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
        if (DRWP_User::is_retired()) return false;
        return current_user_can('edit_posts');
    }

    /**
     * AI endpoints — same edit cap as the rest of the API, plus a
     * plan gate. Returns a 403 `WP_Error` instead of a bare false
     * when the caller is authenticated but on a plan that doesn't
     * include AI, so a UI that hasn't been refreshed since a plan
     * downgrade can show a clean message instead of "?".
     */
    public static function can_use_ai() {
        if (!self::can_edit()) return false;
        if (!DRWP_License::plan_allows('ai')) {
            return new WP_Error(
                'drwp_plan_locked',
                __('AI 機能は Pro プランで利用可能です。', 'drwp-daily-reports'),
                ['status' => 403]
            );
        }
        return true;
    }

    public static function can_review() {
        if (DRWP_User::is_retired()) return false;
        return current_user_can('edit_others_posts');
    }

    public static function can_view_one(WP_REST_Request $request) {
        if (DRWP_User::is_retired()) return false;
        if (!current_user_can('edit_posts')) return false;
        $report = self::find_report((int) $request['id']);
        if (!$report) return new WP_Error('drwp_not_found', '指定された日報が見つかりませんでした。', ['status' => 404]);
        if (current_user_can('edit_others_posts')) return true;
        return (int) $report->user_id === get_current_user_id()
            ? true
            : new WP_Error('drwp_forbidden', 'この日報を編集する権限がありません。', ['status' => 403]);
    }

    public static function can_edit_one(WP_REST_Request $request) {
        $base = self::can_view_one($request);
        if ($base !== true) return $base;
        // Operators (`edit_others_posts`) can edit any report in any
        // state — that's the review/fix-up workflow. Everyone else
        // (a worker editing their own report) is restricted to
        // `pending` か `needs_revision`、すなわち「まだ承認されて
        // いない」状態だけ。差戻し中もここを通すことで再編集 →
        // 再提出ができる。承認済みを後から書き換えるのは塞ぐ。
        if (current_user_can('edit_others_posts')) return true;
        $report = self::find_report((int) $request['id']);
        if ($report && !in_array((string) $report->review_status, ['pending', 'needs_revision'], true)) {
            return new WP_Error(
                'drwp_forbidden',
                __('レビュー待ち または 差戻し の日報のみ編集できます。', 'drwp-daily-reports'),
                ['status' => 403]
            );
        }
        return true;
    }

    public static function can_convert(WP_REST_Request $request) {
        if (!current_user_can('publish_posts')) return false;
        return self::can_view_one($request);
    }

    public static function can_archive_report(WP_REST_Request $request) {
        // archive / restore はレビュアー以上 (`edit_others_posts`)。
        // 投稿者本人には開けないことで「都合の悪い日報を隠す」を防ぐ。
        return current_user_can(DRWP_Reports::CAP_ARCHIVE);
    }

    public static function can_purge_report(WP_REST_Request $request) {
        return current_user_can(DRWP_Reports::CAP_PURGE);
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
                'kind'          => DRWP_Media::normalize_kind($ph->photo_kind ?? ''),
            ];
        }
        // Resolve project + author for the front-end view modal so
        // it doesn't have to fan out additional REST calls.
        $project_name = null;
        $proj = null;
        if ($r->project_id) {
            $proj = DRWP_Project::find((int) $r->project_id);
            if ($proj) $project_name = (string) $proj->name;
        }
        $author_name = DRWP_User::display_name((int) $r->user_id);

        // 公開記事化モーダルで「個人情報の可能性あり」を検知するための
        // ヒント。案件に紐づく顧客名・担当者名・報告者名を渡しておくと
        // フロント側で本文と照らし合わせて警告／マスク提案ができる。
        $pii_candidates = [];
        if ($proj) {
            if (!empty($proj->client_name))     $pii_candidates[] = (string) $proj->client_name;
            if (!empty($proj->contact_person))  $pii_candidates[] = (string) $proj->contact_person;
            if (!empty($proj->customer_id) && class_exists('DRWP_Customer')) {
                $cust = DRWP_Customer::find((int) $proj->customer_id);
                if ($cust && !empty($cust->name)) $pii_candidates[] = (string) $cust->name;
            }
        }
        if ($author_name) $pii_candidates[] = (string) $author_name;
        $pii_candidates = array_values(array_unique(array_filter($pii_candidates, function ($s) {
            // 2 文字未満は誤検知が増えるので落とす。
            return is_string($s) && mb_strlen($s) >= 2;
        })));

        return [
            'id'                => (int) $r->id,
            'project_id'        => $r->project_id ? (int) $r->project_id : null,
            'project_name'      => $project_name,
            'user_id'           => (int) $r->user_id,
            'author_name'       => $author_name ?: null,
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
            'pii_candidates'    => $pii_candidates,
            'post_template'     => (string) $r->post_template,
            'post_category_id'  => $r->post_category_id ? (int) $r->post_category_id : null,
            'post_tags'         => (string) $r->post_tags,
            'post_status'       => (string) $r->post_status,
            'scheduled_at'      => $r->scheduled_at ?: null,
            'linked_post_id'    => $r->linked_post_id ? (int) $r->linked_post_id : null,
            'archived_at'       => !empty($r->archived_at) ? (string) $r->archived_at : null,
            'days_archived'     => DRWP_Reports::days_since_archived($r),
            'purge_min_days'    => DRWP_Reports::PURGE_MIN_DAYS,
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
            : new WP_Error('drwp_not_found', '指定された日報が見つかりませんでした。', ['status' => 404]);
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
            return new WP_Error('drwp_invalid', '予定日 (planned_date) を YYYY-MM-DD 形式で指定してください。', ['status' => 400]);
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

    /**
     * Partial update for a 予定 row — used by the front-end
     * calendar's drag-and-drop date change. The same endpoint
     * accepts other field tweaks (started_at / ended_at / notes /
     * status / linked_report_id) so we don't have to add another
     * REST route every time the UI grows. Permission honors
     * `DRWP_Plan::can_edit($plan)` so workers can only touch their
     * own rows.
     */
    public static function update_plan(WP_REST_Request $request) {
        if ($err = DRWP_User::block_write_rest()) return $err;
        if (!DRWP_License::can_write()) return self::license_error();

        $id = (int) $request['id'];
        $plan = DRWP_Plan::find($id);
        if (!$plan) {
            return new WP_Error('drwp_not_found', '指定された予定が見つかりませんでした。', ['status' => 404]);
        }
        if (!DRWP_Plan::can_edit($plan)) {
            return new WP_Error('drwp_forbidden', 'この予定を編集する権限がありません。', ['status' => 403]);
        }

        $input = $request->get_json_params() ?: [];
        $data  = [];

        if (array_key_exists('planned_date', $input)) {
            $date = sanitize_text_field((string) $input['planned_date']);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return new WP_Error('drwp_invalid', '予定日 (planned_date) の形式が不正です (YYYY-MM-DD)。', ['status' => 400]);
            }
            $data['planned_date'] = $date;
        }
        if (array_key_exists('started_at', $input)) {
            $data['started_at'] = self::sanitize_time_or_null($input['started_at']);
        }
        if (array_key_exists('ended_at', $input)) {
            $data['ended_at'] = self::sanitize_time_or_null($input['ended_at']);
        }
        if (array_key_exists('notes', $input)) {
            $data['notes'] = wp_kses_post((string) $input['notes']);
        }
        if (array_key_exists('project_id', $input)) {
            // 案件は誰でも変更可能(空 = 案件解除)。存在チェックして、
            // 不正な ID は弾く(無効値で「案件未設定」になる事故を防ぐ)。
            $pid = absint($input['project_id']);
            if ($pid) {
                global $wpdb;
                $proj_t = $wpdb->prefix . 'drwp_projects';
                $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $proj_t WHERE id = %d", $pid));
                if (!$exists) {
                    return new WP_Error('drwp_invalid', '案件 ID (project_id) が不正です。', ['status' => 400]);
                }
            }
            $data['project_id'] = $pid ?: null;
        }
        if (array_key_exists('user_id', $input)) {
            // 担当者の付け替えは事務所(edit_others_posts)だけ。作業員
            // が他社員に振り直すのを REST 側でも止める。
            if (!current_user_can('edit_others_posts')) {
                return new WP_Error(
                    'drwp_forbidden',
                    __('担当者の変更は事務所のみ可能です。', 'drwp-daily-reports'),
                    ['status' => 403]
                );
            }
            $uid = absint($input['user_id']);
            if ($uid) {
                $target = get_userdata($uid);
                if (!$target || !user_can($target, 'edit_posts')) {
                    return new WP_Error('drwp_invalid', 'ユーザー ID (user_id) が不正です。', ['status' => 400]);
                }
            }
            $data['user_id'] = $uid ?: null;
        }
        if (array_key_exists('status', $input)) {
            $status = sanitize_text_field((string) $input['status']);
            if (!array_key_exists($status, DRWP_Plan::status_labels())) {
                return new WP_Error('drwp_invalid', '指定された予定ステータスが不正です。', ['status' => 400]);
            }
            $data['status'] = $status;
        }
        if (array_key_exists('linked_report_id', $input)) {
            // Match the admin save path: only store a link that points
            // at a real report, otherwise drop it rather than persist
            // a dangling pointer.
            $rid = absint($input['linked_report_id']);
            if ($rid) {
                global $wpdb;
                $reports_t = $wpdb->prefix . 'drwp_reports';
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $reports_t WHERE id = %d", $rid
                ));
                $rid = $exists ? $rid : 0;
            }
            $data['linked_report_id'] = $rid ?: null;
        }

        if ($data) {
            global $wpdb;
            $wpdb->update(DRWP_Plan::table(), $data, ['id' => $id]);
            DRWP_Audit::log('plan_updated', '予定を更新 (REST)', $id, [
                'source' => 'rest',
                'fields' => array_keys($data),
            ]);
        }

        return rest_ensure_response([
            'id'      => $id,
            'updated' => array_keys($data),
        ]);
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

        // 予定チップから作成された日報なら、その予定に紐づけて
        // 「完了」にする。編集権限のある自分の予定で、まだ未リンク
        // のものだけが対象(他人の予定や済んだ予定は触らない)。
        self::link_plan_to_report($input['linked_plan_id'] ?? 0, $id);

        $response = rest_ensure_response(self::shape_report(self::find_report($id)));
        $response->set_status(201);
        return $response;
    }

    /**
     * Tie a just-created report back to the 予定 it was spawned from:
     * set the plan's `linked_report_id` + flip it to `completed`.
     * No-op unless the plan exists, the caller can edit it, and it
     * isn't already linked.
     */
    private static function link_plan_to_report($plan_id, $report_id) {
        $plan_id = absint($plan_id);
        if (!$plan_id) return;
        $plan = DRWP_Plan::find($plan_id);
        if (!$plan || !DRWP_Plan::can_edit($plan)) return;
        if (!empty($plan->linked_report_id)) return;
        global $wpdb;
        $wpdb->update(DRWP_Plan::table(), [
            'linked_report_id' => (int) $report_id,
            'status'           => 'completed',
        ], ['id' => $plan_id]);
        DRWP_Audit::log('plan_linked_to_report', '予定を日報に紐づけ完了', $report_id, [
            'plan_id' => $plan_id,
        ]);
    }

    public static function update_report(WP_REST_Request $request) {
        if ($err = DRWP_User::block_write_rest()) return $err;
        if (!DRWP_License::can_write()) {
            return self::license_error();
        }
        global $wpdb;
        $id = (int) $request['id'];
        $report = self::find_report($id);
        if (!$report) return new WP_Error('drwp_not_found', '指定された日報が見つかりませんでした。', ['status' => 404]);

        $input = $request->get_json_params() ?: [];
        if ($err = self::validate_input($input)) return $err;

        $data = self::sanitize_writable($input);
        // 投稿者本人が差戻し中の日報を再編集した場合は、自動で再び
        // レビュー待ちに戻す (= 再提出)。レビュアーが編集した場合は
        // 状態を維持するので、運用上の小修正 → 即承認のフローを
        // 邪魔しない。
        if ($report->review_status === 'needs_revision'
            && (int) $report->user_id === get_current_user_id()
            && !current_user_can('edit_others_posts')) {
            $data['review_status'] = 'pending';
        }
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

    public static function archive_report(WP_REST_Request $request) {
        $id = (int) $request['id'];
        $report = DRWP_Reports::find($id);
        if (!$report) return new WP_Error('drwp_not_found', '指定された日報が見つかりませんでした。', ['status' => 404]);
        $input = $request->get_json_params() ?: [];
        $reason = isset($input['reason']) ? sanitize_text_field((string) $input['reason']) : '';
        DRWP_Reports::archive($id, $reason);
        return ['archived' => true, 'id' => $id];
    }

    public static function restore_report(WP_REST_Request $request) {
        $id = (int) $request['id'];
        $report = DRWP_Reports::find($id);
        if (!$report) return new WP_Error('drwp_not_found', '指定された日報が見つかりませんでした。', ['status' => 404]);
        DRWP_Reports::restore($id);
        return ['restored' => true, 'id' => $id];
    }

    public static function purge_report(WP_REST_Request $request) {
        $id = (int) $request['id'];
        $r = DRWP_Reports::purge($id);
        if (is_wp_error($r)) {
            // 「アーカイブ未経過」エラーは 409、それ以外は 400
            $code = $r->get_error_code() === 'drwp_purge_too_soon' ? 409 : 400;
            return new WP_Error($r->get_error_code(), $r->get_error_message(), ['status' => $code]);
        }
        return ['purged' => true, 'id' => $id];
    }

    public static function convert_report(WP_REST_Request $request) {
        if ($err = DRWP_User::block_write_rest()) return $err;
        if (!DRWP_License::can_write()) {
            return self::license_error();
        }
        $id = (int) $request['id'];
        $report = self::find_report($id);
        if (!$report) return new WP_Error('drwp_not_found', '指定された日報が見つかりませんでした。', ['status' => 404]);

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
        // 並列配列 attachment_kinds[] (normal / before / after) を受ける。
        // 未指定なら 'normal' 扱い → DB 上は NULL。
        $kinds = (array) ($input['attachment_kinds'] ?? []);
        $rows = [];
        foreach ($ids as $i => $att_id) {
            $rows[] = [
                'attachment_id' => (int) $att_id,
                'caption'       => (string) ($captions[$i] ?? ''),
                'photo_kind'    => (string) ($kinds[$i] ?? ''),
            ];
        }
        DRWP_Media::sync((int) $report_id, $rows);
    }

    /**
     * Build a 402 license-inactive response that tells the client what
     * went wrong (license_status) and where to fix it (settings_url).
     */
    private static function license_error() {
        $status   = DRWP_License::status();
        $api_url  = (string) get_option(DRWP_License::OPT_API_URL, '');
        $key      = (string) get_option(DRWP_License::OPT_KEY, '');
        $reason   = ($api_url === '' || $key === '') ? 'not_configured' : $status;

        // 状態ごとに、操作員にそのまま見せられる日本語メッセージを
        // 用意する。JS 側は `err.message` を表示するだけなので、ここで
        // 言葉を整えておくことで「次に何をすべきか」が伝わる。
        $reason_messages = [
            'not_configured' => __('ライセンスが未設定です。「日報マン → ライセンス」で API URL とライセンスキーを設定してください。', 'drwp-daily-reports'),
            'inactive'       => __('ライセンスがアクティブではありません。ライセンスサーバの状態を確認してください。', 'drwp-daily-reports'),
            'expired'        => __('ライセンスの有効期限が切れています。ライセンスサーバの状態を確認してください。', 'drwp-daily-reports'),
            'domain_mismatch'=> __('ライセンスが許可されているドメインと一致しません。ライセンスサーバ側のドメイン設定を確認してください。', 'drwp-daily-reports'),
            'not_found'      => __('ライセンスサーバにこのキーが登録されていません。キーが正しいか確認してください。', 'drwp-daily-reports'),
        ];
        $message = $reason_messages[$reason]
            ?? __('ライセンスが有効ではありません。「日報マン → ライセンス」の照会結果を確認してください。', 'drwp-daily-reports');

        return new WP_Error(
            'drwp_license',
            $message,
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
                __('日付 (report_date) は YYYY-MM-DD 形式で指定してください。', 'drwp-daily-reports'),
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
        // post_template is a closed set; coerce unknown values to
        // `standard` instead of storing whatever the client sent.
        if (isset($out['post_template'])) {
            $out['post_template'] = DRWP_Labels::sanitize_post_template($out['post_template']);
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
        if (!$report) return new WP_Error('drwp_not_found', '指定された日報が見つかりませんでした。', ['status' => 404]);

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
                'display_name' => DRWP_User::display_name((int) $c->user_id) ?: (string) ($c->display_name ?? ''),
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
            return new WP_Error('drwp_empty_comment', 'コメント本文 (body) を入力してください。', ['status' => 400]);
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
            return new WP_Error('drwp_no_file', 'ファイルがアップロードされていません。', ['status' => 400]);
        }
        if (($files['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            // PHP のアップロードエラーコードを「ユーザーが次に何をすれば
            // いいか」が分かる日本語に置換。1/2 (サイズ超過) のときは
            // php.ini の現在値も載せると問い合わせが減る。
            $err_code = (int) $files['file']['error'];
            $size_limit = ini_get('upload_max_filesize') ?: '?';
            $post_limit = ini_get('post_max_size') ?: '?';
            switch ($err_code) {
                case UPLOAD_ERR_INI_SIZE: // 1
                case UPLOAD_ERR_FORM_SIZE: // 2
                    $msg = sprintf(
                        /* translators: %s: php.ini upload_max_filesize current value */
                        __('ファイルサイズが大きすぎます。サーバの上限 %s を超えています。事前に画像を縮小するか、サーバ管理者に `upload_max_filesize` と `post_max_size` の引き上げを依頼してください。', 'drwp-daily-reports'),
                        $size_limit
                    );
                    break;
                case UPLOAD_ERR_PARTIAL: // 3
                    $msg = __('ファイルが途中までしか送信されませんでした。通信状況を確認してから再度お試しください。', 'drwp-daily-reports');
                    break;
                case UPLOAD_ERR_NO_FILE: // 4
                    $msg = __('ファイルが選択されていません。', 'drwp-daily-reports');
                    break;
                case UPLOAD_ERR_NO_TMP_DIR: // 6
                case UPLOAD_ERR_CANT_WRITE: // 7
                    $msg = __('サーバ側で一時ファイルの保存に失敗しました。サーバ管理者にディスク空き容量と権限の確認を依頼してください。', 'drwp-daily-reports');
                    break;
                case UPLOAD_ERR_EXTENSION: // 8
                    $msg = __('PHP の拡張モジュールによってアップロードが中断されました。サーバ管理者に確認してください。', 'drwp-daily-reports');
                    break;
                default:
                    $msg = sprintf(
                        /* translators: %d: PHP UPLOAD_ERR_* code */
                        __('アップロードに失敗しました (PHP アップロードエラーコード %d)。', 'drwp-daily-reports'),
                        $err_code
                    );
            }
            return new WP_Error('drwp_upload_error', $msg, [
                'status'              => 400,
                'upload_error'        => $err_code,
                'upload_max_filesize' => $size_limit,
                'post_max_size'       => $post_limit,
            ]);
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
            return new WP_Error('drwp_upload_failed', 'メディアライブラリへの登録に失敗しました。サーバの権限を確認してください。', ['status' => 500]);
        }
        if (get_post_type((int) $attachment_id) !== 'attachment') {
            return new WP_Error('drwp_not_attachment', 'アップロードしたファイルが添付ファイルとして登録できませんでした。', ['status' => 500]);
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
