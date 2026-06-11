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

        // 退職アカウントは:
        //   - すでに `invalidate_session_if_retired` で wp_logout 済み
        //   - 退職マーカー Cookie が立っているか、認証直後のリダイレ
        //     クトで `?drwp_retired=1` が乗っている
        // どちらの経路でも「ログインできません」を出す。データに到
        // 達する前にここで止める。
        $retired_marker = !is_user_logged_in()
                       && (DRWP_User::has_marker_cookie() || !empty($_GET['drwp_retired']));
        if ($retired_marker || (is_user_logged_in() && DRWP_User::is_retired())) {
            return self::wrap('<p class="drwp-archive-message drwp-archive-retired">'
                . esc_html__('このアカウントは退職状態のため、ログインできません。', 'drwp-daily-reports')
                . '</p>');
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
        // "自分のみ" filter — checkbox on the form, persisted via
        // ?drwp_mine=1. When set we scope the calendar to the
        // current user just like the old [drwp_report_form] my-list.
        $mine = !empty($_GET['drwp_mine']) && is_user_logged_in();
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
        // Stick the "X さんとしてログイン中 / ログアウト" bar in the
        // archive output too — so operators who only use
        // `[drwp_report_archive]` still get the same logged-in
        // affordance the dedicated `[drwp_login_form]` used to
        // provide. The bar dedups internally if both shortcodes are
        // on the same page.
        $bar = DRWP_Login::render_logged_in_bar(wp_get_current_user());
        return $bar . $body . $modals;
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
        $project_list = array_map(function ($p) {
            return ['id' => (int) $p->id, 'name' => (string) $p->name];
        }, DRWP_Project::all(true));

        $cfg = wp_json_encode([
            'restRoot'  => $rest_root,
            'nonce'     => $nonce,
            'labels'    => $labels,
            'projects'  => $project_list,
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
                    <label class="drwp-archive-plan-field">
                        <span><?php esc_html_e('案件', 'drwp-daily-reports'); ?></span>
                        <select name="project_id">
                            <option value=""><?php esc_html_e('（未設定）', 'drwp-daily-reports'); ?></option>
                            <?php foreach (DRWP_Project::all(true) as $p): ?>
                                <option value="<?php echo (int) $p->id; ?>"><?php echo esc_html($p->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
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

            if (d.review_status === 'pending' && !cfg.isRetired) {
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

            html += '<label class="drwp-archive-inline-field">';
            html += '<span><?php echo esc_js(__('案件', 'drwp-daily-reports')); ?> <em>*</em></span>';
            html += '<select name="project_id" required>';
            html += '<option value=""><?php echo esc_js(__('選択してください', 'drwp-daily-reports')); ?></option>';
            projects.forEach(function (p) {
              var sel = (String(p.id) === String(d.project_id || '')) ? ' selected' : '';
              html += '<option value="' + esc(p.id) + '"' + sel + '>' + esc(p.name) + '</option>';
            });
            html += '</select>';
            html += '</label>';

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
              html += renderEditPhoto(p.attachment_id, p.url, p.caption || '');
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
          }

          function renderEditPhoto(id, url, caption) {
            return '<div class="drwp-archive-inline-photo-item">'
                 + '<img src="' + esc(url) + '" alt="" />'
                 + '<input type="hidden" name="attachment_ids[]" value="' + esc(id) + '" />'
                 + '<input type="text" name="attachment_captions[]" placeholder="<?php echo esc_js(__('キャプション', 'drwp-daily-reports')); ?>" value="' + esc(caption) + '" />'
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
                tmp.innerHTML = renderEditPhoto(j.id, j.thumbnail_url || j.full_url || '', '');
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
              if (form.project_id) form.project_id.value  = d.planProjectId || '';
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
                     + ' ' + '<?php echo esc_js(__('からテンプレを読み込みました', 'drwp-daily-reports')); ?>';
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
            var planChip = e.target.closest('.drwp-archive-cal-plan-chip[data-plan-id]');
            if (planChip) {
              e.preventDefault();
              openReportFromPlan(planChip);
              return;
            }
            var chip = e.target.closest('.drwp-archive-cal-chip[data-id]');
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
          document.querySelectorAll('.drwp-archive-cal-plan-chip:not(.is-linked)').forEach(function (chip) {
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

          document.querySelectorAll('.drwp-archive-cal-plan-chip:not(.is-linked)').forEach(function (chip) {
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

        // 予定オーバーレイ — same month window, visibility scoped
        // either to the toggled "自分のみ" view or the default
        // worker/operator rule. Operators get every active plan;
        // workers get the ones they own or were assigned.
        $plans = DRWP_Plan::for_archive_month($month_start, $month_end, (bool) $opts['user_id']);
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
                </p>
            <?php endif; ?>

            <?php echo self::render_filter_form($q, $project, $status, $month_param, $projects, !empty($_GET['drwp_mine'])); ?>

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

            <?php echo self::render_calendar($month_param, $month_start, $by_date, $prev_month, $next_month, $today_month, ['q' => $q, 'project' => $project, 'status' => $status], $plans_by_date); ?>
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

    private static function render_filter_form($q, $project, $status, $month_param, $projects, $mine = false) {
        // On non-permalink sites the page is identified by ?page_id=N
        // (or similar). A GET form replaces the entire query string
        // with its form fields, so those external params would be lost
        // unless we mirror them as hidden inputs. Anything that isn't
        // one of our drwp_* keys gets preserved this way.
        $current_query = [];
        parse_str($_SERVER['QUERY_STRING'] ?? '', $current_query);
        $drwp_keys = ['drwp_q', 'drwp_project', 'drwp_status', 'drwp_month',
                      'drwp_mine', 'drwp_id', 'drwp_edit', 'drwp_new',
                      'drwp_saved', 'drwp_requested', 'drwp_err', 'drwp_p', 'drwp_per'];
        $preserve = [];
        foreach ($current_query as $k => $v) {
            if (!in_array($k, $drwp_keys, true) && is_scalar($v)) {
                $preserve[$k] = (string) $v;
            }
        }
        $reset_url = remove_query_arg(['drwp_q', 'drwp_project', 'drwp_status', 'drwp_month', 'drwp_mine'], $_SERVER['REQUEST_URI'] ?? '');

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
        $has_filters = ($q !== '') || $project || ($status !== '') || $mine;
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

    private static function render_calendar($month_param, $month_start, $by_date, $prev_month, $next_month, $today_month, $filters, $plans_by_date = []) {
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
              <div class="<?php echo esc_attr($cell_cls); ?>" data-date="<?php echo esc_attr($date); ?>">
                <div class="drwp-archive-cal-day"><?php echo (int) $d; ?></div>
                <?php foreach ($items as $r):
                  $proj = $r->project_id ? DRWP_Project::find((int) $r->project_id) : null;
                  $proj_name = $proj ? $proj->name : __('（案件未設定）', 'drwp-daily-reports');
                  $time = self::format_time_window($r->started_at ?? '', $r->ended_at ?? '');
                ?>
                  <button type="button" class="drwp-archive-cal-chip status-<?php echo esc_attr((string) $r->review_status); ?>"
                          data-id="<?php echo (int) $r->id; ?>"
                          title="<?php echo esc_attr($proj_name . ($time ? ' / ' . $time : '')); ?>">
                    <?php if ($time !== ''): ?><span class="drwp-archive-cal-chip-time"><?php echo esc_html(substr($time, 0, 5)); ?></span><?php endif; ?>
                    <span class="drwp-archive-cal-chip-text"><?php echo esc_html($proj_name); ?></span>
                  </button>
                <?php endforeach; ?>

                <?php
                // 予定チップ — 緑系の見た目で日報チップと区別。
                // クリックで日報フォームをこの予定の項目で開く。
                $plan_items = $plans_by_date[$date] ?? [];
                foreach ($plan_items as $pl):
                  $pproj = $pl->project_id ? DRWP_Project::find((int) $pl->project_id) : null;
                  $pproj_name = $pproj ? $pproj->name : __('（案件未設定）', 'drwp-daily-reports');
                  $ptime = self::format_time_window($pl->started_at ?? '', $pl->ended_at ?? '');
                  $tip = __('予定', 'drwp-daily-reports') . ': ' . $pproj_name . ($ptime ? ' / ' . $ptime : '');
                  if (!empty($pl->linked_report_id)) $tip .= ' (' . __('日報 #', 'drwp-daily-reports') . (int) $pl->linked_report_id . ' に紐づき)';
                ?>
                  <button type="button" class="drwp-archive-cal-plan-chip<?php echo !empty($pl->linked_report_id) ? ' is-linked' : ''; ?>"
                          data-plan-id="<?php echo (int) $pl->id; ?>"
                          data-plan-date="<?php echo esc_attr((string) $pl->planned_date); ?>"
                          data-plan-project-id="<?php echo (int) ($pl->project_id ?? 0); ?>"
                          data-plan-project-name="<?php echo esc_attr($pproj_name); ?>"
                          data-plan-start="<?php echo esc_attr(substr((string) ($pl->started_at ?? ''), 0, 5)); ?>"
                          data-plan-end="<?php echo esc_attr(substr((string) ($pl->ended_at ?? ''), 0, 5)); ?>"
                          data-plan-notes="<?php echo esc_attr((string) ($pl->notes ?? '')); ?>"
                          data-plan-linked="<?php echo (int) ($pl->linked_report_id ?? 0); ?>"
                          title="<?php echo esc_attr($tip); ?>">
                    <?php if ($ptime !== ''): ?><span class="drwp-archive-cal-chip-time"><?php echo esc_html(substr($ptime, 0, 5)); ?></span><?php endif; ?>
                    <span class="drwp-archive-cal-chip-text"><?php echo esc_html($pproj_name); ?></span>
                  </button>
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
        $is_own_pending = ((int) $report->user_id === get_current_user_id())
                          && $report->review_status === 'pending'
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
        if (DRWP_User::is_retired()) {
            $back = esc_url(remove_query_arg('drwp_edit'));
            return self::wrap('<p class="drwp-archive-message">'
                . esc_html__('このアカウントは退職状態のため、編集はできません。', 'drwp-daily-reports')
                . '</p><p class="drwp-archive-back"><a href="' . $back . '">&laquo; '
                . esc_html__('一覧に戻る', 'drwp-daily-reports')
                . '</a></p>');
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
                <label class="drwp-archive-edit-field">
                    <span><?php esc_html_e('案件', 'drwp-daily-reports'); ?></span>
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
