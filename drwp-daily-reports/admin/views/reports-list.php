<?php if (!defined('ABSPATH')) exit; ?>
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

  <!-- ============================================================
       検索・絞り込み
       ============================================================ -->
  <div class="drwp-list-card drwp-list-search">
    <h2><?php esc_html_e('検索・絞り込み', 'drwp-daily-reports'); ?></h2>
    <form method="get" class="drwp-list-search-form">
      <input type="hidden" name="page" value="drwp_reports" />
      <div class="drwp-list-search-row">
        <input type="search" name="s" value="<?php echo esc_attr($filters['search']); ?>" placeholder="<?php esc_attr_e('本文・公開タイトル・タグ検索', 'drwp-daily-reports'); ?>" class="drwp-list-search-input" />
        <select name="review_status">
          <option value=""><?php esc_html_e('レビュー状態すべて', 'drwp-daily-reports'); ?></option>
          <?php
          $review_labels = [
              'pending'        => DRWP_Labels::review_status('pending'),
              'approved'       => DRWP_Labels::review_status('approved'),
              'needs_revision' => DRWP_Labels::review_status('needs_revision'),
              'edit_requested' => DRWP_Labels::review_status('edit_requested'),
          ];
          foreach ($review_labels as $k => $v): ?>
            <option value="<?php echo esc_attr($k); ?>" <?php selected($filters['review_status'], $k); ?>><?php echo esc_html($v); ?></option>
          <?php endforeach; ?>
        </select>
        <select name="post_status">
          <option value=""><?php esc_html_e('投稿状態すべて', 'drwp-daily-reports'); ?></option>
          <?php
          $post_status_labels = [
              'draft'   => DRWP_Labels::post_status('draft'),
              'pending' => DRWP_Labels::post_status('pending'),
              'future'  => DRWP_Labels::post_status('future'),
              'publish' => DRWP_Labels::post_status('publish'),
          ];
          foreach ($post_status_labels as $k => $v): ?>
            <option value="<?php echo esc_attr($k); ?>" <?php selected($filters['post_status'], $k); ?>><?php echo esc_html($v); ?></option>
          <?php endforeach; ?>
        </select>
        <select name="project_id">
          <option value="0"><?php esc_html_e('現場すべて', 'drwp-daily-reports'); ?></option>
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
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=drwp_reports')); ?>"><?php esc_html_e('クリア', 'drwp-daily-reports'); ?></a>
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

    <!-- ============================================================
         一括操作
         ============================================================ -->
    <div class="drwp-list-card drwp-list-bulk">
      <h2><?php esc_html_e('一括操作', 'drwp-daily-reports'); ?></h2>
      <div class="drwp-list-bulk-row">
        <select name="bulk_action" id="drwp-bulk-action-select">
          <option value=""><?php esc_html_e('操作を選択', 'drwp-daily-reports'); ?></option>
          <option value="bulk_approve"><?php esc_html_e('一括承認', 'drwp-daily-reports'); ?></option>
          <option value="bulk_revision"><?php esc_html_e('一括差し戻し', 'drwp-daily-reports'); ?></option>
          <option value="bulk_convert"><?php esc_html_e('一括で記事作成/更新', 'drwp-daily-reports'); ?></option>
          <option value="bulk_update_publish"><?php esc_html_e('一括で公開設定を更新', 'drwp-daily-reports'); ?></option>
          <option value="bulk_export_csv"><?php esc_html_e('選択した日報をCSV出力', 'drwp-daily-reports'); ?></option>
        </select>
        <button class="button button-primary"><?php esc_html_e('実行', 'drwp-daily-reports'); ?></button>
      </div>

      <div id="drwp-bulk-publish-opts" class="drwp-list-bulk-sub" style="display:none;">
        <p class="drwp-bulk-sub-label"><?php esc_html_e('記事作成/公開設定に使う値:', 'drwp-daily-reports'); ?></p>
        <div class="drwp-list-bulk-row">
          <label>
            <span><?php esc_html_e('テンプレート', 'drwp-daily-reports'); ?></span>
            <select name="bulk_post_template">
              <?php foreach (DRWP_Labels::post_template_options() as $key => $label): ?>
                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <span><?php esc_html_e('カテゴリ', 'drwp-daily-reports'); ?></span>
            <?php
              wp_dropdown_categories([
                'show_option_all' => __('カテゴリを選択', 'drwp-daily-reports'),
                'hide_empty'      => 0,
                'name'            => 'bulk_post_category_id',
                'selected'        => 0,
                'taxonomy'        => 'category',
                'value_field'     => 'term_id',
              ]);
            ?>
          </label>
          <label>
            <span><?php esc_html_e('タグ', 'drwp-daily-reports'); ?></span>
            <input type="text" name="bulk_post_tags" placeholder="<?php esc_attr_e('カンマ区切り', 'drwp-daily-reports'); ?>" />
          </label>
          <label>
            <span><?php esc_html_e('投稿状態', 'drwp-daily-reports'); ?></span>
            <select name="bulk_post_status">
              <option value="draft"><?php echo esc_html(DRWP_Labels::post_status('draft')); ?></option>
              <option value="pending"><?php echo esc_html(DRWP_Labels::post_status('pending')); ?></option>
              <option value="future"><?php echo esc_html(DRWP_Labels::post_status('future')); ?></option>
            </select>
          </label>
          <label>
            <span><?php esc_html_e('予約日時', 'drwp-daily-reports'); ?></span>
            <input type="datetime-local" name="bulk_scheduled_at" />
          </label>
        </div>
      </div>
    </div>

    <table class="widefat striped" id="drwp-reports-table">
      <thead>
        <tr>
          <th><input type="checkbox" onclick="document.querySelectorAll('.drwp-check').forEach(cb => cb.checked = this.checked)" /></th>
          <th>ID</th>
          <th><?php esc_html_e('日付', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('作成者', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('現場', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('公開タイトル', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('レビュー', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('投稿状態', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('記事', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('操作', 'drwp-daily-reports'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($reports)): ?>
          <tr><td colspan="10"><?php esc_html_e('データがありません。', 'drwp-daily-reports'); ?></td></tr>
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
            <td><?php echo esc_html($report->public_title ?: __('（未設定）', 'drwp-daily-reports')); ?></td>
            <td><?php echo esc_html(DRWP_Labels::review_status((string) $report->review_status)); ?></td>
            <td><?php echo esc_html(DRWP_Labels::post_status((string) ($report->post_status ?: 'draft'))); ?></td>
            <td><?php echo $report->linked_post_id ? '<a href="' . esc_url(get_edit_post_link((int) $report->linked_post_id)) . '">#' . esc_html($report->linked_post_id) . '</a>' : '-'; ?></td>
            <td style="white-space:nowrap;">
              <button type="button" class="button button-small drwp-view-btn" data-id="<?php echo (int) $report->id; ?>"><?php esc_html_e('内容確認', 'drwp-daily-reports'); ?></button>
              <button type="button" class="button button-small drwp-edit-btn" data-id="<?php echo (int) $report->id; ?>"><?php esc_html_e('編集', 'drwp-daily-reports'); ?></button>
              <?php if (current_user_can('publish_posts')): ?>
                <button type="button" class="button button-small drwp-convert-btn" data-id="<?php echo (int) $report->id; ?>"><?php echo $report->linked_post_id ? esc_html__('記事更新', 'drwp-daily-reports') : esc_html__('投稿', 'drwp-daily-reports'); ?></button>
              <?php endif; ?>
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
                      's'             => $filters['search'],
                      'review_status' => $filters['review_status'],
                      'post_status'   => $filters['post_status'],
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

  <!-- ============================================================
       内容確認モーダル — read-only view
       ============================================================ -->
  <dialog id="drwp-view-dialog" class="drwp-modal drwp-modal-wide">
    <div class="drwp-modal-header">
      <h2><?php esc_html_e('日報 内容確認', 'drwp-daily-reports'); ?></h2>
      <button type="button" class="drwp-modal-close">&times;</button>
    </div>
    <div class="drwp-modal-body" id="drwp-view-body"><p>読み込み中…</p></div>
    <div class="drwp-modal-footer">
      <button type="button" class="button drwp-modal-close"><?php esc_html_e('閉じる', 'drwp-daily-reports'); ?></button>
    </div>
  </dialog>

  <!-- ============================================================
       編集モーダル — quick edit via REST PATCH
       ============================================================ -->
  <dialog id="drwp-edit-dialog" class="drwp-modal drwp-modal-wide">
    <div class="drwp-modal-header">
      <h2><?php esc_html_e('日報を編集', 'drwp-daily-reports'); ?></h2>
      <button type="button" class="drwp-modal-close">&times;</button>
    </div>
    <div class="drwp-modal-body">
      <input type="hidden" id="drwp-edit-id" />
      <table class="form-table" role="presentation">
        <tr>
          <th><?php esc_html_e('日付', 'drwp-daily-reports'); ?></th>
          <td><input type="date" id="drwp-edit-date" /></td>
        </tr>
        <tr>
          <th><?php esc_html_e('現場', 'drwp-daily-reports'); ?></th>
          <td>
            <select id="drwp-edit-project">
              <option value=""><?php esc_html_e('（未設定）', 'drwp-daily-reports'); ?></option>
              <?php foreach (($projects ?? []) as $p): ?>
                <option value="<?php echo (int) $p->id; ?>"><?php echo esc_html($p->name); ?></option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>
        <tr>
          <th><?php esc_html_e('時刻', 'drwp-daily-reports'); ?></th>
          <td>
            <input type="time" id="drwp-edit-started" /> 〜 <input type="time" id="drwp-edit-ended" />
          </td>
        </tr>
        <tr>
          <th><?php esc_html_e('作業内容', 'drwp-daily-reports'); ?></th>
          <td><textarea id="drwp-edit-work" rows="4" class="large-text"></textarea></td>
        </tr>
        <tr>
          <th><?php esc_html_e('問題点', 'drwp-daily-reports'); ?></th>
          <td><textarea id="drwp-edit-issues" rows="2" class="large-text"></textarea></td>
        </tr>
        <tr>
          <th><?php esc_html_e('次回予定', 'drwp-daily-reports'); ?></th>
          <td><textarea id="drwp-edit-next" rows="2" class="large-text"></textarea></td>
        </tr>
        <tr>
          <th><?php esc_html_e('公開タイトル', 'drwp-daily-reports'); ?></th>
          <td><input type="text" id="drwp-edit-title" class="regular-text" /></td>
        </tr>
      </table>
    </div>
    <div class="drwp-modal-footer">
      <button type="button" class="button button-primary" id="drwp-edit-save"><?php esc_html_e('保存', 'drwp-daily-reports'); ?></button>
      <button type="button" class="button drwp-modal-close"><?php esc_html_e('キャンセル', 'drwp-daily-reports'); ?></button>
      <span id="drwp-edit-status" style="margin-left:12px;"></span>
    </div>
  </dialog>

  <!-- ============================================================
       投稿モーダル — publish settings + convert
       ============================================================ -->
  <dialog id="drwp-convert-dialog" class="drwp-modal drwp-modal-wide">
    <div class="drwp-modal-header">
      <h2><?php esc_html_e('記事を作成 / 更新', 'drwp-daily-reports'); ?></h2>
      <button type="button" class="drwp-modal-close">&times;</button>
    </div>
    <div class="drwp-modal-body">
      <input type="hidden" id="drwp-conv-id" />
      <table class="form-table" role="presentation">
        <tr>
          <th><?php esc_html_e('公開タイトル', 'drwp-daily-reports'); ?></th>
          <td><input type="text" id="drwp-conv-title" class="regular-text" /></td>
        </tr>
        <tr>
          <th><?php esc_html_e('テンプレート', 'drwp-daily-reports'); ?></th>
          <td>
            <select id="drwp-conv-template">
              <?php foreach (DRWP_Labels::post_template_options() as $key => $label): ?>
                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>
        <tr>
          <th><?php esc_html_e('カテゴリ', 'drwp-daily-reports'); ?></th>
          <td>
            <?php
              wp_dropdown_categories([
                'show_option_all' => __('カテゴリを選択', 'drwp-daily-reports'),
                'hide_empty'      => 0,
                'name'            => 'conv_post_category_id',
                'id'              => 'drwp-conv-category',
                'selected'        => 0,
                'taxonomy'        => 'category',
                'value_field'     => 'term_id',
              ]);
            ?>
          </td>
        </tr>
        <tr>
          <th><?php esc_html_e('タグ', 'drwp-daily-reports'); ?></th>
          <td><input type="text" id="drwp-conv-tags" class="regular-text" placeholder="<?php esc_attr_e('カンマ区切り', 'drwp-daily-reports'); ?>" /></td>
        </tr>
        <tr>
          <th><?php esc_html_e('投稿状態', 'drwp-daily-reports'); ?></th>
          <td>
            <select id="drwp-conv-post-status">
              <option value="draft"><?php echo esc_html(DRWP_Labels::post_status('draft')); ?></option>
              <option value="pending"><?php echo esc_html(DRWP_Labels::post_status('pending')); ?></option>
              <option value="future"><?php echo esc_html(DRWP_Labels::post_status('future')); ?></option>
            </select>
          </td>
        </tr>
        <tr>
          <th><?php esc_html_e('予約日時', 'drwp-daily-reports'); ?></th>
          <td><input type="datetime-local" id="drwp-conv-scheduled" /></td>
        </tr>
      </table>
      <p id="drwp-conv-linked" class="description" style="display:none;"></p>
    </div>
    <div class="drwp-modal-footer">
      <button type="button" class="button button-primary" id="drwp-conv-submit"><?php esc_html_e('記事を作成', 'drwp-daily-reports'); ?></button>
      <button type="button" class="button drwp-modal-close"><?php esc_html_e('キャンセル', 'drwp-daily-reports'); ?></button>
      <span id="drwp-conv-status" style="margin-left:12px;"></span>
    </div>
  </dialog>

  <style>
  .drwp-list-card{border:1px solid #c3c4c7;border-radius:8px;padding:12px 16px;margin-bottom:12px}
  .drwp-list-card>h2{margin:0 0 8px;font-size:.95em;color:#1d2327;border-bottom:1px solid #e5e7eb;padding-bottom:6px}
  .drwp-list-search{background:#f0f6fc;border-left:4px solid #2271b1}
  .drwp-list-bulk{background:#fefce8;border-left:4px solid #d97706}
  .drwp-list-search-form,.drwp-list-bulk-row{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end}
  .drwp-list-bulk-sub{margin-top:10px;padding-top:10px;border-top:1px dashed #d97706}
  .drwp-bulk-sub-label{margin:0 0 6px;font-size:.85em;color:#92400e;font-weight:600}
  .drwp-list-bulk-row label{display:flex;flex-direction:column;gap:2px;min-width:120px}
  .drwp-list-bulk-row label>span{font-size:.8em;color:#50575e;font-weight:600}
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
  .drwp-view-text{white-space:pre-wrap}
  .drwp-view-photos{display:flex;gap:8px;flex-wrap:wrap;margin-top:4px}
  .drwp-view-photos img{width:100px;height:100px;object-fit:cover;border-radius:4px;border:1px solid #e5e7eb}
  </style>

  <script>
  (function(){
    var sel=document.getElementById('drwp-bulk-action-select');
    var opts=document.getElementById('drwp-bulk-publish-opts');
    if(!sel||!opts)return;
    function toggle(){
      var v=sel.value;
      opts.style.display=(v==='bulk_convert'||v==='bulk_update_publish')?'':'none';
    }
    sel.addEventListener('change',toggle);
    toggle();
  })();
  </script>

  <script>
  (function(){
    var rest = <?php echo wp_json_encode([
        'url'      => esc_url_raw(rest_url('drwp/v1')),
        'nonce'    => wp_create_nonce('wp_rest'),
        'projects' => (object) DRWP_Admin::project_map_public(),
        'labels'   => [
            'pending'        => DRWP_Labels::review_status('pending'),
            'approved'       => DRWP_Labels::review_status('approved'),
            'needs_revision' => DRWP_Labels::review_status('needs_revision'),
            'edit_requested' => DRWP_Labels::review_status('edit_requested'),
        ],
    ]); ?>;
    var table=document.getElementById('drwp-reports-table');
    if(!table)return;
    var viewDlg=document.getElementById('drwp-view-dialog');
    var editDlg=document.getElementById('drwp-edit-dialog');
    var convDlg=document.getElementById('drwp-convert-dialog');

    function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
    function api(path,opts){
      opts=opts||{};opts.credentials='same-origin';
      opts.headers=Object.assign({'X-WP-Nonce':rest.nonce},opts.headers||{});
      return fetch(rest.url+path,opts).then(function(r){
        return r.json().then(function(j){if(!r.ok)throw new Error(j.message||'HTTP '+r.status);return j;});
      });
    }

    [viewDlg,editDlg,convDlg].forEach(function(dlg){
      if(!dlg)return;
      dlg.addEventListener('click',function(e){
        if(e.target.classList.contains('drwp-modal-close'))dlg.close();
        if(e.target===dlg)dlg.close();
      });
    });

    /* ---- 内容確認モーダル ---- */
    table.addEventListener('click',function(e){
      var vb=e.target.closest('.drwp-view-btn');
      if(!vb)return;
      var id=vb.dataset.id;
      var body=document.getElementById('drwp-view-body');
      body.innerHTML='<p>読み込み中…</p>';
      viewDlg.showModal();
      api('/reports/'+id).then(function(d){
        var time='';
        if(d.started_at)time+=d.started_at.substring(0,5);
        if(d.started_at&&d.ended_at)time+=' — ';
        if(d.ended_at)time+=d.ended_at.substring(0,5);
        var h='<table class="form-table drwp-view-table">';
        h+='<tr><th>日付</th><td>'+esc(d.report_date)+'</td></tr>';
        h+='<tr><th>現場</th><td>'+esc(d.project_id?(rest.projects&&rest.projects[d.project_id]||'#'+d.project_id):'（未設定）')+'</td></tr>';
        if(time)h+='<tr><th>時刻</th><td>'+esc(time)+'</td></tr>';
        h+='<tr><th>レビュー</th><td>'+esc(rest.labels&&rest.labels[d.review_status]||d.review_status)+'</td></tr>';
        h+='<tr><th>作業内容</th><td class="drwp-view-text">'+esc(d.work_description||'')+'</td></tr>';
        if(d.issues)h+='<tr><th>問題点</th><td class="drwp-view-text">'+esc(d.issues)+'</td></tr>';
        if(d.next_plan)h+='<tr><th>次回予定</th><td class="drwp-view-text">'+esc(d.next_plan)+'</td></tr>';
        if(d.public_title)h+='<tr><th>公開タイトル</th><td>'+esc(d.public_title)+'</td></tr>';
        h+='</table>';
        body.innerHTML=h;
      }).catch(function(err){body.innerHTML='<p style="color:#991b1b;">'+esc(err.message)+'</p>';});
    });

    /* ---- 編集モーダル ---- */
    table.addEventListener('click',function(e){
      var eb=e.target.closest('.drwp-edit-btn');
      if(!eb)return;
      var id=eb.dataset.id;
      document.getElementById('drwp-edit-id').value=id;
      document.getElementById('drwp-edit-status').textContent='';
      ['drwp-edit-date','drwp-edit-started','drwp-edit-ended','drwp-edit-title'].forEach(function(k){document.getElementById(k).value='';});
      ['drwp-edit-work','drwp-edit-issues','drwp-edit-next'].forEach(function(k){document.getElementById(k).value='';});
      document.getElementById('drwp-edit-project').value='';
      editDlg.showModal();
      api('/reports/'+id).then(function(d){
        document.getElementById('drwp-edit-date').value=d.report_date||'';
        document.getElementById('drwp-edit-project').value=d.project_id||'';
        document.getElementById('drwp-edit-started').value=(d.started_at||'').substring(0,5);
        document.getElementById('drwp-edit-ended').value=(d.ended_at||'').substring(0,5);
        document.getElementById('drwp-edit-work').value=d.work_description||'';
        document.getElementById('drwp-edit-issues').value=d.issues||'';
        document.getElementById('drwp-edit-next').value=d.next_plan||'';
        document.getElementById('drwp-edit-title').value=d.public_title||'';
      }).catch(function(err){document.getElementById('drwp-edit-status').textContent=err.message;});
    });

    document.getElementById('drwp-edit-save').addEventListener('click',function(){
      var id=document.getElementById('drwp-edit-id').value;
      var st=document.getElementById('drwp-edit-status');
      st.textContent='保存中…';this.disabled=true;var self=this;
      api('/reports/'+id,{
        method:'PATCH',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({
          report_date:document.getElementById('drwp-edit-date').value,
          project_id:Number(document.getElementById('drwp-edit-project').value)||null,
          started_at:document.getElementById('drwp-edit-started').value||null,
          ended_at:document.getElementById('drwp-edit-ended').value||null,
          work_description:document.getElementById('drwp-edit-work').value,
          issues:document.getElementById('drwp-edit-issues').value,
          next_plan:document.getElementById('drwp-edit-next').value,
          public_title:document.getElementById('drwp-edit-title').value
        })
      }).then(function(){editDlg.close();location.reload();})
        .catch(function(err){st.textContent=err.message;self.disabled=false;});
    });

    /* ---- 投稿モーダル ---- */
    if(convDlg){
      table.addEventListener('click',function(e){
        var cb=e.target.closest('.drwp-convert-btn');
        if(!cb)return;
        var id=cb.dataset.id;
        document.getElementById('drwp-conv-id').value=id;
        document.getElementById('drwp-conv-status').textContent='';
        document.getElementById('drwp-conv-submit').disabled=false;
        document.getElementById('drwp-conv-title').value='';
        document.getElementById('drwp-conv-tags').value='';
        document.getElementById('drwp-conv-template').value='standard';
        document.getElementById('drwp-conv-category').value='0';
        document.getElementById('drwp-conv-post-status').value='draft';
        document.getElementById('drwp-conv-scheduled').value='';
        document.getElementById('drwp-conv-linked').style.display='none';
        convDlg.showModal();
        api('/reports/'+id).then(function(d){
          document.getElementById('drwp-conv-title').value=d.public_title||'';
          document.getElementById('drwp-conv-tags').value=d.post_tags||'';
          if(d.post_template)document.getElementById('drwp-conv-template').value=d.post_template;
          if(d.post_category_id)document.getElementById('drwp-conv-category').value=d.post_category_id;
          if(d.post_status)document.getElementById('drwp-conv-post-status').value=d.post_status;
          if(d.scheduled_at)document.getElementById('drwp-conv-scheduled').value=d.scheduled_at.substring(0,16);
          if(d.linked_post_id){
            var el=document.getElementById('drwp-conv-linked');
            el.innerHTML='連携記事: #'+esc(String(d.linked_post_id))+' — 記事を更新します。';
            el.style.display='';
            document.getElementById('drwp-conv-submit').textContent='記事を更新';
          } else {
            document.getElementById('drwp-conv-submit').textContent='記事を作成';
          }
        }).catch(function(err){document.getElementById('drwp-conv-status').textContent=err.message;});
      });

      document.getElementById('drwp-conv-submit').addEventListener('click',function(){
        var id=document.getElementById('drwp-conv-id').value;
        var st=document.getElementById('drwp-conv-status');
        st.textContent='処理中…';this.disabled=true;var self=this;
        api('/reports/'+id+'/convert',{
          method:'POST',
          headers:{'Content-Type':'application/json'},
          body:JSON.stringify({
            public_title:document.getElementById('drwp-conv-title').value,
            post_template:document.getElementById('drwp-conv-template').value,
            post_category_id:document.getElementById('drwp-conv-category').value,
            post_tags:document.getElementById('drwp-conv-tags').value,
            post_status:document.getElementById('drwp-conv-post-status').value,
            scheduled_at:document.getElementById('drwp-conv-scheduled').value||null
          })
        }).then(function(){convDlg.close();location.reload();})
          .catch(function(err){st.textContent=err.message;self.disabled=false;});
      });
    }
  })();
  </script>

</div>
