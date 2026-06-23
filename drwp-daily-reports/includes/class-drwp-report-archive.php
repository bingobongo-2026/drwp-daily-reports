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

    const HANDLE       = 'drwp-archive';
    const HANDLE_EDIT  = 'drwp-archive-edit';
    const HANDLE_COMBO = 'drwp-combo';

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
            'drwp_mine', 'drwp_id', 'drwp_edit', 'drwp_new',
            'drwp_saved', 'drwp_requested', 'drwp_err',
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
        // 案件選択コンボボックス (DRWP_Report_Form 側で登録済み)
        wp_enqueue_style(self::HANDLE_COMBO);
        wp_enqueue_script(self::HANDLE_COMBO);
        // 共有モザイク編集 (DRWP_Report_Form 側で登録済み)
        wp_enqueue_style('drwp-mosaic');
        wp_enqueue_script('drwp-mosaic');

        // 現にログイン中の退職者(init ログアウト前のレース) — データ
        // に到達する前に通知だけ出して止める。
        if (is_user_logged_in() && DRWP_User::is_retired()) {
            return self::wrap('<p class="drwp-archive-message drwp-archive-retired">'
                . esc_html__('このアカウントは退職状態のため、ログインできません。', 'drwp-daily-reports')
                . '</p>');
        }
        // ログアウト済み + 退職マーカー: 退職者がログアウトさせられた
        // 直後。通知を出しつつ、ログインフォームも併せて出す — 共有端末
        // で別の社員がそのままログインできるようにする(通知だけ出して
        // フォームを隠すと、マーカー Cookie が切れるまで誰もログインで
        // きなくなる)。
        $retired_marker = !is_user_logged_in()
                       && (DRWP_User::has_marker_cookie() || !empty($_GET['drwp_retired']));
        if ($retired_marker) {
            $notice = self::wrap('<p class="drwp-archive-message drwp-archive-retired">'
                . esc_html__('前回のアカウントは退職状態のためログインできません。別のアカウントでログインしてください。', 'drwp-daily-reports')
                . '</p>');
            return $notice . DRWP_Login::render_login_box(get_permalink() ?: null);
        }
        // 未ログイン時はログインフォームを埋め込んで返す。これで
        // 「[drwp_login_form] と [drwp_report_archive] の 2 つを別
        // ページに置く」設定をしなくても、1 つのショートコードで
        // 「ログイン→そのまま日報カレンダー」が完結する。
        // redirect_to は現在のページ自体を渡して、ログイン後に同じ
        // ページに戻ってくるようにする。
        if (!is_user_logged_in()) {
            return DRWP_Login::render_login_box(get_permalink() ?: null);
        }
        if (!current_user_can('edit_posts')) {
            // ログイン済みだが閲覧権限を持たないアカウント (Subscriber 等)
            // で詰まる人がいるので、別アカウントで入り直せるよう
            // ログアウト動線を必ず添える。ログアウト後の戻り先は
            // 同じページにして、未ログイン分岐で出るログインボックスに
            // 接続する (#issue: ユーザが「閲覧する権限がありません」で
            // 立ち往生する)。
            $here = get_permalink() ?: home_url();
            $logout_url = wp_logout_url($here);
            return self::wrap(
                '<p class="drwp-archive-message">'
                . esc_html__('閲覧する権限がありません。', 'drwp-daily-reports')
                . '</p>'
                . '<p class="drwp-archive-message" style="text-align:center;">'
                . '<a class="button" href="' . esc_url($logout_url) . '">'
                . esc_html__('別のアカウントでログイン', 'drwp-daily-reports')
                . '</a>'
                . '</p>'
            );
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
        // "自分のみ" filter.
        // 既定は「自分のみ表示」(ログイン中ユーザの日報だけ)。
        // 利用者が一度フォームを操作すると hidden `drwp_scope_set=1` が
        // URL に乗るので、その後はチェックボックスの状態 (drwp_mine)
        // をそのまま尊重する。これでチェックを外しても初期値に戻らない。
        $scope_set = isset($_GET['drwp_scope_set']);
        if ($scope_set) {
            $mine = !empty($_GET['drwp_mine']) && is_user_logged_in();
        } else {
            $mine = is_user_logged_in();
        }
        $user_id = $mine ? get_current_user_id() : 0;
        // Retired users keep their read access (so they can review
        // their own past work) but lose every write entry point.
        $is_retired = is_user_logged_in() && DRWP_User::is_retired();
        $can_write = is_user_logged_in() && current_user_can('edit_posts') && !$is_retired;

        $flash = '';
        if (isset($_GET['drwp_saved'])) {
            $flash = '<p class="drwp-mform-status ok">' . esc_html__('保存しました。', 'drwp-daily-reports') . '</p>';
        } elseif (isset($_GET['drwp_requested'])) {
            $flash = '<p class="drwp-mform-status ok">' . esc_html__('編集依頼を送信しました。', 'drwp-daily-reports') . '</p>';
        }
        if ($is_retired) {
            $flash .= '<p class="drwp-mform-status drwp-retired-notice">'
                   . esc_html__('このアカウントは退職状態のため、新規日報・予定の作成や編集はできません。閲覧のみ可能です。', 'drwp-daily-reports')
                   . '</p>';
        }

        // The "+日報を書く" CTA opens the form modal on click. URL
        // fallback is provided for no-JS / direct linking.
        $new_url = add_query_arg('drwp_new', '1', $_SERVER['REQUEST_URI'] ?? '');

        $body = self::render_month_view([
            'user_id'         => $user_id,
            'show_new_button' => $can_write,
            'new_url'         => $new_url,
            'title'           => __('日報カレンダー', 'drwp-daily-reports'),
            'extra_message'   => $flash,
            'use_modals'      => true,
        ]);

        $modals = self::render_modals($can_write);
        // ログインバー (X さんとしてログイン中 / ログアウト) はカレンダー
        // 画面の上に乗せると視覚的に重く、せっかくのデザイン整理が
        // 台無しになるので archive 出力には含めない。ユーザー識別は
        // ダッシュボードのサブタイトルで控えめに出している。ログアウト
        // は WP の admin bar や別の場所から行う想定。
        return $body . $modals;
    }

    /**
     * Hidden <dialog> containers used by [drwp_report_archive]:
     * - View dialog: JS populates with /reports/{id} data
     * - Form dialog: embeds the new-report form so users don't
     *   navigate away when clicking "+日報を書く"
     */
    private static function render_modals($can_write) {
        $rest_root = esc_url_raw(rest_url('drwp/v1'));
        $nonce     = wp_create_nonce('wp_rest');
        $labels    = [
            'pending'        => DRWP_Labels::review_status('pending'),
            'approved'       => DRWP_Labels::review_status('approved'),
            'needs_revision' => DRWP_Labels::review_status('needs_revision'),
            'edit_requested' => DRWP_Labels::review_status('edit_requested'),
        ];
        // 案件 (active のみ) と「最近使った」案件 ID。前者を combobox の
        // 全件、後者を上部ピン留めに使う。recent_for_user は空ユーザ
        // (未ログイン) に空配列を返すので分岐は不要。
        $recent_project_ids = DRWP_Project::recent_for_user(get_current_user_id(), 8);
        $recent_project_lookup = array_flip(array_map('intval', $recent_project_ids));
        $project_list = array_map(function ($p) use ($recent_project_lookup) {
            return [
                'id'        => (int) $p->id,
                'name'      => (string) $p->name,
                'is_recent' => isset($recent_project_lookup[(int) $p->id]) ? 1 : 0,
            ];
        }, DRWP_Project::all(true));

        // 担当者ドロップダウン — 事務所(`edit_others_posts`)だけが
        // 予定の assignee を変えられる。作業員は自分の予定の担当を
        // 書き換える権利を持たない(REST 側もここで弾く)ので、UI も
        // 事務所にだけ出す。
        $can_assign_plans = current_user_can('edit_others_posts');
        $worker_options = $can_assign_plans ? DRWP_Plan::worker_options() : [];

        $cfg = wp_json_encode([
            'restRoot'  => $rest_root,
            'nonce'     => $nonce,
            'labels'    => $labels,
            'projects'  => $project_list,
            'recentProjectIds' => array_values(array_map('intval', $recent_project_ids)),
            'canAssignPlans' => $can_assign_plans,
            // 「自分以外の日報も編集できる」事務所権限。レビュー権限と
            // 同じ判定 (edit_others_posts)。UI 側で「他人の日報の編集
            // ボタンを隠す/出す」の判定に使う。サーバ側 (PATCH) は
            // 別途 can_edit_one でガード済み。
            'canEditOthers' => current_user_can('edit_others_posts'),
            'currentUserId' => (int) get_current_user_id(),
            // The archive's edit flow uses ?drwp_id=N&drwp_edit=1
            // (see shortcode() dispatch); we build a link template
            // with __ID__ that JS replaces per-report.
            'editBase'  => esc_url_raw(add_query_arg(['drwp_id' => '__ID__', 'drwp_edit' => 1], $_SERVER['REQUEST_URI'] ?? '')),
            'autoOpenNew' => !empty($_GET['drwp_new']),
            'isRetired'  => is_user_logged_in() && DRWP_User::is_retired(),
        ]);

        ob_start();
        ?>
        <dialog id="drwp-archive-view-dialog" class="drwp-archive-dialog">
            <div class="drwp-archive-dialog-head">
                <h3 id="drwp-archive-view-title"><?php esc_html_e('日報の内容', 'drwp-daily-reports'); ?></h3>
                <button type="button" class="drwp-archive-dialog-close" aria-label="<?php esc_attr_e('閉じる', 'drwp-daily-reports'); ?>">×</button>
            </div>
            <div class="drwp-archive-dialog-body" id="drwp-archive-view-body">
                <p class="drwp-archive-dialog-loading"><?php esc_html_e('読み込み中…', 'drwp-daily-reports'); ?></p>
            </div>
        </dialog>

        <?php if ($can_write): ?>
        <dialog id="drwp-archive-form-dialog" class="drwp-archive-dialog drwp-archive-dialog-wide">
            <div class="drwp-archive-dialog-head">
                <h3><?php esc_html_e('日報を書く', 'drwp-daily-reports'); ?></h3>
                <button type="button" class="drwp-archive-dialog-close" aria-label="<?php esc_attr_e('閉じる', 'drwp-daily-reports'); ?>">×</button>
            </div>
            <div class="drwp-archive-dialog-body">
                <?php echo DRWP_Report_Form::render_form(); ?>
            </div>
        </dialog>

        <dialog id="drwp-archive-plan-edit-dialog" class="drwp-archive-dialog">
            <div class="drwp-archive-dialog-head">
                <h3><?php esc_html_e('予定を編集', 'drwp-daily-reports'); ?></h3>
                <button type="button" class="drwp-archive-dialog-close" aria-label="<?php esc_attr_e('閉じる', 'drwp-daily-reports'); ?>">×</button>
            </div>
            <div class="drwp-archive-dialog-body">
                <form id="drwp-archive-plan-edit-form" class="drwp-archive-plan-form">
                    <input type="hidden" name="id" value="" />
                    <label class="drwp-archive-plan-field">
                        <span><?php esc_html_e('日付', 'drwp-daily-reports'); ?> <em>*</em></span>
                        <input type="date" name="planned_date" required />
                    </label>
                    <div class="drwp-archive-plan-field">
                        <span><?php esc_html_e('案件', 'drwp-daily-reports'); ?></span>
                        <?php echo self::project_combo_select($project_list, 0, 'project_id', __('（未設定）', 'drwp-daily-reports'), false); ?>
                    </div>
                    <?php if ($can_assign_plans && !empty($worker_options)): ?>
                    <label class="drwp-archive-plan-field">
                        <span><?php esc_html_e('担当者', 'drwp-daily-reports'); ?></span>
                        <select name="user_id">
                            <option value=""><?php esc_html_e('（未割当）', 'drwp-daily-reports'); ?></option>
                            <?php foreach ($worker_options as $wid => $wname): ?>
                                <option value="<?php echo (int) $wid; ?>"><?php echo esc_html($wname); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php endif; ?>
                    <div class="drwp-archive-plan-times">
                        <label class="drwp-archive-plan-field">
                            <span><?php esc_html_e('開始時刻', 'drwp-daily-reports'); ?></span>
                            <input type="time" name="started_at" />
                        </label>
                        <label class="drwp-archive-plan-field">
                            <span><?php esc_html_e('終了時刻', 'drwp-daily-reports'); ?></span>
                            <input type="time" name="ended_at" />
                        </label>
                    </div>
                    <label class="drwp-archive-plan-field">
                        <span><?php esc_html_e('メモ', 'drwp-daily-reports'); ?></span>
                        <textarea name="notes" rows="3"></textarea>
                    </label>
                    <div class="drwp-archive-plan-actions">
                        <button type="submit" class="drwp-archive-new-btn">
                            <?php esc_html_e('保存', 'drwp-daily-reports'); ?>
                        </button>
                        <span class="drwp-archive-plan-status" data-role="status"></span>
                    </div>
                </form>
            </div>
        </dialog>

        <dialog id="drwp-archive-plan-dialog" class="drwp-archive-dialog">
            <div class="drwp-archive-dialog-head">
                <h3><?php esc_html_e('予定を登録', 'drwp-daily-reports'); ?></h3>
                <button type="button" class="drwp-archive-dialog-close" aria-label="<?php esc_attr_e('閉じる', 'drwp-daily-reports'); ?>">×</button>
            </div>
            <div class="drwp-archive-dialog-body">
                <form id="drwp-archive-plan-form" class="drwp-archive-plan-form">
                    <label class="drwp-archive-plan-field">
                        <span><?php esc_html_e('日付', 'drwp-daily-reports'); ?> <em>*</em></span>
                        <input type="date" name="planned_date" value="<?php echo esc_attr(current_time('Y-m-d')); ?>" required />
                    </label>
                    <div class="drwp-archive-plan-field">
                        <span><?php esc_html_e('案件', 'drwp-daily-reports'); ?></span>
                        <?php echo self::project_combo_select($project_list, 0, 'project_id', __('（未設定）', 'drwp-daily-reports'), false); ?>
                    </div>
                    <div class="drwp-archive-plan-times">
                        <label class="drwp-archive-plan-field">
                            <span><?php esc_html_e('開始時刻', 'drwp-daily-reports'); ?></span>
                            <input type="time" name="started_at" />
                        </label>
                        <label class="drwp-archive-plan-field">
                            <span><?php esc_html_e('終了時刻', 'drwp-daily-reports'); ?></span>
                            <input type="time" name="ended_at" />
                        </label>
                    </div>
                    <label class="drwp-archive-plan-field">
                        <span><?php esc_html_e('メモ', 'drwp-daily-reports'); ?></span>
                        <textarea name="notes" rows="3" placeholder="<?php esc_attr_e('やること・持ち物・連絡事項など', 'drwp-daily-reports'); ?>"></textarea>
                    </label>
                    <div class="drwp-archive-plan-actions">
                        <button type="submit" class="drwp-archive-new-btn">
                            <?php esc_html_e('予定を登録', 'drwp-daily-reports'); ?>
                        </button>
                        <span class="drwp-archive-plan-status" data-role="status"></span>
                    </div>
                </form>
            </div>
        </dialog>
        <?php endif; ?>

        <script>
        (function(){
          var cfg = <?php echo $cfg; ?>;
          var viewDlg = document.getElementById('drwp-archive-view-dialog');
          var formDlg = document.getElementById('drwp-archive-form-dialog');
          var viewBody = document.getElementById('drwp-archive-view-body');
          if (!viewDlg) return;

          function esc(s){ var d=document.createElement('div'); d.textContent=String(s==null?'':s); return d.innerHTML; }
          function nl2br(s){ return esc(s).replace(/\n/g, '<br>'); }
          function fmtDate(d){
            if(!d) return '';
            var m = String(d).match(/^(\d{4})-(\d{2})-(\d{2})/);
            if(!m) return esc(d);
            var w = ['日','月','火','水','木','金','土'][new Date(+m[1], +m[2]-1, +m[3]).getDay()];
            return m[1]+'年'+(+m[2])+'月'+(+m[3])+'日（'+w+'）';
          }
          function fmtTime(t){ return String(t||'').substring(0,5); }

          // Latest fetched report payload — kept around so that toggling
          // edit ↔ view modes doesn't need a fresh GET each time.
          var currentReport = null;

          function openView(id){
            viewBody.innerHTML = '<p class="drwp-archive-dialog-loading"><?php echo esc_js(__('読み込み中…', 'drwp-daily-reports')); ?></p>';
            viewDlg.showModal();
            fetch(cfg.restRoot + '/reports/' + encodeURIComponent(id), {
              credentials: 'same-origin',
              headers: { 'X-WP-Nonce': cfg.nonce },
            }).then(function(r){ return r.json().then(function(j){ if(!r.ok) throw new Error(j.message||'HTTP '+r.status); return j; }); })
              .then(function(d){
                currentReport = d;
                renderViewMode(d);
              })
              .catch(function(err){
                viewBody.innerHTML = '<p class="drwp-archive-dialog-error">'+esc(err.message || '<?php echo esc_js(__('読み込みに失敗しました。', 'drwp-daily-reports')); ?>')+'</p>';
              });
          }

          // 紙面風ビュー — 管理画面の「確認」モーダルと同じレイア
          // ウト (「作業日報」タイトル → 案件/日付/作業時間/報告者
          // /レビュー のメタ表 → 作業内容 / 特記事項 / 次回予定 /
          // 写真 の section テーブル) で揃える。「編集する」は同じ
          // ダイアログの body を renderEditMode に差し替えるので、
          // 別ページに遷移しない。
          function renderViewMode(d) {
            var time = '';
            if (d.started_at) time += fmtTime(d.started_at);
            if (d.started_at && d.ended_at) time += ' 〜 ';
            if (d.ended_at) time += fmtTime(d.ended_at);
            var statusLabel = (cfg.labels && cfg.labels[d.review_status]) || d.review_status;
            var emptyTxt = '<?php echo esc_js(__('（記載なし）', 'drwp-daily-reports')); ?>';

            var html = '';
            html += '<article class="drwp-archive-page">';
            html += '<div class="drwp-archive-page-title"><?php echo esc_js(__('作業日報', 'drwp-daily-reports')); ?></div>';

            html += '<table class="drwp-archive-page-meta"><colgroup>';
            html += '<col class="drwp-archive-meta-col-head"/><col class="drwp-archive-meta-col-val"/>';
            html += '<col class="drwp-archive-meta-col-head"/><col class="drwp-archive-meta-col-val"/>';
            html += '</colgroup>';
            html += '<tr><th><?php echo esc_js(__('案件名', 'drwp-daily-reports')); ?></th>'
                  + '<td colspan="3">' + esc(d.project_name || '<?php echo esc_js(__('（案件未設定）', 'drwp-daily-reports')); ?>') + '</td></tr>';
            html += '<tr><th><?php echo esc_js(__('日付', 'drwp-daily-reports')); ?></th><td>' + esc(fmtDate(d.report_date)) + '</td>'
                  + '<th><?php echo esc_js(__('作業時間', 'drwp-daily-reports')); ?></th><td>' + esc(time || '-') + '</td></tr>';
            html += '<tr><th><?php echo esc_js(__('報告者', 'drwp-daily-reports')); ?></th><td>' + esc(d.author_name || '-') + '</td>'
                  + '<th><?php echo esc_js(__('レビュー', 'drwp-daily-reports')); ?></th>'
                  + '<td><span class="drwp-archive-page-status is-' + esc(d.review_status) + '">' + esc(statusLabel) + '</span></td></tr>';
            html += '</table>';

            html += renderPageSection('<?php echo esc_js(__('作業内容', 'drwp-daily-reports')); ?>',
                    d.work_description ? nl2br(d.work_description) : '<span class="drwp-archive-page-empty">' + emptyTxt + '</span>');
            html += renderPageSection('<?php echo esc_js(__('特記事項（反省・連絡・相談・提案）', 'drwp-daily-reports')); ?>',
                    d.issues ? nl2br(d.issues) : '<span class="drwp-archive-page-empty">' + emptyTxt + '</span>');
            html += renderPageSection('<?php echo esc_js(__('次回予定', 'drwp-daily-reports')); ?>',
                    d.next_plan ? nl2br(d.next_plan) : '<span class="drwp-archive-page-empty">' + emptyTxt + '</span>');

            var photosHtml = '';
            if (d.photos && d.photos.length) {
              photosHtml += '<div class="drwp-archive-page-photos">';
              d.photos.forEach(function(p){
                photosHtml += '<figure><a href="' + esc(p.url) + '" target="_blank" rel="noopener"><img src="' + esc(p.url) + '" alt=""></a>';
                if (p.caption) photosHtml += '<figcaption>' + esc(p.caption) + '</figcaption>';
                photosHtml += '</figure>';
              });
              photosHtml += '</div>';
            } else {
              photosHtml = '<span class="drwp-archive-page-empty">' + emptyTxt + '</span>';
            }
            html += renderPageSection('<?php echo esc_js(__('写真', 'drwp-daily-reports')); ?>', photosHtml);

            html += '</article>';

            // pending (レビュー待ち) と needs_revision (差戻し) は
            // 投稿者がまだ自力で修正できる状態なので、両方で「編集
            // する」ボタンを出す。投稿者本人 (= user_id 一致) または
            // 事務所 (edit_others_posts) のみ。他社員には出さない
            // (他人の日報は閲覧のみで編集不可)。
            var canEdit = !cfg.isRetired
                       && (d.review_status === 'pending' || d.review_status === 'needs_revision')
                       && (cfg.canEditOthers || Number(d.user_id) === Number(cfg.currentUserId));
            if (canEdit) {
              html += '<div class="drwp-archive-view-actions">';
              html += '<button type="button" class="drwp-archive-new-btn" data-action="enter-edit">'
                    + '<?php echo esc_js(__('編集する', 'drwp-daily-reports')); ?></button>';
              html += '</div>';
            }

            viewBody.innerHTML = html;
          }

          function renderPageSection(title, bodyHtml) {
            return '<table class="drwp-archive-page-section">'
                 + '<tr><th class="drwp-archive-page-section-head">' + esc(title) + '</th></tr>'
                 + '<tr><td class="drwp-archive-page-section-body"><div class="drwp-archive-page-text">' + bodyHtml + '</div></td></tr>'
                 + '</table>';
          }

          // Inline edit form swap — same dialog, same backdrop, no page
          // navigation. Fields mirror the standalone ?drwp_edit=1 view
          // (date / project / times / work / issues / next_plan +
          // photo list with caption + remove). New-photo upload reuses
          // /upload-photo (matches the mobile-form pattern). Save fires
          // PATCH /reports/{id} and on success re-renders the view
          // mode with the updated payload.
          function renderEditMode(d) {
            var projects = (cfg.projects || []);
            var html = '';
            html += '<form id="drwp-archive-inline-edit" class="drwp-archive-inline-edit">';
            html += '<input type="hidden" name="id" value="' + esc(d.id) + '" />';

            html += '<label class="drwp-archive-inline-field">';
            html += '<span><?php echo esc_js(__('日付', 'drwp-daily-reports')); ?> <em>*</em></span>';
            html += '<input type="date" name="report_date" value="' + esc(d.report_date) + '" required />';
            html += '</label>';

            html += '<div class="drwp-archive-inline-field">';
            html += '<span><?php echo esc_js(__('案件', 'drwp-daily-reports')); ?> <em>*</em></span>';
            html += '<div class="drwp-combo" data-drwp-combo>';
            html += '<select name="project_id" required>';
            html += '<option value=""><?php echo esc_js(__('選択してください', 'drwp-daily-reports')); ?></option>';
            // 「最近使った」と「案件」で optgroup を分けて combo.js のグループ
            // 見出しを効かせる。is_recent フラグは PHP 側で付与済み。
            var recentProjects = projects.filter(function (p) { return !!p.is_recent; });
            var otherProjects  = projects.filter(function (p) { return !p.is_recent; });
            if (recentProjects.length) {
              html += '<optgroup label="<?php echo esc_js(__('最近使った', 'drwp-daily-reports')); ?>">';
              recentProjects.forEach(function (p) {
                var sel = (String(p.id) === String(d.project_id || '')) ? ' selected' : '';
                html += '<option value="' + esc(p.id) + '"' + sel + '>' + esc(p.name) + '</option>';
              });
              html += '</optgroup>';
            }
            if (otherProjects.length) {
              html += '<optgroup label="<?php echo esc_js(__('案件', 'drwp-daily-reports')); ?>">';
              otherProjects.forEach(function (p) {
                var sel = (String(p.id) === String(d.project_id || '')) ? ' selected' : '';
                html += '<option value="' + esc(p.id) + '"' + sel + '>' + esc(p.name) + '</option>';
              });
              html += '</optgroup>';
            }
            html += '</select>';
            html += '</div>';
            html += '</div>';

            html += '<div class="drwp-archive-inline-times">';
            html += '<label class="drwp-archive-inline-field"><span><?php echo esc_js(__('開始時刻', 'drwp-daily-reports')); ?></span>';
            html += '<input type="time" name="started_at" value="' + esc(fmtTime(d.started_at)) + '" /></label>';
            html += '<label class="drwp-archive-inline-field"><span><?php echo esc_js(__('終了時刻', 'drwp-daily-reports')); ?></span>';
            html += '<input type="time" name="ended_at" value="' + esc(fmtTime(d.ended_at)) + '" /></label>';
            html += '</div>';

            html += '<label class="drwp-archive-inline-field">';
            html += '<span><?php echo esc_js(__('作業内容', 'drwp-daily-reports')); ?> <em>*</em></span>';
            html += '<textarea name="work_description" rows="4" required>' + esc(d.work_description || '') + '</textarea>';
            html += '</label>';

            html += '<label class="drwp-archive-inline-field">';
            html += '<span><?php echo esc_js(__('特記事項', 'drwp-daily-reports')); ?></span>';
            html += '<textarea name="issues" rows="2">' + esc(d.issues || '') + '</textarea>';
            html += '</label>';

            html += '<label class="drwp-archive-inline-field">';
            html += '<span><?php echo esc_js(__('次回予定', 'drwp-daily-reports')); ?></span>';
            html += '<textarea name="next_plan" rows="2">' + esc(d.next_plan || '') + '</textarea>';
            html += '</label>';

            html += '<div class="drwp-archive-inline-field">';
            html += '<span><?php echo esc_js(__('写真', 'drwp-daily-reports')); ?></span>';
            html += '<div class="drwp-archive-inline-photos" data-role="photos">';
            (d.photos || []).forEach(function (p) {
              html += renderEditPhoto(p.attachment_id, p.url, p.caption || '', p.full_url || p.url);
            });
            html += '</div>';
            html += '<label class="drwp-archive-inline-photo-pick">+ <?php echo esc_js(__('写真を追加', 'drwp-daily-reports')); ?>'
                  + '<input type="file" accept="image/*" multiple data-role="photo-input" /></label>';
            html += '<p class="drwp-archive-inline-photo-status" data-role="photo-status"></p>';
            html += '</div>';

            html += '<div class="drwp-archive-inline-actions">';
            html += '<button type="submit" class="drwp-archive-new-btn"><?php echo esc_js(__('保存', 'drwp-daily-reports')); ?></button>';
            html += '<button type="button" class="drwp-archive-inline-cancel" data-action="cancel-edit"><?php echo esc_js(__('キャンセル', 'drwp-daily-reports')); ?></button>';
            html += '<span class="drwp-archive-inline-status" data-role="save-status"></span>';
            html += '</div>';
            html += '</form>';

            viewBody.innerHTML = html;
            // combobox 拡張は DOMContentLoaded 時点で 1 度走るだけなので、
            // ここで挿入したフォームに対して明示的に再適用する。
            if (window.DRWP_Combo) window.DRWP_Combo.enhance(viewBody);
          }

          function renderEditPhoto(id, url, caption, fullUrl) {
            // url = サムネ表示用 (WP medium / thumbnail)
            // fullUrl = モザイク編集用のオリジナル (アスペクト比を保つため)
            //   WP thumbnail は 150x150 ハードクロップなので、ぼかし
            //   ソースにそれを使うと画像が正方形に切れる事故が出る。
            var fu = fullUrl || url;
            return '<div class="drwp-archive-inline-photo-item" data-url="' + esc(url) + '" data-full-url="' + esc(fu) + '">'
                 + '<img src="' + esc(url) + '" alt="" />'
                 + '<input type="hidden" name="attachment_ids[]" value="' + esc(id) + '" />'
                 + '<input type="text" name="attachment_captions[]" placeholder="<?php echo esc_js(__('キャプション', 'drwp-daily-reports')); ?>" value="' + esc(caption) + '" />'
                 + '<button type="button" class="drwp-archive-inline-photo-mosaic" data-role="mosaic-photo">'
                 +   '<?php echo esc_js(__('ぼかし', 'drwp-daily-reports')); ?>'
                 + '</button>'
                 + '<button type="button" class="drwp-archive-inline-photo-remove" data-role="remove-photo">×</button>'
                 + '</div>';
          }

          function uploadPhoto(file, statusEl) {
            var body = new FormData();
            body.append('file', file, file.name);
            return fetch(cfg.restRoot + '/upload-photo', {
              method: 'POST',
              credentials: 'same-origin',
              headers: { 'X-WP-Nonce': cfg.nonce },
              body: body
            }).then(function (r) {
              return r.json().then(function (j) { if (!r.ok) throw new Error(j.message || 'HTTP ' + r.status); return j; });
            });
          }

          viewBody.addEventListener('click', function (e) {
            var a = e.target.dataset && e.target.dataset.action;
            if (a === 'enter-edit') {
              if (currentReport) renderEditMode(currentReport);
              return;
            }
            if (a === 'cancel-edit') {
              if (currentReport) renderViewMode(currentReport);
              return;
            }
            if (e.target.dataset && e.target.dataset.role === 'remove-photo') {
              var item = e.target.closest('.drwp-archive-inline-photo-item');
              if (item) item.remove();
              return;
            }
            if (e.target.dataset && e.target.dataset.role === 'mosaic-photo') {
              // 既存の attachment をモザイク編集 → 新規アップロード →
              // この行の attachment_id を新しいものに差し替える。
              // 元の attachment は別の場所で参照されている可能性があるので、
              // 上書きはせず「新しい添付」として作る方が安全。
              var item = e.target.closest('.drwp-archive-inline-photo-item');
              if (!item || !window.DRWP_Mosaic) return;
              // モザイク編集はオリジナル (data-full-url) で開く。サムネ
              // URL (data-url) は WP の hard-crop された thumbnail だと
              // 正方形に切れて困るので、フル URL を優先する。
              var url = item.dataset.fullUrl || item.dataset.url || (item.querySelector('img') || {}).src;
              if (!url) return;
              var btn = e.target;
              btn.disabled = true;
              window.DRWP_Mosaic.open({
                imageUrl: url,
                onApply: function (blob) {
                  if (!blob) { btn.disabled = false; return; }
                  var body = new FormData();
                  body.append('file', blob, 'mosaic.jpg');
                  fetch(cfg.restRoot + '/upload-photo', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-WP-Nonce': cfg.nonce },
                    body: body
                  }).then(function (r) {
                    return r.json().then(function (j) { if (!r.ok) throw new Error(j.message || 'HTTP ' + r.status); return j; });
                  }).then(function (j) {
                    // この行のサムネ・hidden id・data-url を新規 attachment に差し替え
                    var img = item.querySelector('img');
                    var hidden = item.querySelector('input[name="attachment_ids[]"]');
                    var newUrl = j.thumbnail_url || j.full_url || '';
                    var newFull = j.full_url || j.thumbnail_url || '';
                    if (img && newUrl) img.src = newUrl;
                    if (hidden) hidden.value = String(j.id);
                    item.dataset.url = newUrl;
                    item.dataset.fullUrl = newFull;
                    btn.disabled = false;
                  }).catch(function (err) {
                    alert((err && err.message) || '<?php echo esc_js(__('アップロード失敗', 'drwp-daily-reports')); ?>');
                    btn.disabled = false;
                  });
                },
                onCancel: function () { btn.disabled = false; }
              });
              return;
            }
          });

          viewBody.addEventListener('change', function (e) {
            if (!e.target.dataset || e.target.dataset.role !== 'photo-input') return;
            var input = e.target;
            var files = Array.from(input.files || []);
            if (!files.length) return;
            var status = viewBody.querySelector('[data-role=photo-status]');
            var photoList = viewBody.querySelector('[data-role=photos]');
            if (!photoList) return;
            var i = 0;
            function next() {
              if (i >= files.length) {
                input.value = '';
                if (status) status.textContent = '';
                return;
              }
              var f = files[i++];
              if (status) status.textContent = '<?php echo esc_js(__('アップロード中…', 'drwp-daily-reports')); ?> (' + i + '/' + files.length + ')';
              uploadPhoto(f).then(function (j) {
                var tmp = document.createElement('div');
                tmp.innerHTML = renderEditPhoto(j.id, j.thumbnail_url || j.full_url || '', '', j.full_url || j.thumbnail_url || '');
                photoList.appendChild(tmp.firstChild);
                next();
              }).catch(function (err) {
                if (status) status.textContent = err.message || '<?php echo esc_js(__('アップロード失敗', 'drwp-daily-reports')); ?>';
                input.value = '';
              });
            }
            next();
          });

          viewBody.addEventListener('submit', function (e) {
            if (!e.target.matches('#drwp-archive-inline-edit')) return;
            e.preventDefault();
            var form = e.target;
            var status = form.querySelector('[data-role=save-status]');
            var btn = form.querySelector('button[type=submit]');
            if (status) { status.textContent = '<?php echo esc_js(__('保存中…', 'drwp-daily-reports')); ?>'; status.className = 'drwp-archive-inline-status'; }
            if (btn) btn.disabled = true;

            var ids = Array.from(form.querySelectorAll('input[name="attachment_ids[]"]')).map(function (i) { return Number(i.value); });
            var caps = Array.from(form.querySelectorAll('input[name="attachment_captions[]"]')).map(function (i) { return i.value; });
            var payload = {
              report_date:         form.report_date.value,
              project_id:          form.project_id.value ? Number(form.project_id.value) : null,
              started_at:          form.started_at.value || null,
              ended_at:            form.ended_at.value || null,
              work_description:    form.work_description.value,
              issues:              form.issues.value,
              next_plan:           form.next_plan.value,
              attachment_ids:      ids,
              attachment_captions: caps
            };
            fetch(cfg.restRoot + '/reports/' + encodeURIComponent(form.id.value), {
              method: 'PATCH',
              credentials: 'same-origin',
              headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
              body: JSON.stringify(payload)
            }).then(function (r) {
              return r.json().then(function (j) { if (!r.ok) throw new Error(j.message || 'HTTP ' + r.status); return j; });
            }).then(function (updated) {
              currentReport = updated;
              renderViewMode(updated);
            }).catch(function (err) {
              if (status) { status.textContent = err.message || '<?php echo esc_js(__('保存に失敗しました', 'drwp-daily-reports')); ?>'; status.className = 'drwp-archive-inline-status err'; }
              if (btn) btn.disabled = false;
            });
          });

          var planDlg = document.getElementById('drwp-archive-plan-dialog');

          // Plan chip → open the report form with date / project /
          // time pre-filled from the plan. The mobile-form.js submit
          // handler reads form values straight off the inputs, so
          // populating them here is enough; we don't need a separate
          // wire-up. We also stash the linked-plan id on a hidden
          // input the report submit can later send back if we ever
          // want to auto-link the report on POST.
          function openReportFromPlan(chip) {
            if (!formDlg) return;
            var d = chip.dataset;
            var form = document.getElementById('drwp-mform');
            if (form) {
              if (form.report_date) form.report_date.value = d.planDate || '';
              if (form.project_id) {
                form.project_id.value = d.planProjectId || '';
                // combobox 拡張側に同期させる (input 表示の更新)
                form.project_id.dispatchEvent(new Event('change', { bubbles: true }));
              }
              if (form.started_at) form.started_at.value  = d.planStart || '';
              if (form.ended_at)   form.ended_at.value    = d.planEnd || '';
              var hidden = form.querySelector('input[name="linked_plan_id"]');
              if (!hidden) {
                hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'linked_plan_id';
                form.appendChild(hidden);
              }
              hidden.value = d.planId || '';
            }
            var body = formDlg.querySelector('.drwp-archive-dialog-body');
            var prev = body.querySelector('.drwp-archive-plan-hint');
            if (prev) prev.remove();
            var hint = document.createElement('div');
            hint.className = 'drwp-archive-plan-hint';
            var lead = '📋 ' + '<?php echo esc_js(__('予定', 'drwp-daily-reports')); ?>' + ' #' + esc(d.planId)
                     + ' ' + '<?php echo esc_js(__('からテンプレを読み込みました（保存するとこの予定は完了になります）', 'drwp-daily-reports')); ?>';
            hint.innerHTML = lead;
            if (d.planNotes) {
              var n = document.createElement('div');
              n.className = 'drwp-archive-plan-hint-notes';
              n.textContent = d.planNotes;
              hint.appendChild(n);
            }
            body.insertBefore(hint, body.firstChild);
            formDlg.showModal();
          }

          document.addEventListener('click', function(e){
            // Tap a plan chip → open the report form pre-filled from it.
            // Linked plans (already tied to a report) are excluded:
            // they're non-draggable / non-editable, so they should not
            // spawn a second report either.
            // リストビューの行 (予定) も予定チップ扱いで日報フォームへ。
            var listPlan = e.target.closest('.drwp-archive-list-row.is-plan[data-plan-id]');
            if (listPlan) {
              e.preventDefault();
              openReportFromPlan(listPlan);
              return;
            }
            var planChip = e.target.closest('.drwp-archive-cal-line-plan[data-plan-id]:not(.is-linked)');
            if (planChip) {
              e.preventDefault();
              openReportFromPlan(planChip);
              return;
            }
            // リストビューの日報行 → 詳細モーダル
            var listRow = e.target.closest('.drwp-archive-list-report[data-id]');
            if (listRow) {
              e.preventDefault();
              openView(listRow.dataset.id);
              return;
            }
            // カレンダーの日報チップ → 詳細モーダル (要対応カード or 承認済み行)
            var chip = e.target.closest('.drwp-archive-cal-card[data-id], .drwp-archive-cal-line-approved[data-id]');
            if (chip) {
              e.preventDefault();
              openView(chip.dataset.id);
              return;
            }
            if (formDlg) {
              var newBtn = e.target.closest('#drwp-archive-new-btn');
              if (newBtn) {
                e.preventDefault();
                // Manual open should not carry a stale plan hint.
                var body = formDlg.querySelector('.drwp-archive-dialog-body');
                var stale = body && body.querySelector('.drwp-archive-plan-hint');
                if (stale) stale.remove();
                var form = document.getElementById('drwp-mform');
                var hidden = form && form.querySelector('input[name="linked_plan_id"]');
                if (hidden) hidden.value = '';
                formDlg.showModal();
                return;
              }
            }
            if (planDlg) {
              var planBtn = e.target.closest('#drwp-archive-new-plan-btn');
              if (planBtn) {
                e.preventDefault();
                planDlg.showModal();
                return;
              }
            }
            if (e.target.classList.contains('drwp-archive-dialog-close')) {
              var dlg = e.target.closest('dialog');
              if (dlg) dlg.close();
            }
          });

          // Plan create — POST to REST /plans, then reload so the
          // calendar reflects the new chip. Keeps the same patterns
          // as mobile-form.js (status pill, disabled-while-sending).
          var planForm = document.getElementById('drwp-archive-plan-form');
          if (planForm) {
            planForm.addEventListener('submit', function (e) {
              e.preventDefault();
              var st = planForm.querySelector('[data-role=status]');
              var btn = planForm.querySelector('button[type=submit]');
              if (st) { st.textContent = '<?php echo esc_js(__('送信中…', 'drwp-daily-reports')); ?>'; st.className = 'drwp-archive-plan-status'; }
              if (btn) btn.disabled = true;
              var payload = {
                planned_date: planForm.planned_date.value,
                project_id:   planForm.project_id.value ? Number(planForm.project_id.value) : null,
                started_at:   planForm.started_at.value || null,
                ended_at:     planForm.ended_at.value || null,
                notes:        planForm.notes.value || ''
              };
              fetch(cfg.restRoot + '/plans', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
                body: JSON.stringify(payload)
              }).then(function (r) {
                return r.json().then(function (j) { if (!r.ok) throw new Error(j.message || ('HTTP ' + r.status)); return j; });
              }).then(function () {
                if (st) { st.textContent = '<?php echo esc_js(__('登録しました', 'drwp-daily-reports')); ?>'; st.className = 'drwp-archive-plan-status ok'; }
                // Reload so the new plan shows up in the calendar +
                // any other surfaces that read from server state.
                window.location.reload();
              }).catch(function (err) {
                if (st) { st.textContent = err.message || '<?php echo esc_js(__('登録に失敗しました', 'drwp-daily-reports')); ?>'; st.className = 'drwp-archive-plan-status err'; }
                if (btn) btn.disabled = false;
              });
            });
          }

          // 予定の日付変更 — HTML5 drag-and-drop. クリックで「予定
          // からテンプレ作成」も残してあるので、両方の操作が共存。
          // すでに日報と紐づいた予定 (.is-linked) はドラッグ対象外
          // にする(済んだ予定の日付は動かさない)。サーバ側でも
          // `DRWP_Plan::can_edit($plan)` が無理な書き込みを弾く。
          var draggingPlan = null;
          var moveErrMsg = <?php echo wp_json_encode(__('日付の変更に失敗しました', 'drwp-daily-reports')); ?>;
          document.querySelectorAll('.drwp-archive-cal-line-plan:not(.is-linked)').forEach(function (chip) {
            chip.setAttribute('draggable', 'true');
            chip.addEventListener('dragstart', function (e) {
              draggingPlan = chip;
              chip.classList.add('is-dragging');
              if (e.dataTransfer) {
                e.dataTransfer.effectAllowed = 'move';
                try { e.dataTransfer.setData('text/plain', chip.dataset.planId); } catch (err) {}
              }
            });
            chip.addEventListener('dragend', function () {
              chip.classList.remove('is-dragging');
              document.querySelectorAll('.drwp-archive-cal-cell.is-drop-target').forEach(function (c) {
                c.classList.remove('is-drop-target');
              });
              draggingPlan = null;
            });
          });
          document.querySelectorAll('.drwp-archive-cal-cell[data-date]').forEach(function (cell) {
            cell.addEventListener('dragenter', function (e) {
              if (!draggingPlan) return;
              e.preventDefault();
              if (cell.dataset.date === draggingPlan.dataset.planDate) return;
              cell.classList.add('is-drop-target');
            });
            cell.addEventListener('dragover', function (e) {
              if (!draggingPlan) return;
              e.preventDefault();
              if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
            });
            cell.addEventListener('dragleave', function (e) {
              // dragleave fires when entering a child node too;
              // only clear the highlight when we've truly left
              // the cell (relatedTarget is outside).
              if (!cell.contains(e.relatedTarget)) {
                cell.classList.remove('is-drop-target');
              }
            });
            cell.addEventListener('drop', function (e) {
              e.preventDefault();
              cell.classList.remove('is-drop-target');
              if (!draggingPlan) return;
              var date = cell.dataset.date;
              var planId = draggingPlan.dataset.planId;
              if (!date || !planId) return;
              if (date === draggingPlan.dataset.planDate) return;
              draggingPlan.style.opacity = '0.5';
              fetch(cfg.restRoot + '/plans/' + encodeURIComponent(planId), {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
                body: JSON.stringify({ planned_date: date })
              }).then(function (r) {
                return r.json().then(function (j) {
                  if (!r.ok) throw new Error(j.message || 'HTTP ' + r.status);
                  return j;
                });
              }).then(function () {
                window.location.reload();
              }).catch(function (err) {
                window.alert(err.message || moveErrMsg);
                if (draggingPlan) draggingPlan.style.opacity = '';
              });
            });
          });

          // 予定の編集 — 長押し(touch / mouse 両対応)で編集ダイ
          // アログを開く。タップ = 「予定→日報テンプレ」、ドラッグ
          // = 日付変更、長押し = 編集の3経路を共存させる。
          // 500ms 経過前にドラッグが始まったり指を離したりしたら、
          // 長押し判定はキャンセル(= 普通のクリック / ドラッグへ
          // 自然にフォールバック)。
          var planEditDlg  = document.getElementById('drwp-archive-plan-edit-dialog');
          var planEditForm = document.getElementById('drwp-archive-plan-edit-form');
          var updateErrMsg = <?php echo wp_json_encode(__('予定の更新に失敗しました', 'drwp-daily-reports')); ?>;

          function openPlanEditDialog(chip) {
            if (!planEditDlg || !planEditForm) return;
            planEditForm.id.value           = chip.dataset.planId || '';
            planEditForm.planned_date.value = chip.dataset.planDate || '';
            planEditForm.started_at.value   = chip.dataset.planStart || '';
            planEditForm.ended_at.value     = chip.dataset.planEnd || '';
            planEditForm.notes.value        = chip.dataset.planNotes || '';
            // 案件 select は全員あり。data-plan-project-id がある時だけ
            // selected を当てる(無いと "（未設定）" が選ばれる)。
            if (planEditForm.project_id) {
              var pid = chip.dataset.planProjectId || '';
              planEditForm.project_id.value = (pid && pid !== '0') ? pid : '';
              planEditForm.project_id.dispatchEvent(new Event('change', { bubbles: true }));
            }
            // 担当者 select は事務所だけ。データ属性は data-plan-user-id
            // で出してる(case-sensitive で正規化済み — dataset は
            // kebab → camel)。
            if (planEditForm.user_id) {
              var uid = chip.dataset.planUserId || '';
              planEditForm.user_id.value = (uid && uid !== '0') ? uid : '';
            }
            var st = planEditForm.querySelector('[data-role=status]');
            if (st) { st.textContent = ''; st.className = 'drwp-archive-plan-status'; }
            var btn = planEditForm.querySelector('button[type=submit]');
            if (btn) btn.disabled = false;
            planEditDlg.showModal();
          }

          function setupLongPress(el, onLongPress) {
            var timer = null, startX = 0, startY = 0, fired = false;
            function start(e) {
              fired = false;
              var t = e.touches ? e.touches[0] : e;
              startX = t.clientX; startY = t.clientY;
              if (timer) clearTimeout(timer);
              timer = setTimeout(function () {
                fired = true;
                timer = null;
                onLongPress();
              }, 500);
            }
            function move(e) {
              if (!timer) return;
              var t = e.touches ? e.touches[0] : e;
              if (Math.abs(t.clientX - startX) > 8 || Math.abs(t.clientY - startY) > 8) {
                clearTimeout(timer); timer = null;
              }
            }
            function cancel() {
              if (timer) { clearTimeout(timer); timer = null; }
            }
            el.addEventListener('touchstart',  start,  { passive: true });
            el.addEventListener('touchmove',   move,   { passive: true });
            el.addEventListener('touchend',    cancel);
            el.addEventListener('touchcancel', cancel);
            el.addEventListener('mousedown',   start);
            el.addEventListener('mousemove',   move);
            el.addEventListener('mouseup',     cancel);
            el.addEventListener('mouseleave',  cancel);
            // ドラッグが始まったら長押しはキャンセル(D&D 優先)。
            el.addEventListener('dragstart',   cancel);
            // 長押しが発火したら、その直後の click は飲み込む
            // (タップ後の意図しない「日報テンプレ作成」を防ぐ)。
            el.addEventListener('click', function (e) {
              if (fired) { e.preventDefault(); e.stopPropagation(); fired = false; }
            }, true);
          }

          document.querySelectorAll('.drwp-archive-cal-line-plan:not(.is-linked)').forEach(function (chip) {
            setupLongPress(chip, function () { openPlanEditDialog(chip); });
          });

          if (planEditForm) {
            planEditForm.addEventListener('submit', function (e) {
              e.preventDefault();
              var st = planEditForm.querySelector('[data-role=status]');
              var btn = planEditForm.querySelector('button[type=submit]');
              if (st) { st.textContent = '<?php echo esc_js(__('送信中…', 'drwp-daily-reports')); ?>'; st.className = 'drwp-archive-plan-status'; }
              if (btn) btn.disabled = true;
              var payload = {
                planned_date: planEditForm.planned_date.value,
                started_at:   planEditForm.started_at.value || null,
                ended_at:     planEditForm.ended_at.value || null,
                notes:        planEditForm.notes.value || ''
              };
              // project_id は誰でも変更可能(空 = 案件解除)。
              if (planEditForm.project_id) {
                payload.project_id = planEditForm.project_id.value
                  ? Number(planEditForm.project_id.value) : null;
              }
              // user_id は事務所のみ送る(REST 側でも edit_others_posts
              // でないリクエストは弾く)。空 = 担当解除。
              if (planEditForm.user_id && cfg.canAssignPlans) {
                payload.user_id = planEditForm.user_id.value
                  ? Number(planEditForm.user_id.value) : null;
              }
              fetch(cfg.restRoot + '/plans/' + encodeURIComponent(planEditForm.id.value), {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
                body: JSON.stringify(payload)
              }).then(function (r) {
                return r.json().then(function (j) {
                  if (!r.ok) throw new Error(j.message || 'HTTP ' + r.status);
                  return j;
                });
              }).then(function () {
                window.location.reload();
              }).catch(function (err) {
                if (st) { st.textContent = err.message || updateErrMsg; st.className = 'drwp-archive-plan-status err'; }
                if (btn) btn.disabled = false;
              });
            });
          }

          // Click outside dialog content closes it.
          [viewDlg, formDlg, planDlg, planEditDlg].forEach(function(dlg){
            if (!dlg) return;
            dlg.addEventListener('click', function(e){
              var rect = dlg.getBoundingClientRect();
              if (e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom) {
                dlg.close();
              }
            });
          });

          // Auto-open the form modal when arriving via ?drwp_new=1.
          // Lets external links / bookmarks land users directly on
          // the new-report form without needing a click.
          if (cfg.autoOpenNew && formDlg) {
            try { formDlg.showModal(); } catch (e) {}
          }

          // ↑↓ 日付ジャンプボタン。リスト内の .drwp-archive-list-group
          // を順番に見て、ビューポート真ん中より上にある最後の見出し
          // を「今いる日」と判定し、その前後にスクロールする。日付
          // ヘッダは sticky なのでスクロール時はビューポート上端に
          // 張り付くから、その分のオフセットも引いておく。
          (function () {
            var jump = document.querySelector('[data-role=date-jump]');
            if (!jump) return;
            function groups() {
              return Array.prototype.slice.call(
                document.querySelectorAll('.drwp-archive-list-group')
              );
            }
            function currentIndex() {
              var gs = groups();
              if (!gs.length) return -1;
              var threshold = 80; // sticky header の高さ目安
              var idx = 0;
              for (var i = 0; i < gs.length; i++) {
                if (gs[i].getBoundingClientRect().top - threshold <= 0) idx = i;
              }
              return idx;
            }
            function scrollToGroup(g) {
              if (!g) return;
              var y = g.getBoundingClientRect().top + window.pageYOffset - 8;
              window.scrollTo({ top: y, behavior: 'smooth' });
            }
            jump.addEventListener('click', function (e) {
              var btn = e.target.closest('[data-act]');
              if (!btn) return;
              var gs = groups();
              if (!gs.length) return;
              var i = currentIndex();
              if (btn.dataset.act === 'prev-day') {
                // 「今いる日」のヘッダがビューポート上端付近に張り付いて
                // いる場合、↑ は 1 つ前の日へ。それ以外 (グループの途中)
                // ならその日の先頭に戻す = i のまま。
                var topOfCurrent = gs[i].getBoundingClientRect().top - 80;
                if (topOfCurrent < -20 && i > 0) i--;
              } else if (btn.dataset.act === 'next-day') {
                i = Math.min(gs.length - 1, i + 1);
              }
              scrollToGroup(gs[i]);
            });
            // リストが 1 日分しか無い (= ジャンプの意味がない) 時は隠す
            if (groups().length < 2) jump.style.display = 'none';
          })();
        })();
        </script>
        <?php
        return ob_get_clean();
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
            'use_modals'      => false,
        ], $opts);

        wp_enqueue_style(self::HANDLE);

        global $wpdb;
        $reports_t = $wpdb->prefix . 'drwp_reports';

        $q       = isset($_GET['drwp_q']) ? sanitize_text_field(wp_unslash((string) $_GET['drwp_q'])) : '';
        $project = isset($_GET['drwp_project']) ? absint($_GET['drwp_project']) : 0;
        $status  = isset($_GET['drwp_status']) ? sanitize_key((string) $_GET['drwp_status']) : '';
        // 表示モード: calendar (PC 既定) / list。
        // スマートフォン (UA 判定) ではカレンダーが可読性に欠けるため、
        // ?drwp_view を無視して常に list 固定にする。
        // PC では従来通り ?drwp_view=list で切り替え、トグルから操作可。
        if (wp_is_mobile()) {
            $view = 'list';
        } else {
            $view = (isset($_GET['drwp_view']) && (string) $_GET['drwp_view'] === 'list') ? 'list' : 'calendar';
        }

        // Month navigation. Default to the current month so the view
        // opens on "今月". URL state lets users bookmark a specific month.
        $month_param = isset($_GET['drwp_month']) ? sanitize_text_field((string) $_GET['drwp_month']) : '';
        if (!preg_match('/^\d{4}-\d{2}$/', $month_param)) {
            // current_time() returns the site-TZ now; raw date() would
            // run in the server TZ (UTC on most installs) and tip into
            // "tomorrow"/"yesterday" outside JST business hours.
            $month_param = current_time('Y-m');
        }
        $month_start = $month_param . '-01';
        $month_end   = date('Y-m-t', strtotime($month_start));
        $prev_month  = date('Y-m', strtotime($month_start . ' -1 month'));
        $next_month  = date('Y-m', strtotime($month_start . ' +1 month'));
        $today_month = current_time('Y-m');

        // 日付範囲 (リストビュー専用)。?drwp_from / ?drwp_to が指定されて
        // いるとき、リスト表示では月単位の絞り込みを無視してこの範囲で
        // クエリを発行する。カレンダーは月グリッドが前提なので無視。
        $from_raw = isset($_GET['drwp_from']) ? sanitize_text_field((string) $_GET['drwp_from']) : '';
        $to_raw   = isset($_GET['drwp_to'])   ? sanitize_text_field((string) $_GET['drwp_to'])   : '';
        $range_from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_raw) ? $from_raw : '';
        $range_to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_raw)   ? $to_raw   : '';
        // 片方だけ指定された場合の補完: from だけ -> to は今日、
        // to だけ -> from は to の 30 日前。利用者が「ここから今まで」
        // をやりがちなので、from だけのケースを優先サポートする。
        if ($range_from !== '' && $range_to === '') {
            $range_to = current_time('Y-m-d');
        }
        if ($range_to !== '' && $range_from === '') {
            $range_from = date('Y-m-d', strtotime($range_to . ' -30 days'));
        }
        // from > to の入れ違いはひっくり返して保護
        if ($range_from !== '' && $range_to !== '' && strcmp($range_from, $range_to) > 0) {
            [$range_from, $range_to] = [$range_to, $range_from];
        }
        $use_range = ($view === 'list' && $range_from !== '' && $range_to !== '');
        $q_start = $use_range ? $range_from : $month_start;
        $q_end   = $use_range ? $range_to   : $month_end;

        $where = ['r.report_date >= %s', 'r.report_date <= %s'];
        $args  = [$q_start, $q_end];
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

        // 予定オーバーレイ — same date window, visibility scoped
        // either to the toggled "自分のみ" view or the default
        // worker/operator rule. Operators get every active plan;
        // workers get the ones they own or were assigned.
        $plans = DRWP_Plan::for_archive_month($q_start, $q_end, (bool) $opts['user_id']);
        // 絞り込み条件を予定にも適用する。元の実装では予定だけ全件
        // 出ていて「案件で絞り込んだのに違う案件の予定が混ざる」
        // 不具合になっていた。
        // - project: 予定にも project_id があるので同じ ID だけ残す
        // - status:  予定にレビュー状態は無いので、ステータス絞り込みの
        //            時は予定を一律で隠す (差戻し / 承認済み だけ見たい
        //            状況で予定が混ざっても役に立たない)
        // - q (キーワード): 予定の notes に対して同じ LIKE を掛ける
        if ($project) {
            $plans = array_values(array_filter($plans, function ($pl) use ($project) {
                return (int) ($pl->project_id ?? 0) === (int) $project;
            }));
        }
        if ($status && in_array($status, ['pending', 'approved', 'needs_revision', 'edit_requested'], true)) {
            $plans = [];
        }
        if ($q !== '') {
            $needle = function_exists('mb_strtolower') ? mb_strtolower($q) : strtolower($q);
            $plans = array_values(array_filter($plans, function ($pl) use ($needle) {
                $notes = (string) ($pl->notes ?? '');
                $hay = function_exists('mb_strtolower') ? mb_strtolower($notes) : strtolower($notes);
                return $needle === '' || strpos($hay, $needle) !== false;
            }));
        }
        $plans_by_date = [];
        foreach ($plans as $pl) {
            $plans_by_date[(string) $pl->planned_date][] = $pl;
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

        // ---- ダッシュボード用の集計 -----------------------------
        // ヘッダの「要対応 N 件」と各ステータスごとの件数バッジを
        // 出すため、月内の review_status を集計する。
        $stat = ['needs_revision' => 0, 'pending' => 0, 'edit_requested' => 0, 'approved' => 0];
        foreach ($rows as $r) {
            $key = (string) $r->review_status;
            if (isset($stat[$key])) $stat[$key]++;
        }
        $stat['needs_action'] = $stat['needs_revision'] + $stat['pending'] + $stat['edit_requested'];
        $stat['plans']        = count($plans);

        // ユーザー名はダッシュボードに小さなサブタイトルとして添える。
        // 「shizuoka-company さんとしてログイン中」のような大きな
        // ピル表示は重いので、display_name だけ控えめに出す。
        $current_user = wp_get_current_user();
        $brand_sub = $current_user && $current_user->ID
            ? ($current_user->display_name ?: $current_user->user_login)
            : '';

        ob_start();
        ?>
        <div class="drwp-archive-wrap">
            <?php if (!empty($opts['extra_message'])): ?>
                <div class="drwp-archive-flash"><?php echo wp_kses_post($opts['extra_message']); ?></div>
            <?php endif; ?>

            <?php // ---- 新しいダッシュボードヘッダ ----------------------
            //   1) ブランド名 + ページタイトル (左) と 2 つの CTA (右)
            //   2) 要対応の日報 + ステータス別カウントのカード列
            //   旧 .drwp-archive-actions と .drwp-archive-toolbar を
            //   1 つにまとめて、上から眺めた時に「いま何件あるか」が
            //   一望できるようにする。
            ?>
            <header class="drwp-archive-dashboard">
                <div class="drwp-archive-dashboard-head">
                    <div class="drwp-archive-dashboard-brand">
                        <h1 class="drwp-archive-dashboard-title">
                            <?php echo esc_html(!empty($opts['title']) ? $opts['title'] : __('日報カレンダー', 'drwp-daily-reports')); ?>
                        </h1>
                    </div>
                    <div class="drwp-archive-dashboard-actions">
                        <?php if ($brand_sub): ?>
                            <p class="drwp-archive-dashboard-user">
                                <span class="drwp-archive-dashboard-user-icon" aria-hidden="true">👤</span>
                                <?php echo esc_html($brand_sub); ?>
                            </p>
                        <?php endif; ?>
                        <?php if ($opts['show_new_button'] && $opts['new_url']): ?>
                            <div class="drwp-archive-dashboard-actions-buttons">
                                <?php if (!empty($opts['use_modals'])): ?>
                                    <button type="button" id="drwp-archive-new-btn" class="drwp-archive-new-btn">
                                        + <?php esc_html_e('日報を書く', 'drwp-daily-reports'); ?>
                                    </button>
                                    <button type="button" id="drwp-archive-new-plan-btn" class="drwp-archive-new-btn drwp-archive-new-btn-secondary">
                                        + <?php esc_html_e('予定を書く', 'drwp-daily-reports'); ?>
                                    </button>
                                <?php else: ?>
                                    <a class="drwp-archive-new-btn" href="<?php echo esc_url($opts['new_url']); ?>">
                                        + <?php esc_html_e('日報を書く', 'drwp-daily-reports'); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </header>

            <?php echo self::render_filter_form($q, $project, $status, $month_param, $projects, !empty($_GET['drwp_mine']), $view, $range_from, $range_to); ?>

            <?php
            $total_count = count($rows);
            $sort = (isset($_GET['drwp_sort']) && (string) $_GET['drwp_sort'] === 'date_asc') ? 'date_asc' : 'date_desc';
            // ビュー切替トグルはスマホ (wp_is_mobile) では出さない
            // (どちらにせよ表示は list 固定なので、選択肢を見せて
            // 混乱させないため)。
            $show_view_toggle = !wp_is_mobile();
            if ($view === 'list'): ?>
                <div class="drwp-archive-toolbar">
                    <p class="drwp-archive-summary">
                        <?php if ($use_range): ?>
                            <?php printf(
                                esc_html__('%1$s 〜 %2$s（%3$d 件）', 'drwp-daily-reports'),
                                esc_html(date_i18n('Y/n/j', strtotime($q_start))),
                                esc_html(date_i18n('Y/n/j', strtotime($q_end))),
                                $total_count
                            ); ?>
                        <?php else: ?>
                            <?php printf(
                                esc_html__('%1$s（%2$d 件）', 'drwp-daily-reports'),
                                esc_html(date_i18n('Y年n月', strtotime($month_start))),
                                $total_count
                            ); ?>
                        <?php endif; ?>
                    </p>
                    <div class="drwp-archive-toolbar-actions">
                        <?php echo self::render_sort_toggle($sort); ?>
                        <?php if ($show_view_toggle) echo self::render_view_toggle($view); ?>
                    </div>
                </div>
                <?php echo self::render_archive_list_view($rows, $plans_by_date, $sort); ?>
            <?php else: ?>
                <?php echo self::render_calendar($month_param, $month_start, $by_date, $prev_month, $next_month, $today_month, ['q' => $q, 'project' => $project, 'status' => $status], $plans_by_date, $view, $total_count); ?>
            <?php endif; ?>
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

    private static function render_filter_form($q, $project, $status, $month_param, $projects, $mine = false, $view = 'calendar', $range_from = '', $range_to = '') {
        // On non-permalink sites the page is identified by ?page_id=N
        // (or similar). A GET form replaces the entire query string
        // with its form fields, so those external params would be lost
        // unless we mirror them as hidden inputs. Anything that isn't
        // one of our drwp_* keys gets preserved this way.
        $current_query = [];
        parse_str($_SERVER['QUERY_STRING'] ?? '', $current_query);
        $drwp_keys = ['drwp_q', 'drwp_project', 'drwp_status', 'drwp_month',
                      'drwp_mine', 'drwp_scope_set', 'drwp_id', 'drwp_edit', 'drwp_new',
                      'drwp_saved', 'drwp_requested', 'drwp_err', 'drwp_p', 'drwp_per',
                      'drwp_view', 'drwp_sort', 'drwp_from', 'drwp_to'];
        $preserve = [];
        foreach ($current_query as $k => $v) {
            if (!in_array($k, $drwp_keys, true) && is_scalar($v)) {
                $preserve[$k] = (string) $v;
            }
        }
        $reset_url = remove_query_arg(['drwp_q', 'drwp_project', 'drwp_status', 'drwp_month', 'drwp_mine', 'drwp_scope_set', 'drwp_from', 'drwp_to'], $_SERVER['REQUEST_URI'] ?? '');

        $statuses = [
            ''                   => __('すべて', 'drwp-daily-reports'),
            'pending'            => DRWP_Labels::review_status('pending'),
            'approved'           => DRWP_Labels::review_status('approved'),
            'needs_revision'     => DRWP_Labels::review_status('needs_revision'),
        ];
        // Auto-open the filter card when any filter is currently
        // active so the user can see why the list looks scoped
        // without having to click through. Default closed otherwise
        // so the calendar gets more vertical space.
        $has_filters = ($q !== '') || $project || ($status !== '') || $mine || ($range_from !== '') || ($range_to !== '');
        ob_start();
        ?>
        <details class="drwp-archive-filter-card" <?php echo $has_filters ? 'open' : ''; ?>>
            <summary class="drwp-archive-filter-summary">
                <span class="drwp-archive-filter-summary-text">🔍 <?php esc_html_e('検索・絞り込み', 'drwp-daily-reports'); ?></span>
                <?php if ($has_filters): ?>
                    <span class="drwp-archive-filter-summary-badge"><?php esc_html_e('条件あり', 'drwp-daily-reports'); ?></span>
                <?php endif; ?>
            </summary>
            <form method="get" action="" class="drwp-archive-filter">
                <?php foreach ($preserve as $k => $v): ?>
                    <input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>" />
                <?php endforeach; ?>
                <input type="hidden" name="drwp_month" value="<?php echo esc_attr($month_param); ?>" />
                <?php // フォームから一度でも送信されたことを示すサーバ側センチネル。
                  //   未送信 → 自動で「自分のみ」スコープ。
                  //   送信済み → checkbox の状態 (drwp_mine) をそのまま尊重。
                  ?>
                <input type="hidden" name="drwp_scope_set" value="1" />
                <?php if (!empty($_GET['drwp_view']) && $_GET['drwp_view'] === 'list'): ?>
                    <input type="hidden" name="drwp_view" value="list" />
                <?php endif; ?>
                <?php if (!empty($_GET['drwp_sort'])): ?>
                    <input type="hidden" name="drwp_sort" value="<?php echo esc_attr((string) $_GET['drwp_sort']); ?>" />
                <?php endif; ?>
                <div class="drwp-archive-filter-row">
                    <label class="drwp-archive-field grow">
                        <span><?php esc_html_e('キーワード', 'drwp-daily-reports'); ?></span>
                        <input type="search" name="drwp_q" value="<?php echo esc_attr($q); ?>"
                               placeholder="<?php esc_attr_e('作業内容に含まれる語', 'drwp-daily-reports'); ?>" />
                    </label>
                    <label class="drwp-archive-field">
                        <span><?php esc_html_e('案件', 'drwp-daily-reports'); ?></span>
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
                    <?php if (is_user_logged_in()): ?>
                    <label class="drwp-archive-field-mine">
                        <input type="checkbox" name="drwp_mine" value="1" <?php checked($mine); ?> />
                        <span><?php esc_html_e('自分のみ', 'drwp-daily-reports'); ?></span>
                    </label>
                    <?php endif; ?>
                </div>
                <?php if ($view === 'list'):
                    // リストビュー専用: 日付範囲。空のまま送信されると month
                    // 単位の既定 (例: 今月) に戻る。プリセットは form-submit
                    // 不要の <a> で URL に直接 drwp_from / drwp_to を載せる。
                    $today  = current_time('Y-m-d');
                    $base_uri = $_SERVER['REQUEST_URI'] ?? '';
                    $preset_url = function ($from, $to) use ($base_uri) {
                        return esc_url(add_query_arg([
                            'drwp_from' => $from,
                            'drwp_to'   => $to,
                            // 月切替を消して範囲側を効かせる
                            'drwp_month' => false,
                        ], $base_uri));
                    };
                    $first_of_month = current_time('Y-m') . '-01';
                    $first_of_prev  = date('Y-m-01', strtotime($first_of_month . ' -1 month'));
                    $last_of_prev   = date('Y-m-t',  strtotime($first_of_prev));
                ?>
                <div class="drwp-archive-filter-row drwp-archive-filter-range">
                    <label class="drwp-archive-field">
                        <span><?php esc_html_e('開始日', 'drwp-daily-reports'); ?></span>
                        <input type="date" name="drwp_from" value="<?php echo esc_attr($range_from); ?>" />
                    </label>
                    <label class="drwp-archive-field">
                        <span><?php esc_html_e('終了日', 'drwp-daily-reports'); ?></span>
                        <input type="date" name="drwp_to" value="<?php echo esc_attr($range_to); ?>" />
                    </label>
                    <div class="drwp-archive-range-presets" role="group" aria-label="<?php esc_attr_e('期間プリセット', 'drwp-daily-reports'); ?>">
                        <a class="drwp-archive-range-preset" href="<?php echo $preset_url(date('Y-m-d', strtotime($today . ' -6 days')), $today); ?>"><?php esc_html_e('直近7日', 'drwp-daily-reports'); ?></a>
                        <a class="drwp-archive-range-preset" href="<?php echo $preset_url(date('Y-m-d', strtotime($today . ' -29 days')), $today); ?>"><?php esc_html_e('直近30日', 'drwp-daily-reports'); ?></a>
                        <a class="drwp-archive-range-preset" href="<?php echo $preset_url(date('Y-m-d', strtotime($today . ' -89 days')), $today); ?>"><?php esc_html_e('直近90日', 'drwp-daily-reports'); ?></a>
                        <a class="drwp-archive-range-preset" href="<?php echo $preset_url($first_of_month, $today); ?>"><?php esc_html_e('今月', 'drwp-daily-reports'); ?></a>
                        <a class="drwp-archive-range-preset" href="<?php echo $preset_url($first_of_prev, $last_of_prev); ?>"><?php esc_html_e('先月', 'drwp-daily-reports'); ?></a>
                    </div>
                </div>
                <?php endif; ?>
                <div class="drwp-archive-filter-row">
                    <button type="submit" class="drwp-archive-submit">
                        <?php esc_html_e('絞り込み', 'drwp-daily-reports'); ?>
                    </button>
                    <a class="drwp-archive-reset" href="<?php echo esc_url($reset_url); ?>">
                        <?php esc_html_e('条件をクリア', 'drwp-daily-reports'); ?>
                    </a>
                </div>
            </form>
        </details>
        <?php
        return ob_get_clean();
    }

    /**
     * 案件選択用の <select> を combo-box 対応の markup で出力する。
     * combo.js が data-drwp-combo を拾って検索可能な UI に昇格させる。
     *
     * @param array  $projects     ['id', 'name', 'is_recent'] の連想配列
     * @param int    $selected     初期選択 (0 で未選択)
     * @param string $name         input name (project_id 等)
     * @param string $placeholder  空 option のラベル
     * @param bool   $required     required 属性を付けるか
     */
    private static function project_combo_select($projects, $selected = 0, $name = 'project_id', $placeholder = '', $required = false) {
        $recent = [];
        $other  = [];
        foreach ($projects as $p) {
            $row = ['id' => (int) $p['id'], 'name' => (string) $p['name']];
            if (!empty($p['is_recent'])) $recent[] = $row;
            else                         $other[]  = $row;
        }
        if ($placeholder === '') {
            $placeholder = __('選択してください', 'drwp-daily-reports');
        }
        $sel = (int) $selected;
        ob_start();
        ?>
        <div class="drwp-combo" data-drwp-combo>
            <select name="<?php echo esc_attr($name); ?>"<?php echo $required ? ' required' : ''; ?>>
                <option value=""><?php echo esc_html($placeholder); ?></option>
                <?php if (!empty($recent)): ?>
                    <optgroup label="<?php esc_attr_e('最近使った', 'drwp-daily-reports'); ?>">
                        <?php foreach ($recent as $p): ?>
                            <option value="<?php echo (int) $p['id']; ?>" <?php selected($sel, (int) $p['id']); ?>>
                                <?php echo esc_html($p['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
                <?php if (!empty($other)): ?>
                    <optgroup label="<?php esc_attr_e('案件', 'drwp-daily-reports'); ?>">
                        <?php foreach ($other as $p): ?>
                            <option value="<?php echo (int) $p['id']; ?>" <?php selected($sel, (int) $p['id']); ?>>
                                <?php echo esc_html($p['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
            </select>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * カレンダー / リスト 切替トグル。URL の drwp_view クエリで状態
     * を保持する。calendar は「いつあったか」、list は「内容を一覧
     * でスキャン」用途。
     */
    private static function render_view_toggle($current) {
        $base = $_SERVER['REQUEST_URI'] ?? '';
        $cal_url = esc_url(remove_query_arg('drwp_view', $base));
        $list_url = esc_url(add_query_arg(['drwp_view' => 'list'], $base));
        ob_start();
        ?>
        <div class="drwp-archive-view-toggle" role="tablist" aria-label="<?php esc_attr_e('表示モード', 'drwp-daily-reports'); ?>">
            <a class="drwp-archive-view-btn<?php echo $current === 'calendar' ? ' is-active' : ''; ?>"
               href="<?php echo $cal_url; ?>" role="tab" aria-selected="<?php echo $current === 'calendar' ? 'true' : 'false'; ?>">
                <?php esc_html_e('カレンダー', 'drwp-daily-reports'); ?>
            </a>
            <a class="drwp-archive-view-btn<?php echo $current === 'list' ? ' is-active' : ''; ?>"
               href="<?php echo $list_url; ?>" role="tab" aria-selected="<?php echo $current === 'list' ? 'true' : 'false'; ?>">
                <?php esc_html_e('リスト', 'drwp-daily-reports'); ?>
            </a>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * リストビューの日付ソート切替。クリックで降順 / 昇順をトグルする
     * 単一ボタン。URL の drwp_sort クエリで状態を保持。
     */
    private static function render_sort_toggle($current) {
        $base = $_SERVER['REQUEST_URI'] ?? '';
        $is_asc = ($current === 'date_asc');
        // 押すと反対方向に切り替わる
        $next_url = $is_asc
            ? esc_url(remove_query_arg('drwp_sort', $base))
            : esc_url(add_query_arg(['drwp_sort' => 'date_asc'], $base));
        $label = $is_asc
            ? __('日付 ↑ 古い順', 'drwp-daily-reports')
            : __('日付 ↓ 新しい順', 'drwp-daily-reports');
        $aria = $is_asc
            ? __('日付の新しい順に並べ替える', 'drwp-daily-reports')
            : __('日付の古い順に並べ替える', 'drwp-daily-reports');
        ob_start();
        ?>
        <a class="drwp-archive-sort-btn<?php echo $is_asc ? ' is-asc' : ' is-desc'; ?>"
           href="<?php echo $next_url; ?>"
           title="<?php echo esc_attr($aria); ?>"
           aria-label="<?php echo esc_attr($aria); ?>">
            <?php echo esc_html($label); ?>
        </a>
        <?php
        return ob_get_clean();
    }

    /**
     * カレンダー / リスト 共通の色凡例。覚えなくても色の意味が分かる
     * よう、毎回常時表示するシンプルな帯。
     */
    private static function render_status_legend() {
        $items = [
            'pending'        => __('レビュー待ち', 'drwp-daily-reports'),
            'approved'       => __('承認済み', 'drwp-daily-reports'),
            'needs_revision' => __('差戻し', 'drwp-daily-reports'),
            'edit_requested' => __('編集依頼中', 'drwp-daily-reports'),
        ];
        ob_start();
        ?>
        <ul class="drwp-archive-legend" aria-label="<?php esc_attr_e('状態の凡例', 'drwp-daily-reports'); ?>">
            <?php foreach ($items as $key => $label): ?>
                <li>
                    <span class="drwp-archive-legend-dot status-<?php echo esc_attr($key); ?>" aria-hidden="true"></span>
                    <?php echo esc_html($label); ?>
                </li>
            <?php endforeach; ?>
            <li>
                <span class="drwp-archive-legend-dot is-plan" aria-hidden="true"></span>
                <?php esc_html_e('予定', 'drwp-daily-reports'); ?>
            </li>
        </ul>
        <?php
        return ob_get_clean();
    }

    /**
     * 「内容をスキャンする」用のリストビュー。
     * 各行に日付 / 状態バッジ / 案件 / 時刻 / 作業内容のスニペット /
     * 報告者 / 📷 写真件数 / ⚠️ 特記事項フラグ を出す。クリックで
     * カレンダーチップと同じ詳細モーダルが開けるように `data-id` を
     * 仕込む。
     */
    private static function render_archive_list_view($rows, $plans_by_date = [], $sort = 'date_desc') {
        if (empty($rows) && empty($plans_by_date)) {
            ob_start();
            ?>
            <div class="drwp-archive-list drwp-archive-list-empty">
                <p><?php esc_html_e('該当する日報はありません。', 'drwp-daily-reports'); ?></p>
            </div>
            <?php
            return ob_get_clean();
        }

        // 写真件数を 1 クエリでバルク取得 — 行ごとに DRWP_Media::for_report
        // を叩くと N+1 になるので避ける。
        $photo_counts = [];
        if (!empty($rows)) {
            global $wpdb;
            $photos_t = $wpdb->prefix . 'drwp_report_photos';
            $ids = array_map(function ($r) { return (int) $r->id; }, $rows);
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $count_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT report_id, COUNT(*) AS c FROM $photos_t WHERE report_id IN ($placeholders) GROUP BY report_id",
                $ids
            ));
            foreach ($count_rows as $row) {
                $photo_counts[(int) $row->report_id] = (int) $row->c;
            }
        }

        $weekdays = ['日', '月', '火', '水', '木', '金', '土'];

        // 予定 (linked 済みは除外) を日報リストに織り交ぜたほうが
        // 「今後の予定」も同じ画面で見渡せて便利。日付順に並べ替え。
        $entries = [];
        foreach ($rows as $r) {
            $entries[] = ['kind' => 'report', 'date' => (string) $r->report_date, 'time' => (string) ($r->started_at ?? ''), 'row' => $r];
        }
        foreach ($plans_by_date as $pdate => $plans) {
            foreach ($plans as $pl) {
                if (!empty($pl->linked_report_id)) continue;  // 日報化済みは除外
                $entries[] = ['kind' => 'plan', 'date' => (string) $pdate, 'time' => (string) ($pl->started_at ?? ''), 'row' => $pl];
            }
        }
        // 既定の並びは「日付の降順 (新しい順)」。同日内は時刻昇順
        // (古い時間が先) で安定。?drwp_sort=date_asc を指定すると
        // 「古い順」(上に古い日付) に切り替わる。
        $asc = ($sort === 'date_asc');
        usort($entries, function ($a, $b) use ($asc) {
            $d = $asc ? strcmp($a['date'], $b['date']) : strcmp($b['date'], $a['date']);
            if ($d !== 0) return $d;
            return strcmp($a['time'], $b['time']);
        });

        // 日付ごとにグループ化 (PHP の連想配列は挿入順を保持するので
        // 上の usort で得た降順がそのまま反映される)。
        $grouped = [];
        foreach ($entries as $e) {
            $grouped[$e['date']][] = $e;
        }

        ob_start();
        ?>
        <div class="drwp-archive-list">
            <?php foreach ($grouped as $date => $day_entries):
                $date_ts = strtotime((string) $date);
                $dow = $date_ts ? (int) date('w', $date_ts) : -1;
                $date_label = $date_ts ? date_i18n('n/j', $date_ts) : '';
                $dow_label  = ($dow >= 0) ? '(' . $weekdays[$dow] . ')' : '';
                $count = count($day_entries);
            ?>
            <section class="drwp-archive-list-group">
                <header class="drwp-archive-list-group-head">
                    <span class="drwp-archive-list-group-date mono"><?php echo esc_html($date_label); ?></span>
                    <span class="drwp-archive-list-group-dow"><?php echo esc_html($dow_label); ?></span>
                    <span class="drwp-archive-list-group-count">
                        ·　<?php printf(esc_html__('%d 件', 'drwp-daily-reports'), (int) $count); ?>
                    </span>
                </header>
                <div class="drwp-archive-list-group-body">
                <?php foreach ($day_entries as $e):
                    $row = $e['row'];
                ?>
                <?php if ($e['kind'] === 'plan'):
                      $pp = $row->project_id ? DRWP_Project::find((int) $row->project_id) : null;
                      $pp_name = $pp ? (string) $pp->name : __('（案件未設定）', 'drwp-daily-reports');
                      $ptime = self::format_time_window($row->started_at ?? '', $row->ended_at ?? '');
                      $pnote = trim((string) ($row->notes ?? ''));
                      if (mb_strlen($pnote) > 80) $pnote = mb_substr($pnote, 0, 80) . '…';
                ?>
                <button type="button" class="drwp-archive-list-row is-plan"
                        data-plan-id="<?php echo (int) $row->id; ?>"
                        data-plan-date="<?php echo esc_attr((string) $row->planned_date); ?>"
                        data-plan-project-id="<?php echo (int) ($row->project_id ?? 0); ?>"
                        data-plan-project-name="<?php echo esc_attr($pp_name); ?>"
                        data-plan-user-id="<?php echo (int) ($row->user_id ?? 0); ?>"
                        data-plan-start="<?php echo esc_attr(substr((string) ($row->started_at ?? ''), 0, 5)); ?>"
                        data-plan-end="<?php echo esc_attr(substr((string) ($row->ended_at ?? ''), 0, 5)); ?>"
                        data-plan-notes="<?php echo esc_attr((string) ($row->notes ?? '')); ?>"
                        data-plan-linked="<?php echo (int) ($row->linked_report_id ?? 0); ?>">
                    <span class="drwp-archive-list-badge is-plan-badge"><?php esc_html_e('予定', 'drwp-daily-reports'); ?></span>
                    <span class="drwp-archive-list-time mono"><?php echo esc_html($ptime); ?></span>
                    <span class="drwp-archive-list-title"><?php echo esc_html($pp_name); ?></span>
                    <span class="drwp-archive-list-snippet"><?php echo esc_html($pnote); ?></span>
                    <span class="drwp-archive-list-side"></span>
                </button>
                <?php else:
                    $r = $row;
                    $time = self::format_time_window($r->started_at ?? '', $r->ended_at ?? '');
                    $proj = $r->project_id ? DRWP_Project::find((int) $r->project_id) : null;
                    $proj_name = $proj ? (string) $proj->name : __('（案件未設定）', 'drwp-daily-reports');
                    $status_label = DRWP_Labels::review_status((string) $r->review_status);
                    $author = DRWP_User::display_name((int) $r->user_id) ?: ('#' . (int) $r->user_id);
                    $snippet = trim((string) ($r->work_description ?? ''));
                    if (mb_strlen($snippet) > 80) $snippet = mb_substr($snippet, 0, 80) . '…';
                    $issues = trim((string) ($r->issues ?? ''));
                    $photo_n = $photo_counts[(int) $r->id] ?? 0;
                ?>
                <button type="button" class="drwp-archive-list-row drwp-archive-list-report status-<?php echo esc_attr((string) $r->review_status); ?>"
                        data-id="<?php echo (int) $r->id; ?>">
                    <span class="drwp-archive-list-badge status-<?php echo esc_attr((string) $r->review_status); ?>">
                        <?php echo esc_html($status_label); ?>
                    </span>
                    <span class="drwp-archive-list-time mono"><?php echo esc_html($time); ?></span>
                    <span class="drwp-archive-list-title"><?php echo esc_html($proj_name); ?></span>
                    <span class="drwp-archive-list-snippet"><?php echo esc_html($snippet); ?></span>
                    <span class="drwp-archive-list-side">
                        <?php if ($photo_n > 0): ?>
                            <span class="drwp-archive-list-icon" title="<?php echo esc_attr(sprintf(__('写真 %d 枚', 'drwp-daily-reports'), $photo_n)); ?>">📷 <?php echo (int) $photo_n; ?></span>
                        <?php endif; ?>
                        <?php if ($issues !== ''): ?>
                            <span class="drwp-archive-list-icon" title="<?php esc_attr_e('特記事項あり', 'drwp-daily-reports'); ?>">⚠️</span>
                        <?php endif; ?>
                        <span class="drwp-archive-list-author"><?php echo esc_html($author); ?></span>
                    </span>
                </button>
                <?php endif; ?>
                <?php endforeach; ?>
                </div>
            </section>
            <?php endforeach; ?>
        </div>
        <?php // ↑↓ で日付グループ間を移動する浮動ボタン。
            //     画面下右に小さく出して、長いリストでも目的の日付に
            //     一気に飛べるようにする。JS は drwp_archive_init で
            //     後付けする (グループ要素は同じ DOM 内にある)。
        ?>
        <div class="drwp-archive-jump" data-role="date-jump" aria-hidden="false">
            <button type="button" class="drwp-archive-jump-btn" data-act="prev-day"
                    aria-label="<?php esc_attr_e('前の日へ', 'drwp-daily-reports'); ?>"
                    title="<?php esc_attr_e('前の日へ', 'drwp-daily-reports'); ?>">↑</button>
            <button type="button" class="drwp-archive-jump-btn" data-act="next-day"
                    aria-label="<?php esc_attr_e('次の日へ', 'drwp-daily-reports'); ?>"
                    title="<?php esc_attr_e('次の日へ', 'drwp-daily-reports'); ?>">↓</button>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function render_calendar($month_param, $month_start, $by_date, $prev_month, $next_month, $today_month, $filters, $plans_by_date = [], $view = 'calendar', $total_count = 0) {
        // Use the FULL REQUEST_URI as the base so add_query_arg merges
        // new args into the existing query string instead of replacing
        // it. This preserves params like ?page_id=N that WordPress
        // needs to resolve the current page on non-permalink sites.
        $base = $_SERVER['REQUEST_URI'] ?? '';
        $build_url = function ($month) use ($base, $filters) {
            $args = [
                'drwp_month'   => $month,
                'drwp_q'       => $filters['q']       !== '' ? $filters['q']             : false,
                'drwp_project' => !empty($filters['project']) ? (int) $filters['project'] : false,
                'drwp_status'  => $filters['status']  !== '' ? $filters['status']        : false,
            ];
            return esc_url(add_query_arg($args, $base));
        };

        $year  = (int) date('Y', strtotime($month_start));
        $month = (int) date('n', strtotime($month_start));
        $first_dow = (int) date('w', strtotime($month_start));
        $days_in_month = (int) date('t', strtotime($month_start));
        $today = current_time('Y-m-d');

        $dows = ['日', '月', '火', '水', '木', '金', '土'];

        ob_start();
        ?>
        <div class="drwp-archive-cal">
          <div class="drwp-archive-cal-nav">
            <div class="drwp-archive-cal-nav-left">
                <a class="drwp-archive-cal-btn" href="<?php echo $build_url($prev_month); ?>" aria-label="<?php esc_attr_e('前の月', 'drwp-daily-reports'); ?>">‹</a>
                <h3 class="drwp-archive-cal-month"><?php echo esc_html($year . '年 ' . $month . '月'); ?></h3>
                <a class="drwp-archive-cal-btn" href="<?php echo $build_url($next_month); ?>" aria-label="<?php esc_attr_e('次の月', 'drwp-daily-reports'); ?>">›</a>
                <span class="drwp-archive-cal-count">
                    <?php printf(esc_html__('%d 件', 'drwp-daily-reports'), (int) $total_count); ?>
                </span>
                <?php if ($month_param !== $today_month): ?>
                  <a class="drwp-archive-cal-today" href="<?php echo $build_url($today_month); ?>"><?php esc_html_e('今月', 'drwp-daily-reports'); ?></a>
                <?php endif; ?>
            </div>
            <?php echo self::render_view_toggle($view); ?>
          </div>

          <div class="drwp-archive-cal-grid">
            <?php foreach ($dows as $i => $d): ?>
              <div class="drwp-archive-cal-dow<?php echo $i === 0 ? ' sun' : ($i === 6 ? ' sat' : ''); ?>"><?php echo esc_html($d); ?></div>
            <?php endforeach; ?>

            <?php for ($i = 0; $i < $first_dow; $i++): ?>
              <div class="drwp-archive-cal-cell empty"></div>
            <?php endfor; ?>

            <?php
            for ($d = 1; $d <= $days_in_month; $d++):
              $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
              $dow = ((int) date('w', strtotime($date)));
              $cell_cls = 'drwp-archive-cal-cell';
              if ($dow === 0) $cell_cls .= ' sun';
              if ($dow === 6) $cell_cls .= ' sat';
              if ($date === $today) $cell_cls .= ' today';
              $items = $by_date[$date] ?? [];
              $plan_items = $plans_by_date[$date] ?? [];

              // 「要対応 N」 = approved 以外の日報数
              $cell_needs_action = 0;
              $cell_has_returned = false;
              foreach ($items as $r) {
                  if ($r->review_status !== 'approved') $cell_needs_action++;
                  if ($r->review_status === 'needs_revision') $cell_has_returned = true;
              }

              // 表示順 (ユーザー指定): 予定 → レビュー待ち → 編集依頼中
              // → 差戻し → 承認済み。1 つの配列にまとめてキーで並べ替え。
              $priority_map = [
                  'plan'           => 0,
                  'pending'        => 1,
                  'edit_requested' => 2,
                  'needs_revision' => 3,
                  'approved'       => 4,
              ];
              $combined = [];
              foreach ($plan_items as $p) {
                  $combined[] = ['type' => 'plan', 'data' => $p, 'prio' => 0, 'time' => (string) ($p->started_at ?? '')];
              }
              foreach ($items as $r) {
                  $st = (string) $r->review_status;
                  $combined[] = [
                      'type' => 'report', 'data' => $r,
                      'prio' => $priority_map[$st] ?? 99,
                      'time' => (string) ($r->started_at ?? ''),
                  ];
              }
              usort($combined, function ($a, $b) {
                  if ($a['prio'] !== $b['prio']) return $a['prio'] - $b['prio'];
                  return strcmp($a['time'], $b['time']);
              });

              // 要対応バッジの色: 差戻しがあれば赤系、それ以外は橙系。
              $needs_badge_cls = $cell_has_returned ? ' is-returned' : '';
            ?>
              <div class="<?php echo esc_attr($cell_cls); ?>" data-date="<?php echo esc_attr($date); ?>">
                <div class="drwp-archive-cal-cell-head">
                    <?php if ($date === $today): ?>
                        <span class="drwp-archive-cal-today-pill"><span class="mono"><?php echo (int) $d; ?></span><small><?php esc_html_e('今日', 'drwp-daily-reports'); ?></small></span>
                    <?php else: ?>
                        <span class="drwp-archive-cal-day"><?php echo (int) $d; ?></span>
                    <?php endif; ?>
                    <?php if ($cell_needs_action > 0): ?>
                        <span class="drwp-archive-cal-needs-badge<?php echo esc_attr($needs_badge_cls); ?>">
                            <?php printf(esc_html__('要対応 %d', 'drwp-daily-reports'), (int) $cell_needs_action); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php
                foreach ($combined as $entry):
                    if ($entry['type'] === 'report'):
                        $r = $entry['data'];
                        $proj = $r->project_id ? DRWP_Project::find((int) $r->project_id) : null;
                        $proj_name = $proj ? $proj->name : __('（案件未設定）', 'drwp-daily-reports');
                        $time = self::format_time_window($r->started_at ?? '', $r->ended_at ?? '');
                        $status_label = DRWP_Labels::review_status((string) $r->review_status);
                        $is_approved = ((string) $r->review_status === 'approved');
                        // 「要対応」 (pending / needs_revision / edit_requested)
                        // は塗りカード型 (.ev-card)、承認済みは控えめなテキスト
                        // 行型 (.ev-line) で「終わった案件」感を表現
                        if ($is_approved): ?>
                          <button type="button" class="drwp-archive-cal-line drwp-archive-cal-line-approved"
                                  data-id="<?php echo (int) $r->id; ?>"
                                  title="<?php echo esc_attr($proj_name . ($time ? ' / ' . $time : '') . ' / ' . $status_label); ?>">
                            <span class="drwp-archive-cal-line-dot"></span>
                            <span class="drwp-archive-cal-line-title"><?php echo esc_html($proj_name); ?></span>
                            <?php if ($time !== ''): ?><span class="drwp-archive-cal-line-time mono"><?php echo esc_html(substr($time, 0, 5)); ?></span><?php endif; ?>
                          </button>
                        <?php else: ?>
                          <button type="button" class="drwp-archive-cal-card status-<?php echo esc_attr((string) $r->review_status); ?>"
                                  data-id="<?php echo (int) $r->id; ?>"
                                  title="<?php echo esc_attr($proj_name . ($time ? ' / ' . $time : '') . ' / ' . $status_label); ?>">
                            <span class="drwp-archive-cal-card-title"><?php echo esc_html($proj_name); ?></span>
                            <span class="drwp-archive-cal-card-meta">
                                <?php if ($time !== ''): ?><span class="drwp-archive-cal-card-time mono"><?php echo esc_html(substr($time, 0, 5)); ?></span><?php endif; ?>
                                <span class="drwp-archive-cal-card-status"><?php echo esc_html($status_label); ?></span>
                            </span>
                          </button>
                        <?php endif; ?>
                <?php else:
                        // 予定 — 1 行テキスト + ○ 中抜き丸の控えめ行
                        $pl = $entry['data'];
                        $pproj = $pl->project_id ? DRWP_Project::find((int) $pl->project_id) : null;
                        $pproj_name = $pproj ? $pproj->name : __('（案件未設定）', 'drwp-daily-reports');
                        $ptime = self::format_time_window($pl->started_at ?? '', $pl->ended_at ?? '');
                        $tip = __('予定', 'drwp-daily-reports') . ': ' . $pproj_name . ($ptime ? ' / ' . $ptime : '');
                        if (!empty($pl->linked_report_id)) $tip .= ' (' . __('日報 #', 'drwp-daily-reports') . (int) $pl->linked_report_id . ' に紐づき)';
                ?>
                  <button type="button" class="drwp-archive-cal-line drwp-archive-cal-line-plan<?php echo !empty($pl->linked_report_id) ? ' is-linked' : ''; ?>"
                          data-plan-id="<?php echo (int) $pl->id; ?>"
                          data-plan-date="<?php echo esc_attr((string) $pl->planned_date); ?>"
                          data-plan-project-id="<?php echo (int) ($pl->project_id ?? 0); ?>"
                          data-plan-project-name="<?php echo esc_attr($pproj_name); ?>"
                          data-plan-user-id="<?php echo (int) ($pl->user_id ?? 0); ?>"
                          data-plan-start="<?php echo esc_attr(substr((string) ($pl->started_at ?? ''), 0, 5)); ?>"
                          data-plan-end="<?php echo esc_attr(substr((string) ($pl->ended_at ?? ''), 0, 5)); ?>"
                          data-plan-notes="<?php echo esc_attr((string) ($pl->notes ?? '')); ?>"
                          data-plan-linked="<?php echo (int) ($pl->linked_report_id ?? 0); ?>"
                          title="<?php echo esc_attr($tip); ?>">
                    <span class="drwp-archive-cal-line-dot"></span>
                    <span class="drwp-archive-cal-line-title"><?php echo esc_html($pproj_name); ?></span>
                    <?php if ($ptime !== ''): ?><span class="drwp-archive-cal-line-time mono"><?php echo esc_html(substr($ptime, 0, 5)); ?></span><?php endif; ?>
                  </button>
                <?php endif;
                endforeach; ?>
              </div>
            <?php endfor;

            // Pad the trailing cells so the grid completes its last row.
            $total_cells = $first_dow + $days_in_month;
            $trailing = (7 - ($total_cells % 7)) % 7;
            for ($i = 0; $i < $trailing; $i++): ?>
              <div class="drwp-archive-cal-cell empty"></div>
            <?php endfor; ?>
          </div>
          <p class="drwp-archive-cal-hint">
            <?php esc_html_e('💡 予定はタップで日報作成、長押しで編集、ドラッグ&ドロップで日付を変更できます。', 'drwp-daily-reports'); ?>
          </p>
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

        $author_name = DRWP_User::display_name((int) $report->user_id) ?: '-';
        $back_url = esc_url(remove_query_arg('drwp_id'));
        // 「自分の日報」かつ「まだ承認前 (レビュー待ち or 差戻し)」
        // のときだけフロント編集 CTA を表示する。差戻し中も再編集
        // できるようにすることで「差し戻し → 修正 → 再提出」のループ
        // が成立する。
        $is_own_editable = ((int) $report->user_id === get_current_user_id())
                          && in_array((string) $report->review_status, ['pending', 'needs_revision'], true)
                          && !DRWP_User::is_retired();

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
                <?php if ($is_own_editable): ?>
                    <p class="drwp-archive-edit-cta">
                        <?php if ($report->review_status === 'needs_revision'): ?>
                            <?php esc_html_e('この日報は差戻し中です。修正して再提出すると、再度レビュー待ちに戻ります。', 'drwp-daily-reports'); ?>
                        <?php else: ?>
                            <?php esc_html_e('この日報はあなたのレビュー待ち日報です。', 'drwp-daily-reports'); ?>
                        <?php endif; ?>
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
        if (DRWP_User::is_retired()) {
            $back = esc_url(remove_query_arg('drwp_edit'));
            return self::wrap('<p class="drwp-archive-message">'
                . esc_html__('このアカウントは退職状態のため、編集はできません。', 'drwp-daily-reports')
                . '</p><p class="drwp-archive-back"><a href="' . $back . '">&laquo; '
                . esc_html__('一覧に戻る', 'drwp-daily-reports')
                . '</a></p>');
        }
        if ((int) $report->user_id !== get_current_user_id()
            || !in_array((string) $report->review_status, ['pending', 'needs_revision'], true)) {
            // Permission check, defense in depth — JS link won't be
            // shown for non-own / approved / archived reports, but a
            // direct URL hit still needs to be rejected.
            $back = esc_url(remove_query_arg('drwp_edit'));
            return self::wrap('<p class="drwp-archive-message">'
                . esc_html__('この日報はフロントから編集できません(自分の レビュー待ち または 差戻し の日報のみ編集可能です)。', 'drwp-daily-reports')
                . '</p><p class="drwp-archive-back"><a href="' . $back . '">&laquo; '
                . esc_html__('一覧に戻る', 'drwp-daily-reports')
                . '</a></p>');
        }

        wp_enqueue_script(self::HANDLE_EDIT);
        wp_enqueue_style(self::HANDLE_COMBO);
        wp_enqueue_script(self::HANDLE_COMBO);

        // 編集フォームの案件ドロップダウンも combobox + 最近使った
        // ピン留めに揃える。閉鎖済み案件は出さない (active のみ)。
        // 現在の案件が inactive 化されている場合のみ追加する。
        $projects_active = DRWP_Project::all(true);
        $current_in_list = false;
        foreach ($projects_active as $p) {
            if ((int) $p->id === (int) $report->project_id) { $current_in_list = true; break; }
        }
        if (!$current_in_list && $report->project_id) {
            $cur = DRWP_Project::find((int) $report->project_id);
            if ($cur) array_unshift($projects_active, $cur);
        }
        $recent_ids_edit = DRWP_Project::recent_for_user(get_current_user_id(), 8);
        $recent_lookup_edit = array_flip(array_map('intval', $recent_ids_edit));
        $projects = array_map(function ($p) use ($recent_lookup_edit) {
            return [
                'id'        => (int) $p->id,
                'name'      => (string) $p->name,
                'is_recent' => isset($recent_lookup_edit[(int) $p->id]) ? 1 : 0,
            ];
        }, $projects_active);
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
            if ($flash === 'noproject') echo '<p class="drwp-archive-flash err">' . esc_html__('案件を選択してください。', 'drwp-daily-reports') . '</p>';
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
                <div class="drwp-archive-edit-field">
                    <span><?php esc_html_e('案件', 'drwp-daily-reports'); ?></span>
                    <?php echo self::project_combo_select($projects, (int) $report->project_id, 'project_id', __('選択してください', 'drwp-daily-reports'), true); ?>
                </div>
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
        // Retired-employee guard. Same wp_die behavior as the
        // admin-post handlers so a direct POST can't slip past.
        DRWP_User::block_write_or_die();

        $id = absint($_POST['drwp_id'] ?? 0);
        if (!$id) return;
        check_admin_referer('drwp_archive_edit_' . $id);

        global $wpdb;
        $reports_t = $wpdb->prefix . 'drwp_reports';
        $report = $wpdb->get_row($wpdb->prepare("SELECT * FROM $reports_t WHERE id = %d", $id));
        if (!$report) return;
        if ((int) $report->user_id !== get_current_user_id()) return;
        if (!in_array((string) $report->review_status, ['pending', 'needs_revision'], true)) return;

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
        // 差戻しからの再編集は自動的に「レビュー待ち」に戻して
        // 再提出扱いにする。投稿者が別途「再提出」ボタンを押す手間
        // を省く (修正したら自然に再レビュー待ちのキューへ)。
        $was_returned = ($report->review_status === 'needs_revision');
        if ($was_returned) {
            $update['review_status'] = 'pending';
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
        if ($was_returned) {
            DRWP_Audit::log('report_resubmitted', __('差戻しからの再提出 (needs_revision → pending)', 'drwp-daily-reports'), $id, []);
        }

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
