<?php if (!defined('ABSPATH')) exit;

// 予定 admin page — list + add/edit modal + delete.
//
// Operators (`edit_others_posts`) see every plan and can assign
// any worker; workers (`edit_posts` only) see plans they own /
// created and the assignee dropdown is hidden (always themselves).

$project_map = [];
foreach (($projects ?? []) as $p) $project_map[(int) $p->id] = (string) $p->name;
$worker_map = $can_view_all ? $workers : [];
$is_retired = DRWP_User::is_retired();
?>
<div class="wrap">
  <h1><?php esc_html_e('予定', 'drwp-daily-reports'); ?></h1>

  <?php if (!empty($_GET['saved'])): ?>
    <div class="notice notice-success"><p><?php esc_html_e('予定を保存しました。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>
  <?php if (!empty($_GET['deleted'])): ?>
    <div class="notice notice-success"><p><?php esc_html_e('予定を削除しました。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>
  <?php if (!empty($_GET['error']) && $_GET['error'] === 'missing_date'): ?>
    <div class="notice notice-error"><p><?php esc_html_e('日付は必須です。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>
  <?php if ($is_retired): ?>
    <div class="notice notice-warning"><p><?php esc_html_e('このアカウントは退職状態のため、予定の追加・編集はできません。閲覧のみ可能です。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>

  <?php if (!$is_retired): ?>
  <p>
    <button type="button" class="button button-primary" id="drwp-plan-add-btn">
      + <?php esc_html_e('新しい予定を追加', 'drwp-daily-reports'); ?>
    </button>
  </p>
  <?php endif; ?>

  <details class="drwp-card drwp-filter-card" <?php echo array_filter($filters) ? 'open' : ''; ?>>
    <summary><?php esc_html_e('検索・絞り込み', 'drwp-daily-reports'); ?></summary>
    <form method="get" class="drwp-filter-form" style="padding:12px 14px;">
      <input type="hidden" name="page" value="drwp_plans" />
      <div class="drwp-row">
        <input type="search" name="s" value="<?php echo esc_attr($filters['search']); ?>"
               placeholder="<?php esc_attr_e('メモ・案件名検索', 'drwp-daily-reports'); ?>"
               class="drwp-search-input" />
        <select name="project_id">
          <option value="0"><?php esc_html_e('案件すべて', 'drwp-daily-reports'); ?></option>
          <?php foreach ($projects as $p): ?>
            <option value="<?php echo (int) $p->id; ?>" <?php selected((int) $filters['project_id'], (int) $p->id); ?>>
              <?php echo esc_html($p->name); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if ($can_view_all && !empty($workers)): ?>
        <select name="user_id">
          <option value="0"><?php esc_html_e('担当者すべて', 'drwp-daily-reports'); ?></option>
          <?php foreach ($workers as $wid => $wname): ?>
            <option value="<?php echo (int) $wid; ?>" <?php selected((int) $filters['user_id'], (int) $wid); ?>>
              <?php echo esc_html($wname); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <select name="status">
          <option value=""><?php esc_html_e('状態すべて', 'drwp-daily-reports'); ?></option>
          <?php foreach ($statuses as $k => $v): ?>
            <option value="<?php echo esc_attr($k); ?>" <?php selected($filters['status'], $k); ?>>
              <?php echo esc_html($v); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="drwp-row">
        <input type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>" />
        〜
        <input type="date" name="date_to" value="<?php echo esc_attr($filters['date_to']); ?>" />
        <button class="button button-primary"><?php esc_html_e('検索', 'drwp-daily-reports'); ?></button>
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=drwp_plans')); ?>"><?php esc_html_e('クリア', 'drwp-daily-reports'); ?></a>
      </div>
    </form>
  </details>

  <p class="description" style="margin:8px 0;">
    <?php
      printf(
          esc_html(_n('合計 %d 件', '合計 %d 件', count($plans), 'drwp-daily-reports')),
          count($plans)
      );
    ?>
  </p>

  <table class="widefat striped">
    <thead>
      <tr>
        <th>ID</th>
        <th><?php esc_html_e('日付', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('時間', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('案件', 'drwp-daily-reports'); ?></th>
        <?php if ($can_view_all): ?><th><?php esc_html_e('担当', 'drwp-daily-reports'); ?></th><?php endif; ?>
        <th><?php esc_html_e('メモ', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('状態', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('紐づく日報', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('操作', 'drwp-daily-reports'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($plans)): ?>
        <tr><td colspan="<?php echo $can_view_all ? 9 : 8; ?>">
          <?php
            if (array_filter($filters)) {
                esc_html_e('該当する予定が見つかりません。', 'drwp-daily-reports');
            } else {
                esc_html_e('まだ予定がありません。', 'drwp-daily-reports');
            }
          ?>
        </td></tr>
      <?php else: foreach ($plans as $plan):
        $assignee_name = !empty($plan->user_id)
            ? (DRWP_User::display_name((int) $plan->user_id) ?: ('#' . (int) $plan->user_id))
            : '';
        $project_name = $plan->project_id ? ($project_map[(int) $plan->project_id] ?? '#' . (int) $plan->project_id) : '-';
        $time = '';
        if ($plan->started_at) $time .= substr((string) $plan->started_at, 0, 5);
        if ($plan->started_at && $plan->ended_at) $time .= ' 〜 ';
        if ($plan->ended_at) $time .= substr((string) $plan->ended_at, 0, 5);
        $notes_short = mb_substr(wp_strip_all_tags((string) $plan->notes), 0, 40);
        if (mb_strlen(wp_strip_all_tags((string) $plan->notes)) > 40) $notes_short .= '…';
      ?>
        <tr class="drwp-plan-row drwp-plan-status-<?php echo esc_attr($plan->status); ?>">
          <td><?php echo (int) $plan->id; ?></td>
          <td><?php echo esc_html(date_i18n('Y/n/j (D)', strtotime((string) $plan->planned_date))); ?></td>
          <td><?php echo esc_html($time ?: '-'); ?></td>
          <td><?php echo esc_html($project_name); ?></td>
          <?php if ($can_view_all): ?><td><?php echo esc_html($assignee_name ?: '-'); ?></td><?php endif; ?>
          <td><?php echo esc_html($notes_short ?: '-'); ?></td>
          <td><span class="drwp-plan-badge is-<?php echo esc_attr($plan->status); ?>"><?php echo esc_html($statuses[$plan->status] ?? $plan->status); ?></span></td>
          <td>
            <?php if (!empty($plan->linked_report_id)): ?>
              <a href="<?php echo esc_url(admin_url('admin.php?page=drwp_reports')); ?>">
                #<?php echo (int) $plan->linked_report_id; ?>
              </a>
            <?php else: ?>
              -
            <?php endif; ?>
          </td>
          <td style="white-space:nowrap;">
            <?php if (DRWP_Plan::can_edit($plan)): ?>
              <button type="button" class="button button-small drwp-plan-edit-btn"
                      data-id="<?php echo (int) $plan->id; ?>"
                      data-project_id="<?php echo (int) ($plan->project_id ?? 0); ?>"
                      data-user_id="<?php echo (int) ($plan->user_id ?? 0); ?>"
                      data-planned_date="<?php echo esc_attr((string) $plan->planned_date); ?>"
                      data-started_at="<?php echo esc_attr(substr((string) ($plan->started_at ?? ''), 0, 5)); ?>"
                      data-ended_at="<?php echo esc_attr(substr((string) ($plan->ended_at ?? ''), 0, 5)); ?>"
                      data-notes="<?php echo esc_attr($plan->notes ?? ''); ?>"
                      data-status="<?php echo esc_attr($plan->status); ?>"
                      data-linked_report_id="<?php echo (int) ($plan->linked_report_id ?? 0); ?>">
                <?php esc_html_e('編集', 'drwp-daily-reports'); ?>
              </button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <!-- 追加・編集モーダル -->
  <dialog id="drwp-plan-dialog" class="drwp-plan-modal">
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <?php wp_nonce_field('drwp_save_plan'); ?>
      <input type="hidden" name="action" value="drwp_save_plan" />
      <input type="hidden" name="id" id="drwp-plan-id" value="0" />

      <div class="drwp-plan-modal-header">
        <h2 id="drwp-plan-title"><?php esc_html_e('新しい予定を追加', 'drwp-daily-reports'); ?></h2>
        <button type="button" class="drwp-plan-modal-close" aria-label="<?php esc_attr_e('閉じる', 'drwp-daily-reports'); ?>">&times;</button>
      </div>

      <div class="drwp-plan-modal-body">
        <table class="form-table">
          <tr>
            <th><label for="drwp-plan-date"><?php esc_html_e('日付', 'drwp-daily-reports'); ?> <em style="color:#b91c1c;">*</em></label></th>
            <td><input type="date" id="drwp-plan-date" name="planned_date" required /></td>
          </tr>
          <tr>
            <th><label for="drwp-plan-project"><?php esc_html_e('案件', 'drwp-daily-reports'); ?></label></th>
            <td>
              <select id="drwp-plan-project" name="project_id">
                <option value=""><?php esc_html_e('（未設定）', 'drwp-daily-reports'); ?></option>
                <?php foreach ($projects as $p): ?>
                  <option value="<?php echo (int) $p->id; ?>"><?php echo esc_html($p->name); ?></option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
          <tr>
            <th><?php esc_html_e('時刻', 'drwp-daily-reports'); ?></th>
            <td>
              <input type="time" id="drwp-plan-start" name="started_at" />
              〜
              <input type="time" id="drwp-plan-end" name="ended_at" />
            </td>
          </tr>
          <?php if ($can_view_all && !empty($workers)): ?>
          <tr>
            <th><label for="drwp-plan-user"><?php esc_html_e('担当者', 'drwp-daily-reports'); ?></label></th>
            <td>
              <select id="drwp-plan-user" name="user_id">
                <option value=""><?php esc_html_e('（未割当）', 'drwp-daily-reports'); ?></option>
                <?php foreach ($workers as $wid => $wname): ?>
                  <option value="<?php echo (int) $wid; ?>"><?php echo esc_html($wname); ?></option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
          <?php else: ?>
          <tr>
            <th><?php esc_html_e('担当者', 'drwp-daily-reports'); ?></th>
            <td>
              <span><?php echo esc_html(DRWP_User::display_name($current_user) ?: $current_user->user_login); ?></span>
              <p class="description"><?php esc_html_e('自分の予定として保存されます。', 'drwp-daily-reports'); ?></p>
            </td>
          </tr>
          <?php endif; ?>
          <tr>
            <th><label for="drwp-plan-notes"><?php esc_html_e('メモ', 'drwp-daily-reports'); ?></label></th>
            <td><textarea id="drwp-plan-notes" name="notes" rows="3" class="large-text"></textarea></td>
          </tr>
          <tr>
            <th><label for="drwp-plan-status"><?php esc_html_e('状態', 'drwp-daily-reports'); ?></label></th>
            <td>
              <select id="drwp-plan-status" name="status">
                <?php foreach ($statuses as $k => $v): ?>
                  <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($v); ?></option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
          <tr>
            <th><label for="drwp-plan-linked"><?php esc_html_e('紐づく日報', 'drwp-daily-reports'); ?></label></th>
            <td>
              <input type="number" id="drwp-plan-linked" name="linked_report_id" min="0" placeholder="0" style="width:100px;" />
              <p class="description"><?php esc_html_e('日報の ID を入れると紐づきます。同日同案件の候補があれば自動で提案されます。', 'drwp-daily-reports'); ?></p>
              <div id="drwp-plan-linkable" class="drwp-plan-linkable"></div>
            </td>
          </tr>
        </table>
      </div>

      <div class="drwp-plan-modal-footer">
        <button type="submit" class="button button-primary" id="drwp-plan-submit">
          <?php esc_html_e('保存', 'drwp-daily-reports'); ?>
        </button>
        <span class="drwp-plan-modal-spacer"></span>
        <button type="button" class="button button-link-delete" id="drwp-plan-delete-btn" hidden>
          <?php esc_html_e('削除', 'drwp-daily-reports'); ?>
        </button>
        <button type="button" class="button drwp-plan-modal-close">
          <?php esc_html_e('キャンセル', 'drwp-daily-reports'); ?>
        </button>
      </div>
    </form>
  </dialog>

  <!-- 削除フォーム (hidden, submitted by 削除ボタン) -->
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="drwp-plan-delete-form" style="display:none;">
    <?php wp_nonce_field('drwp_delete_plan'); ?>
    <input type="hidden" name="action" value="drwp_delete_plan" />
    <input type="hidden" name="id" id="drwp-plan-delete-id" value="0" />
  </form>
</div>

<style>
.drwp-card{background:#fff;border:1px solid #d1d5db;border-radius:10px;padding:0;margin-bottom:12px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
.drwp-card>summary{cursor:pointer;padding:10px 14px;font-weight:600;color:#1d2327;list-style:none;display:flex;align-items:center;gap:6px}
.drwp-card>summary::before{content:'▸';font-size:.8em;color:#6b7280;transition:transform .15s}
.drwp-card[open]>summary::before{transform:rotate(90deg)}
.drwp-card>summary:hover{color:#2271b1}
.drwp-filter-card{background:#f0f6fc;border-left:4px solid #2271b1}
.drwp-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:8px}
.drwp-row:last-child{margin-bottom:0}
.drwp-search-input{min-width:200px;flex:1}

.drwp-plan-row.drwp-plan-status-cancelled td{opacity:.55}
.drwp-plan-badge{display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:600;background:#e0e7ff;color:#3730a3}
.drwp-plan-badge.is-completed{background:#dcfce7;color:#166534}
.drwp-plan-badge.is-cancelled{background:#f3f4f6;color:#6b7280}

.drwp-plan-modal{border:0;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.18);padding:0;max-width:640px;width:90vw}
.drwp-plan-modal::backdrop{background:rgba(0,0,0,.45)}
.drwp-plan-modal-header{display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-bottom:1px solid #e5e7eb}
.drwp-plan-modal-header h2{margin:0;font-size:1.1em}
.drwp-plan-modal-close{background:transparent;border:0;font-size:1.6em;cursor:pointer;color:#50575e;line-height:1;padding:0 4px}
.drwp-plan-modal-body{padding:16px 20px;max-height:72vh;overflow-y:auto}
.drwp-plan-modal-body .form-table th{width:100px;padding:6px 0;vertical-align:top}
.drwp-plan-modal-body .form-table td{padding:6px 0}
.drwp-plan-modal-footer{display:flex;gap:8px;align-items:center;padding:12px 20px;border-top:1px solid #e5e7eb;background:#f6f7f7;border-radius:0 0 12px 12px}
.drwp-plan-modal-spacer{flex:1}
.drwp-plan-linkable{margin-top:6px;display:flex;gap:6px;flex-wrap:wrap}
.drwp-plan-linkable button{background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:4px 10px;cursor:pointer;font-size:.85em;color:#1d4ed8}
.drwp-plan-linkable button:hover{background:#dbeafe}
.drwp-plan-linkable button.is-picked{background:#1d4ed8;color:#fff;border-color:#1d4ed8}
.drwp-plan-linkable .empty{color:#94a3b8;font-size:.85em}
</style>

<script>
(function () {
  var dlg = document.getElementById('drwp-plan-dialog');
  if (!dlg) return;

  var rest = <?php echo wp_json_encode([
      'url'   => esc_url_raw(rest_url('drwp/v1')),
      'nonce' => wp_create_nonce('wp_rest'),
  ]); ?>;

  var idEl       = document.getElementById('drwp-plan-id');
  var dateEl     = document.getElementById('drwp-plan-date');
  var projectEl  = document.getElementById('drwp-plan-project');
  var startEl    = document.getElementById('drwp-plan-start');
  var endEl      = document.getElementById('drwp-plan-end');
  var userEl     = document.getElementById('drwp-plan-user');
  var notesEl    = document.getElementById('drwp-plan-notes');
  var statusEl   = document.getElementById('drwp-plan-status');
  var linkedEl   = document.getElementById('drwp-plan-linked');
  var linkableEl = document.getElementById('drwp-plan-linkable');
  var titleEl    = document.getElementById('drwp-plan-title');
  var submitEl   = document.getElementById('drwp-plan-submit');
  var deleteBtn  = document.getElementById('drwp-plan-delete-btn');

  var addTitle  = <?php echo wp_json_encode(__('新しい予定を追加', 'drwp-daily-reports')); ?>;
  var editTitle = <?php echo wp_json_encode(__('予定を編集', 'drwp-daily-reports')); ?>;
  var addLabel  = <?php echo wp_json_encode(__('追加', 'drwp-daily-reports')); ?>;
  var saveLabel = <?php echo wp_json_encode(__('更新', 'drwp-daily-reports')); ?>;
  var deleteConfirm = <?php echo wp_json_encode(__('この予定を削除します。よろしいですか？', 'drwp-daily-reports')); ?>;
  var noLinkable = <?php echo wp_json_encode(__('同日同案件の日報候補はありません。', 'drwp-daily-reports')); ?>;

  function clearForm() {
    idEl.value = '0';
    dateEl.value = '';
    projectEl.value = '';
    startEl.value = '';
    endEl.value = '';
    if (userEl) userEl.value = '';
    notesEl.value = '';
    statusEl.value = 'active';
    linkedEl.value = '';
    linkableEl.innerHTML = '';
  }

  function loadLinkable() {
    // Pull reports matching the form's date + project so the
    // operator can pick one with a click instead of typing the
    // report id. Stays empty until both fields are set.
    linkableEl.innerHTML = '';
    var date = dateEl.value;
    var pid  = projectEl.value;
    if (!date || !pid) return;
    fetch(rest.url + '/reports?date_from=' + encodeURIComponent(date) + '&date_to=' + encodeURIComponent(date) + '&project_id=' + encodeURIComponent(pid), {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': rest.nonce }
    }).then(function (r) { return r.json(); }).then(function (j) {
      var items = (j && j.items) ? j.items : [];
      if (!items.length) {
        linkableEl.innerHTML = '<span class="empty">' + noLinkable + '</span>';
        return;
      }
      items.forEach(function (it) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = '#' + it.id + ' (' + (it.author_name || '') + ')';
        if (String(it.id) === String(linkedEl.value)) btn.classList.add('is-picked');
        btn.addEventListener('click', function () {
          linkedEl.value = it.id;
          linkableEl.querySelectorAll('button').forEach(function (b) { b.classList.remove('is-picked'); });
          btn.classList.add('is-picked');
        });
        linkableEl.appendChild(btn);
      });
    }).catch(function () {});
  }

  [dateEl, projectEl].forEach(function (el) {
    el.addEventListener('change', loadLinkable);
  });

  document.getElementById('drwp-plan-add-btn').addEventListener('click', function () {
    clearForm();
    titleEl.textContent = addTitle;
    submitEl.textContent = addLabel;
    deleteBtn.hidden = true;
    dlg.showModal();
    dateEl.focus();
  });

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.drwp-plan-edit-btn');
    if (!btn) return;
    var d = btn.dataset;
    clearForm();
    titleEl.textContent = editTitle + ' (#' + d.id + ')';
    submitEl.textContent = saveLabel;
    idEl.value = d.id;
    dateEl.value = d.planned_date || '';
    projectEl.value = d.project_id && d.project_id !== '0' ? d.project_id : '';
    startEl.value = d.started_at || '';
    endEl.value = d.ended_at || '';
    if (userEl) userEl.value = d.user_id && d.user_id !== '0' ? d.user_id : '';
    notesEl.value = d.notes || '';
    statusEl.value = d.status || 'active';
    linkedEl.value = d.linked_report_id && d.linked_report_id !== '0' ? d.linked_report_id : '';
    deleteBtn.hidden = false;
    dlg.showModal();
    dateEl.focus();
    loadLinkable();
  });

  deleteBtn.addEventListener('click', function () {
    if (idEl.value === '0') return;
    if (!window.confirm(deleteConfirm)) return;
    document.getElementById('drwp-plan-delete-id').value = idEl.value;
    document.getElementById('drwp-plan-delete-form').submit();
  });

  dlg.addEventListener('click', function (e) {
    if (e.target.classList.contains('drwp-plan-modal-close')) dlg.close();
    if (e.target === dlg) dlg.close();
  });
})();
</script>
