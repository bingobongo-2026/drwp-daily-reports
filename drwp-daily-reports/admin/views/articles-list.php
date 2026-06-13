<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1><?php esc_html_e('記事作成', 'drwp-daily-reports'); ?></h1>
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

  <p class="description"><?php esc_html_e('承認済みの日報のみ表示されます。', 'drwp-daily-reports'); ?></p>

  <!-- 検索・絞り込み — details で折りたたみ、条件があれば自動展開 -->
  <details class="drwp-list-filter" <?php echo ($filters['search'] || $filters['post_status'] || $filters['project_id'] || $filters['date_from'] || $filters['date_to']) ? 'open' : ''; ?>>
    <summary class="drwp-list-filter-summary"><?php esc_html_e('検索・絞り込み', 'drwp-daily-reports'); ?></summary>
    <form method="get" class="drwp-list-filter-form">
      <input type="hidden" name="page" value="drwp_articles" />
      <div class="drwp-list-filter-row">
        <input type="search" name="s" value="<?php echo esc_attr($filters['search']); ?>" placeholder="<?php esc_attr_e('本文・公開タイトル・タグ検索', 'drwp-daily-reports'); ?>" class="drwp-list-filter-input" />
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
          <option value="0"><?php esc_html_e('案件すべて', 'drwp-daily-reports'); ?></option>
          <?php foreach (($projects ?? []) as $project): ?>
            <option value="<?php echo (int) $project->id; ?>" <?php selected((int) $filters['project_id'], (int) $project->id); ?>><?php echo esc_html($project->name); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="drwp-list-filter-row">
        <input type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>" />
        <span>〜</span>
        <input type="date" name="date_to" value="<?php echo esc_attr($filters['date_to']); ?>" />
        <button class="button button-primary"><?php esc_html_e('検索', 'drwp-daily-reports'); ?></button>
        <a class="button-link" href="<?php echo esc_url(admin_url('admin.php?page=drwp_articles')); ?>"><?php esc_html_e('クリア', 'drwp-daily-reports'); ?></a>
      </div>
    </form>
  </details>

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
    <input type="hidden" name="redirect_page" value="drwp_articles" />

    <div class="drwp-list-bulk-inline">
      <label for="drwp-bulk-action-select"><?php esc_html_e('一括操作:', 'drwp-daily-reports'); ?></label>
      <select name="bulk_action" id="drwp-bulk-action-select">
        <option value=""><?php esc_html_e('操作を選択', 'drwp-daily-reports'); ?></option>
        <option value="bulk_convert"><?php esc_html_e('一括で記事作成/更新', 'drwp-daily-reports'); ?></option>
        <option value="bulk_update_publish"><?php esc_html_e('一括で公開設定を更新', 'drwp-daily-reports'); ?></option>
        <option value="bulk_export_csv"><?php esc_html_e('選択した日報をCSV出力', 'drwp-daily-reports'); ?></option>
      </select>
      <button class="button"><?php esc_html_e('実行', 'drwp-daily-reports'); ?></button>
    </div>

    <details id="drwp-bulk-publish-opts" class="drwp-list-bulk-sub" style="display:none;">
      <summary class="drwp-list-bulk-sub-summary"><?php esc_html_e('記事作成/公開設定に使う値', 'drwp-daily-reports'); ?></summary>
      <div class="drwp-list-bulk-sub-row">
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
    </details>

    <table class="widefat striped" id="drwp-articles-table">
      <thead>
        <tr>
          <th><input type="checkbox" onclick="document.querySelectorAll('.drwp-check').forEach(cb => cb.checked = this.checked)" /></th>
          <th>ID</th>
          <th><?php esc_html_e('日付', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('作成者', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('案件', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('公開タイトル', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('投稿状態', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('記事', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('操作', 'drwp-daily-reports'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($reports)): ?>
          <tr><td colspan="9"><?php esc_html_e('承認済みの日報がありません。', 'drwp-daily-reports'); ?></td></tr>
        <?php else: foreach ($reports as $report): ?>
          <tr>
            <td><input class="drwp-check" type="checkbox" name="report_ids[]" value="<?php echo esc_attr($report->id); ?>" /></td>
            <td><?php echo esc_html($report->id); ?></td>
            <td><?php echo esc_html(date_i18n('Y年n月j日', strtotime((string) $report->report_date))); ?></td>
            <td><?php
              echo esc_html(DRWP_User::display_name((int) $report->user_id) ?: ('#' . (int) $report->user_id));
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
            <td><?php
              $display_post_status = $report->post_status ?: 'draft';
              if ($report->linked_post_id) {
                  $linked = get_post((int) $report->linked_post_id);
                  if ($linked) {
                      $display_post_status = $linked->post_status;
                  }
              }
              echo esc_html(DRWP_Labels::post_status((string) $display_post_status));
            ?></td>
            <td><?php echo $report->linked_post_id ? '<a href="' . esc_url(get_edit_post_link((int) $report->linked_post_id)) . '">#' . esc_html($report->linked_post_id) . '</a>' : '-'; ?></td>
            <td style="white-space:nowrap;">
              <button type="button" class="button button-small drwp-article-btn" data-id="<?php echo (int) $report->id; ?>"><?php echo $report->linked_post_id ? esc_html__('記事更新', 'drwp-daily-reports') : esc_html__('記事作成', 'drwp-daily-reports'); ?></button>
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
              ['page' => 'drwp_articles'],
              array_filter(
                  [
                      's'           => $filters['search'],
                      'post_status' => $filters['post_status'],
                      'project_id'  => $filters['project_id'] ?: '',
                      'date_from'   => $filters['date_from'],
                      'date_to'     => $filters['date_to'],
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
       記事作成モーダル — view + content editor + publish settings
       ============================================================ -->
  <dialog id="drwp-article-dialog" class="drwp-modal drwp-modal-wide">
    <div class="drwp-modal-header">
      <h2><?php esc_html_e('記事を作成 / 更新', 'drwp-daily-reports'); ?></h2>
      <button type="button" class="drwp-modal-close">&times;</button>
    </div>
    <div class="drwp-modal-body">
      <input type="hidden" id="drwp-conv-id" />

      <div class="drwp-article-section">
        <h3><?php esc_html_e('日報の内容', 'drwp-daily-reports'); ?></h3>
        <div id="drwp-view-body"><p>読み込み中…</p></div>
      </div>

      <div class="drwp-article-section">
        <h3><?php esc_html_e('記事の本文', 'drwp-daily-reports'); ?></h3>
        <table class="form-table" role="presentation">
          <tr>
            <th><?php esc_html_e('公開タイトル', 'drwp-daily-reports'); ?></th>
            <td>
              <div style="display:flex;gap:8px;align-items:center;">
                <input type="text" id="drwp-conv-title" class="large-text" />
                <button type="button" class="button button-small" id="drwp-dup-check" style="white-space:nowrap;"><?php esc_html_e('重複を確認', 'drwp-daily-reports'); ?></button>
              </div>
              <div id="drwp-dup-result" style="margin-top:4px;"></div>
            </td>
          </tr>
          <tr>
            <th><?php esc_html_e('導入文', 'drwp-daily-reports'); ?></th>
            <td><textarea id="drwp-conv-intro" rows="3" class="large-text"></textarea></td>
          </tr>
          <tr>
            <th><?php esc_html_e('本文', 'drwp-daily-reports'); ?></th>
            <td>
              <?php wp_editor('', 'drwp-conv-body', [
                  'textarea_rows' => 10,
                  'media_buttons' => true,
                  'teeny'         => false,
                  'quicktags'     => true,
                  'tinymce'       => ['init_instance_callback' => 'function(e){e.hide()}'],
              ]); ?>
            </td>
          </tr>
          <tr>
            <th><?php esc_html_e('今後の予定', 'drwp-daily-reports'); ?></th>
            <td><textarea id="drwp-conv-next-plan" rows="3" class="large-text"></textarea></td>
          </tr>
        </table>
      </div>

      <div class="drwp-article-section">
        <h3><?php esc_html_e('投稿設定', 'drwp-daily-reports'); ?></h3>
        <table class="form-table" role="presentation">
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
    </div>
    <div class="drwp-modal-footer">
      <button type="button" class="button button-primary" id="drwp-conv-submit"><?php esc_html_e('記事を作成', 'drwp-daily-reports'); ?></button>
      <button type="button" class="button drwp-modal-close"><?php esc_html_e('キャンセル', 'drwp-daily-reports'); ?></button>
      <span id="drwp-conv-status" style="margin-left:12px;"></span>
    </div>
  </dialog>

  <style>
  /* 検索・絞り込み — details で折りたたみ、アクセントカラー無しの
     薄いグレー枠だけで囲って補助領域感を出す。 */
  .drwp-list-filter{margin-bottom:10px;border:1px solid #e5e7eb;border-radius:6px;background:#fff}
  .drwp-list-filter-summary{cursor:pointer;font-weight:600;color:#1d2327;list-style:none;display:flex;align-items:center;gap:6px;padding:8px 12px}
  .drwp-list-filter-summary::-webkit-details-marker{display:none}
  .drwp-list-filter-summary::before{content:'▸';font-size:.8em;color:#6b7280;transition:transform .15s}
  .drwp-list-filter[open] .drwp-list-filter-summary{border-bottom:1px solid #f1f5f9}
  .drwp-list-filter[open] .drwp-list-filter-summary::before{transform:rotate(90deg)}
  .drwp-list-filter-summary:hover{color:#2271b1}
  .drwp-list-filter-form{padding:10px 12px}
  .drwp-list-filter-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;width:100%;margin-bottom:8px}
  .drwp-list-filter-row:last-child{margin-bottom:0}
  .drwp-list-filter-input{min-width:200px;flex:1}

  /* 一括操作 — カード化はやめてテーブル直上のインライン行に。
     公開設定の追加項目だけ details で畳んでおく。 */
  .drwp-list-bulk-inline{display:flex;align-items:center;gap:8px;margin-bottom:8px;padding:6px 0;font-size:.92em;color:#475569}
  .drwp-list-bulk-inline label{font-weight:600;color:#1d2327}
  .drwp-list-bulk-sub{margin-bottom:10px;padding:0 0 0 4px;border-left:2px solid #e5e7eb}
  .drwp-list-bulk-sub-summary{cursor:pointer;font-size:.88em;color:#475569;font-weight:600;padding:4px 8px;list-style:none}
  .drwp-list-bulk-sub-summary::-webkit-details-marker{display:none}
  .drwp-list-bulk-sub-summary::before{content:'▸';font-size:.8em;color:#6b7280;margin-right:4px;transition:transform .15s}
  .drwp-list-bulk-sub[open] .drwp-list-bulk-sub-summary::before{transform:rotate(90deg)}
  .drwp-list-bulk-sub-row{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;padding:4px 8px 8px}
  .drwp-list-bulk-sub-row label{display:flex;flex-direction:column;gap:2px;min-width:120px}
  .drwp-list-bulk-sub-row label>span{font-size:.8em;color:#50575e;font-weight:600}
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
  .drwp-article-section{margin-bottom:16px;padding-bottom:4px;border-bottom:1px solid #e5e7eb}
  .drwp-article-section:last-child{border-bottom:0;margin-bottom:0}
  .drwp-article-section>h3{margin:0 0 8px;font-size:.95em;color:#1d2327}
  .drwp-article-section .drwp-ref-table th{font-weight:600;color:#50575e;font-size:.85em;padding:3px 8px 3px 0;vertical-align:top;white-space:nowrap}
  .drwp-article-section .drwp-ref-table td{padding:3px 0;font-size:.9em}
  </style>

  <script>
  (function(){
    var sel=document.getElementById('drwp-bulk-action-select');
    var opts=document.getElementById('drwp-bulk-publish-opts');
    if(!sel||!opts)return;
    function toggle(){
      var v=sel.value;
      var show=(v==='bulk_convert'||v==='bulk_update_publish');
      opts.style.display=show?'':'none';
      // Auto-expand when relevant so the operator doesn't have to
      // click the summary every time. Leave alone when hidden.
      if(show) opts.open=true;
    }
    sel.addEventListener('change',toggle);
    toggle();
  })();
  </script>

  <script>
  (function(){
    var rest = <?php echo wp_json_encode([
        'url'       => esc_url_raw(rest_url('drwp/v1')),
        'wpurl'     => esc_url_raw(rest_url('wp/v2')),
        'nonce'     => wp_create_nonce('wp_rest'),
        'projects'  => (object) DRWP_Admin::project_map_public(),
        'locations' => (object) DRWP_Admin::project_location_map(),
    ]); ?>;
    var table=document.getElementById('drwp-articles-table');
    if(!table)return;
    var dlg=document.getElementById('drwp-article-dialog');

    function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
    function api(path,opts){
      opts=opts||{};opts.credentials='same-origin';
      opts.headers=Object.assign({'X-WP-Nonce':rest.nonce},opts.headers||{});
      return fetch(rest.url+path,opts).then(function(r){
        return r.json().then(function(j){
          if (!r.ok) {
            // WP_Error の code / data を JS 側でも参照できるようにして、
            // ライセンスエラー時にはインラインで「設定を開く」ボタンを
            // 出せるようにする。
            var er = new Error(j.message || 'HTTP ' + r.status);
            er.code = j.code || '';
            er.data = j.data || {};
            throw er;
          }
          return j;
        });
      });
    }

    function renderApiError(target, err) {
      target.innerHTML = '';
      if (err && err.code === 'drwp_license' && err.data && err.data.settings_url) {
        var msg = document.createElement('span');
        msg.textContent = '<?php echo esc_js(__('ライセンスがアクティブではありません。ライセンスサーバの状態を確認してください。', 'drwp-daily-reports')); ?>';
        msg.style.color = '#991b1b';
        target.appendChild(msg);
        var link = document.createElement('a');
        link.href = err.data.settings_url;
        link.className = 'button button-small';
        link.style.marginLeft = '8px';
        link.textContent = '<?php echo esc_js(__('ライセンス設定を開く', 'drwp-daily-reports')); ?>';
        target.appendChild(link);
        return;
      }
      target.style.color = '#991b1b';
      target.textContent = err && err.message ? err.message : '';
    }

    var editorId='drwp-conv-body';

    function setEditorContent(content){
      var ed=typeof tinymce!=='undefined'&&tinymce.get(editorId);
      if(ed){ed.setContent(content||'');}
      document.getElementById(editorId).value=content||'';
    }
    function getEditorContent(){
      var ed=typeof tinymce!=='undefined'&&tinymce.get(editorId);
      if(ed)ed.save();
      return document.getElementById(editorId).value;
    }
    function showEditor(){
      var ed=typeof tinymce!=='undefined'&&tinymce.get(editorId);
      if(ed)ed.show();
    }

    if(dlg){
      dlg.addEventListener('click',function(e){
        if(e.target.classList.contains('drwp-modal-close'))dlg.close();
        if(e.target===dlg)dlg.close();
      });
    }

    /* ---- 重複確認 ---- */
    document.getElementById('drwp-dup-check').addEventListener('click',function(){
      var title=document.getElementById('drwp-conv-title').value.trim();
      var res=document.getElementById('drwp-dup-result');
      if(!title){res.innerHTML='<span style="color:#991b1b;">タイトルを入力してください</span>';return;}
      res.innerHTML='<span>確認中…</span>';
      var headers={'X-WP-Nonce':rest.nonce};
      var enc=encodeURIComponent(title);
      Promise.all([
        fetch(rest.wpurl+'/posts?search='+enc+'&per_page=100&status=publish,draft,pending,future,private',{headers:headers,credentials:'same-origin'}).then(function(r){return r.json();}),
        fetch(rest.wpurl+'/pages?search='+enc+'&per_page=100&status=publish,draft,pending,future,private',{headers:headers,credentials:'same-origin'}).then(function(r){return r.json();})
      ]).then(function(results){
        var all=[].concat(results[0]||[],results[1]||[]);
        var dupes=all.filter(function(p){
          var t=(p.title&&p.title.rendered||'').replace(/&amp;/g,'&').replace(/&#039;/g,"'").replace(/&quot;/g,'"').replace(/&lt;/g,'<').replace(/&gt;/g,'>');
          return t===title;
        });
        if(dupes.length){
          var links=dupes.map(function(p){return '<a href="'+esc(p.link)+'" target="_blank">#'+p.id+' '+esc(p.title.rendered)+'</a>';});
          res.innerHTML='<span style="color:#991b1b;font-weight:600;">⚠ 同じタイトルの記事が '+dupes.length+' 件あります:</span><br>'+links.join('<br>');
        } else {
          res.innerHTML='<span style="color:#166534;">重複なし</span>';
        }
      }).catch(function(){res.innerHTML='<span style="color:#991b1b;">確認に失敗しました</span>';});
    });

    /* ---- モーダルを開く ---- */
    table.addEventListener('click',function(e){
      var btn=e.target.closest('.drwp-article-btn');
      if(!btn)return;
      var id=btn.dataset.id;
      document.getElementById('drwp-conv-id').value=id;
      document.getElementById('drwp-conv-status').textContent='';
      document.getElementById('drwp-conv-submit').disabled=false;
      document.getElementById('drwp-conv-title').value='';
      document.getElementById('drwp-dup-result').innerHTML='';
      document.getElementById('drwp-conv-intro').value='';
      setEditorContent('');
      document.getElementById('drwp-conv-next-plan').value='';
      document.getElementById('drwp-conv-tags').value='';
      document.getElementById('drwp-conv-template').value='standard';
      document.getElementById('drwp-conv-category').value='0';
      document.getElementById('drwp-conv-post-status').value='draft';
      document.getElementById('drwp-conv-scheduled').value='';
      document.getElementById('drwp-conv-linked').style.display='none';
      var viewBody=document.getElementById('drwp-view-body');
      viewBody.innerHTML='<p>読み込み中…</p>';
      dlg.showModal();
      setTimeout(showEditor,50);
      api('/reports/'+id).then(function(d){
        var time='';
        if(d.started_at)time+=d.started_at.substring(0,5);
        if(d.started_at&&d.ended_at)time+=' — ';
        if(d.ended_at)time+=d.ended_at.substring(0,5);
        var h='<table class="drwp-ref-table">';
        h+='<tr><th>日付</th><td>'+esc(d.report_date)+'</td></tr>';
        h+='<tr><th>案件</th><td>'+esc(d.project_id?(rest.projects&&rest.projects[d.project_id]||'#'+d.project_id):'（未設定）')+'</td></tr>';
        if(time)h+='<tr><th>時刻</th><td>'+esc(time)+'</td></tr>';
        h+='<tr><th>作業内容</th><td class="drwp-view-text">'+esc(d.work_description||'')+'</td></tr>';
        if(d.issues)h+='<tr><th>特記事項</th><td class="drwp-view-text">'+esc(d.issues)+'</td></tr>';
        if(d.next_plan)h+='<tr><th>次回予定</th><td class="drwp-view-text">'+esc(d.next_plan)+'</td></tr>';
        h+='</table>';
        if(d.photos&&d.photos.length){
          h+='<div class="drwp-view-photos">';
          d.photos.forEach(function(p){
            h+='<figure><img src="'+esc(p.url)+'" alt="" />'+(p.caption?'<figcaption>'+esc(p.caption)+'</figcaption>':'')+'</figure>';
          });
          h+='</div>';
        }
        viewBody.innerHTML=h;
        var autoTitle=d.public_title||'';
        if(!autoTitle&&d.project_id&&rest.locations&&rest.locations[d.project_id]){
          autoTitle=rest.locations[d.project_id];
        }
        document.getElementById('drwp-conv-title').value=autoTitle;
        document.getElementById('drwp-conv-intro').value=d.public_intro||'';
        setEditorContent(d.public_body||(d.work_description||''));
        document.getElementById('drwp-conv-next-plan').value=d.public_next_plan||'';
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
      }).catch(function(err){viewBody.innerHTML='<p style="color:#991b1b;">'+esc(err.message)+'</p>';});
    });

    /* ---- 記事作成/更新 ---- */
    document.getElementById('drwp-conv-submit').addEventListener('click',function(){
      var id=document.getElementById('drwp-conv-id').value;
      var st=document.getElementById('drwp-conv-status');
      st.textContent='処理中…';st.style.color='';this.disabled=true;var self=this;
      api('/reports/'+id+'/convert',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({
          public_title:document.getElementById('drwp-conv-title').value,
          public_intro:document.getElementById('drwp-conv-intro').value,
          public_body:getEditorContent(),
          public_next_plan:document.getElementById('drwp-conv-next-plan').value,
          post_template:document.getElementById('drwp-conv-template').value,
          post_category_id:document.getElementById('drwp-conv-category').value,
          post_tags:document.getElementById('drwp-conv-tags').value,
          post_status:document.getElementById('drwp-conv-post-status').value,
          scheduled_at:document.getElementById('drwp-conv-scheduled').value||null
        })
      }).then(function(){dlg.close();location.reload();})
        .catch(function(err){renderApiError(st,err);self.disabled=false;});
    });
  })();
  </script>

</div>
