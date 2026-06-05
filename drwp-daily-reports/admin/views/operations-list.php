<?php if (!defined('ABSPATH')) exit;
$review_labels = [
    'pending'        => DRWP_Labels::review_status('pending'),
    'approved'       => DRWP_Labels::review_status('approved'),
    'needs_revision' => DRWP_Labels::review_status('needs_revision'),
    'edit_requested' => DRWP_Labels::review_status('edit_requested'),
];
?>
<div class="wrap">
  <h1><?php esc_html_e('日報操作', 'drwp-daily-reports'); ?></h1>
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
  <div class="drwp-list-card drwp-list-search">
    <h2><?php esc_html_e('検索・絞り込み', 'drwp-daily-reports'); ?></h2>
    <form method="get" class="drwp-list-search-form">
      <input type="hidden" name="page" value="drwp_operations" />
      <div class="drwp-list-search-row">
        <input type="search" name="s" value="<?php echo esc_attr($filters['search']); ?>" placeholder="<?php esc_attr_e('本文・公開タイトル・タグ検索', 'drwp-daily-reports'); ?>" class="drwp-list-search-input" />
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
      </div>
      <div class="drwp-list-search-row">
        <input type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>" />
        <span>〜</span>
        <input type="date" name="date_to" value="<?php echo esc_attr($filters['date_to']); ?>" />
        <button class="button button-primary"><?php esc_html_e('検索', 'drwp-daily-reports'); ?></button>
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=drwp_operations')); ?>"><?php esc_html_e('クリア', 'drwp-daily-reports'); ?></a>
      </div>
    </form>
  </div>

  <p class="description" style="margin:8px 0;">
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
    <input type="hidden" name="redirect_page" value="drwp_operations" />

    <div class="drwp-list-card drwp-list-bulk">
      <h2><?php esc_html_e('一括操作', 'drwp-daily-reports'); ?></h2>
      <div class="drwp-list-bulk-row">
        <select name="bulk_action">
          <option value=""><?php esc_html_e('操作を選択', 'drwp-daily-reports'); ?></option>
          <option value="bulk_approve"><?php esc_html_e('一括承認', 'drwp-daily-reports'); ?></option>
          <option value="bulk_revision"><?php esc_html_e('一括差し戻し', 'drwp-daily-reports'); ?></option>
          <option value="bulk_export_csv"><?php esc_html_e('選択した日報をCSV出力', 'drwp-daily-reports'); ?></option>
        </select>
        <button class="button button-primary"><?php esc_html_e('実行', 'drwp-daily-reports'); ?></button>
      </div>
    </div>

    <table class="widefat striped" id="drwp-ops-table">
      <thead>
        <tr>
          <th><input type="checkbox" onclick="document.querySelectorAll('.drwp-check').forEach(cb => cb.checked = this.checked)" /></th>
          <th>ID</th>
          <th><?php esc_html_e('日付', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('作成者', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('案件', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('レビュー', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('操作', 'drwp-daily-reports'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($reports)): ?>
          <tr><td colspan="7"><?php esc_html_e('データがありません。', 'drwp-daily-reports'); ?></td></tr>
        <?php else: foreach ($reports as $report): ?>
          <tr>
            <td><input class="drwp-check" type="checkbox" name="report_ids[]" value="<?php echo esc_attr($report->id); ?>" /></td>
            <td><?php echo esc_html($report->id); ?></td>
            <td><?php echo esc_html(date_i18n('Y年n月j日', strtotime((string) $report->report_date))); ?></td>
            <td><?php
              $author = get_userdata((int) $report->user_id);
              echo esc_html($author ? $author->display_name : ('#' . (int) $report->user_id));
            ?></td>
            <td><?php
              $project_name = '-';
              if (!empty($report->project_id)) {
                  $proj = DRWP_Project::find((int) $report->project_id);
                  $project_name = $proj ? $proj->name : (string) $report->project_id;
              }
              echo esc_html($project_name);
            ?></td>
            <td><?php echo esc_html(DRWP_Labels::review_status((string) $report->review_status)); ?></td>
            <td style="white-space:nowrap;">
              <button type="button" class="button button-small drwp-edit-btn" data-id="<?php echo (int) $report->id; ?>"><?php esc_html_e('編集', 'drwp-daily-reports'); ?></button>
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
              ['page' => 'drwp_operations'],
              array_filter(
                  [
                      's'             => $filters['search'],
                      'review_status' => $filters['review_status'],
                      'project_id'    => $filters['project_id'] ?: '',
                      'date_from'     => $filters['date_from'],
                      'date_to'       => $filters['date_to'],
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

  <dialog id="drwp-edit-dialog" class="drwp-modal drwp-modal-wide">
    <div class="drwp-modal-header">
      <h2><?php esc_html_e('日報を編集', 'drwp-daily-reports'); ?></h2>
      <button type="button" class="drwp-modal-close">&times;</button>
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
.drwp-list-card{border:1px solid #c3c4c7;border-radius:8px;padding:12px 16px;margin-bottom:12px}
.drwp-list-card>h2{margin:0 0 8px;font-size:.95em;color:#1d2327;border-bottom:1px solid #e5e7eb;padding-bottom:6px}
.drwp-list-search{background:#f0f6fc;border-left:4px solid #2271b1}
.drwp-list-bulk{background:#fefce8;border-left:4px solid #d97706}
.drwp-list-search-form,.drwp-list-bulk-row{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end}
.drwp-list-search-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;width:100%}
.drwp-list-search-input{min-width:200px;flex:1}
.drwp-modal{border:0;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.18);padding:0;max-width:640px;width:90vw}
.drwp-modal-wide{max-width:780px}
.drwp-modal::backdrop{background:rgba(0,0,0,.45)}
.drwp-modal-header{display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-bottom:1px solid #e5e7eb}
.drwp-modal-header h2{margin:0;font-size:1.1em}
.drwp-modal-close{background:transparent;border:0;font-size:1.6em;cursor:pointer;color:#50575e;line-height:1;padding:0 4px}
.drwp-modal-body{padding:16px 20px;max-height:65vh;overflow-y:auto}
.drwp-modal-body .form-table th{width:100px;padding:6px 0}
.drwp-modal-body .form-table td{padding:6px 0}
.drwp-modal-footer{display:flex;gap:8px;align-items:center;padding:12px 20px;border-top:1px solid #e5e7eb;background:#f6f7f7;border-radius:0 0 12px 12px}
</style>

<script>
(function(){
  var rest = <?php echo wp_json_encode([
      'url'   => esc_url_raw(rest_url('drwp/v1')),
      'nonce' => wp_create_nonce('wp_rest'),
  ]); ?>;
  var table = document.getElementById('drwp-ops-table');
  if (!table) return;
  var dlg = document.getElementById('drwp-edit-dialog');

  function api(path,opts){
    opts=opts||{};opts.credentials='same-origin';
    opts.headers=Object.assign({'X-WP-Nonce':rest.nonce},opts.headers||{});
    return fetch(rest.url+path,opts).then(function(r){
      return r.json().then(function(j){if(!r.ok)throw new Error(j.message||'HTTP '+r.status);return j;});
    });
  }

  dlg.addEventListener('click', function(e){
    if (e.target.classList.contains('drwp-modal-close')) dlg.close();
    if (e.target === dlg) dlg.close();
  });

  table.addEventListener('click', function(e){
    var btn = e.target.closest('.drwp-edit-btn');
    if (!btn) return;
    var id = btn.dataset.id;
    document.getElementById('drwp-edit-id').value = id;
    document.getElementById('drwp-edit-status').textContent = '';
    ['drwp-edit-date','drwp-edit-started','drwp-edit-ended'].forEach(function(k){document.getElementById(k).value='';});
    ['drwp-edit-work','drwp-edit-issues','drwp-edit-next'].forEach(function(k){document.getElementById(k).value='';});
    document.getElementById('drwp-edit-project').value = '';
    dlg.showModal();
    api('/reports/'+id).then(function(d){
      document.getElementById('drwp-edit-date').value = d.report_date || '';
      document.getElementById('drwp-edit-project').value = d.project_id || '';
      document.getElementById('drwp-edit-started').value = (d.started_at||'').substring(0,5);
      document.getElementById('drwp-edit-ended').value = (d.ended_at||'').substring(0,5);
      document.getElementById('drwp-edit-work').value = d.work_description || '';
      document.getElementById('drwp-edit-issues').value = d.issues || '';
      document.getElementById('drwp-edit-next').value = d.next_plan || '';
    }).catch(function(err){ document.getElementById('drwp-edit-status').textContent = err.message; });
  });

  document.getElementById('drwp-edit-save').addEventListener('click', function(){
    var id = document.getElementById('drwp-edit-id').value;
    var st = document.getElementById('drwp-edit-status');
    st.textContent = '保存中…'; this.disabled = true; var self = this;
    api('/reports/'+id, {
      method:'PATCH',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({
        report_date: document.getElementById('drwp-edit-date').value,
        project_id: Number(document.getElementById('drwp-edit-project').value)||null,
        started_at: document.getElementById('drwp-edit-started').value||null,
        ended_at: document.getElementById('drwp-edit-ended').value||null,
        work_description: document.getElementById('drwp-edit-work').value,
        issues: document.getElementById('drwp-edit-issues').value,
        next_plan: document.getElementById('drwp-edit-next').value
      })
    }).then(function(){ dlg.close(); location.reload(); })
      .catch(function(err){ st.textContent = err.message; self.disabled = false; });
  });
})();
</script>
