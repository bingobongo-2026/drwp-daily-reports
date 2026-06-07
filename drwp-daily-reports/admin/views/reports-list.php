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
      <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=drwp_operations')); ?>"><?php esc_html_e('日報操作へ', 'drwp-daily-reports'); ?></a>
    </div>

    <!-- ページ（紙面風） -->
    <article class="drwp-page" id="drwp-page">
      <div class="drwp-page-view" id="drwp-page-view">
        <div class="drwp-page-title">作業日報</div>

        <table class="drwp-page-meta">
          <colgroup>
            <col class="drwp-meta-col-head" />
            <col class="drwp-meta-col-val" />
            <col class="drwp-meta-col-head" />
            <col class="drwp-meta-col-val" />
          </colgroup>
          <tr>
            <th>案件名</th>
            <td colspan="3" id="drwp-view-project"></td>
          </tr>
          <tr>
            <th>日付</th>
            <td id="drwp-view-date"></td>
            <th>作業時間</th>
            <td id="drwp-view-time"></td>
          </tr>
          <tr>
            <th>報告者</th>
            <td id="drwp-view-author"></td>
            <th>レビュー</th>
            <td><span id="drwp-view-status" class="drwp-page-status"></span></td>
          </tr>
        </table>

        <table class="drwp-page-section">
          <tr><th class="drwp-page-section-head">作業内容</th></tr>
          <tr><td class="drwp-page-section-body"><div class="drwp-page-text" id="drwp-view-work"></div></td></tr>
        </table>
        <table class="drwp-page-section">
          <tr><th class="drwp-page-section-head">特記事項（反省・連絡・相談・提案）</th></tr>
          <tr><td class="drwp-page-section-body"><div class="drwp-page-text" id="drwp-view-issues"></div></td></tr>
        </table>
        <table class="drwp-page-section">
          <tr><th class="drwp-page-section-head">次回予定</th></tr>
          <tr><td class="drwp-page-section-body"><div class="drwp-page-text" id="drwp-view-next"></div></td></tr>
        </table>
        <table class="drwp-page-section">
          <tr><th class="drwp-page-section-head">写真</th></tr>
          <tr><td class="drwp-page-section-body"><div class="drwp-page-photos" id="drwp-view-photos"></div></td></tr>
        </table>
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
.drwp-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:8px}
.drwp-search-input{min-width:240px;flex:1}
.drwp-cal-wrap{padding:0 14px 12px}
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
.drwp-cal-day.has-reports::after{content:'';position:absolute;left:50%;bottom:2px;transform:translateX(-50%);width:4px;height:4px;border-radius:50%;background:#2271b1}
.drwp-cal-day.today{outline:1px solid #2271b1}
.drwp-cal-day.in-range{background:#dbeafe}
.drwp-cal-day.range-edge{background:#2271b1;color:#fff}
.drwp-cal-day.range-edge::after{background:#fff}
.drwp-counter-line{margin:8px 0;color:#64748b;font-size:.9em}
.drwp-empty{background:#fff;border:1px dashed #cbd5e1;border-radius:10px;padding:32px;text-align:center;color:#94a3b8}

.drwp-viewer-nav{display:flex;gap:8px;align-items:center;background:#fff;border:1px solid #d1d5db;border-radius:10px;padding:8px 14px;margin-bottom:8px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
.drwp-counter{font-size:.95em;color:#374151;font-weight:600;min-width:60px;text-align:center}
.drwp-nav-hint{font-size:.8em;color:#94a3b8;margin-left:8px}
.drwp-nav-spacer{flex:1}

.drwp-page{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:24px 28px;margin-bottom:12px;box-shadow:0 2px 6px rgba(0,0,0,.06);font-family:"Hiragino Sans","Yu Gothic","Noto Sans JP",sans-serif;color:#1f2937;transition:opacity .25s ease, transform .25s ease}
.drwp-page.flipping{opacity:0;transform:translateX(8px)}
.drwp-page-title{text-align:center;font-size:1.3em;font-weight:700;background:#e5e7eb;border:1px solid #1f2937;padding:6px 0;margin-bottom:14px}
.drwp-page-meta{width:100%;border-collapse:collapse;margin-bottom:14px;table-layout:fixed}
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
.drwp-page-section-body{border:1px solid #1f2937;padding:12px 14px;vertical-align:top;min-height:80px}
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

  // Initial cursor: month of date_from, else date_to, else current
  var init = parseDate(fromEl.value) || parseDate(toEl.value) || new Date();
  var cursor = startOfMonth(init.getFullYear(), init.getMonth());
  var pendingStart = null; // first click for range mode

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
      // Start fresh selection: single day
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

  // Sync calendar when user edits date inputs manually
  [fromEl, toEl].forEach(function(el){
    el.addEventListener('change', function(){ pendingStart = null; render(); });
  });

  render();
})();
</script>

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
  var page = document.getElementById('drwp-page');
  var counter = document.getElementById('drwp-cur');

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
  function renderView(r){
    document.getElementById('drwp-view-date').textContent = formatDate(r.report_date);
    document.getElementById('drwp-view-project').textContent = r.project_name || '（案件未設定）';
    document.getElementById('drwp-view-author').textContent = r.author_name || '';
    var time = '';
    if (r.started_at) time += r.started_at.substring(0,5);
    if (r.started_at && r.ended_at) time += ' 〜 ';
    if (r.ended_at) time += r.ended_at.substring(0,5);
    document.getElementById('drwp-view-time').textContent = time;
    var status = document.getElementById('drwp-view-status');
    status.textContent = rest.labels[r.review_status] || r.review_status;
    status.className = 'drwp-page-status is-' + r.review_status;
    document.getElementById('drwp-view-work').textContent = r.work_description || '';
    document.getElementById('drwp-view-issues').textContent = r.issues || '';
    document.getElementById('drwp-view-next').textContent = r.next_plan || '';
    // Photos: lazy fetch (常時表示、空のときは空欄)
    document.getElementById('drwp-view-photos').innerHTML = '';
    api('/reports/'+r.id).then(function(d){
      // Stale check: user may have flipped pages already
      if (reports[idx].id !== r.id) return;
      if (d.photos && d.photos.length){
        var html = '';
        d.photos.forEach(function(p){
          html += '<figure><img src="'+esc(p.url)+'" alt="" />'+(p.caption?'<figcaption>'+esc(p.caption)+'</figcaption>':'')+'</figure>';
        });
        document.getElementById('drwp-view-photos').innerHTML = html;
      }
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

  function go(newIdx){
    if (newIdx < 0 || newIdx >= reports.length) return;
    page.classList.add('flipping');
    setTimeout(function(){
      idx = newIdx;
      counter.textContent = (idx + 1);
      renderView(reports[idx]);
      page.classList.remove('flipping');
    }, 180);
  }

  document.getElementById('drwp-prev').addEventListener('click', function(){ go(idx-1); });
  document.getElementById('drwp-next').addEventListener('click', function(){ go(idx+1); });
  document.addEventListener('keydown', function(e){
    if (e.target.matches('input, textarea, select')) return;
    if (e.key === 'ArrowLeft') { e.preventDefault(); go(idx-1); }
    else if (e.key === 'ArrowRight') { e.preventDefault(); go(idx+1); }
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
