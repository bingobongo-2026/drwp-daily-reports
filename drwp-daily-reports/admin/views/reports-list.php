<?php if (!defined('ABSPATH')) exit;

// Precompute display data for each report so JS can render without
// additional round-trips. Photos are still fetched lazily so the
// initial payload stays small.
$review_labels = [
    'pending'        => DRWP_Labels::review_status('pending'),
    'approved'       => DRWP_Labels::review_status('approved'),
    'needs_revision' => DRWP_Labels::review_status('needs_revision'),
    'edit_requested' => DRWP_Labels::review_status('edit_requested'),
];
$reports_json = [];
foreach (($reports ?? []) as $r) {
    $author  = get_userdata((int) $r->user_id);
    $project = !empty($r->project_id) ? DRWP_Project::find((int) $r->project_id) : null;
    $reports_json[] = [
        'id'               => (int) $r->id,
        'report_date'      => (string) $r->report_date,
        'started_at'       => (string) ($r->started_at ?? ''),
        'ended_at'         => (string) ($r->ended_at ?? ''),
        'project_name'     => $project ? (string) $project->name : '',
        'project_id'       => $r->project_id ? (int) $r->project_id : null,
        'author_name'      => $author ? $author->display_name : ('#' . (int) $r->user_id),
        'work_description' => (string) ($r->work_description ?? ''),
        'issues'           => (string) ($r->issues ?? ''),
        'next_plan'        => (string) ($r->next_plan ?? ''),
        'review_status'    => (string) $r->review_status,
    ];
}
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
  <details class="drwp-card drwp-filter-card" <?php echo ($filters['search'] || $filters['review_status'] || $filters['project_id'] || $filters['date_from'] || $filters['date_to']) ? 'open' : ''; ?>>
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
          <option value="0"><?php esc_html_e('現場すべて', 'drwp-daily-reports'); ?></option>
          <?php foreach (($projects ?? []) as $project): ?>
            <option value="<?php echo (int) $project->id; ?>" <?php selected((int) $filters['project_id'], (int) $project->id); ?>><?php echo esc_html($project->name); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="drwp-row">
        <input type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>" />
        〜
        <input type="date" name="date_to" value="<?php echo esc_attr($filters['date_to']); ?>" />
        <button class="button button-primary"><?php esc_html_e('検索', 'drwp-daily-reports'); ?></button>
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=drwp_reports')); ?>"><?php esc_html_e('クリア', 'drwp-daily-reports'); ?></a>
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

  <!-- 一括操作（折りたたみ） -->
  <details class="drwp-card drwp-bulk-card">
    <summary><?php esc_html_e('一括操作', 'drwp-daily-reports'); ?></summary>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="drwp-bulk-form">
      <?php wp_nonce_field('drwp_bulk_reports'); ?>
      <input type="hidden" name="action" value="drwp_bulk_reports" />
      <div class="drwp-row">
        <select name="bulk_action">
          <option value=""><?php esc_html_e('操作を選択', 'drwp-daily-reports'); ?></option>
          <option value="bulk_approve"><?php esc_html_e('一括承認', 'drwp-daily-reports'); ?></option>
          <option value="bulk_revision"><?php esc_html_e('一括差し戻し', 'drwp-daily-reports'); ?></option>
          <option value="bulk_export_csv"><?php esc_html_e('選択した日報をCSV出力', 'drwp-daily-reports'); ?></option>
        </select>
        <button class="button button-primary"><?php esc_html_e('実行', 'drwp-daily-reports'); ?></button>
      </div>
      <div class="drwp-bulk-grid">
        <?php foreach (($reports ?? []) as $r): ?>
          <label class="drwp-bulk-item">
            <input class="drwp-check" type="checkbox" name="report_ids[]" value="<?php echo esc_attr($r->id); ?>" />
            <span>
              #<?php echo (int) $r->id; ?>
              <?php echo esc_html(date_i18n('Y/n/j', strtotime((string) $r->report_date))); ?>
              <?php if (!empty($r->project_id)) {
                  $proj = DRWP_Project::find((int) $r->project_id);
                  if ($proj) echo ' · ' . esc_html($proj->name);
              } ?>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
    </form>
  </details>

  <?php if (empty($reports_json)): ?>
    <div class="drwp-empty"><?php esc_html_e('該当する日報がありません。', 'drwp-daily-reports'); ?></div>
  <?php else: ?>

    <!-- ナビ -->
    <div class="drwp-viewer-nav">
      <button type="button" class="button drwp-nav-btn" id="drwp-prev">← <?php esc_html_e('前', 'drwp-daily-reports'); ?></button>
      <span class="drwp-counter">
        <span id="drwp-cur">1</span> / <span id="drwp-total"><?php echo count($reports_json); ?></span>
      </span>
      <button type="button" class="button drwp-nav-btn" id="drwp-next"><?php esc_html_e('次', 'drwp-daily-reports'); ?> →</button>
      <span class="drwp-nav-hint"><?php esc_html_e('← → キーで切り替え', 'drwp-daily-reports'); ?></span>
      <span class="drwp-nav-spacer"></span>
      <button type="button" class="button" id="drwp-edit-toggle"><?php esc_html_e('編集', 'drwp-daily-reports'); ?></button>
    </div>

    <!-- ページ -->
    <article class="drwp-page" id="drwp-page">
      <!-- 表示モード -->
      <div class="drwp-page-view" id="drwp-page-view">
        <header class="drwp-page-header">
          <div class="drwp-page-date" id="drwp-view-date"></div>
          <div class="drwp-page-meta">
            <span id="drwp-view-project" class="drwp-page-project"></span>
            <span id="drwp-view-author" class="drwp-page-author"></span>
            <span id="drwp-view-time" class="drwp-page-time"></span>
            <span id="drwp-view-status" class="drwp-page-status"></span>
          </div>
        </header>
        <div class="drwp-page-section" id="drwp-view-work-wrap" style="display:none;">
          <h3>作業内容</h3>
          <div class="drwp-page-text" id="drwp-view-work"></div>
        </div>
        <div class="drwp-page-section" id="drwp-view-issues-wrap" style="display:none;">
          <h3>特記事項（反省・連絡・相談・提案）</h3>
          <div class="drwp-page-text" id="drwp-view-issues"></div>
        </div>
        <div class="drwp-page-section" id="drwp-view-next-wrap" style="display:none;">
          <h3>次回予定</h3>
          <div class="drwp-page-text" id="drwp-view-next"></div>
        </div>
        <div class="drwp-page-section" id="drwp-view-photos-wrap" style="display:none;">
          <h3>写真</h3>
          <div class="drwp-page-photos" id="drwp-view-photos"></div>
        </div>
      </div>

      <!-- 編集モード -->
      <div class="drwp-page-edit" id="drwp-page-edit" style="display:none;">
        <table class="form-table" role="presentation">
          <tr>
            <th>日付</th>
            <td><input type="date" id="drwp-edit-date" /></td>
          </tr>
          <tr>
            <th>現場</th>
            <td>
              <select id="drwp-edit-project">
                <option value="">（未設定）</option>
                <?php foreach (($projects ?? []) as $p): ?>
                  <option value="<?php echo (int) $p->id; ?>"><?php echo esc_html($p->name); ?></option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
          <tr>
            <th>時刻</th>
            <td><input type="time" id="drwp-edit-started" /> 〜 <input type="time" id="drwp-edit-ended" /></td>
          </tr>
          <tr>
            <th>作業内容</th>
            <td><textarea id="drwp-edit-work" rows="4" class="large-text"></textarea></td>
          </tr>
          <tr>
            <th>特記事項</th>
            <td><textarea id="drwp-edit-issues" rows="3" class="large-text"></textarea></td>
          </tr>
          <tr>
            <th>次回予定</th>
            <td><textarea id="drwp-edit-next" rows="3" class="large-text"></textarea></td>
          </tr>
        </table>
        <div class="drwp-edit-actions">
          <button type="button" class="button button-primary" id="drwp-edit-save">保存</button>
          <button type="button" class="button" id="drwp-edit-cancel">キャンセル</button>
          <span id="drwp-edit-status"></span>
        </div>
      </div>
    </article>

    <!-- レビュー操作（常時表示） -->
    <?php if (current_user_can('edit_others_posts')): ?>
    <div class="drwp-card drwp-review-card">
      <h3>レビュー操作</h3>
      <div class="drwp-row">
        <select id="drwp-review-status">
          <?php foreach ($review_labels as $k => $v): ?>
            <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($v); ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" id="drwp-review-comment" placeholder="コメント（任意）" class="drwp-review-input" />
        <button type="button" class="button button-primary" id="drwp-review-submit">レビュー送信</button>
        <span id="drwp-review-msg"></span>
      </div>
    </div>
    <?php endif; ?>

    <!-- コメント（常時表示） -->
    <div class="drwp-card drwp-comments-card">
      <h3>コメント</h3>
      <div id="drwp-comments-list"><p class="description">読み込み中…</p></div>
      <div class="drwp-row">
        <textarea id="drwp-comment-body" rows="2" class="large-text" placeholder="コメントを入力…"></textarea>
        <button type="button" class="button" id="drwp-comment-submit" style="white-space:nowrap;">送信</button>
      </div>
    </div>
  <?php endif; ?>

  <?php
  if ($pages > 1):
      $base = add_query_arg(
          array_merge(
              ['page' => 'drwp_reports'],
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
</div>

<style>
.drwp-card{background:#fff;border:1px solid #d1d5db;border-radius:10px;padding:0;margin-bottom:12px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
.drwp-card>summary{cursor:pointer;padding:10px 14px;font-weight:600;color:#1d2327;list-style:none;display:flex;align-items:center;gap:6px}
.drwp-card>summary::before{content:'▸';font-size:.8em;color:#6b7280;transition:transform .15s}
.drwp-card[open]>summary::before{transform:rotate(90deg)}
.drwp-card>summary:hover{color:#2271b1}
.drwp-card>.drwp-filter-form,.drwp-card>form,.drwp-card>.drwp-row,.drwp-card>h3,.drwp-card>p,.drwp-card>div{padding:0 14px 12px}
.drwp-card>h3{padding-top:10px;margin:0 0 6px;font-size:.95em;color:#1d2327;border-bottom:1px solid #f1f5f9;padding-bottom:6px}
.drwp-filter-card{background:#f0f6fc;border-left:4px solid #2271b1}
.drwp-bulk-card{background:#fefce8;border-left:4px solid #d97706}
.drwp-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:8px}
.drwp-search-input{min-width:240px;flex:1}
.drwp-bulk-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px;padding:0 14px 12px;max-height:160px;overflow-y:auto}
.drwp-bulk-item{display:flex;gap:6px;align-items:center;font-size:.85em;color:#374151;padding:2px 4px;border-radius:4px}
.drwp-bulk-item:hover{background:#f9fafb}
.drwp-counter-line{margin:8px 0;color:#64748b;font-size:.9em}
.drwp-empty{background:#fff;border:1px dashed #cbd5e1;border-radius:10px;padding:32px;text-align:center;color:#94a3b8}

.drwp-viewer-nav{display:flex;gap:8px;align-items:center;background:#fff;border:1px solid #d1d5db;border-radius:10px;padding:8px 14px;margin-bottom:8px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
.drwp-counter{font-size:.95em;color:#374151;font-weight:600;min-width:60px;text-align:center}
.drwp-nav-hint{font-size:.8em;color:#94a3b8;margin-left:8px}
.drwp-nav-spacer{flex:1}

.drwp-page{background:#fffefb;border:1px solid #d4d4d8;border-radius:6px;padding:32px 40px;margin-bottom:12px;box-shadow:0 4px 12px rgba(0,0,0,.06),0 1px 3px rgba(0,0,0,.04);position:relative;min-height:300px;background-image:linear-gradient(to bottom,#fffefb 0,#fffefb 100%);transition:opacity .25s ease, transform .25s ease}
.drwp-page::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px;background:linear-gradient(to bottom,#e5e7eb,#cbd5e1);border-top-left-radius:6px;border-bottom-left-radius:6px}
.drwp-page.flipping{opacity:0;transform:translateX(8px)}
.drwp-page-header{border-bottom:2px solid #1f2937;padding-bottom:10px;margin-bottom:18px}
.drwp-page-date{font-size:1.6em;font-weight:700;color:#0f172a;letter-spacing:.5px;font-family:"Hiragino Mincho ProN","Yu Mincho","Noto Serif JP",serif}
.drwp-page-meta{margin-top:6px;font-size:.9em;color:#475569;display:flex;gap:12px;flex-wrap:wrap;align-items:center}
.drwp-page-project{font-weight:600;color:#1f2937}
.drwp-page-status{padding:2px 10px;border-radius:999px;font-size:.8em;font-weight:600;background:#f1f5f9;color:#475569}
.drwp-page-status.is-approved{background:#dcfce7;color:#166534}
.drwp-page-status.is-needs_revision{background:#fee2e2;color:#991b1b}
.drwp-page-status.is-edit_requested{background:#fef3c7;color:#92400e}
.drwp-page-status.is-pending{background:#e0e7ff;color:#3730a3}
.drwp-page-section{margin-top:18px}
.drwp-page-section h3{font-size:1em;margin:0 0 6px;color:#1f2937;border-left:4px solid #2271b1;padding:2px 0 2px 10px;font-weight:600}
.drwp-page-text{white-space:pre-wrap;line-height:1.7;color:#1f2937;padding-left:14px}
.drwp-page-photos{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;padding-left:14px}
.drwp-page-photos figure{margin:0}
.drwp-page-photos img{width:100%;aspect-ratio:1;object-fit:cover;border-radius:4px;border:1px solid #e5e7eb;display:block}
.drwp-page-photos figcaption{font-size:.8em;color:#64748b;margin-top:3px}

.drwp-page-edit .form-table th{width:100px;padding:8px 10px 8px 0;font-weight:600}
.drwp-page-edit .form-table td{padding:8px 0}
.drwp-edit-actions{display:flex;gap:8px;align-items:center;margin-top:12px;padding-top:12px;border-top:1px solid #e5e7eb}

.drwp-review-card{background:#f0fdf4;border-left:4px solid #16a34a}
.drwp-review-input{flex:1;min-width:200px}
.drwp-comments-card{background:#fff}
.drwp-comment-item{padding:8px 0;border-bottom:1px solid #f1f5f9}
.drwp-comment-meta{font-size:.8em;color:#64748b;margin-bottom:2px}
.drwp-comment-body{white-space:pre-wrap;font-size:.92em;color:#1f2937}
</style>

<script>
(function(){
  var reports = <?php echo wp_json_encode($reports_json); ?>;
  if (!reports || !reports.length) return;
  var rest = <?php echo wp_json_encode([
      'url'      => esc_url_raw(rest_url('drwp/v1')),
      'nonce'    => wp_create_nonce('wp_rest'),
      'labels'   => $review_labels,
  ]); ?>;

  var idx = 0;
  var editMode = false;
  var page = document.getElementById('drwp-page');
  var view = document.getElementById('drwp-page-view');
  var edit = document.getElementById('drwp-page-edit');
  var counter = document.getElementById('drwp-cur');
  var editBtn = document.getElementById('drwp-edit-toggle');

  function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
  function api(path,opts){
    opts=opts||{};opts.credentials='same-origin';
    opts.headers=Object.assign({'X-WP-Nonce':rest.nonce},opts.headers||{});
    return fetch(rest.url+path,opts).then(function(r){
      return r.json().then(function(j){if(!r.ok)throw new Error(j.message||'HTTP '+r.status);return j;});
    });
  }

  function formatDate(d){
    if(!d)return '';
    var dt = new Date(d+'T00:00:00');
    if(isNaN(dt))return d;
    var w = ['日','月','火','水','木','金','土'][dt.getDay()];
    return dt.getFullYear()+'年'+(dt.getMonth()+1)+'月'+dt.getDate()+'日（'+w+'）';
  }
  function setSectionText(wrapId, textId, value){
    var wrap = document.getElementById(wrapId);
    var text = document.getElementById(textId);
    if (value && value.trim()) {
      text.textContent = value;
      wrap.style.display = '';
    } else {
      wrap.style.display = 'none';
    }
  }

  function renderView(r){
    document.getElementById('drwp-view-date').textContent = formatDate(r.report_date);
    document.getElementById('drwp-view-project').textContent = r.project_name || '（現場未設定）';
    document.getElementById('drwp-view-author').textContent = r.author_name ? ('作成: ' + r.author_name) : '';
    var time = '';
    if (r.started_at) time += r.started_at.substring(0,5);
    if (r.started_at && r.ended_at) time += ' 〜 ';
    if (r.ended_at) time += r.ended_at.substring(0,5);
    document.getElementById('drwp-view-time').textContent = time ? ('時刻: ' + time) : '';
    var status = document.getElementById('drwp-view-status');
    status.textContent = rest.labels[r.review_status] || r.review_status;
    status.className = 'drwp-page-status is-' + r.review_status;
    setSectionText('drwp-view-work-wrap','drwp-view-work',r.work_description);
    setSectionText('drwp-view-issues-wrap','drwp-view-issues',r.issues);
    setSectionText('drwp-view-next-wrap','drwp-view-next',r.next_plan);
    // Photos: lazy fetch
    document.getElementById('drwp-view-photos').innerHTML = '';
    document.getElementById('drwp-view-photos-wrap').style.display = 'none';
    api('/reports/'+r.id).then(function(d){
      // Stale check: user may have flipped pages already
      if (reports[idx].id !== r.id) return;
      if (d.photos && d.photos.length){
        var html = '';
        d.photos.forEach(function(p){
          html += '<figure><img src="'+esc(p.url)+'" alt="" />'+(p.caption?'<figcaption>'+esc(p.caption)+'</figcaption>':'')+'</figure>';
        });
        document.getElementById('drwp-view-photos').innerHTML = html;
        document.getElementById('drwp-view-photos-wrap').style.display = '';
      }
      // Refresh edit form values from latest server data
      if (editMode) populateEdit(d);
    }).catch(function(){});
    // Reset review/comment UI
    var reviewSel = document.getElementById('drwp-review-status');
    if (reviewSel) reviewSel.value = r.review_status || 'pending';
    var reviewCmt = document.getElementById('drwp-review-comment');
    if (reviewCmt) reviewCmt.value = '';
    var reviewMsg = document.getElementById('drwp-review-msg');
    if (reviewMsg) { reviewMsg.textContent = ''; reviewMsg.style.color = ''; }
    loadComments(r.id);
  }

  function populateEdit(r){
    document.getElementById('drwp-edit-date').value = r.report_date || '';
    document.getElementById('drwp-edit-project').value = r.project_id || '';
    document.getElementById('drwp-edit-started').value = (r.started_at||'').substring(0,5);
    document.getElementById('drwp-edit-ended').value = (r.ended_at||'').substring(0,5);
    document.getElementById('drwp-edit-work').value = r.work_description || '';
    document.getElementById('drwp-edit-issues').value = r.issues || '';
    document.getElementById('drwp-edit-next').value = r.next_plan || '';
  }

  function go(newIdx){
    if (newIdx < 0 || newIdx >= reports.length) return;
    // exit edit mode on flip
    if (editMode) toggleEdit(false);
    page.classList.add('flipping');
    setTimeout(function(){
      idx = newIdx;
      counter.textContent = (idx + 1);
      renderView(reports[idx]);
      page.classList.remove('flipping');
    }, 180);
  }

  function toggleEdit(on){
    editMode = on !== undefined ? on : !editMode;
    view.style.display = editMode ? 'none' : '';
    edit.style.display = editMode ? '' : 'none';
    editBtn.textContent = editMode ? '編集を閉じる' : '編集';
    document.getElementById('drwp-edit-status').textContent = '';
    if (editMode) populateEdit(reports[idx]);
  }

  document.getElementById('drwp-prev').addEventListener('click', function(){ go(idx-1); });
  document.getElementById('drwp-next').addEventListener('click', function(){ go(idx+1); });
  document.addEventListener('keydown', function(e){
    if (e.target.matches('input, textarea, select')) return;
    if (e.key === 'ArrowLeft') { e.preventDefault(); go(idx-1); }
    else if (e.key === 'ArrowRight') { e.preventDefault(); go(idx+1); }
  });

  editBtn.addEventListener('click', function(){ toggleEdit(); });
  document.getElementById('drwp-edit-cancel').addEventListener('click', function(){ toggleEdit(false); });
  document.getElementById('drwp-edit-save').addEventListener('click', function(){
    var st = document.getElementById('drwp-edit-status');
    st.textContent = '保存中…'; st.style.color = '';
    var self = this; self.disabled = true;
    var r = reports[idx];
    api('/reports/'+r.id, {
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
    }).then(function(d){
      // Merge updated fields back into local reports array
      r.report_date = d.report_date; r.started_at = d.started_at; r.ended_at = d.ended_at;
      r.project_id = d.project_id;
      r.work_description = d.work_description; r.issues = d.issues; r.next_plan = d.next_plan;
      if (d.project_id) {
        var sel = document.getElementById('drwp-edit-project');
        var opt = sel.querySelector('option[value="'+d.project_id+'"]');
        r.project_name = opt ? opt.textContent : '';
      } else { r.project_name = ''; }
      st.textContent = '保存しました'; st.style.color = '#166534';
      self.disabled = false;
      toggleEdit(false);
      renderView(r);
    }).catch(function(err){ st.textContent = err.message; st.style.color = '#991b1b'; self.disabled = false; });
  });

  function loadComments(id){
    var list = document.getElementById('drwp-comments-list');
    if (!list) return;
    list.innerHTML = '<p class="description">読み込み中…</p>';
    api('/reports/'+id+'/comments').then(function(r){
      if (reports[idx].id !== id) return;
      var items = r.items || [];
      if (!items.length) { list.innerHTML = '<p class="description">コメントはありません。</p>'; return; }
      var html = '';
      items.forEach(function(c){
        html += '<div class="drwp-comment-item"><div class="drwp-comment-meta">'+esc(c.display_name||('#'+c.user_id))+' — '+esc(c.created_at)+'</div><div class="drwp-comment-body">'+esc(c.body)+'</div></div>';
      });
      list.innerHTML = html;
    }).catch(function(){ list.innerHTML = '<p class="description" style="color:#991b1b;">コメントの読み込みに失敗しました。</p>'; });
  }

  var reviewSubmit = document.getElementById('drwp-review-submit');
  if (reviewSubmit) {
    reviewSubmit.addEventListener('click', function(){
      var r = reports[idx];
      var msg = document.getElementById('drwp-review-msg');
      msg.textContent = '送信中…'; msg.style.color = ''; this.disabled = true; var self = this;
      api('/reports/'+r.id+'/review', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({
          review_status: document.getElementById('drwp-review-status').value,
          comment: document.getElementById('drwp-review-comment').value
        })
      }).then(function(){
        msg.textContent = '送信しました'; msg.style.color = '#166534'; self.disabled = false;
        r.review_status = document.getElementById('drwp-review-status').value;
        document.getElementById('drwp-review-comment').value = '';
        // Refresh status badge
        var status = document.getElementById('drwp-view-status');
        status.textContent = rest.labels[r.review_status] || r.review_status;
        status.className = 'drwp-page-status is-' + r.review_status;
        loadComments(r.id);
      }).catch(function(err){ msg.textContent = err.message; msg.style.color = '#991b1b'; self.disabled = false; });
    });
  }

  document.getElementById('drwp-comment-submit').addEventListener('click', function(){
    var r = reports[idx];
    var bodyEl = document.getElementById('drwp-comment-body');
    var text = bodyEl.value.trim(); if (!text) return;
    this.disabled = true; var self = this;
    api('/reports/'+r.id+'/comments', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({body:text})
    }).then(function(){ bodyEl.value=''; self.disabled = false; loadComments(r.id); })
      .catch(function(){ self.disabled = false; });
  });

  // Initial render
  renderView(reports[0]);
})();
</script>
