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
    const PER_PAGE_DEFAULT = 20;
    const PER_PAGE_OPTIONS = [10, 20, 50, 100];

    public static function init() {
        add_shortcode('drwp_report_archive', [__CLASS__, 'shortcode']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
        // Phase B: own-pending edit POST handler runs on
        // template_redirect so we can wp_safe_redirect after a
        // successful save (PRG pattern, same as the lost-password
        // flow). Output hasn't started yet at this hook.
        add_action('template_redirect', [__CLASS__, 'handle_edit_post']);
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
        global $wpdb;
        $reports_t = $wpdb->prefix . 'drwp_reports';
        $entries_t = $wpdb->prefix . 'drwp_report_entries';

        $q       = isset($_GET['drwp_q']) ? sanitize_text_field(wp_unslash((string) $_GET['drwp_q'])) : '';
        $author  = isset($_GET['drwp_author']) ? absint($_GET['drwp_author']) : 0;
        $from    = isset($_GET['drwp_from']) ? sanitize_text_field((string) $_GET['drwp_from']) : '';
        $to      = isset($_GET['drwp_to']) ? sanitize_text_field((string) $_GET['drwp_to']) : '';
        $status  = isset($_GET['drwp_status']) ? sanitize_key((string) $_GET['drwp_status']) : '';
        $per     = (int) ($_GET['drwp_per'] ?? self::PER_PAGE_DEFAULT);
        if (!in_array($per, self::PER_PAGE_OPTIONS, true)) $per = self::PER_PAGE_DEFAULT;
        $page    = max(1, (int) ($_GET['drwp_p'] ?? 1));

        // WHERE assembly: each predicate added only when the input
        // is well-formed, with prepared placeholders. The free-text
        // LIKE goes against both the flat report body AND each
        // entry's work_description via EXISTS, so multi-entry
        // reports surface even if only an entry matches.
        $where = ['1=1'];
        $args  = [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where[] = 'r.report_date >= %s';
            $args[]  = $from;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where[] = 'r.report_date <= %s';
            $args[]  = $to;
        }
        if ($author) {
            $where[] = 'r.user_id = %d';
            $args[]  = $author;
        }
        if ($status && in_array($status, ['pending', 'approved', 'revision_requested'], true)) {
            $where[] = 'r.review_status = %s';
            $args[]  = $status;
        }
        if ($q !== '') {
            $like = '%' . $wpdb->esc_like($q) . '%';
            $where[] = '(r.work_description LIKE %s OR EXISTS '
                . '(SELECT 1 FROM ' . $entries_t . ' e WHERE e.report_id = r.id AND e.work_description LIKE %s))';
            $args[] = $like;
            $args[] = $like;
        }
        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*) FROM $reports_t r WHERE $where_sql";
        $total = (int) ($args
            ? $wpdb->get_var($wpdb->prepare($count_sql, $args))
            : $wpdb->get_var($count_sql));

        $total_pages = max(1, (int) ceil($total / $per));
        if ($page > $total_pages) $page = $total_pages;
        $offset = ($page - 1) * $per;

        $list_sql = "SELECT r.* FROM $reports_t r WHERE $where_sql ORDER BY r.report_date DESC, r.id DESC LIMIT %d OFFSET %d";
        $list_args = array_merge($args, [$per, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($list_sql, $list_args));

        $authors = self::report_authors();

        ob_start();
        ?>
        <div class="drwp-archive-wrap">
            <h2 class="drwp-archive-title"><?php esc_html_e('過去の日報', 'drwp-daily-reports'); ?></h2>

            <?php echo self::render_filter_form($q, $author, $from, $to, $status, $per, $authors); ?>

            <p class="drwp-archive-summary">
                <?php
                printf(
                    /* translators: 1: total count, 2: current page, 3: total pages */
                    esc_html__('全 %1$d 件 / %2$d ページ目 / 全 %3$d ページ', 'drwp-daily-reports'),
                    $total, $page, $total_pages
                );
                ?>
            </p>

            <?php if (empty($rows)): ?>
                <p class="drwp-archive-empty"><?php esc_html_e('該当する日報がありません。', 'drwp-daily-reports'); ?></p>
            <?php else: ?>
                <ul class="drwp-archive-list">
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

    private static function render_filter_form($q, $author, $from, $to, $status, $per, $authors) {
        $action = esc_url(get_permalink());
        $statuses = [
            ''                   => __('すべて', 'drwp-daily-reports'),
            'pending'            => DRWP_Labels::review_status('pending'),
            'approved'           => DRWP_Labels::review_status('approved'),
            'revision_requested' => DRWP_Labels::review_status('revision_requested'),
        ];
        ob_start();
        ?>
        <form method="get" action="<?php echo $action; ?>" class="drwp-archive-filter">
            <div class="drwp-archive-filter-row">
                <label class="drwp-archive-field grow">
                    <span><?php esc_html_e('キーワード', 'drwp-daily-reports'); ?></span>
                    <input type="search" name="drwp_q" value="<?php echo esc_attr($q); ?>"
                           placeholder="<?php esc_attr_e('作業内容に含まれる語', 'drwp-daily-reports'); ?>" />
                </label>
                <label class="drwp-archive-field">
                    <span><?php esc_html_e('作成者', 'drwp-daily-reports'); ?></span>
                    <select name="drwp_author">
                        <option value="0"><?php esc_html_e('すべて', 'drwp-daily-reports'); ?></option>
                        <?php foreach ($authors as $u): ?>
                            <option value="<?php echo (int) $u->ID; ?>" <?php selected($author, (int) $u->ID); ?>>
                                <?php echo esc_html($u->display_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="drwp-archive-filter-row">
                <label class="drwp-archive-field">
                    <span><?php esc_html_e('開始日', 'drwp-daily-reports'); ?></span>
                    <input type="date" name="drwp_from" value="<?php echo esc_attr($from); ?>" />
                </label>
                <label class="drwp-archive-field">
                    <span><?php esc_html_e('終了日', 'drwp-daily-reports'); ?></span>
                    <input type="date" name="drwp_to" value="<?php echo esc_attr($to); ?>" />
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
                <label class="drwp-archive-field">
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
            <div class="drwp-archive-filter-row">
                <button type="submit" class="drwp-archive-submit">
                    <?php esc_html_e('絞り込み', 'drwp-daily-reports'); ?>
                </button>
                <a class="drwp-archive-reset" href="<?php echo $action; ?>">
                    <?php esc_html_e('条件をクリア', 'drwp-daily-reports'); ?>
                </a>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    private static function render_list_item($r) {
        $author = get_userdata((int) $r->user_id);
        $author_name = $author ? $author->display_name : '-';

        // Snippet picks the most informative work_description: prefer
        // the first entry's text (multi-entry case), fall back to the
        // report-level field (legacy flat reports).
        $entries = DRWP_Report_Entry::for_report((int) $r->id);
        $entry_count = count($entries);
        if ($entry_count > 0) {
            $snippet_source = (string) $entries[0]->work_description;
            $project = $entries[0]->project_id ? DRWP_Project::find((int) $entries[0]->project_id) : null;
            $first_project_name = $project ? $project->name : '';
        } else {
            $snippet_source = (string) $r->work_description;
            $project = $r->project_id ? DRWP_Project::find((int) $r->project_id) : null;
            $first_project_name = $project ? $project->name : '';
        }
        $snippet = wp_strip_all_tags($snippet_source);
        if (mb_strlen($snippet) > 80) $snippet = mb_substr($snippet, 0, 80) . '…';

        $href = esc_url(add_query_arg('drwp_id', (int) $r->id, get_permalink()));
        $status_label = DRWP_Labels::review_status((string) $r->review_status);

        ob_start();
        ?>
        <li class="drwp-archive-item">
            <a class="drwp-archive-item-link" href="<?php echo $href; ?>">
                <div class="drwp-archive-item-head">
                    <span class="drwp-archive-date"><?php echo esc_html((string) $r->report_date); ?></span>
                    <span class="drwp-archive-status status-<?php echo esc_attr((string) $r->review_status); ?>">
                        <?php echo esc_html($status_label); ?>
                    </span>
                </div>
                <div class="drwp-archive-item-body">
                    <?php if ($first_project_name !== ''): ?>
                        <span class="drwp-archive-project"><?php echo esc_html($first_project_name); ?></span>
                        <?php if ($entry_count > 1): ?>
                            <span class="drwp-archive-entry-count">
                                <?php printf(
                                    /* translators: %d: total entry count */
                                    esc_html__('他 %d 現場', 'drwp-daily-reports'),
                                    $entry_count - 1
                                ); ?>
                            </span>
                        <?php endif; ?>
                    <?php endif; ?>
                    <span class="drwp-archive-snippet"><?php echo esc_html($snippet); ?></span>
                </div>
                <div class="drwp-archive-item-meta">
                    <span class="drwp-archive-author"><?php echo esc_html($author_name); ?></span>
                </div>
            </a>
        </li>
        <?php
        return ob_get_clean();
    }

    private static function render_pagination($total_pages, $page) {
        if ($total_pages <= 1) return '';

        $base = remove_query_arg('drwp_p');
        $url_for = function ($p) use ($base) {
            return esc_url(add_query_arg('drwp_p', (int) $p, $base));
        };

        // Show: first, prev, current ± 2, next, last. Compact for
        // mobile but still shows where you are in a 50-page archive.
        $range = [];
        $range[] = 1;
        for ($p = max(2, $page - 2); $p <= min($total_pages - 1, $page + 2); $p++) $range[] = $p;
        if ($total_pages > 1) $range[] = $total_pages;
        $range = array_values(array_unique($range));
        sort($range, SORT_NUMERIC);

        ob_start();
        ?>
        <nav class="drwp-archive-pagination" aria-label="<?php esc_attr_e('ページ送り', 'drwp-daily-reports'); ?>">
            <?php if ($page > 1): ?>
                <a class="drwp-archive-page" href="<?php echo $url_for($page - 1); ?>">&laquo; <?php esc_html_e('前', 'drwp-daily-reports'); ?></a>
            <?php endif; ?>
            <?php
            $prev = 0;
            foreach ($range as $p):
                if ($prev && $p > $prev + 1):
                    echo '<span class="drwp-archive-page-gap">…</span>';
                endif;
                $prev = $p;
                $cls = $p === $page ? 'drwp-archive-page current' : 'drwp-archive-page';
            ?>
                <a class="<?php echo esc_attr($cls); ?>" href="<?php echo $url_for($p); ?>"><?php echo (int) $p; ?></a>
            <?php endforeach; ?>
            <?php if ($page < $total_pages): ?>
                <a class="drwp-archive-page" href="<?php echo $url_for($page + 1); ?>"><?php esc_html_e('次', 'drwp-daily-reports'); ?> &raquo;</a>
            <?php endif; ?>
        </nav>
        <?php
        return ob_get_clean();
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

        $entries = DRWP_Report_Entry::for_report((int) $report->id);
        $author = get_userdata((int) $report->user_id);
        $author_name = $author ? $author->display_name : '-';
        $back_url = esc_url(remove_query_arg('drwp_id'));
        $is_own_pending = ((int) $report->user_id === get_current_user_id())
                          && $report->review_status === 'pending';

        ob_start();
        ?>
        <div class="drwp-archive-wrap drwp-archive-single">
            <p class="drwp-archive-back">
                <a href="<?php echo $back_url; ?>">&laquo; <?php esc_html_e('一覧に戻る', 'drwp-daily-reports'); ?></a>
            </p>

            <header class="drwp-archive-single-head">
                <h2>
                    <?php echo esc_html((string) $report->report_date); ?>
                    <span class="drwp-archive-status status-<?php echo esc_attr((string) $report->review_status); ?>">
                        <?php echo esc_html(DRWP_Labels::review_status((string) $report->review_status)); ?>
                    </span>
                </h2>
                <p class="drwp-archive-meta">
                    <?php esc_html_e('作成者:', 'drwp-daily-reports'); ?>
                    <strong><?php echo esc_html($author_name); ?></strong>
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

            <?php if (empty($entries)): ?>
                <?php echo self::render_legacy_body($report); ?>
            <?php else: ?>
                <?php foreach ($entries as $idx => $entry): ?>
                    <?php echo self::render_entry($idx, $entry); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function render_legacy_body($report) {
        // Legacy flat report (no entries[]). Surface project, work,
        // issues, next_plan, photos. Public fields are intentionally
        // not shown here — they're the office-curated wording for
        // published posts, not the worker's reference view.
        $project = $report->project_id ? DRWP_Project::find((int) $report->project_id) : null;
        $photos = DRWP_Media::for_report((int) $report->id);

        ob_start();
        ?>
        <section class="drwp-archive-section">
            <?php if ($project): ?>
                <h3><?php echo esc_html($project->name); ?></h3>
            <?php endif; ?>
            <?php if (!empty($report->work_description)): ?>
                <div class="drwp-archive-block">
                    <h4><?php esc_html_e('作業内容', 'drwp-daily-reports'); ?></h4>
                    <?php echo wp_kses_post(wpautop((string) $report->work_description)); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($report->issues)): ?>
                <div class="drwp-archive-block">
                    <h4><?php esc_html_e('問題点', 'drwp-daily-reports'); ?></h4>
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
        <?php
        return ob_get_clean();
    }

    private static function render_entry($idx, $entry) {
        $project = $entry->project_id ? DRWP_Project::find((int) $entry->project_id) : null;
        $project_name = $project ? $project->name : __('（現場未設定）', 'drwp-daily-reports');
        $time_window = trim(
            substr((string) $entry->started_at, 0, 5)
            . ' - '
            . substr((string) $entry->ended_at, 0, 5),
            ' -'
        );
        $photos = DRWP_Media::for_entry((int) $entry->id);

        ob_start();
        ?>
        <section class="drwp-archive-section">
            <h3>
                <span class="drwp-archive-entry-num">
                    <?php
                    printf(
                        /* translators: %d: entry order (1-based) */
                        esc_html__('現場 #%d', 'drwp-daily-reports'),
                        (int) $idx + 1
                    );
                    ?>
                </span>
                <?php echo esc_html($project_name); ?>
                <?php if ($time_window !== ''): ?>
                    <small class="drwp-archive-time"><?php echo esc_html($time_window); ?></small>
                <?php endif; ?>
            </h3>
            <?php if (!empty($entry->work_description)): ?>
                <div class="drwp-archive-block">
                    <h4><?php esc_html_e('作業内容', 'drwp-daily-reports'); ?></h4>
                    <?php echo wp_kses_post(wpautop((string) $entry->work_description)); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($entry->issues)): ?>
                <div class="drwp-archive-block">
                    <h4><?php esc_html_e('問題点', 'drwp-daily-reports'); ?></h4>
                    <?php echo wp_kses_post(wpautop((string) $entry->issues)); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($entry->next_plan)): ?>
                <div class="drwp-archive-block">
                    <h4><?php esc_html_e('次回予定', 'drwp-daily-reports'); ?></h4>
                    <?php echo wp_kses_post(wpautop((string) $entry->next_plan)); ?>
                </div>
            <?php endif; ?>
            <?php echo self::render_photo_grid($photos); ?>
        </section>
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

        $entries  = DRWP_Report_Entry::for_report((int) $report->id);
        $projects = DRWP_Project::all();
        $back     = esc_url(remove_query_arg('drwp_edit'));
        $action   = esc_url(get_permalink());

        // Pre-render existing entries as cards. The cloned template
        // for "+ 現場を追加" lives below — JS swaps __IDX__ for the
        // next available index when the user appends a fresh card.
        ob_start();
        ?>
        <div class="drwp-archive-wrap drwp-archive-edit">
            <p class="drwp-archive-back">
                <a href="<?php echo $back; ?>">&laquo; <?php esc_html_e('閲覧に戻る', 'drwp-daily-reports'); ?></a>
            </p>
            <h2><?php esc_html_e('日報を編集', 'drwp-daily-reports'); ?></h2>

            <?php
            $flash = isset($_GET['drwp_err']) ? sanitize_key((string) $_GET['drwp_err']) : '';
            if ($flash === 'noentry') echo '<p class="drwp-archive-flash err">' . esc_html__('現場エントリを 1 つ以上入力してください。', 'drwp-daily-reports') . '</p>';
            if ($flash === 'noproject') echo '<p class="drwp-archive-flash err">' . esc_html__('現場が選択されていないエントリがあります。', 'drwp-daily-reports') . '</p>';
            if ($flash === 'nowork') echo '<p class="drwp-archive-flash err">' . esc_html__('作業内容が空のエントリがあります。', 'drwp-daily-reports') . '</p>';
            if ($flash === 'license') echo '<p class="drwp-archive-flash err">' . esc_html__('現在保存できない状態です(ライセンス未有効など)。', 'drwp-daily-reports') . '</p>';
            ?>

            <form method="post" action="<?php echo $action; ?>"
                  class="drwp-archive-edit-form"
                  data-drwp-edit-config="<?php echo esc_attr(wp_json_encode([
                      'rest_root' => esc_url_raw(rest_url('drwp/v1/')),
                      'nonce'     => wp_create_nonce('wp_rest'),
                      'i18n'      => [
                          'pick_project' => __('選択してください', 'drwp-daily-reports'),
                          'entry_label'  => __('現場', 'drwp-daily-reports'),
                          'remove_entry' => __('この現場を削除', 'drwp-daily-reports'),
                          'started'      => __('開始時刻', 'drwp-daily-reports'),
                          'ended'        => __('終了時刻', 'drwp-daily-reports'),
                          'work'         => __('作業内容', 'drwp-daily-reports'),
                          'issues'       => __('問題点 (任意)', 'drwp-daily-reports'),
                          'next'         => __('次回予定 (任意)', 'drwp-daily-reports'),
                          'photos'       => __('写真', 'drwp-daily-reports'),
                          'pick_photos'  => __('+ 写真を追加', 'drwp-daily-reports'),
                          'uploading'    => __('アップロード中…', 'drwp-daily-reports'),
                          'upload_failed'=> __('アップロード失敗', 'drwp-daily-reports'),
                      ],
                      'projects' => array_map(function ($p) {
                          return ['id' => (int) $p->id, 'name' => (string) $p->name];
                      }, $projects),
                  ])); ?>">
                <?php wp_nonce_field('drwp_archive_edit_' . $id); ?>
                <input type="hidden" name="_drwp_archive_edit" value="1" />
                <input type="hidden" name="drwp_id" value="<?php echo (int) $id; ?>" />

                <label class="drwp-archive-edit-field">
                    <span><?php esc_html_e('日付', 'drwp-daily-reports'); ?></span>
                    <input type="date" name="report_date"
                           value="<?php echo esc_attr((string) $report->report_date); ?>" required />
                </label>

                <div class="drwp-archive-edit-entries" data-role="entries">
                    <?php if (empty($entries)): ?>
                        <?php echo self::render_edit_entry(0, null, $projects); ?>
                    <?php else: ?>
                        <?php foreach ($entries as $idx => $entry): ?>
                            <?php echo self::render_edit_entry((int) $idx, $entry, $projects); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <p>
                    <button type="button" class="drwp-archive-edit-add" data-role="add-entry">
                        + <?php esc_html_e('現場を追加', 'drwp-daily-reports'); ?>
                    </button>
                </p>

                <div class="drwp-archive-edit-actions">
                    <button type="submit" class="drwp-archive-edit-submit">
                        <?php esc_html_e('保存する', 'drwp-daily-reports'); ?>
                    </button>
                    <a class="drwp-archive-edit-cancel" href="<?php echo $back; ?>">
                        <?php esc_html_e('キャンセル', 'drwp-daily-reports'); ?>
                    </a>
                </div>
            </form>

            <template id="drwp-archive-edit-template">
                <?php echo self::render_edit_entry_template($projects); ?>
            </template>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function render_edit_entry($idx, $entry, $projects) {
        $project_id    = $entry ? (int) $entry->project_id : 0;
        $started_at    = $entry ? (string) $entry->started_at : '';
        $ended_at      = $entry ? (string) $entry->ended_at   : '';
        $work          = $entry ? (string) $entry->work_description : '';
        $issues        = $entry ? (string) $entry->issues : '';
        $next_plan     = $entry ? (string) $entry->next_plan : '';
        $entry_id      = $entry ? (int) $entry->id : 0;
        $photos        = $entry_id ? DRWP_Media::for_entry($entry_id) : [];

        ob_start();
        ?>
        <div class="drwp-archive-edit-entry" data-idx="<?php echo (int) $idx; ?>">
            <header class="drwp-archive-edit-entry-head">
                <strong>
                    <?php printf(
                        /* translators: %d: entry order (1-based) */
                        esc_html__('現場 #%d', 'drwp-daily-reports'),
                        (int) $idx + 1
                    ); ?>
                </strong>
                <button type="button" class="drwp-archive-edit-remove" data-role="remove-entry">
                    <?php esc_html_e('この現場を削除', 'drwp-daily-reports'); ?>
                </button>
            </header>
            <label class="drwp-archive-edit-field">
                <span><?php esc_html_e('現場', 'drwp-daily-reports'); ?></span>
                <select name="entries[<?php echo (int) $idx; ?>][project_id]" required>
                    <option value=""><?php esc_html_e('選択してください', 'drwp-daily-reports'); ?></option>
                    <?php foreach ($projects as $p): ?>
                        <option value="<?php echo (int) $p->id; ?>" <?php selected($project_id, (int) $p->id); ?>>
                            <?php echo esc_html($p->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="drwp-archive-edit-times">
                <label class="drwp-archive-edit-field">
                    <span><?php esc_html_e('開始時刻', 'drwp-daily-reports'); ?></span>
                    <input type="time" name="entries[<?php echo (int) $idx; ?>][started_at]"
                           value="<?php echo esc_attr(substr($started_at, 0, 5)); ?>" />
                </label>
                <label class="drwp-archive-edit-field">
                    <span><?php esc_html_e('終了時刻', 'drwp-daily-reports'); ?></span>
                    <input type="time" name="entries[<?php echo (int) $idx; ?>][ended_at]"
                           value="<?php echo esc_attr(substr($ended_at, 0, 5)); ?>" />
                </label>
            </div>
            <label class="drwp-archive-edit-field">
                <span><?php esc_html_e('作業内容', 'drwp-daily-reports'); ?></span>
                <textarea name="entries[<?php echo (int) $idx; ?>][work_description]" rows="4" required><?php echo esc_textarea($work); ?></textarea>
            </label>
            <label class="drwp-archive-edit-field">
                <span><?php esc_html_e('問題点 (任意)', 'drwp-daily-reports'); ?></span>
                <textarea name="entries[<?php echo (int) $idx; ?>][issues]" rows="2"><?php echo esc_textarea($issues); ?></textarea>
            </label>
            <label class="drwp-archive-edit-field">
                <span><?php esc_html_e('次回予定 (任意)', 'drwp-daily-reports'); ?></span>
                <textarea name="entries[<?php echo (int) $idx; ?>][next_plan]" rows="2"><?php echo esc_textarea($next_plan); ?></textarea>
            </label>

            <div class="drwp-archive-edit-photo-block">
                <span class="drwp-archive-edit-field-label"><?php esc_html_e('写真', 'drwp-daily-reports'); ?></span>
                <div class="drwp-archive-edit-photos" data-role="photos">
                    <?php foreach ($photos as $photo): ?>
                        <?php $thumb = wp_get_attachment_image_url((int) $photo->attachment_id, 'medium'); ?>
                        <div class="drwp-archive-edit-photo-item">
                            <?php if ($thumb): ?>
                                <img src="<?php echo esc_url($thumb); ?>" alt="" />
                            <?php endif; ?>
                            <input type="hidden" name="entries[<?php echo (int) $idx; ?>][attachment_ids][]"
                                   value="<?php echo (int) $photo->attachment_id; ?>" />
                            <input type="text"
                                   name="entries[<?php echo (int) $idx; ?>][attachment_captions][]"
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
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Cloned by JS when "+ 現場を追加" is pressed. Has __IDX__
     * placeholder in every `entries[N][...]` name so the JS can
     * swap in the next available index.
     */
    private static function render_edit_entry_template($projects) {
        ob_start();
        ?>
        <div class="drwp-archive-edit-entry" data-idx="__IDX__">
            <header class="drwp-archive-edit-entry-head">
                <strong>
                    <?php printf(
                        /* translators: %s: placeholder, replaced by JS */
                        esc_html__('現場 #%s', 'drwp-daily-reports'),
                        '__N__'
                    ); ?>
                </strong>
                <button type="button" class="drwp-archive-edit-remove" data-role="remove-entry">
                    <?php esc_html_e('この現場を削除', 'drwp-daily-reports'); ?>
                </button>
            </header>
            <label class="drwp-archive-edit-field">
                <span><?php esc_html_e('現場', 'drwp-daily-reports'); ?></span>
                <select name="entries[__IDX__][project_id]" required>
                    <option value=""><?php esc_html_e('選択してください', 'drwp-daily-reports'); ?></option>
                    <?php foreach ($projects as $p): ?>
                        <option value="<?php echo (int) $p->id; ?>"><?php echo esc_html($p->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="drwp-archive-edit-times">
                <label class="drwp-archive-edit-field">
                    <span><?php esc_html_e('開始時刻', 'drwp-daily-reports'); ?></span>
                    <input type="time" name="entries[__IDX__][started_at]" />
                </label>
                <label class="drwp-archive-edit-field">
                    <span><?php esc_html_e('終了時刻', 'drwp-daily-reports'); ?></span>
                    <input type="time" name="entries[__IDX__][ended_at]" />
                </label>
            </div>
            <label class="drwp-archive-edit-field">
                <span><?php esc_html_e('作業内容', 'drwp-daily-reports'); ?></span>
                <textarea name="entries[__IDX__][work_description]" rows="4" required></textarea>
            </label>
            <label class="drwp-archive-edit-field">
                <span><?php esc_html_e('問題点 (任意)', 'drwp-daily-reports'); ?></span>
                <textarea name="entries[__IDX__][issues]" rows="2"></textarea>
            </label>
            <label class="drwp-archive-edit-field">
                <span><?php esc_html_e('次回予定 (任意)', 'drwp-daily-reports'); ?></span>
                <textarea name="entries[__IDX__][next_plan]" rows="2"></textarea>
            </label>
            <div class="drwp-archive-edit-photo-block">
                <span class="drwp-archive-edit-field-label"><?php esc_html_e('写真', 'drwp-daily-reports'); ?></span>
                <div class="drwp-archive-edit-photos" data-role="photos"></div>
                <label class="drwp-archive-edit-photo-pick">
                    + <?php esc_html_e('写真を追加', 'drwp-daily-reports'); ?>
                    <input type="file" accept="image/*" multiple data-role="photo-input" />
                </label>
                <p class="drwp-archive-edit-photo-status" data-role="photo-status"></p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Form POST handler for the edit view. Runs on template_redirect
     * before any output. Re-checks ownership + pending status as
     * defense in depth (the UI link is gated, but a direct POST
     * could still arrive). On success, DRWP_Report_Entry::sync()
     * replaces the entry set wholesale — additions, deletions, and
     * reorderings are all handled by that single call. The report's
     * report_date is updated separately.
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

        if (!DRWP_License::can_write()) {
            wp_safe_redirect(add_query_arg(['drwp_id' => $id, 'drwp_edit' => 1, 'drwp_err' => 'license'], get_permalink()));
            exit;
        }

        $entries_in = isset($_POST['entries']) && is_array($_POST['entries'])
            ? $_POST['entries'] : [];
        // Preserve form order; the user may have re-arranged via JS.
        ksort($entries_in, SORT_NUMERIC);

        $entries_to_sync = [];
        foreach ($entries_in as $e) {
            if (!is_array($e)) continue;
            $project_id = absint($e['project_id'] ?? 0);
            $work       = trim((string) wp_unslash($e['work_description'] ?? ''));
            if (!$project_id) {
                wp_safe_redirect(add_query_arg(['drwp_id' => $id, 'drwp_edit' => 1, 'drwp_err' => 'noproject'], get_permalink()));
                exit;
            }
            if ($work === '') {
                wp_safe_redirect(add_query_arg(['drwp_id' => $id, 'drwp_edit' => 1, 'drwp_err' => 'nowork'], get_permalink()));
                exit;
            }
            $entries_to_sync[] = [
                'project_id'          => $project_id,
                'started_at'          => sanitize_text_field((string) wp_unslash($e['started_at'] ?? '')),
                'ended_at'            => sanitize_text_field((string) wp_unslash($e['ended_at'] ?? '')),
                'work_description'    => wp_kses_post(wp_unslash($work)),
                'issues'              => wp_kses_post(wp_unslash((string) ($e['issues'] ?? ''))),
                'next_plan'           => wp_kses_post(wp_unslash((string) ($e['next_plan'] ?? ''))),
                'attachment_ids'      => array_map('absint', (array) ($e['attachment_ids'] ?? [])),
                'attachment_captions' => array_map(
                    'sanitize_text_field',
                    array_map('wp_unslash', (array) ($e['attachment_captions'] ?? []))
                ),
            ];
        }
        if (empty($entries_to_sync)) {
            wp_safe_redirect(add_query_arg(['drwp_id' => $id, 'drwp_edit' => 1, 'drwp_err' => 'noentry'], get_permalink()));
            exit;
        }

        $report_date = sanitize_text_field((string) wp_unslash($_POST['report_date'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date)) {
            $wpdb->update($reports_t, ['report_date' => $report_date], ['id' => $id]);
        }

        DRWP_Report_Entry::sync($id, $entries_to_sync);
        DRWP_Audit::log('report_edited_frontend', __('日報をフロントから編集', 'drwp-daily-reports'), $id, [
            'entries' => count($entries_to_sync),
        ]);

        wp_safe_redirect(add_query_arg(['drwp_id' => $id, 'drwp_saved' => 1], get_permalink()));
        exit;
    }

    /* ------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------ */

    private static function report_authors() {
        global $wpdb;
        $ids = $wpdb->get_col(
            "SELECT DISTINCT user_id FROM " . $wpdb->prefix . "drwp_reports ORDER BY user_id ASC"
        );
        if (empty($ids)) return [];
        $users = [];
        foreach ($ids as $uid) {
            $u = get_userdata((int) $uid);
            if ($u) $users[] = $u;
        }
        usort($users, function ($a, $b) {
            return strcmp((string) $a->display_name, (string) $b->display_name);
        });
        return $users;
    }

}
