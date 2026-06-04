<?php
if (!defined('ABSPATH')) exit;

/**
 * Frontend "past reports" archive — [drwp_report_archive] shortcode.
 *
 * One shortcode renders three views, picked by URL state:
 *
 *   default                → list with filter form, pagination
 *   ?drwp_id=N             → single report (read-only)
 *
 * (?drwp_id=N&drwp_edit=1 — frontend edit for own pending reports —
 *  is a follow-up; placeholder link is exposed in the single view
 *  so callers can see where it'll land.)
 *
 * Visibility
 *   Any logged-in user with edit_posts can see every report — this
 *   is broader than the admin scope (where contributors only see
 *   their own) on purpose. The archive is a team-visible record of
 *   what was done; the office's admin view stays scoped per-user
 *   for the review workflow. Status badges are shown so an
 *   approved record is visually distinct from a pending one.
 *
 * Search
 *   Free-text query LIKEs against both the legacy report-level
 *   work_description AND the per-entry work_description (via
 *   EXISTS subquery). Author / date-range / status are exact
 *   filters. Pagination + per-page selector follow standard
 *   listing-page idioms — values are validated against an
 *   allow-list so the URL can't ask for weird page sizes.
 */
class DRWP_Report_Archive {

    const HANDLE      = 'drwp-archive';
    const HANDLE_EDIT = 'drwp-archive-edit';

    public static function init() {
        add_shortcode('drwp_report_archive', [__CLASS__, 'shortcode']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
        // Register our query vars with WordPress so canonical_redirect
        // doesn't strip them on static-front-page sites. Without this,
        // clicking the month-nav button on a homepage-mounted shortcode
        // ends up at "/" because WP doesn't recognize drwp_month.
        add_filter('query_vars', [__CLASS__, 'register_query_vars']);
        // Phase B: own-pending edit POST handler runs on
        // template_redirect so we can wp_safe_redirect after a
        // successful save (PRG pattern, same as the lost-password
        // flow). Output hasn't started yet at this hook.
        add_action('template_redirect', [__CLASS__, 'handle_edit_post']);
    }

    public static function register_query_vars($vars) {
        return array_merge($vars, [
            'drwp_month', 'drwp_q', 'drwp_project', 'drwp_status',
            'drwp_id', 'drwp_edit', 'drwp_new', 'drwp_saved',
            'drwp_requested', 'drwp_err',
        ]);
    }

    public static function register_assets() {
        wp_register_style(
            self::HANDLE,
            DRWP_URL . 'public/assets/archive.css',
            [],
            DRWP_VERSION
        );
        wp_register_script(
            self::HANDLE_EDIT,
            DRWP_URL . 'public/assets/archive-edit.js',
            [],
            DRWP_VERSION,
            true
        );
    }

    public static function shortcode($atts = []) {
        wp_enqueue_style(self::HANDLE);

        if (!is_user_logged_in()) {
            return self::wrap('<p class="drwp-archive-message">'
                . esc_html__('閲覧にはログインが必要です。', 'drwp-daily-reports')
                . '</p>');
        }
        if (!current_user_can('edit_posts')) {
            return self::wrap('<p class="drwp-archive-message">'
                . esc_html__('閲覧する権限がありません。', 'drwp-daily-reports')
                . '</p>');
        }

        $id = absint($_GET['drwp_id'] ?? 0);
        if ($id) {
            $edit = !empty($_GET['drwp_edit']);
            if ($edit) return self::render_edit($id);
            return self::render_single($id);
        }
        return self::render_list();
    }

    private static function wrap($inner) {
        return '<div class="drwp-archive-wrap">' . $inner . '</div>';
    }

    /* ------------------------------------------------------------
     * List view
     * ------------------------------------------------------------ */

    private static function render_list() {
        return self::render_month_view([
            'title' => __('日報カレンダー', 'drwp-daily-reports'),
        ]);
    }

    /**
     * Render a month-view calendar of reports. Shared between this
     * archive shortcode (team-wide) and DRWP_Report_Form's "my list"
     * default view (scoped to the current user via user_id).
     *
     * Options:
     *   user_id          int  - 0 = team-wide, else scope to that user
     *   show_new_button  bool - render "+日報を書く" CTA above the filter
     *   new_url          string - href for the CTA
     *   title            string - optional H2 above the view
     *   extra_message    string - optional HTML banner (e.g. "保存しました")
     */
    public static function render_month_view(array $opts = []) {
        $opts = array_merge([
            'user_id'         => 0,
            'show_new_button' => false,
            'new_url'         => '',
            'title'           => '',
            'extra_message'   => '',
        ], $opts);

        wp_enqueue_style(self::HANDLE);

        global $wpdb;
        $reports_t = $wpdb->prefix . 'drwp_reports';

        $q       = isset($_GET['drwp_q']) ? sanitize_text_field(wp_unslash((string) $_GET['drwp_q'])) : '';
        $project = isset($_GET['drwp_project']) ? absint($_GET['drwp_project']) : 0;
        $status  = isset($_GET['drwp_status']) ? sanitize_key((string) $_GET['drwp_status']) : '';

        // Month navigation. Default to the current month so the view
        // opens on "今月". URL state lets users bookmark a specific month.
        $month_param = isset($_GET['drwp_month']) ? sanitize_text_field((string) $_GET['drwp_month']) : '';
        if (!preg_match('/^\d{4}-\d{2}$/', $month_param)) {
            $month_param = date('Y-m');
        }
        $month_start = $month_param . '-01';
        $month_end   = date('Y-m-t', strtotime($month_start));
        $prev_month  = date('Y-m', strtotime($month_start . ' -1 month'));
        $next_month  = date('Y-m', strtotime($month_start . ' +1 month'));
        $today_month = date('Y-m');

        $where = ['r.report_date >= %s', 'r.report_date <= %s'];
        $args  = [$month_start, $month_end];
        if ($opts['user_id']) {
            $where[] = 'r.user_id = %d';
            $args[]  = (int) $opts['user_id'];
        }
        if ($project) {
            $where[] = 'r.project_id = %d';
            $args[]  = $project;
        }
        if ($status && in_array($status, ['pending', 'approved', 'needs_revision', 'edit_requested'], true)) {
            $where[] = 'r.review_status = %s';
            $args[]  = $status;
        }
        if ($q !== '') {
            $like = '%' . $wpdb->esc_like($q) . '%';
            $where[] = 'r.work_description LIKE %s';
            $args[]  = $like;
        }
        $where_sql = implode(' AND ', $where);

        $list_sql = "SELECT r.* FROM $reports_t r WHERE $where_sql ORDER BY r.report_date ASC, r.started_at ASC, r.id ASC";
        $rows = $wpdb->get_results($wpdb->prepare($list_sql, $args));

        $by_date = [];
        foreach ($rows as $r) {
            $by_date[(string) $r->report_date][] = $r;
        }

        // For the my-list view we restrict the project dropdown to
        // projects the worker has actually visited (plus the
        // currently-selected one, if any). Archive view shows all
        // projects so reviewers can filter widely.
        if ($opts['user_id']) {
            $projects = self::projects_for_user((int) $opts['user_id'], $project);
        } else {
            $projects = DRWP_Project::all();
        }

        ob_start();
        ?>
        <div class="drwp-archive-wrap">
            <?php if (!empty($opts['title'])): ?>
                <h2 class="drwp-archive-title"><?php echo esc_html($opts['title']); ?></h2>
            <?php endif; ?>

            <?php if (!empty($opts['extra_message'])): ?>
                <div class="drwp-archive-flash"><?php echo wp_kses_post($opts['extra_message']); ?></div>
            <?php endif; ?>

            <?php if ($opts['show_new_button'] && $opts['new_url']): ?>
                <p class="drwp-archive-actions">
                    <a class="drwp-archive-new-btn" href="<?php echo esc_url($opts['new_url']); ?>">
                        + <?php esc_html_e('日報を書く', 'drwp-daily-reports'); ?>
                    </a>
                </p>
            <?php endif; ?>

            <?php echo self::render_filter_form($q, $project, $status, $month_param, $projects); ?>

            <p class="drwp-archive-summary">
                <?php
                $count = count($rows);
                printf(
                    esc_html__('%1$s（%2$d 件）', 'drwp-daily-reports'),
                    esc_html(date_i18n('Y年n月', strtotime($month_start))),
                    $count
                );
                ?>
            </p>

            <?php echo self::render_calendar($month_param, $month_start, $by_date, $prev_month, $next_month, $today_month, ['q' => $q, 'project' => $project, 'status' => $status]); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function projects_for_user($user_id, $extra_id = 0) {
        global $wpdb;
        $reports_t = $wpdb->prefix . 'drwp_reports';
        $ids = array_filter(array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT project_id FROM $reports_t WHERE user_id = %d AND project_id IS NOT NULL",
            $user_id
        ))));
        if ($extra_id && !in_array($extra_id, $ids, true)) {
            $ids[] = $extra_id;
        }
        $projects = array_filter(array_map([DRWP_Project::class, 'find'], $ids));
        usort($projects, function ($a, $b) {
            return strcmp((string) $a->name, (string) $b->name);
        });
        return $projects;
    }

    private static function render_filter_form($q, $project, $status, $month_param, $projects) {
        // Empty form action submits to the current URL. We avoid
        // get_permalink() here because on a static-front-page setup
        // it can resolve to the site root and "lose" the archive
        // page entirely when the user clicks 絞り込み.
        $statuses = [
            ''                   => __('すべて', 'drwp-daily-reports'),
            'pending'            => DRWP_Labels::review_status('pending'),
            'approved'           => DRWP_Labels::review_status('approved'),
            'needs_revision'     => DRWP_Labels::review_status('needs_revision'),
        ];
        ob_start();
        ?>
        <form method="get" action="" class="drwp-archive-filter">
            <input type="hidden" name="drwp_month" value="<?php echo esc_attr($month_param); ?>" />
            <div class="drwp-archive-filter-row">
                <label class="drwp-archive-field grow">
                    <span><?php esc_html_e('キーワード', 'drwp-daily-reports'); ?></span>
                    <input type="search" name="drwp_q" value="<?php echo esc_attr($q); ?>"
                           placeholder="<?php esc_attr_e('作業内容に含まれる語', 'drwp-daily-reports'); ?>" />
                </label>
                <label class="drwp-archive-field">
                    <span><?php esc_html_e('現場', 'drwp-daily-reports'); ?></span>
                    <select name="drwp_project">
                        <option value="0"><?php esc_html_e('すべて', 'drwp-daily-reports'); ?></option>
                        <?php foreach (($projects ?? []) as $p): ?>
                            <option value="<?php echo (int) $p->id; ?>" <?php selected($project, (int) $p->id); ?>>
                                <?php echo esc_html($p->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="drwp-archive-field">
                    <span><?php esc_html_e('ステータス', 'drwp-daily-reports'); ?></span>
                    <select name="drwp_status">
                        <?php foreach ($statuses as $val => $label): ?>
                            <option value="<?php echo esc_attr($val); ?>" <?php selected($status, $val); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="drwp-archive-filter-row">
                <button type="submit" class="drwp-archive-submit">
                    <?php esc_html_e('絞り込み', 'drwp-daily-reports'); ?>
                </button>
                <a class="drwp-archive-reset" href="<?php echo esc_url(strtok($_SERVER['REQUEST_URI'] ?? '', '?')); ?>">
                    <?php esc_html_e('条件をクリア', 'drwp-daily-reports'); ?>
                </a>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    private static function render_calendar($month_param, $month_start, $by_date, $prev_month, $next_month, $today_month, $filters) {
        // Build URLs relative to the current request path so links
        // keep working on static-front-page setups where
        // get_permalink() would resolve to the site root.
        $base = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
        $build_url = function ($month) use ($base, $filters) {
            $args = ['drwp_month' => $month];
            if (!empty($filters['q']))       $args['drwp_q']       = $filters['q'];
            if (!empty($filters['project'])) $args['drwp_project'] = (int) $filters['project'];
            if (!empty($filters['status']))  $args['drwp_status']  = $filters['status'];
            return esc_url(add_query_arg($args, $base));
        };

        $year  = (int) date('Y', strtotime($month_start));
        $month = (int) date('n', strtotime($month_start));
        $first_dow = (int) date('w', strtotime($month_start));
        $days_in_month = (int) date('t', strtotime($month_start));
        $today = date('Y-m-d');

        $dows = ['日', '月', '火', '水', '木', '金', '土'];

        ob_start();
        ?>
        <div class="drwp-archive-cal">
          <div class="drwp-archive-cal-nav">
            <a class="drwp-archive-cal-btn" href="<?php echo $build_url($prev_month); ?>" aria-label="<?php esc_attr_e('前の月', 'drwp-daily-reports'); ?>">‹</a>
            <h3 class="drwp-archive-cal-month"><?php echo esc_html($year . '年 ' . $month . '月'); ?></h3>
            <a class="drwp-archive-cal-btn" href="<?php echo $build_url($next_month); ?>" aria-label="<?php esc_attr_e('次の月', 'drwp-daily-reports'); ?>">›</a>
            <?php if ($month_param !== $today_month): ?>
              <a class="drwp-archive-cal-today" href="<?php echo $build_url($today_month); ?>"><?php esc_html_e('今月', 'drwp-daily-reports'); ?></a>
            <?php endif; ?>
          </div>

          <div class="drwp-archive-cal-grid">
            <?php foreach ($dows as $i => $d): ?>
              <div class="drwp-archive-cal-dow<?php echo $i === 0 ? ' sun' : ($i === 6 ? ' sat' : ''); ?>"><?php echo esc_html($d); ?></div>
            <?php endforeach; ?>

            <?php for ($i = 0; $i < $first_dow; $i++): ?>
              <div class="drwp-archive-cal-cell empty"></div>
            <?php endfor; ?>

            <?php for ($d = 1; $d <= $days_in_month; $d++):
              $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
              $dow = ((int) date('w', strtotime($date)));
              $cell_cls = 'drwp-archive-cal-cell';
              if ($dow === 0) $cell_cls .= ' sun';
              if ($dow === 6) $cell_cls .= ' sat';
              if ($date === $today) $cell_cls .= ' today';
              $items = $by_date[$date] ?? [];
            ?>
              <div class="<?php echo esc_attr($cell_cls); ?>">
                <div class="drwp-archive-cal-day"><?php echo (int) $d; ?></div>
                <?php foreach ($items as $r):
                  $proj = $r->project_id ? DRWP_Project::find((int) $r->project_id) : null;
                  $proj_name = $proj ? $proj->name : __('（現場未設定）', 'drwp-daily-reports');
                  $href = esc_url(add_query_arg('drwp_id', (int) $r->id, $base));
                  $time = self::format_time_window($r->started_at ?? '', $r->ended_at ?? '');
                ?>
                  <a class="drwp-archive-cal-chip status-<?php echo esc_attr((string) $r->review_status); ?>"
                     href="<?php echo $href; ?>"
                     title="<?php echo esc_attr($proj_name . ($time ? ' / ' . $time : '')); ?>">
                    <?php if ($time !== ''): ?><span class="drwp-archive-cal-chip-time"><?php echo esc_html(substr($time, 0, 5)); ?></span><?php endif; ?>
                    <span class="drwp-archive-cal-chip-text"><?php echo esc_html($proj_name); ?></span>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php endfor;

            // Pad the trailing cells so the grid completes its last row.
            $total_cells = $first_dow + $days_in_month;
            $trailing = (7 - ($total_cells % 7)) % 7;
            for ($i = 0; $i < $trailing; $i++): ?>
              <div class="drwp-archive-cal-cell empty"></div>
            <?php endfor; ?>
          </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function format_time_window($started_at, $ended_at) {
        $s = substr((string) $started_at, 0, 5);
        $e = substr((string) $ended_at, 0, 5);
        if ($s === '' && $e === '') return '';
        return trim($s . ' - ' . $e, ' -');
    }

    /* ------------------------------------------------------------
     * Single view (read-only)
     * ------------------------------------------------------------ */

    private static function render_single($id) {
        global $wpdb;
        $report = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . $wpdb->prefix . "drwp_reports WHERE id = %d", $id
        ));
        if (!$report) {
            return self::wrap('<p class="drwp-archive-message">'
                . esc_html__('該当する日報が見つかりません。', 'drwp-daily-reports')
                . '</p>');
        }

        $author = get_userdata((int) $report->user_id);
        $author_name = $author ? $author->display_name : '-';
        $back_url = esc_url(remove_query_arg('drwp_id'));
        $is_own_pending = ((int) $report->user_id === get_current_user_id())
                          && $report->review_status === 'pending';

        $project = $report->project_id ? DRWP_Project::find((int) $report->project_id) : null;
        $project_name = $project ? $project->name : '';
        $time_window = self::format_time_window($report->started_at ?? '', $report->ended_at ?? '');
        $photos = DRWP_Media::for_report((int) $report->id);

        ob_start();
        ?>
        <div class="drwp-archive-wrap drwp-archive-single">
            <p class="drwp-archive-back">
                <a href="<?php echo $back_url; ?>">&laquo; <?php esc_html_e('一覧に戻る', 'drwp-daily-reports'); ?></a>
            </p>

            <header class="drwp-archive-single-head">
                <h2>
                    <?php echo esc_html((string) $report->report_date); ?>
                    <?php if ($project_name !== ''): ?>
                        <span class="drwp-archive-project-inline"><?php echo esc_html($project_name); ?></span>
                    <?php endif; ?>
                    <span class="drwp-archive-status status-<?php echo esc_attr((string) $report->review_status); ?>">
                        <?php echo esc_html(DRWP_Labels::review_status((string) $report->review_status)); ?>
                    </span>
                </h2>
                <p class="drwp-archive-meta">
                    <?php esc_html_e('作成者:', 'drwp-daily-reports'); ?>
                    <strong><?php echo esc_html($author_name); ?></strong>
                    <?php if ($time_window !== ''): ?>
                        <span class="separator">·</span>
                        <span><?php esc_html_e('時刻:', 'drwp-daily-reports'); ?> <?php echo esc_html($time_window); ?></span>
                    <?php endif; ?>
                </p>
                <?php if ($is_own_pending): ?>
                    <p class="drwp-archive-edit-cta">
                        <?php esc_html_e('この日報はあなたのレビュー待ち日報です。', 'drwp-daily-reports'); ?>
                        <a class="drwp-archive-edit-link" href="<?php echo esc_url(add_query_arg('drwp_edit', '1')); ?>">
                            <?php esc_html_e('フロントから編集する', 'drwp-daily-reports'); ?>
                        </a>
                    </p>
                <?php endif; ?>
                <?php if (isset($_GET['drwp_saved'])): ?>
                    <p class="drwp-archive-flash ok">
                        <?php esc_html_e('保存しました。', 'drwp-daily-reports'); ?>
                    </p>
                <?php endif; ?>
            </header>

            <section class="drwp-archive-section">
                <?php if (!empty($report->work_description)): ?>
                    <div class="drwp-archive-block">
                        <h4><?php esc_html_e('作業内容', 'drwp-daily-reports'); ?></h4>
                        <?php echo wp_kses_post(wpautop((string) $report->work_description)); ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($report->issues)): ?>
                    <div class="drwp-archive-block">
                        <h4><?php esc_html_e('特記事項', 'drwp-daily-reports'); ?></h4>
                        <?php echo wp_kses_post(wpautop((string) $report->issues)); ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($report->next_plan)): ?>
                    <div class="drwp-archive-block">
                        <h4><?php esc_html_e('次回予定', 'drwp-daily-reports'); ?></h4>
                        <?php echo wp_kses_post(wpautop((string) $report->next_plan)); ?>
                    </div>
                <?php endif; ?>
                <?php echo self::render_photo_grid($photos); ?>
            </section>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function render_photo_grid($photos) {
        if (empty($photos)) return '';
        ob_start();
        ?>
        <div class="drwp-archive-photos">
            <?php foreach ($photos as $photo): ?>
                <?php
                $full_url = wp_get_attachment_image_url((int) $photo->attachment_id, 'large');
                $thumb_url = wp_get_attachment_image_url((int) $photo->attachment_id, 'medium');
                if (!$full_url) continue;
                ?>
                <figure class="drwp-archive-photo">
                    <a href="<?php echo esc_url($full_url); ?>" target="_blank" rel="noopener">
                        <img src="<?php echo esc_url($thumb_url ?: $full_url); ?>" alt="" loading="lazy" />
                    </a>
                    <?php if (!empty($photo->caption)): ?>
                        <figcaption><?php echo esc_html((string) $photo->caption); ?></figcaption>
                    <?php endif; ?>
                </figure>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ------------------------------------------------------------
     * Edit view (Phase B) — own pending reports only
     * ------------------------------------------------------------ */

    private static function render_edit($id) {
        global $wpdb;
        $report = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . $wpdb->prefix . "drwp_reports WHERE id = %d", $id
        ));
        if (!$report) {
            return self::wrap('<p class="drwp-archive-message">'
                . esc_html__('該当する日報が見つかりません。', 'drwp-daily-reports')
                . '</p>');
        }
        if ((int) $report->user_id !== get_current_user_id()
            || $report->review_status !== 'pending') {
            // Permission check, defense in depth — JS link won't be
            // shown for non-own / non-pending, but a direct URL hit
            // still needs to be rejected.
            $back = esc_url(remove_query_arg('drwp_edit'));
            return self::wrap('<p class="drwp-archive-message">'
                . esc_html__('この日報はフロントから編集できません(自分の レビュー待ち の日報のみ編集可能です)。', 'drwp-daily-reports')
                . '</p><p class="drwp-archive-back"><a href="' . $back . '">&laquo; '
                . esc_html__('一覧に戻る', 'drwp-daily-reports')
                . '</a></p>');
        }

        wp_enqueue_script(self::HANDLE_EDIT);

        $projects     = DRWP_Project::all();
        $report_photos = DRWP_Media::for_report((int) $report->id);
        $back         = esc_url(remove_query_arg('drwp_edit'));
        $action       = esc_url(get_permalink());

        ob_start();
        ?>
        <div class="drwp-archive-wrap drwp-archive-edit">
            <p class="drwp-archive-back">
                <a href="<?php echo $back; ?>">&laquo; <?php esc_html_e('閲覧に戻る', 'drwp-daily-reports'); ?></a>
            </p>
            <h2><?php esc_html_e('日報を編集', 'drwp-daily-reports'); ?></h2>

            <?php
            $flash = isset($_GET['drwp_err']) ? sanitize_key((string) $_GET['drwp_err']) : '';
            if ($flash === 'noproject') echo '<p class="drwp-archive-flash err">' . esc_html__('現場を選択してください。', 'drwp-daily-reports') . '</p>';
            if ($flash === 'nowork')    echo '<p class="drwp-archive-flash err">' . esc_html__('作業内容を入力してください。', 'drwp-daily-reports') . '</p>';
            if ($flash === 'license')   echo '<p class="drwp-archive-flash err">' . esc_html__('現在保存できない状態です(ライセンス未有効など)。', 'drwp-daily-reports') . '</p>';
            ?>

            <form method="post" action="<?php echo $action; ?>"
                  class="drwp-archive-edit-form"
                  data-drwp-edit-config="<?php echo esc_attr(wp_json_encode([
                      'rest_root' => esc_url_raw(rest_url('drwp/v1/')),
                      'nonce'     => wp_create_nonce('wp_rest'),
                      'i18n'      => [
                          'uploading'    => __('アップロード中…', 'drwp-daily-reports'),
                          'upload_failed'=> __('アップロード失敗', 'drwp-daily-reports'),
                      ],
                  ])); ?>">
                <?php wp_nonce_field('drwp_archive_edit_' . $id); ?>
                <input type="hidden" name="_drwp_archive_edit" value="1" />
                <input type="hidden" name="drwp_id" value="<?php echo (int) $id; ?>" />

                <label class="drwp-archive-edit-field">
                    <span><?php esc_html_e('日付', 'drwp-daily-reports'); ?></span>
                    <input type="date" name="report_date"
                           value="<?php echo esc_attr((string) $report->report_date); ?>" required />
                </label>
                <label class="drwp-archive-edit-field">
                    <span><?php esc_html_e('現場', 'drwp-daily-reports'); ?></span>
                    <select name="project_id" required>
                        <option value=""><?php esc_html_e('選択してください', 'drwp-daily-reports'); ?></option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?php echo (int) $p->id; ?>" <?php selected((int) $report->project_id, (int) $p->id); ?>>
                                <?php echo esc_html($p->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="drwp-archive-edit-times">
                    <label class="drwp-archive-edit-field">
                        <span><?php esc_html_e('開始時刻', 'drwp-daily-reports'); ?></span>
                        <input type="time" name="started_at"
                               value="<?php echo esc_attr(substr((string) ($report->started_at ?? ''), 0, 5)); ?>" />
                    </label>
                    <label class="drwp-archive-edit-field">
                        <span><?php esc_html_e('終了時刻', 'drwp-daily-reports'); ?></span>
                        <input type="time" name="ended_at"
                               value="<?php echo esc_attr(substr((string) ($report->ended_at ?? ''), 0, 5)); ?>" />
                    </label>
                </div>
                <label class="drwp-archive-edit-field">
                    <span><?php esc_html_e('作業内容', 'drwp-daily-reports'); ?></span>
                    <textarea name="work_description" rows="4" required><?php echo esc_textarea((string) $report->work_description); ?></textarea>
                </label>
                <label class="drwp-archive-edit-field">
                    <span><?php esc_html_e('特記事項（反省・連絡・相談・提案、任意）', 'drwp-daily-reports'); ?></span>
                    <textarea name="issues" rows="2"><?php echo esc_textarea((string) $report->issues); ?></textarea>
                </label>
                <label class="drwp-archive-edit-field">
                    <span><?php esc_html_e('次回予定 (任意)', 'drwp-daily-reports'); ?></span>
                    <textarea name="next_plan" rows="2"><?php echo esc_textarea((string) $report->next_plan); ?></textarea>
                </label>

                <div class="drwp-archive-edit-photo-block">
                    <span class="drwp-archive-edit-field-label"><?php esc_html_e('写真', 'drwp-daily-reports'); ?></span>
                    <div class="drwp-archive-edit-photos" data-role="photos">
                        <?php foreach ($report_photos as $photo): ?>
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

                <div class="drwp-archive-edit-actions">
                    <button type="submit" class="drwp-archive-edit-submit">
                        <?php esc_html_e('保存する', 'drwp-daily-reports'); ?>
                    </button>
                    <a class="drwp-archive-edit-cancel" href="<?php echo $back; ?>">
                        <?php esc_html_e('キャンセル', 'drwp-daily-reports'); ?>
                    </a>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Form POST handler for the edit view. Runs on template_redirect
     * before any output. Re-checks ownership + pending status as
     * defense in depth (the UI link is gated, but a direct POST could
     * still arrive). PRG: every branch redirects so a refresh doesn't
     * re-fire the action.
     */
    public static function handle_edit_post() {
        if (empty($_POST['_drwp_archive_edit'])) return;
        if (!is_user_logged_in()) return;
        if (!current_user_can('edit_posts')) return;

        $id = absint($_POST['drwp_id'] ?? 0);
        if (!$id) return;
        check_admin_referer('drwp_archive_edit_' . $id);

        global $wpdb;
        $reports_t = $wpdb->prefix . 'drwp_reports';
        $report = $wpdb->get_row($wpdb->prepare("SELECT * FROM $reports_t WHERE id = %d", $id));
        if (!$report) return;
        if ((int) $report->user_id !== get_current_user_id()) return;
        if ($report->review_status !== 'pending') return;

        $back_to_edit = function ($err) use ($id) {
            return add_query_arg(['drwp_id' => $id, 'drwp_edit' => 1, 'drwp_err' => $err], get_permalink());
        };

        if (!DRWP_License::can_write()) {
            wp_safe_redirect($back_to_edit('license'));
            exit;
        }

        $project_id = absint($_POST['project_id'] ?? 0);
        $work = trim((string) wp_unslash($_POST['work_description'] ?? ''));
        if (!$project_id) { wp_safe_redirect($back_to_edit('noproject')); exit; }
        if ($work === '') { wp_safe_redirect($back_to_edit('nowork')); exit; }

        $report_date = sanitize_text_field((string) wp_unslash($_POST['report_date'] ?? ''));
        $update = [
            'project_id'       => $project_id,
            'started_at'       => self::sanitize_time_input($_POST['started_at'] ?? ''),
            'ended_at'         => self::sanitize_time_input($_POST['ended_at'] ?? ''),
            'work_description' => wp_kses_post(wp_unslash($work)),
            'issues'           => wp_kses_post(wp_unslash((string) ($_POST['issues'] ?? ''))),
            'next_plan'        => wp_kses_post(wp_unslash((string) ($_POST['next_plan'] ?? ''))),
        ];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date)) {
            $update['report_date'] = $report_date;
        }
        $wpdb->update($reports_t, $update, ['id' => $id]);

        // Photos: wholesale replace with the set in the submitted form
        // (existing items survive because their hidden inputs are
        // re-posted; deleted items don't appear).
        $att_ids = array_map('absint', (array) ($_POST['attachment_ids'] ?? []));
        $captions = array_map('sanitize_text_field', array_map('wp_unslash', (array) ($_POST['attachment_captions'] ?? [])));
        $rows = [];
        foreach ($att_ids as $i => $aid) {
            if (!$aid) continue;
            $rows[] = ['attachment_id' => $aid, 'caption' => (string) ($captions[$i] ?? '')];
        }
        DRWP_Media::sync($id, $rows);

        DRWP_Audit::log('report_edited_frontend', __('日報をフロントから編集', 'drwp-daily-reports'), $id, []);

        wp_safe_redirect(add_query_arg(['drwp_id' => $id, 'drwp_saved' => 1], get_permalink()));
        exit;
    }

    private static function sanitize_time_input($v) {
        $v = trim((string) $v);
        if ($v === '') return null;
        if (preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $v, $m)) {
            return sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0));
        }
        return null;
    }


}
