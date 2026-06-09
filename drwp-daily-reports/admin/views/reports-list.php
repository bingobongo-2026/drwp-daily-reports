<?php if (!defined('ABSPATH')) exit;

// Merged "日報一覧" page — combines the old reports-list and
// operations-list into a single table-driven view. Each row exposes
// 確認 (paper-style view in a modal) and 編集 (inline edit modal)
// buttons; the filter card + calendar + group axes come from the
// reports-list side, the bulk operations + checkbox column come
// from the operations-list side.

$review_labels = [
    'pending'        => DRWP_Labels::review_status('pending'),
    'approved'       => DRWP_Labels::review_status('approved'),
    'needs_revision' => DRWP_Labels::review_status('needs_revision'),
    'edit_requested' => DRWP_Labels::review_status('edit_requested'),
];

$can_review = current_user_can('edit_others_posts');
?>
<div class="wrap">
  <h1><?php esc_html_e('日報一覧', 'drwp-daily-reports'); ?></h1>

  <?php if (isset($_GET['updated'])): ?>
    <div class="notice notice-success"><p>
      <?php
        printf(
            esc_html(_n('%d 件更新しました。', '%d 件更新しました。', intval($_GET['updated']), 'drwp-daily-reports')),
            intval($_GET['updated'])
        );
      ?>
    </p></div>
  <?php endif; ?>

  <!-- 検索・絞り込み -->
  <details class="drwp-card drwp-filter-card" <?php echo ($filters['search'] || $filters['review_status'] || $filters['project_id'] || !empty($filters['customer_group_id']) || !empty($filters['project_group_id']) || $filters['date_from'] || $filters['date_to']) ? 'open' : ''; ?>>
    <summary><?php esc_html_e('検索・絞り込み', 'drwp-daily-reports'); ?></summary>
    <form method="get" class="drwp-filter-form">
      <input type="hidden" name="page" value="drwp_reports" />
      <div class="drwp-row">
        <input type="search" name="s" value="<?php echo esc_attr($filters['search']); ?>" placeholder="<?php esc_attr_e('本文・公開タイトル・タグ検索', 'drwp-daily-reports'); ?>" class="drwp-search-input" />
        <select name="review_status">
          <option value=""><?php esc_html_e('レビュー状態すべて', 'drwp-daily-reports'); ?></option>
          <?php foreach ($review_labels as $k => $v): ?>
            <option value="<?php echo esc_attr($k); ?>" <?php selected($filters['review_status'], $k); ?>><?php echo esc_html($v); ?></option>
          <?php endforeach; ?>
        </select>
        <select name="project_id">
          <option value="0"><?php esc_html_e('案件すべて', 'drwp-daily-reports'); ?></option>
          <?php foreach (($projects ?? []) as $project): ?>
            <option value="<?php echo (int) $project->id; ?>" <?php selected((int) $filters['project_id'], (int) $project->id); ?>><?php echo esc_html($project->name); ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (!empty($project_groups)): ?>
        <select name="project_group_id">
          <option value="0"><?php esc_html_e('案件グループすべて', 'drwp-daily-reports'); ?></option>
          <?php foreach ($project_groups as $g): ?>
            <option value="<?php echo (int) $g->id; ?>" <?php selected((int) ($filters['project_group_id'] ?? 0), (int) $g->id); ?>><?php echo esc_html($g->name); ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <?php if (!empty($customer_groups)): ?>
        <select name="customer_group_id">
          <option value="0"><?php esc_html_e('顧客グループすべて', 'drwp-daily-reports'); ?></option>
          <?php foreach ($customer_groups as $g): ?>
            <option value="<?php echo (int) $g->id; ?>" <?php selected((int) ($filters['customer_group_id'] ?? 0), (int) $g->id); ?>><?php echo esc_html($g->name); ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
      </div>
      <div class="drwp-row">
        <input type="date" name="date_from" id="drwp-date-from" value="<?php echo esc_attr($filters['date_from']); ?>" />
        〜
        <input type="date" name="date_to" id="drwp-date-to" value="<?php echo esc_attr($filters['date_to']); ?>" />
        <button class="button button-primary"><?php esc_html_e('検索', 'drwp-daily-reports'); ?></button>
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=drwp_reports')); ?>"><?php esc_html_e('クリア', 'drwp-daily-reports'); ?></a>
      </div>
      <div class="drwp-cal-wrap">
        <div class="drwp-cal-head">
          <button type="button" class="button button-small" id="drwp-cal-prev">‹</button>
          <span id="drwp-cal-title"></span>
          <button type="button" class="button button-small" id="drwp-cal-next">›</button>
          <span class="drwp-cal-hint"><?php esc_html_e('日付をクリックで開始日 / 範囲指定', 'drwp-daily-reports'); ?></span>
        </div>
        <div class="drwp-cal-grid" id="drwp-cal-grid"></div>
      </div>
    </form>
  </details>

  <p class="drwp-counter-line">
    <?php
      printf(
          esc_html(_n('合計 %d 件', '合計 %d 件', (int) $total, 'drwp-daily-reports')),
          (int) $total
      );
    ?>
  </p>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php wp_nonce_field('drwp_bulk_reports'); ?>
    <input type="hidden" name="action" value="drwp_bulk_reports" />
    <input type="hidden" name="redirect_page" value="drwp_reports" />

    <div class="drwp-card drwp-bulk-card">
      <h3><?php esc_html_e('一括操作', 'drwp-daily-reports'); ?></h3>
      <div class="drwp-row">
        <select name="bulk_action">
          <option value=""><?php esc_html_e('操作を選択', 'drwp-daily-reports'); ?></option>
          <option value="bulk_approve"><?php esc_html_e('一括承認', 'drwp-daily-reports'); ?></option>
          <option value="bulk_revision"><?php esc_html_e('一括差し戻し', 'drwp-daily-reports'); ?></option>
          <option value="bulk_export_csv"><?php esc_html_e('選択した日報をCSV出力', 'drwp-daily-reports'); ?></option>
        </select>
        <button class="button button-primary"><?php esc_html_e('実行', 'drwp-daily-reports'); ?></button>
      </div>
    </div>

    <table class="widefat striped" id="drwp-reports-table">
      <thead>
        <tr>
          <th><input type="checkbox" id="drwp-check-all" /></th>
          <th>ID</th>
          <th><?php esc_html_e('日付', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('報告者', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('案件', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('レビュー', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('操作', 'drwp-daily-reports'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($reports)): ?>
          <tr><td colspan="7"><?php esc_html_e('該当する日報がありません。', 'drwp-daily-reports'); ?></td></tr>
        <?php else: foreach ($reports as $report):
          $author = get_userdata((int) $report->user_id);
          $project_name = '-';
          if (!empty($report->project_id)) {
              $proj = DRWP_Project::find((int) $report->project_id);
              $project_name = $proj ? $proj->name : (string) $report->project_id;
          }
          $status_class = 'is-' . sanitize_html_class((string) $report->review_status);
        ?>
          <tr>
            <td><input class="drwp-check" type="checkbox" name="report_ids[]" value="<?php echo (int) $report->id; ?>" /></td>
            <td><?php echo (int) $report->id; ?></td>
            <td><?php echo esc_html(date_i18n('Y年n月j日', strtotime((string) $report->report_date))); ?></td>
            <td><?php echo esc_html(DRWP_User::display_name((int) $report->user_id) ?: ('#' . (int) $report->user_id)); ?></td>
            <td><?php echo esc_html($project_name); ?></td>
            <td><span class="drwp-page-status <?php echo esc_attr($status_class); ?>"><?php echo esc_html(DRWP_Labels::review_status((string) $report->review_status)); ?></span></td>
            <td style="white-space:nowrap;">
              <button type="button" class="button button-small drwp-view-btn" data-id="<?php echo (int) $report->id; ?>">
                <?php esc_html_e('確認', 'drwp-daily-reports'); ?>
              </button>
              <button type="button" class="button button-small drwp-edit-btn" data-id="<?php echo (int) $report->id; ?>">
                <?php esc_html_e('編集', 'drwp-daily-reports'); ?>
              </button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </form>

  <?php
  if ($pages > 1):
      $base = add_query_arg(
          array_merge(
              ['page' => 'drwp_reports'],
              array_filter(
                  [
                      's'                 => $filters['search'],
                      'review_status'     => $filters['review_status'],
                      'project_id'        => $filters['project_id'] ?: '',
                      'customer_group_id' => $filters['customer_group_id'] ?? '' ?: '',
                      'project_group_id'  => $filters['project_group_id'] ?? '' ?: '',
                      'date_from'         => $filters['date_from'],
                      'date_to'           => $filters['date_to'],
                  ],
                  function ($v) { return $v !== '' && $v !== 0; }
              )
          ),
          admin_url('admin.php')
      );
      $page_links = paginate_links([
          'base'      => add_query_arg('paged', '%#%', $base),
          'format'    => '',
          'current'   => (int) $paged,
          'total'     => (int) $pages,
          'type'      => 'array',
          'prev_text' => '‹',
          'next_text' => '›',
      ]);
  ?>
    <div class="tablenav" style="margin-top:12px;">
      <div class="tablenav-pages">
        <?php foreach (($page_links ?: []) as $link) echo $link . ' '; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- 確認モーダル — 紙面風表示 + レビュー + コメント -->
  <dialog id="drwp-view-dialog" class="drwp-modal drwp-modal-wide">
    <div class="drwp-modal-header">
      <h2 id="drwp-view-title"><?php esc_html_e('日報の内容', 'drwp-daily-reports'); ?></h2>
      <button type="button" class="drwp-modal-close" aria-label="<?php esc_attr_e('閉じる', 'drwp-daily-reports'); ?>">&times;</button>
    </div>
    <div class="drwp-modal-body">
      <p class="drwp-view-loading" id="drwp-view-loading"><?php esc_html_e('読み込み中…', 'drwp-daily-reports'); ?></p>
      <article class="drwp-page" id="drwp-view-page" hidden>
        <div class="drwp-page-title"><?php esc_html_e('作業日報', 'drwp-daily-reports'); ?></div>
        <table class="drwp-page-meta">
          <colgroup>
            <col class="drwp-meta-col-head" />
            <col class="drwp-meta-col-val" />
            <col class="drwp-meta-col-head" />
            <col class="drwp-meta-col-val" />
          </colgroup>
          <tr>
            <th><?php esc_html_e('案件名', 'drwp-daily-reports'); ?></th>
            <td colspan="3" id="drwp-view-project"></td>
          </tr>
          <tr>
            <th><?php esc_html_e('日付', 'drwp-daily-reports'); ?></th>
            <td id="drwp-view-date"></td>
            <th><?php esc_html_e('作業時間', 'drwp-daily-reports'); ?></th>
            <td id="drwp-view-time"></td>
          </tr>
          <tr>
            <th><?php esc_html_e('報告者', 'drwp-daily-reports'); ?></th>
            <td id="drwp-view-author"></td>
            <th><?php esc_html_e('レビュー', 'drwp-daily-reports'); ?></th>
            <td><span id="drwp-view-status" class="drwp-page-status"></span></td>
          </tr>
        </table>

        <table class="drwp-page-section">
          <tr><th class="drwp-page-section-head"><?php esc_html_e('作業内容', 'drwp-daily-reports'); ?></th></tr>
          <tr><td class="drwp-page-section-body"><div class="drwp-page-text" id="drwp-view-work"></div></td></tr>
        </table>
        <table class="drwp-page-section">
          <tr><th class="drwp-page-section-head"><?php esc_html_e('特記事項（反省・連絡・相談・提案）', 'drwp-daily-reports'); ?></th></tr>
          <tr><td class="drwp-page-section-body"><div class="drwp-page-text" id="drwp-view-issues"></div></td></tr>
        </table>
        <table class="drwp-page-section">
          <tr><th class="drwp-page-section-head"><?php esc_html_e('次回予定', 'drwp-daily-reports'); ?></th></tr>
          <tr><td class="drwp-page-section-body"><div class="drwp-page-text" id="drwp-view-next"></div></td></tr>
        </table>
        <table class="drwp-page-section">
          <tr><th class="drwp-page-section-head"><?php esc_html_e('写真', 'drwp-daily-reports'); ?></th></tr>
          <tr><td class="drwp-page-section-body"><div class="drwp-page-photos" id="drwp-view-photos"></div></td></tr>
        </table>
      </article>

      <?php if ($can_review): ?>
      <section class="drwp-card drwp-review-card">
        <h3><?php esc_html_e('レビュー操作', 'drwp-daily-reports'); ?></h3>
        <div class="drwp-row">
          <select id="drwp-review-status">
            <?php foreach ($review_labels as $k => $v): ?>
              <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($v); ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" id="drwp-review-comment" placeholder="<?php esc_attr_e('コメント（任意）', 'drwp-daily-reports'); ?>" class="drwp-review-input" />
          <button type="button" class="button button-primary" id="drwp-review-submit"><?php esc_html_e('レビュー送信', 'drwp-daily-reports'); ?></button>
          <span id="drwp-review-msg"></span>
        </div>
      </section>
      <?php endif; ?>

      <section class="drwp-card drwp-comments-card">
        <h3><?php esc_html_e('コメント', 'drwp-daily-reports'); ?></h3>
        <div id="drwp-comments-list"><p class="description"><?php esc_html_e('読み込み中…', 'drwp-daily-reports'); ?></p></div>
        <div class="drwp-row">
          <textarea id="drwp-comment-body" rows="2" class="large-text" placeholder="<?php esc_attr_e('コメントを入力…', 'drwp-daily-reports'); ?>"></textarea>
          <button type="button" class="button" id="drwp-comment-submit" style="white-space:nowrap;"><?php esc_html_e('送信', 'drwp-daily-reports'); ?></button>
        </div>
      </section>
    </div>
  </dialog>

  <!-- 編集モーダル — フォーム -->
  <dialog id="drwp-edit-dialog" class="drwp-modal drwp-modal-wide">
    <div class="drwp-modal-header">
      <h2><?php esc_html_e('日報を編集', 'drwp-daily-reports'); ?></h2>
      <button type="button" class="drwp-modal-close" aria-label="<?php esc_attr_e('閉じる', 'drwp-daily-reports'); ?>">&times;</button>
    </div>
    <div class="drwp-modal-body">
      <input type="hidden" id="drwp-edit-id" />
      <table class="form-table" role="presentation">
        <tr><th><?php esc_html_e('日付', 'drwp-daily-reports'); ?></th>
            <td><input type="date" id="drwp-edit-date" /></td></tr>
        <tr><th><?php esc_html_e('案件', 'drwp-daily-reports'); ?></th>
            <td>
              <select id="drwp-edit-project">
                <option value=""><?php esc_html_e('（未設定）', 'drwp-daily-reports'); ?></option>
                <?php foreach (($projects ?? []) as $p): ?>
                  <option value="<?php echo (int) $p->id; ?>"><?php echo esc_html($p->name); ?></option>
                <?php endforeach; ?>
              </select>
            </td></tr>
        <tr><th><?php esc_html_e('時刻', 'drwp-daily-reports'); ?></th>
            <td><input type="time" id="drwp-edit-started" /> 〜 <input type="time" id="drwp-edit-ended" /></td></tr>
        <tr><th><?php esc_html_e('作業内容', 'drwp-daily-reports'); ?></th>
            <td><textarea id="drwp-edit-work" rows="4" class="large-text"></textarea></td></tr>
        <tr><th><?php esc_html_e('特記事項', 'drwp-daily-reports'); ?></th>
            <td><textarea id="drwp-edit-issues" rows="3" class="large-text"></textarea></td></tr>
        <tr><th><?php esc_html_e('次回予定', 'drwp-daily-reports'); ?></th>
            <td><textarea id="drwp-edit-next" rows="3" class="large-text"></textarea></td></tr>
      </table>
    </div>
    <div class="drwp-modal-footer">
      <button type="button" class="button button-primary" id="drwp-edit-save"><?php esc_html_e('保存', 'drwp-daily-reports'); ?></button>
      <button type="button" class="button drwp-modal-close"><?php esc_html_e('キャンセル', 'drwp-daily-reports'); ?></button>
      <span id="drwp-edit-status" style="margin-left:12px;"></span>
    </div>
  </dialog>
</div>

<style>
.drwp-card{background:#fff;border:1px solid #d1d5db;border-radius:10px;padding:12px 16px;margin-bottom:12px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
.drwp-card>summary{cursor:pointer;font-weight:600;color:#1d2327;list-style:none;display:flex;align-items:center;gap:6px;margin:-12px -16px 0;padding:10px 14px}
.drwp-card>summary::before{content:'▸';font-size:.8em;color:#6b7280;transition:transform .15s}
.drwp-card[open]>summary{margin-bottom:8px;border-bottom:1px solid #f1f5f9}
.drwp-card[open]>summary::before{transform:rotate(90deg)}
.drwp-card>summary:hover{color:#2271b1}
.drwp-card>h3{margin:0 0 6px;font-size:.95em;color:#1d2327;border-bottom:1px solid #f1f5f9;padding-bottom:6px}
.drwp-filter-card{background:#f0f6fc;border-left:4px solid #2271b1}
.drwp-bulk-card{background:#fefce8;border-left:4px solid #d97706}
.drwp-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:8px}
.drwp-row:last-child{margin-bottom:0}
.drwp-search-input{min-width:240px;flex:1}
.drwp-cal-wrap{margin-top:6px}
.drwp-cal-head{display:flex;gap:8px;align-items:center;margin-bottom:6px}
.drwp-cal-head #drwp-cal-title{font-weight:600;min-width:120px;text-align:center}
.drwp-cal-hint{font-size:.8em;color:#64748b;margin-left:8px}
.drwp-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;background:#fff;border:1px solid #d1d5db;border-radius:6px;padding:4px;max-width:380px}
.drwp-cal-grid .dow{text-align:center;font-size:.75em;color:#64748b;font-weight:600;padding:4px 0}
.drwp-cal-grid .dow.sun{color:#dc2626}
.drwp-cal-grid .dow.sat{color:#2563eb}
.drwp-cal-day{position:relative;text-align:center;padding:6px 0;font-size:.85em;border-radius:4px;cursor:pointer;border:0;background:transparent;color:#374151;transition:background .1s}
.drwp-cal-day.empty{visibility:hidden}
.drwp-cal-day:hover:not(.empty){background:#e0f2fe}
.drwp-cal-day.has-reports{font-weight:600;color:#0f172a}
/* Two-dot row at the bottom of a day: blue = report, green = plan.
   Single dot centered when only one exists; spread apart when both. */
.drwp-cal-day.has-reports::after{content:'';position:absolute;left:50%;bottom:2px;margin-left:-2px;width:4px;height:4px;border-radius:50%;background:#2271b1}
.drwp-cal-day.has-plans::before{content:'';position:absolute;left:50%;bottom:2px;margin-left:-2px;width:4px;height:4px;border-radius:50%;background:#16a34a}
.drwp-cal-day.has-reports.has-plans::after{margin-left:-6px}
.drwp-cal-day.has-reports.has-plans::before{margin-left:2px}
.drwp-cal-day.today{outline:1px solid #2271b1}
.drwp-cal-day.in-range{background:#dbeafe}
.drwp-cal-day.range-edge{background:#2271b1;color:#fff}
.drwp-cal-day.range-edge::after{background:#fff}
.drwp-counter-line{margin:8px 0;color:#64748b;font-size:.9em}

/* 共通モーダル — 確認 / 編集 で同じスタイル */
.drwp-modal{border:0;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.18);padding:0;max-width:640px;width:90vw}
.drwp-modal-wide{max-width:860px}
.drwp-modal::backdrop{background:rgba(0,0,0,.45)}
.drwp-modal-header{display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-bottom:1px solid #e5e7eb}
.drwp-modal-header h2{margin:0;font-size:1.1em}
.drwp-modal-close{background:transparent;border:0;font-size:1.6em;cursor:pointer;color:#50575e;line-height:1;padding:0 4px}
.drwp-modal-body{padding:16px 20px;max-height:78vh;overflow-y:auto}
.drwp-modal-body .form-table th{width:100px;padding:6px 0}
.drwp-modal-body .form-table td{padding:6px 0}
.drwp-modal-footer{display:flex;gap:8px;align-items:center;padding:12px 20px;border-top:1px solid #e5e7eb;background:#f6f7f7;border-radius:0 0 12px 12px}
.drwp-view-loading{color:#64748b;text-align:center;padding:32px 0}

/* 紙面風表示 — 確認モーダル内 */
.drwp-page{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px 24px;margin-bottom:14px;font-family:"Hiragino Sans","Yu Gothic","Noto Sans JP",sans-serif;color:#1f2937}
.drwp-page-title{text-align:center;font-size:1.25em;font-weight:700;background:#e5e7eb;border:1px solid #1f2937;padding:6px 0;margin-bottom:12px}
.drwp-page-meta{width:100%;border-collapse:collapse;margin-bottom:12px;table-layout:fixed}
.drwp-page-meta th,.drwp-page-meta td{border:1px solid #1f2937;padding:6px 10px;font-size:.92em;vertical-align:middle}
.drwp-page-meta th{background:#e5e7eb;text-align:center;font-weight:600;white-space:nowrap}
.drwp-meta-col-head{width:14%}
.drwp-meta-col-val{width:36%}
.drwp-page-status{display:inline-block;padding:2px 10px;border-radius:999px;font-size:.8em;font-weight:600;background:#f1f5f9;color:#475569}
.drwp-page-status.is-approved{background:#dcfce7;color:#166534}
.drwp-page-status.is-needs_revision{background:#fee2e2;color:#991b1b}
.drwp-page-status.is-edit_requested{background:#fef3c7;color:#92400e}
.drwp-page-status.is-pending{background:#e0e7ff;color:#3730a3}
.drwp-page-section{width:100%;border-collapse:collapse;margin-top:10px;table-layout:fixed}
.drwp-page-section-head{background:#e5e7eb;border:1px solid #1f2937;text-align:center;font-weight:700;font-size:.95em;padding:5px}
.drwp-page-section-body{border:1px solid #1f2937;padding:12px 14px;vertical-align:top;min-height:60px}
.drwp-page-text{white-space:pre-wrap;line-height:1.6;color:#1f2937}
.drwp-page-photos{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px}
.drwp-page-photos figure{margin:0}
.drwp-page-photos img{width:100%;aspect-ratio:1;object-fit:cover;border:1px solid #c3c4c7;display:block}
.drwp-page-photos figcaption{font-size:.8em;color:#64748b;margin-top:2px}

.drwp-review-card{background:#f0fdf4}
.drwp-review-input{flex:1;min-width:200px}
.drwp-comments-card{background:#fff}
.drwp-comment-item{padding:8px 0;border-bottom:1px solid #f1f5f9}
.drwp-comment-meta{font-size:.8em;color:#64748b;margin-bottom:2px}
.drwp-comment-body{white-space:pre-wrap;font-size:.92em;color:#1f2937}
</style>

<script>
(function(){
  /* ---- カレンダー ---- */
  var dates = <?php echo wp_json_encode((object) ($report_dates ?? [])); ?>;
  // Planned-visit overlay — separate dot color so days with a
  // 予定 row show up alongside actual report dates.
  var planDates = <?php echo wp_json_encode((object) ($plan_dates ?? [])); ?>;
  var fromEl = document.getElementById('drwp-date-from');
  var toEl = document.getElementById('drwp-date-to');
  var grid = document.getElementById('drwp-cal-grid');
  var titleEl = document.getElementById('drwp-cal-title');
  if (!grid) return;

  function pad(n){return n<10?('0'+n):(''+n);}
  function fmt(d){return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate());}
  function parseDate(s){
    if(!s)return null;
    var m=s.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if(!m)return null;
    return new Date(parseInt(m[1],10),parseInt(m[2],10)-1,parseInt(m[3],10));
  }
  function startOfMonth(y,m){return new Date(y,m,1);}

  var init = parseDate(fromEl.value) || parseDate(toEl.value) || new Date();
  var cursor = startOfMonth(init.getFullYear(), init.getMonth());
  var pendingStart = null;

  function render(){
    var y = cursor.getFullYear(), m = cursor.getMonth();
    titleEl.textContent = y + '年 ' + (m+1) + '月';
    grid.innerHTML = '';
    var dows = ['日','月','火','水','木','金','土'];
    dows.forEach(function(d,i){
      var el = document.createElement('div');
      el.className = 'dow' + (i===0?' sun':(i===6?' sat':''));
      el.textContent = d;
      grid.appendChild(el);
    });
    var firstDow = new Date(y,m,1).getDay();
    var daysInMonth = new Date(y,m+1,0).getDate();
    var today = fmt(new Date());
    var fromVal = fromEl.value, toVal = toEl.value;
    for (var i=0;i<firstDow;i++){
      var emp = document.createElement('div'); emp.className='drwp-cal-day empty'; grid.appendChild(emp);
    }
    for (var d=1; d<=daysInMonth; d++){
      var date = new Date(y,m,d);
      var key = fmt(date);
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'drwp-cal-day';
      btn.textContent = d;
      btn.dataset.date = key;
      if (dates[key]) btn.classList.add('has-reports');
      if (planDates[key]) btn.classList.add('has-plans');
      if (key === today) btn.classList.add('today');
      if (fromVal && toVal) {
        if (key >= fromVal && key <= toVal) btn.classList.add('in-range');
        if (key === fromVal || key === toVal) btn.classList.add('range-edge');
      } else if (pendingStart && pendingStart === key) {
        btn.classList.add('range-edge');
      }
      grid.appendChild(btn);
    }
  }

  grid.addEventListener('click', function(e){
    var btn = e.target.closest('.drwp-cal-day');
    if (!btn || btn.classList.contains('empty')) return;
    var key = btn.dataset.date;
    if (!pendingStart && !(fromEl.value && toEl.value && fromEl.value !== toEl.value)) {
      pendingStart = key;
      fromEl.value = key;
      toEl.value = key;
    } else {
      var anchor = pendingStart || fromEl.value;
      if (key < anchor) { fromEl.value = key; toEl.value = anchor; }
      else { fromEl.value = anchor; toEl.value = key; }
      pendingStart = null;
    }
    render();
  });

  document.getElementById('drwp-cal-prev').addEventListener('click', function(){
    cursor = startOfMonth(cursor.getFullYear(), cursor.getMonth()-1); render();
  });
  document.getElementById('drwp-cal-next').addEventListener('click', function(){
    cursor = startOfMonth(cursor.getFullYear(), cursor.getMonth()+1); render();
  });

  [fromEl, toEl].forEach(function(el){
    el.addEventListener('change', function(){ pendingStart = null; render(); });
  });

  render();
})();
</script>

<script>
(function(){
  var rest = <?php echo wp_json_encode([
      'url'    => esc_url_raw(rest_url('drwp/v1')),
      'nonce'  => wp_create_nonce('wp_rest'),
      'labels' => $review_labels,
  ]); ?>;

  var checkAll = document.getElementById('drwp-check-all');
  if (checkAll) {
    checkAll.addEventListener('change', function () {
      document.querySelectorAll('.drwp-check').forEach(function (cb) {
        cb.checked = checkAll.checked;
      });
    });
  }

  function esc(s){var d=document.createElement('div');d.textContent=String(s==null?'':s);return d.innerHTML;}
  function api(path, opts){
    opts = opts || {}; opts.credentials = 'same-origin';
    opts.headers = Object.assign({'X-WP-Nonce': rest.nonce}, opts.headers || {});
    return fetch(rest.url + path, opts).then(function (r) {
      return r.json().then(function (j) { if (!r.ok) throw new Error(j.message || 'HTTP ' + r.status); return j; });
    });
  }
  function formatDate(d){
    if(!d) return '';
    var dt = new Date(d + 'T00:00:00');
    if(isNaN(dt)) return d;
    var w = ['日','月','火','水','木','金','土'][dt.getDay()];
    return dt.getFullYear() + '年' + (dt.getMonth()+1) + '月' + dt.getDate() + '日（' + w + '）';
  }

  /* ---- 確認モーダル ---- */
  var viewDlg = document.getElementById('drwp-view-dialog');
  var viewPage = document.getElementById('drwp-view-page');
  var viewLoading = document.getElementById('drwp-view-loading');
  var viewTitle = document.getElementById('drwp-view-title');
  var currentViewId = 0;

  function loadComments(id) {
    var list = document.getElementById('drwp-comments-list');
    if (!list) return;
    list.innerHTML = '<p class="description"><?php echo esc_js(__('読み込み中…', 'drwp-daily-reports')); ?></p>';
    api('/reports/' + id + '/comments').then(function (r) {
      // Stale check — operator may have closed/reopened on a different row.
      if (currentViewId !== id) return;
      var items = r.items || [];
      if (!items.length) {
        list.innerHTML = '<p class="description"><?php echo esc_js(__('コメントはありません。', 'drwp-daily-reports')); ?></p>';
        return;
      }
      var html = '';
      items.forEach(function (c) {
        html += '<div class="drwp-comment-item">' +
                  '<div class="drwp-comment-meta">' + esc(c.display_name || ('#' + c.user_id)) + ' — ' + esc(c.created_at) + '</div>' +
                  '<div class="drwp-comment-body">' + esc(c.body) + '</div>' +
                '</div>';
      });
      list.innerHTML = html;
    }).catch(function () {
      list.innerHTML = '<p class="description" style="color:#991b1b;"><?php echo esc_js(__('コメントの読み込みに失敗しました。', 'drwp-daily-reports')); ?></p>';
    });
  }

  function openViewModal(id) {
    currentViewId = id;
    viewTitle.textContent = '<?php echo esc_js(__('日報の内容', 'drwp-daily-reports')); ?> (#' + id + ')';
    viewLoading.hidden = false;
    viewPage.hidden = true;
    // Reset review/comment UI before showing the modal so a stale
    // value doesn't flash up while the API call is in flight.
    var statusSel = document.getElementById('drwp-review-status');
    if (statusSel) statusSel.value = 'pending';
    var commentEl = document.getElementById('drwp-review-comment');
    if (commentEl) commentEl.value = '';
    var msgEl = document.getElementById('drwp-review-msg');
    if (msgEl) { msgEl.textContent = ''; msgEl.style.color = ''; }
    viewDlg.showModal();

    api('/reports/' + id).then(function (d) {
      if (currentViewId !== id) return;
      document.getElementById('drwp-view-project').textContent = d.project_name || '<?php echo esc_js(__('（案件未設定）', 'drwp-daily-reports')); ?>';
      document.getElementById('drwp-view-date').textContent = formatDate(d.report_date);
      var time = '';
      if (d.started_at) time += String(d.started_at).substring(0, 5);
      if (d.started_at && d.ended_at) time += ' 〜 ';
      if (d.ended_at) time += String(d.ended_at).substring(0, 5);
      document.getElementById('drwp-view-time').textContent = time;
      document.getElementById('drwp-view-author').textContent = d.author_name || '';
      var status = document.getElementById('drwp-view-status');
      status.textContent = rest.labels[d.review_status] || d.review_status;
      status.className = 'drwp-page-status is-' + d.review_status;
      document.getElementById('drwp-view-work').textContent = d.work_description || '';
      document.getElementById('drwp-view-issues').textContent = d.issues || '';
      document.getElementById('drwp-view-next').textContent = d.next_plan || '';
      var photosEl = document.getElementById('drwp-view-photos');
      photosEl.innerHTML = '';
      if (d.photos && d.photos.length) {
        var html = '';
        d.photos.forEach(function (p) {
          html += '<figure><img src="' + esc(p.url) + '" alt="" />' +
                  (p.caption ? '<figcaption>' + esc(p.caption) + '</figcaption>' : '') +
                  '</figure>';
        });
        photosEl.innerHTML = html;
      }
      if (statusSel) statusSel.value = d.review_status || 'pending';
      viewLoading.hidden = true;
      viewPage.hidden = false;
      loadComments(id);
    }).catch(function (err) {
      viewLoading.textContent = (err && err.message) || '<?php echo esc_js(__('読み込みに失敗しました。', 'drwp-daily-reports')); ?>';
    });
  }

  // Review submit (only present when capability allows it).
  var reviewBtn = document.getElementById('drwp-review-submit');
  if (reviewBtn) {
    reviewBtn.addEventListener('click', function () {
      var id = currentViewId;
      if (!id) return;
      var msg = document.getElementById('drwp-review-msg');
      msg.textContent = '<?php echo esc_js(__('送信中…', 'drwp-daily-reports')); ?>';
      msg.style.color = '';
      reviewBtn.disabled = true;
      api('/reports/' + id + '/review', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          review_status: document.getElementById('drwp-review-status').value,
          comment: document.getElementById('drwp-review-comment').value
        })
      }).then(function () {
        msg.textContent = '<?php echo esc_js(__('送信しました', 'drwp-daily-reports')); ?>';
        msg.style.color = '#166534';
        reviewBtn.disabled = false;
        document.getElementById('drwp-review-comment').value = '';
        var newStatus = document.getElementById('drwp-review-status').value;
        var statusBadge = document.getElementById('drwp-view-status');
        statusBadge.textContent = rest.labels[newStatus] || newStatus;
        statusBadge.className = 'drwp-page-status is-' + newStatus;
        loadComments(id);
      }).catch(function (err) {
        msg.textContent = err.message;
        msg.style.color = '#991b1b';
        reviewBtn.disabled = false;
      });
    });
  }

  document.getElementById('drwp-comment-submit').addEventListener('click', function () {
    var id = currentViewId;
    if (!id) return;
    var bodyEl = document.getElementById('drwp-comment-body');
    var text = bodyEl.value.trim();
    if (!text) return;
    var btn = this;
    btn.disabled = true;
    api('/reports/' + id + '/comments', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({body: text})
    }).then(function () { bodyEl.value = ''; btn.disabled = false; loadComments(id); })
      .catch(function () { btn.disabled = false; });
  });

  /* ---- 編集モーダル ---- */
  var editDlg = document.getElementById('drwp-edit-dialog');

  function openEditModal(id) {
    document.getElementById('drwp-edit-id').value = id;
    document.getElementById('drwp-edit-status').textContent = '';
    ['drwp-edit-date','drwp-edit-started','drwp-edit-ended'].forEach(function (k) { document.getElementById(k).value = ''; });
    ['drwp-edit-work','drwp-edit-issues','drwp-edit-next'].forEach(function (k) { document.getElementById(k).value = ''; });
    document.getElementById('drwp-edit-project').value = '';
    editDlg.showModal();
    api('/reports/' + id).then(function (d) {
      document.getElementById('drwp-edit-date').value = d.report_date || '';
      document.getElementById('drwp-edit-project').value = d.project_id || '';
      document.getElementById('drwp-edit-started').value = (d.started_at || '').substring(0, 5);
      document.getElementById('drwp-edit-ended').value = (d.ended_at || '').substring(0, 5);
      document.getElementById('drwp-edit-work').value = d.work_description || '';
      document.getElementById('drwp-edit-issues').value = d.issues || '';
      document.getElementById('drwp-edit-next').value = d.next_plan || '';
    }).catch(function (err) {
      document.getElementById('drwp-edit-status').textContent = err.message;
    });
  }

  document.getElementById('drwp-edit-save').addEventListener('click', function () {
    var id = document.getElementById('drwp-edit-id').value;
    var st = document.getElementById('drwp-edit-status');
    st.textContent = '<?php echo esc_js(__('保存中…', 'drwp-daily-reports')); ?>';
    this.disabled = true;
    var self = this;
    api('/reports/' + id, {
      method: 'PATCH',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        report_date:      document.getElementById('drwp-edit-date').value,
        project_id:       Number(document.getElementById('drwp-edit-project').value) || null,
        started_at:       document.getElementById('drwp-edit-started').value || null,
        ended_at:         document.getElementById('drwp-edit-ended').value || null,
        work_description: document.getElementById('drwp-edit-work').value,
        issues:           document.getElementById('drwp-edit-issues').value,
        next_plan:        document.getElementById('drwp-edit-next').value
      })
    }).then(function () { editDlg.close(); location.reload(); })
      .catch(function (err) { st.textContent = err.message; self.disabled = false; });
  });

  /* ---- テーブルクリック委譲 ---- */
  var table = document.getElementById('drwp-reports-table');
  if (table) {
    table.addEventListener('click', function (e) {
      var viewBtn = e.target.closest('.drwp-view-btn');
      if (viewBtn) { openViewModal(parseInt(viewBtn.dataset.id, 10)); return; }
      var editBtn = e.target.closest('.drwp-edit-btn');
      if (editBtn) { openEditModal(parseInt(editBtn.dataset.id, 10)); return; }
    });
  }

  /* ---- モーダル共通: × ボタン / 背景クリック ---- */
  [viewDlg, editDlg].forEach(function (dlg) {
    if (!dlg) return;
    dlg.addEventListener('click', function (e) {
      if (e.target.classList.contains('drwp-modal-close')) dlg.close();
      if (e.target === dlg) dlg.close();
    });
  });
})();
</script>
