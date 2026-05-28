<?php
if (!defined('ABSPATH')) exit;

/**
 * Front-end shortcode that lets a logged-in field worker manage
 * their own daily reports from a phone.
 *
 * Usage:
 *
 *     [drwp_report_form]
 *
 * Three views, selected by URL state:
 *
 *   default        → list of MY past reports + filter form +
 *                    「+ 日報を書く」 button at the top
 *   ?drwp_new=1    → input form for a fresh report (the form is
 *                    the original [drwp_report_form] UI)
 *
 * The list is scoped to the current user only — workers don't need
 * to see (or accidentally edit) other people's reports here. A
 * separate [drwp_report_archive] shortcode exists for team-wide
 * browsing.
 *
 * Requirements for the visitor:
 *   - logged in (WP cookie auth supplies the REST nonce)
 *   - has the edit_posts capability (Contributor or higher)
 *   - the plugin's license is active or in grace, otherwise the
 *     REST POST returns 402 and we surface the message verbatim
 */
class DRWP_Report_Form {

    const HANDLE = 'drwp-mform';
    const PER_PAGE_DEFAULT = 20;
    const PER_PAGE_OPTIONS = [10, 20, 50];

    public static function init() {
        add_shortcode('drwp_report_form', [__CLASS__, 'render']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
        add_action('template_redirect', [__CLASS__, 'handle_post']);
    }

    public static function register_assets() {
        wp_register_style(
            self::HANDLE,
            DRWP_URL . 'public/assets/mobile-form.css',
            [],
            DRWP_VERSION
        );
        wp_register_script(
            self::HANDLE,
            DRWP_URL . 'public/assets/mobile-form.js',
            [],
            DRWP_VERSION,
            true
        );
    }

    public static function render($atts = [], $content = '') {
        if (!is_user_logged_in() || !current_user_can('edit_posts')) {
            return '';
        }
        wp_enqueue_style(self::HANDLE);

        $edit_id = absint($_GET['drwp_edit'] ?? 0);
        if ($edit_id) {
            return self::render_edit($edit_id);
        }
        if (!empty($_GET['drwp_new'])) {
            return self::render_form();
        }
        return self::render_my_list();
    }

    /* ------------------------------------------------------------
     * My list — default view
     * ------------------------------------------------------------ */

    private static function render_my_list() {
        global $wpdb;
        $reports_t = $wpdb->prefix . 'drwp_reports';
        $current_uid = get_current_user_id();

        $q       = isset($_GET['drwp_q']) ? sanitize_text_field(wp_unslash((string) $_GET['drwp_q'])) : '';
        $project = isset($_GET['drwp_project']) ? absint($_GET['drwp_project']) : 0;
        $from    = isset($_GET['drwp_from']) ? sanitize_text_field((string) $_GET['drwp_from']) : '';
        $to      = isset($_GET['drwp_to']) ? sanitize_text_field((string) $_GET['drwp_to']) : '';
        $status  = isset($_GET['drwp_status']) ? sanitize_key((string) $_GET['drwp_status']) : '';
        $per     = (int) ($_GET['drwp_per'] ?? self::PER_PAGE_DEFAULT);
        if (!in_array($per, self::PER_PAGE_OPTIONS, true)) $per = self::PER_PAGE_DEFAULT;
        $page    = max(1, (int) ($_GET['drwp_p'] ?? 1));

        // user_id always pinned — this shortcode is "my reports
        // only" by contract. Anything else would be a privacy
        // surprise for a contributor.
        $where = ['user_id = %d'];
        $args  = [$current_uid];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where[] = 'report_date >= %s';
            $args[]  = $from;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where[] = 'report_date <= %s';
            $args[]  = $to;
        }
        if ($project) {
            $where[] = 'project_id = %d';
            $args[]  = $project;
        }
        if ($status && in_array($status, ['pending', 'approved', 'needs_revision', 'edit_requested'], true)) {
            $where[] = 'review_status = %s';
            $args[]  = $status;
        }
        if ($q !== '') {
            $like = '%' . $wpdb->esc_like($q) . '%';
            $where[] = 'work_description LIKE %s';
            $args[] = $like;
        }
        $where_sql = implode(' AND ', $where);

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $reports_t WHERE $where_sql", $args
        ));
        $total_pages = max(1, (int) ceil($total / $per));
        if ($page > $total_pages) $page = $total_pages;
        $offset = ($page - 1) * $per;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $reports_t WHERE $where_sql ORDER BY report_date DESC, id DESC LIMIT %d OFFSET %d",
            array_merge($args, [$per, $offset])
        ));

        // Project list for the filter dropdown. Show only the
        // projects the worker has actually written about plus the
        // currently-selected one (if any) so the dropdown stays
        // short on long-tenured accounts. We pull DISTINCT project
        // IDs from the worker's own reports.
        $project_ids = array_filter(array_map(
            'intval',
            (array) $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT project_id FROM $reports_t WHERE user_id = %d AND project_id IS NOT NULL",
                $current_uid
            ))
        ));
        if ($project && !in_array($project, $project_ids, true)) {
            $project_ids[] = $project;
        }
        $projects = array_filter(array_map([DRWP_Project::class, 'find'], $project_ids));
        usort($projects, function ($a, $b) {
            return strcmp((string) $a->name, (string) $b->name);
        });

        $new_url = esc_url(add_query_arg('drwp_new', '1', get_permalink()));
        $base    = esc_url(get_permalink());

        ob_start();
        ?>
        <div class="drwp-mform-wrap drwp-mform-list-wrap">
            <?php if (isset($_GET['drwp_saved'])): ?>
                <p class="drwp-mform-status ok"><?php esc_html_e('保存しました。', 'drwp-daily-reports'); ?></p>
            <?php endif; ?>
            <?php if (isset($_GET['drwp_requested'])): ?>
                <p class="drwp-mform-status ok"><?php esc_html_e('編集依頼を送信しました。管理者の承諾をお待ちください。', 'drwp-daily-reports'); ?></p>
            <?php endif; ?>

            <p class="drwp-mform-list-actions">
                <a class="drwp-mform-new-btn" href="<?php echo $new_url; ?>">
                    + <?php esc_html_e('日報を書く', 'drwp-daily-reports'); ?>
                </a>
            </p>

            <form method="get" action="<?php echo $base; ?>" class="drwp-mform-filter">
                <div class="drwp-mform-filter-row">
                    <label class="grow">
                        <span><?php esc_html_e('キーワード', 'drwp-daily-reports'); ?></span>
                        <input type="search" name="drwp_q" value="<?php echo esc_attr($q); ?>"
                               placeholder="<?php esc_attr_e('作業内容に含まれる語', 'drwp-daily-reports'); ?>" />
                    </label>
                    <label>
                        <span><?php esc_html_e('現場', 'drwp-daily-reports'); ?></span>
                        <select name="drwp_project">
                            <option value="0"><?php esc_html_e('すべて', 'drwp-daily-reports'); ?></option>
                            <?php foreach ($projects as $p): ?>
                                <option value="<?php echo (int) $p->id; ?>" <?php selected($project, (int) $p->id); ?>>
                                    <?php echo esc_html($p->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="drwp-mform-filter-row">
                    <label>
                        <span><?php esc_html_e('開始日', 'drwp-daily-reports'); ?></span>
                        <input type="date" name="drwp_from" value="<?php echo esc_attr($from); ?>" />
                    </label>
                    <label>
                        <span><?php esc_html_e('終了日', 'drwp-daily-reports'); ?></span>
                        <input type="date" name="drwp_to" value="<?php echo esc_attr($to); ?>" />
                    </label>
                    <label>
                        <span><?php esc_html_e('ステータス', 'drwp-daily-reports'); ?></span>
                        <select name="drwp_status">
                            <option value=""><?php esc_html_e('すべて', 'drwp-daily-reports'); ?></option>
                            <option value="pending"        <?php selected($status, 'pending'); ?>><?php echo esc_html(DRWP_Labels::review_status('pending')); ?></option>
                            <option value="approved"       <?php selected($status, 'approved'); ?>><?php echo esc_html(DRWP_Labels::review_status('approved')); ?></option>
                            <option value="needs_revision" <?php selected($status, 'needs_revision'); ?>><?php echo esc_html(DRWP_Labels::review_status('needs_revision')); ?></option>
                            <option value="edit_requested" <?php selected($status, 'edit_requested'); ?>><?php echo esc_html(DRWP_Labels::review_status('edit_requested')); ?></option>
                        </select>
                    </label>
                    <label>
                        <span><?php esc_html_e('表示件数', 'drwp-daily-reports'); ?></span>
                        <select name="drwp_per">
                            <?php foreach (self::PER_PAGE_OPTIONS as $opt): ?>
                                <option value="<?php echo (int) $opt; ?>" <?php selected($per, $opt); ?>>
                                    <?php echo (int) $opt; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="drwp-mform-filter-row">
                    <button type="submit" class="drwp-mform-filter-submit">
                        <?php esc_html_e('絞り込み', 'drwp-daily-reports'); ?>
                    </button>
                    <a class="drwp-mform-filter-reset" href="<?php echo $base; ?>">
                        <?php esc_html_e('条件をクリア', 'drwp-daily-reports'); ?>
                    </a>
                </div>
            </form>

            <p class="drwp-mform-list-summary">
                <?php
                printf(
                    /* translators: 1: total count, 2: current page, 3: total pages */
                    esc_html__('全 %1$d 件 / %2$d ページ目 / 全 %3$d ページ', 'drwp-daily-reports'),
                    $total, $page, $total_pages
                );
                ?>
            </p>

            <?php if (empty($rows)): ?>
                <p class="drwp-mform-list-empty">
                    <?php esc_html_e('該当する日報がありません。「+ 日報を書く」から作成してください。', 'drwp-daily-reports'); ?>
                </p>
            <?php else: ?>
                <ul class="drwp-mform-list">
                    <?php foreach ($rows as $r): ?>
                        <?php echo self::render_list_item($r); ?>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php echo self::render_pagination($total_pages, $page); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function render_list_item($r) {
        $project = $r->project_id ? DRWP_Project::find((int) $r->project_id) : null;
        $project_name = $project ? $project->name : __('（現場未設定）', 'drwp-daily-reports');
        $started = substr((string) ($r->started_at ?? ''), 0, 5);
        $ended   = substr((string) ($r->ended_at ?? ''), 0, 5);
        $time_window = trim($started . ' - ' . $ended, ' -');
        $snippet = wp_strip_all_tags((string) $r->work_description);
        if (mb_strlen($snippet) > 80) $snippet = mb_substr($snippet, 0, 80) . '…';
        $status = (string) $r->review_status;
        $id = (int) $r->id;

        ob_start();
        ?>
        <li class="drwp-mform-list-item">
            <div class="head">
                <span class="date"><?php echo esc_html(date_i18n('Y年n月j日', strtotime((string) $r->report_date))); ?></span>
                <span class="status status-<?php echo esc_attr($status); ?>">
                    <?php echo esc_html(DRWP_Labels::review_status($status)); ?>
                </span>
            </div>
            <div class="body">
                <span class="project"><?php echo esc_html($project_name); ?></span>
                <?php if ($time_window !== ''): ?>
                    <span class="time"><?php echo esc_html($time_window); ?></span>
                <?php endif; ?>
            </div>
            <?php if ($snippet !== ''): ?>
                <p class="snippet"><?php echo esc_html($snippet); ?></p>
            <?php endif; ?>
            <div class="actions">
                <?php if ($status === 'pending'): ?>
                    <a class="drwp-mform-edit-link" href="<?php echo esc_url(add_query_arg('drwp_edit', $id, get_permalink())); ?>">
                        <?php esc_html_e('編集', 'drwp-daily-reports'); ?>
                    </a>
                <?php elseif ($status === 'approved'): ?>
                    <form method="post" action="<?php echo esc_url(get_permalink()); ?>" class="drwp-mform-inline-form">
                        <?php wp_nonce_field('drwp_request_edit_' . $id); ?>
                        <input type="hidden" name="_drwp_request_edit" value="1" />
                        <input type="hidden" name="drwp_id" value="<?php echo $id; ?>" />
                        <button type="submit" class="drwp-mform-request-btn">
                            <?php esc_html_e('編集を依頼', 'drwp-daily-reports'); ?>
                        </button>
                    </form>
                <?php elseif ($status === 'edit_requested'): ?>
                    <span class="drwp-mform-waiting"><?php esc_html_e('管理者の承諾待ち', 'drwp-daily-reports'); ?></span>
                <?php endif; ?>
            </div>
        </li>
        <?php
        return ob_get_clean();
    }

    private static function render_pagination($total_pages, $page) {
        if ($total_pages <= 1) return '';
        $base = remove_query_arg('drwp_p');

        ob_start();
        ?>
        <nav class="drwp-mform-pagination">
            <?php if ($page > 1): ?>
                <a href="<?php echo esc_url(add_query_arg('drwp_p', $page - 1, $base)); ?>">&laquo; <?php esc_html_e('前', 'drwp-daily-reports'); ?></a>
            <?php else: ?>
                <span class="disabled">&laquo; <?php esc_html_e('前', 'drwp-daily-reports'); ?></span>
            <?php endif; ?>
            <span class="indicator">
                <?php
                printf(
                    /* translators: 1: current page, 2: total pages */
                    esc_html__('%1$d / %2$d', 'drwp-daily-reports'),
                    $page, $total_pages
                );
                ?>
            </span>
            <?php if ($page < $total_pages): ?>
                <a href="<?php echo esc_url(add_query_arg('drwp_p', $page + 1, $base)); ?>"><?php esc_html_e('次', 'drwp-daily-reports'); ?> &raquo;</a>
            <?php else: ?>
                <span class="disabled"><?php esc_html_e('次', 'drwp-daily-reports'); ?> &raquo;</span>
            <?php endif; ?>
        </nav>
        <?php
        return ob_get_clean();
    }

    /* ------------------------------------------------------------
     * POST handlers (template_redirect)
     * ------------------------------------------------------------ */

    public static function handle_post() {
        if (!is_user_logged_in() || !current_user_can('edit_posts')) return;

        // Handle "編集を依頼" for approved reports.
        if (!empty($_POST['_drwp_request_edit'])) {
            $id = absint($_POST['drwp_id'] ?? 0);
            if (!$id) return;
            check_admin_referer('drwp_request_edit_' . $id);

            global $wpdb;
            $table = $wpdb->prefix . 'drwp_reports';
            $report = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
            if (!$report) return;
            if ((int) $report->user_id !== get_current_user_id()) return;
            if ($report->review_status !== 'approved') return;

            $wpdb->update($table, ['review_status' => 'edit_requested'], ['id' => $id]);
            DRWP_Audit::log('edit_requested', '編集依頼を送信', $id, []);
            do_action('drwp_review_changed', $id, 'approved', 'edit_requested', '');

            wp_safe_redirect(add_query_arg('drwp_requested', '1', get_permalink()));
            exit;
        }

        // Handle edit form save for own pending reports.
        if (!empty($_POST['_drwp_form_edit'])) {
            $id = absint($_POST['drwp_id'] ?? 0);
            if (!$id) return;
            check_admin_referer('drwp_form_edit_' . $id);

            global $wpdb;
            $table = $wpdb->prefix . 'drwp_reports';
            $report = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
            if (!$report) return;
            if ((int) $report->user_id !== get_current_user_id()) return;
            if ($report->review_status !== 'pending') return;
            if (!DRWP_License::can_write()) {
                wp_safe_redirect(add_query_arg(['drwp_edit' => $id, 'drwp_err' => 'license'], get_permalink()));
                exit;
            }

            $project_id = absint($_POST['project_id'] ?? 0);
            $work = trim((string) wp_unslash($_POST['work_description'] ?? ''));
            if (!$project_id) {
                wp_safe_redirect(add_query_arg(['drwp_edit' => $id, 'drwp_err' => 'noproject'], get_permalink()));
                exit;
            }
            if ($work === '') {
                wp_safe_redirect(add_query_arg(['drwp_edit' => $id, 'drwp_err' => 'nowork'], get_permalink()));
                exit;
            }

            $update = [
                'project_id'       => $project_id,
                'started_at'       => self::sanitize_time($_POST['started_at'] ?? ''),
                'ended_at'         => self::sanitize_time($_POST['ended_at'] ?? ''),
                'work_description' => wp_kses_post(wp_unslash($work)),
                'issues'           => wp_kses_post(wp_unslash((string) ($_POST['issues'] ?? ''))),
                'next_plan'        => wp_kses_post(wp_unslash((string) ($_POST['next_plan'] ?? ''))),
            ];
            $report_date = sanitize_text_field((string) wp_unslash($_POST['report_date'] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date)) {
                $update['report_date'] = $report_date;
            }
            $wpdb->update($table, $update, ['id' => $id]);

            $att_ids  = array_map('absint', (array) ($_POST['attachment_ids'] ?? []));
            $captions = array_map('sanitize_text_field', array_map('wp_unslash', (array) ($_POST['attachment_captions'] ?? [])));
            $rows = [];
            foreach ($att_ids as $i => $aid) {
                if (!$aid) continue;
                $rows[] = ['attachment_id' => $aid, 'caption' => (string) ($captions[$i] ?? '')];
            }
            DRWP_Media::sync($id, $rows);
            DRWP_Audit::log('report_edited_frontend', '日報をフロントから編集', $id, []);

            wp_safe_redirect(add_query_arg('drwp_saved', '1', get_permalink()));
            exit;
        }
    }

    private static function sanitize_time($v) {
        $v = trim((string) $v);
        if ($v === '') return null;
        if (preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $v, $m)) {
            return sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0));
        }
        return null;
    }

    /* ------------------------------------------------------------
     * Edit form — ?drwp_edit=N (own pending only)
     * ------------------------------------------------------------ */

    private static function render_edit($id) {
        global $wpdb;
        $report = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . $wpdb->prefix . "drwp_reports WHERE id = %d", $id
        ));
        if (!$report || (int) $report->user_id !== get_current_user_id()
            || $report->review_status !== 'pending') {
            return self::wrap('<p class="drwp-mform-list-empty">'
                . esc_html__('この日報は編集できません(自分のレビュー待ち日報のみ編集可能です)。', 'drwp-daily-reports')
                . '</p>');
        }

        wp_enqueue_script('drwp-archive-edit', DRWP_URL . 'public/assets/archive-edit.js', [], DRWP_VERSION, true);

        $projects = DRWP_Project::all();
        $photos   = DRWP_Media::for_report((int) $report->id);
        $back     = esc_url(remove_query_arg('drwp_edit'));
        $action   = esc_url(get_permalink());

        $flash = isset($_GET['drwp_err']) ? sanitize_key((string) $_GET['drwp_err']) : '';

        ob_start();
        ?>
        <div class="drwp-mform-wrap drwp-mform-edit-wrap">
            <p class="drwp-mform-back">
                <a href="<?php echo $back; ?>">&laquo; <?php esc_html_e('一覧に戻る', 'drwp-daily-reports'); ?></a>
            </p>
            <h2 class="drwp-mform-edit-title"><?php esc_html_e('日報を編集', 'drwp-daily-reports'); ?></h2>

            <?php if ($flash === 'noproject'): ?>
                <p class="drwp-mform-status err"><?php esc_html_e('現場を選択してください。', 'drwp-daily-reports'); ?></p>
            <?php elseif ($flash === 'nowork'): ?>
                <p class="drwp-mform-status err"><?php esc_html_e('作業内容を入力してください。', 'drwp-daily-reports'); ?></p>
            <?php elseif ($flash === 'license'): ?>
                <p class="drwp-mform-status err"><?php esc_html_e('現在保存できない状態です。', 'drwp-daily-reports'); ?></p>
            <?php endif; ?>

            <form method="post" action="<?php echo $action; ?>"
                  class="drwp-mform drwp-archive-edit-form"
                  data-drwp-edit-config="<?php echo esc_attr(wp_json_encode([
                      'rest_root' => esc_url_raw(rest_url('drwp/v1/')),
                      'nonce'     => wp_create_nonce('wp_rest'),
                      'i18n'      => [
                          'uploading'    => __('アップロード中…', 'drwp-daily-reports'),
                          'upload_failed'=> __('アップロード失敗', 'drwp-daily-reports'),
                      ],
                  ])); ?>" novalidate>
                <?php wp_nonce_field('drwp_form_edit_' . $id); ?>
                <input type="hidden" name="_drwp_form_edit" value="1" />
                <input type="hidden" name="drwp_id" value="<?php echo (int) $id; ?>" />

                <label class="drwp-mform-row">
                    <span class="drwp-mform-label"><?php esc_html_e('日付', 'drwp-daily-reports'); ?></span>
                    <input type="date" name="report_date"
                           value="<?php echo esc_attr((string) $report->report_date); ?>" required />
                </label>
                <label class="drwp-mform-row">
                    <span class="drwp-mform-label"><?php esc_html_e('現場', 'drwp-daily-reports'); ?> <em>*</em></span>
                    <select name="project_id" required>
                        <option value=""><?php esc_html_e('選択してください', 'drwp-daily-reports'); ?></option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?php echo (int) $p->id; ?>" <?php selected((int) $report->project_id, (int) $p->id); ?>>
                                <?php echo esc_html($p->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="drwp-mform-times">
                    <label class="col">
                        <?php esc_html_e('開始時刻', 'drwp-daily-reports'); ?>
                        <input type="time" name="started_at"
                               value="<?php echo esc_attr(substr((string) ($report->started_at ?? ''), 0, 5)); ?>" />
                    </label>
                    <label class="col">
                        <?php esc_html_e('終了時刻', 'drwp-daily-reports'); ?>
                        <input type="time" name="ended_at"
                               value="<?php echo esc_attr(substr((string) ($report->ended_at ?? ''), 0, 5)); ?>" />
                    </label>
                </div>
                <label class="drwp-mform-row">
                    <span class="drwp-mform-label"><?php esc_html_e('作業内容', 'drwp-daily-reports'); ?> <em>*</em></span>
                    <textarea name="work_description" rows="4" required><?php echo esc_textarea((string) $report->work_description); ?></textarea>
                </label>
                <label class="drwp-mform-row">
                    <span class="drwp-mform-label"><?php esc_html_e('特記事項（反省・連絡・相談・提案、任意）', 'drwp-daily-reports'); ?></span>
                    <textarea name="issues" rows="2"><?php echo esc_textarea((string) $report->issues); ?></textarea>
                </label>
                <label class="drwp-mform-row">
                    <span class="drwp-mform-label"><?php esc_html_e('次回予定 (任意)', 'drwp-daily-reports'); ?></span>
                    <textarea name="next_plan" rows="2"><?php echo esc_textarea((string) $report->next_plan); ?></textarea>
                </label>

                <div class="drwp-mform-row">
                    <span class="drwp-mform-label"><?php esc_html_e('写真', 'drwp-daily-reports'); ?></span>
                    <div class="drwp-archive-edit-photos" data-role="photos">
                        <?php foreach ($photos as $photo): ?>
                            <?php $thumb = wp_get_attachment_image_url((int) $photo->attachment_id, 'medium'); ?>
                            <div class="drwp-archive-edit-photo-item">
                                <?php if ($thumb): ?>
                                    <img src="<?php echo esc_url($thumb); ?>" alt="" />
                                <?php endif; ?>
                                <input type="hidden" name="attachment_ids[]"
                                       value="<?php echo (int) $photo->attachment_id; ?>" />
                                <input type="text" name="attachment_captions[]"
                                       placeholder="<?php esc_attr_e('キャプション', 'drwp-daily-reports'); ?>"
                                       value="<?php echo esc_attr((string) $photo->caption); ?>" />
                                <button type="button" class="drwp-archive-edit-photo-remove" data-role="remove-photo">×</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <label class="drwp-archive-edit-photo-pick">
                        + <?php esc_html_e('写真を追加', 'drwp-daily-reports'); ?>
                        <input type="file" accept="image/*" multiple data-role="photo-input" />
                    </label>
                    <p class="drwp-archive-edit-photo-status" data-role="photo-status"></p>
                </div>

                <div class="drwp-mform-edit-actions">
                    <button type="submit" class="drwp-mform-submit"><?php esc_html_e('保存する', 'drwp-daily-reports'); ?></button>
                    <a class="drwp-mform-edit-cancel" href="<?php echo $back; ?>"><?php esc_html_e('キャンセル', 'drwp-daily-reports'); ?></a>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ------------------------------------------------------------
     * Input form — ?drwp_new=1
     * ------------------------------------------------------------ */

    private static function render_form() {
        $projects = array_map(function ($p) {
            return ['id' => (int) $p->id, 'name' => (string) $p->name];
        }, DRWP_Project::all());

        $config = [
            'rest_root'   => esc_url_raw(rest_url('drwp/v1/')),
            'nonce'       => wp_create_nonce('wp_rest'),
            'today'       => current_time('Y-m-d'),
            'license_ok'  => DRWP_License::can_write(),
            'projects'    => $projects,
            'i18n'        => [
                'pick_project' => __('選択してください', 'drwp-daily-reports'),
                'need_project' => __('現場を選択してください。', 'drwp-daily-reports'),
                'need_work'    => __('作業内容を入力してください。', 'drwp-daily-reports'),
                'uploading'    => __('写真をアップロード中…', 'drwp-daily-reports'),
                'sending'      => __('送信中…', 'drwp-daily-reports'),
                'sent'         => __('送信しました。レビュー待ちに入っています。', 'drwp-daily-reports'),
                'send_failed'  => __('送信に失敗しました。', 'drwp-daily-reports'),
            ],
        ];

        wp_enqueue_script(self::HANDLE);

        $config_attr = wp_json_encode($config);
        if ($config_attr === false) $config_attr = '{}';

        $back_url = esc_url(remove_query_arg('drwp_new'));

        ob_start();
        ?>
        <div class="drwp-mform-wrap" data-drwp-mform-config="<?php echo esc_attr($config_attr); ?>">
            <p class="drwp-mform-back">
                <a href="<?php echo $back_url; ?>">&laquo; <?php esc_html_e('一覧に戻る', 'drwp-daily-reports'); ?></a>
            </p>
            <?php if (!$config['license_ok']) : ?>
                <p class="drwp-mform-warn">
                    <?php esc_html_e('現在ライセンスが有効ではないため、送信しても保存されません。管理者に確認してください。', 'drwp-daily-reports'); ?>
                </p>
            <?php endif; ?>

            <form class="drwp-mform" id="drwp-mform" novalidate>
                <label class="drwp-mform-row">
                    <span class="drwp-mform-label"><?php esc_html_e('日付', 'drwp-daily-reports'); ?></span>
                    <input type="date" name="report_date" value="<?php echo esc_attr($config['today']); ?>" required>
                </label>

                <label class="drwp-mform-row">
                    <span class="drwp-mform-label">
                        <?php esc_html_e('現場', 'drwp-daily-reports'); ?> <em>*</em>
                    </span>
                    <select name="project_id" required>
                        <option value=""><?php esc_html_e('選択してください', 'drwp-daily-reports'); ?></option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?php echo (int) $p['id']; ?>"><?php echo esc_html($p['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="drwp-mform-times">
                    <label class="col">
                        <?php esc_html_e('開始時刻', 'drwp-daily-reports'); ?>
                        <input type="time" name="started_at">
                    </label>
                    <label class="col">
                        <?php esc_html_e('終了時刻', 'drwp-daily-reports'); ?>
                        <input type="time" name="ended_at">
                    </label>
                </div>

                <label class="drwp-mform-row">
                    <span class="drwp-mform-label">
                        <?php esc_html_e('作業内容', 'drwp-daily-reports'); ?> <em>*</em>
                    </span>
                    <textarea name="work_description" rows="4" required></textarea>
                </label>

                <label class="drwp-mform-row">
                    <span class="drwp-mform-label"><?php esc_html_e('特記事項（反省・連絡・相談・提案、任意）', 'drwp-daily-reports'); ?></span>
                    <textarea name="issues" rows="2"></textarea>
                </label>

                <label class="drwp-mform-row">
                    <span class="drwp-mform-label"><?php esc_html_e('次回予定 (任意)', 'drwp-daily-reports'); ?></span>
                    <textarea name="next_plan" rows="2"></textarea>
                </label>

                <div class="drwp-mform-row">
                    <span class="drwp-mform-label"><?php esc_html_e('写真', 'drwp-daily-reports'); ?></span>
                    <label class="drwp-mform-photo-pick">
                        <span><?php esc_html_e('カメラで撮影 / 端末から選択', 'drwp-daily-reports'); ?></span>
                        <input type="file" accept="image/*" capture="environment" multiple data-role="photo-input">
                    </label>
                    <div class="drwp-mform-photos" data-role="photo-preview"></div>
                </div>

                <button type="submit" class="drwp-mform-submit">
                    <?php esc_html_e('下書きとして送信', 'drwp-daily-reports'); ?>
                </button>

                <p class="drwp-mform-help">
                    <?php esc_html_e('送信した日報は「レビュー待ち」として保存されます。事務所側で内容を確認のうえ、必要に応じて公開されます。', 'drwp-daily-reports'); ?>
                </p>

                <div class="drwp-mform-status" data-role="status" aria-live="polite"></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function wrap($inner) {
        return '<div class="drwp-mform-wrap">' . $inner . '</div>';
    }
}
