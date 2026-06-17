<?php if (!defined('ABSPATH')) exit;
// 社員 admin page — list every `edit_posts` user with a 退職 /
// 復帰 toggle plus an optional profile trio (所属 / 入社日 / 備考)
// edited via a modal. The retired flag blocks all DRWP write paths
// but keeps the user's past reports + attribution intact.
$visible = [];
$needle = function_exists('mb_strtolower') ? mb_strtolower($search ?? '') : strtolower($search ?? '');
foreach ($workers as $w) {
    if ($filter === 'active'  && $w->retired) continue;
    if ($filter === 'retired' && !$w->retired) continue;
    if (($department_filter ?? '') !== '' && $w->department !== $department_filter) continue;
    if ($needle !== '') {
        $hay = function_exists('mb_strtolower')
            ? mb_strtolower($w->name . ' ' . $w->worker_name . ' ' . $w->email . ' ' . $w->department . ' ' . $w->notes)
            : strtolower($w->name . ' ' . $w->worker_name . ' ' . $w->email . ' ' . $w->department . ' ' . $w->notes);
        if (strpos($hay, $needle) === false) continue;
    }
    $visible[] = $w;
}
// 一覧をページャー (DRWP_Admin::paginate_array) で切り出す。
// `$visible_full` には全件を残しておいて、ページャーの総件数や
// ページ計算に使う。
$visible_full = $visible;
$_pager = DRWP_Admin::paginate_array($visible);
$visible = $_pager['items'];
$_total  = $_pager['total'];
$_paged  = $_pager['paged'];
$_pages  = $_pager['pages'];
?>
<div class="wrap">
  <h1><?php esc_html_e('社員', 'drwp-daily-reports'); ?></h1>

  <?php if (!empty($_GET['saved'])): ?>
    <div class="notice notice-success"><p><?php esc_html_e('社員の情報を更新しました。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>
  <?php if (!empty($_GET['err']) && $_GET['err'] === 'invalid'): ?>
    <div class="notice notice-error"><p><?php esc_html_e('対象のユーザーは作業員ではありません。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>

  <p class="description">
    <?php esc_html_e('退職にすると、その社員はログインできなくなり、フロント・wp-admin のいずれも利用できません(既存セッションも次のアクセスで強制ログアウトされます)。過去の日報データはそのまま残ります。', 'drwp-daily-reports'); ?>
  </p>

  <ul class="subsubsub" style="margin-top:8px;">
    <li><a href="<?php echo esc_url(admin_url('admin.php?page=drwp_workers&view=active')); ?>"
           class="<?php echo $filter === 'active' ? 'current' : ''; ?>"><?php esc_html_e('在籍', 'drwp-daily-reports'); ?></a> |</li>
    <li><a href="<?php echo esc_url(admin_url('admin.php?page=drwp_workers&view=retired')); ?>"
           class="<?php echo $filter === 'retired' ? 'current' : ''; ?>"><?php esc_html_e('退職', 'drwp-daily-reports'); ?></a> |</li>
    <li><a href="<?php echo esc_url(admin_url('admin.php?page=drwp_workers&view=all')); ?>"
           class="<?php echo $filter === 'all' ? 'current' : ''; ?>"><?php esc_html_e('すべて', 'drwp-daily-reports'); ?></a></li>
  </ul>

  <details class="drwp-filter" style="clear:both;" <?php echo ($search !== '' || $department_filter !== '') ? 'open' : ''; ?>>
    <summary class="drwp-filter-summary"><?php esc_html_e('検索・絞り込み', 'drwp-daily-reports'); ?></summary>
    <form method="get" class="drwp-filter-form">
      <input type="hidden" name="page" value="drwp_workers" />
      <input type="hidden" name="view" value="<?php echo esc_attr($filter); ?>" />
      <div class="drwp-row">
        <input type="search" name="s" value="<?php echo esc_attr($search); ?>"
               placeholder="<?php esc_attr_e('社員名・メール・所属・備考検索', 'drwp-daily-reports'); ?>"
               class="drwp-search-input" />
        <?php if (!empty($departments)): ?>
        <select name="department">
          <option value=""><?php esc_html_e('所属すべて', 'drwp-daily-reports'); ?></option>
          <?php foreach ($departments as $dep): ?>
            <option value="<?php echo esc_attr($dep); ?>" <?php selected($department_filter, $dep); ?>>
              <?php echo esc_html($dep); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <button class="button button-primary"><?php esc_html_e('検索', 'drwp-daily-reports'); ?></button>
        <a class="button-link" href="<?php echo esc_url(admin_url('admin.php?page=drwp_workers&view=' . $filter)); ?>"><?php esc_html_e('クリア', 'drwp-daily-reports'); ?></a>
      </div>
    </form>
  </details>

  <?php
    $pager_base = remove_query_arg('paged', $_SERVER['REQUEST_URI'] ?? '');
    echo DRWP_Admin::render_pager($_paged, $_pages, $pager_base, $_total);
  ?>

  <table class="widefat striped" style="margin-top:8px;">
    <thead>
      <tr>
        <th><?php esc_html_e('表示名', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('メール', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('所属', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('入社日', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('ロール', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('最終日報', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('日報数', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('状態', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('操作', 'drwp-daily-reports'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($visible)): ?>
        <tr><td colspan="9"><?php esc_html_e('該当する社員が見つかりません。', 'drwp-daily-reports'); ?></td></tr>
      <?php else: foreach ($visible as $w):
        $roles = implode(', ', array_map('esc_html', $w->roles ?: []));
      ?>
        <tr class="drwp-worker-row <?php echo $w->retired ? 'is-retired' : ''; ?>">
          <td>
            <strong><?php echo esc_html($w->name); ?></strong>
            <?php if ($w->notes !== ''): ?>
              <span class="drwp-worker-note-icon" title="<?php echo esc_attr($w->notes); ?>">💬</span>
            <?php endif; ?>
          </td>
          <td><?php echo esc_html($w->email); ?></td>
          <td><?php echo esc_html($w->department ?: '-'); ?></td>
          <td><?php echo $w->hired_at ? esc_html(date_i18n('Y/n/j', strtotime($w->hired_at))) : '-'; ?></td>
          <td><?php echo $roles ?: '-'; ?></td>
          <td><?php echo $w->last_date ? esc_html(date_i18n('Y/n/j', strtotime($w->last_date))) : '-'; ?></td>
          <td><?php echo (int) $w->report_cnt; ?></td>
          <td>
            <?php if ($w->retired): ?>
              <span class="drwp-worker-badge is-retired"><?php esc_html_e('退職', 'drwp-daily-reports'); ?></span>
            <?php else: ?>
              <span class="drwp-worker-badge is-active"><?php esc_html_e('在籍', 'drwp-daily-reports'); ?></span>
            <?php endif; ?>
          </td>
          <td style="white-space:nowrap;">
            <button type="button" class="button button-small drwp-worker-edit-btn"
                    data-id="<?php echo (int) $w->id; ?>"
                    data-name="<?php echo esc_attr($w->name); ?>"
                    data-worker_name="<?php echo esc_attr($w->worker_name); ?>"
                    data-department="<?php echo esc_attr($w->department); ?>"
                    data-hired_at="<?php echo esc_attr($w->hired_at); ?>"
                    data-notes="<?php echo esc_attr($w->notes); ?>">
              <?php esc_html_e('編集', 'drwp-daily-reports'); ?>
            </button>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
              <?php wp_nonce_field('drwp_set_retired'); ?>
              <input type="hidden" name="action" value="drwp_set_retired" />
              <input type="hidden" name="user_id" value="<?php echo (int) $w->id; ?>" />
              <?php if ($w->retired): ?>
                <input type="hidden" name="retired" value="" />
                <button type="submit" class="button button-small"
                        onclick="return confirm('<?php echo esc_js(sprintf(__('%s さんを在籍に戻します。よろしいですか？', 'drwp-daily-reports'), $w->name)); ?>');">
                  <?php esc_html_e('復帰させる', 'drwp-daily-reports'); ?>
                </button>
              <?php else: ?>
                <input type="hidden" name="retired" value="1" />
                <button type="submit" class="button button-small button-link-delete"
                        onclick="return confirm('<?php echo esc_js(sprintf(__('%s さんを退職にします。以降この社員はログインできなくなり、データの閲覧もできなくなります(過去の日報は残ります)。よろしいですか？', 'drwp-daily-reports'), $w->name)); ?>');">
                  <?php esc_html_e('退職にする', 'drwp-daily-reports'); ?>
                </button>
              <?php endif; ?>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <?php echo DRWP_Admin::render_pager($_paged, $_pages, $pager_base, $_total); ?>

  <!-- 社員情報編集モーダル (所属 / 入社日 / 備考 — すべて任意) -->
  <dialog id="drwp-worker-dialog" class="drwp-worker-modal">
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <?php wp_nonce_field('drwp_save_worker'); ?>
      <input type="hidden" name="action" value="drwp_save_worker" />
      <input type="hidden" name="user_id" id="drwp-worker-id" value="0" />

      <div class="drwp-worker-modal-header">
        <h2 id="drwp-worker-title"><?php esc_html_e('社員情報を編集', 'drwp-daily-reports'); ?></h2>
        <button type="button" class="drwp-worker-modal-close" aria-label="<?php esc_attr_e('閉じる', 'drwp-daily-reports'); ?>">&times;</button>
      </div>

      <div class="drwp-worker-modal-body">
        <table class="form-table">
          <tr>
            <th><label for="drwp-worker-name"><?php esc_html_e('社員名', 'drwp-daily-reports'); ?></label></th>
            <td>
              <input type="text" id="drwp-worker-name" name="worker_name" class="regular-text"
                     placeholder="<?php esc_attr_e('例: 山田（東京）', 'drwp-daily-reports'); ?>" />
              <p class="description"><?php esc_html_e('管理画面内だけで使う表示名。空欄なら WP プロフィールの姓名で表示されます。フロント側には出ません。', 'drwp-daily-reports'); ?></p>
            </td>
          </tr>
          <tr>
            <th><label for="drwp-worker-department"><?php esc_html_e('所属', 'drwp-daily-reports'); ?></label></th>
            <td><input type="text" id="drwp-worker-department" name="department" class="regular-text"
                       placeholder="<?php esc_attr_e('例: 工事部 / 営業 / 外注', 'drwp-daily-reports'); ?>" /></td>
          </tr>
          <tr>
            <th><label for="drwp-worker-hired"><?php esc_html_e('入社日', 'drwp-daily-reports'); ?></label></th>
            <td><input type="date" id="drwp-worker-hired" name="hired_at" /></td>
          </tr>
          <tr>
            <th><label for="drwp-worker-notes"><?php esc_html_e('備考', 'drwp-daily-reports'); ?></label></th>
            <td><textarea id="drwp-worker-notes" name="notes" rows="3" class="large-text"
                          placeholder="<?php esc_attr_e('資格・連絡事項など', 'drwp-daily-reports'); ?>"></textarea></td>
          </tr>
        </table>
      </div>

      <div class="drwp-worker-modal-footer">
        <button type="submit" class="button button-primary"><?php esc_html_e('保存', 'drwp-daily-reports'); ?></button>
        <button type="button" class="button drwp-worker-modal-close"><?php esc_html_e('キャンセル', 'drwp-daily-reports'); ?></button>
      </div>
    </form>
  </dialog>
</div>

<style>
/* 検索・絞り込み — 日報一覧と同じ薄いグレー枠の details。 */
.drwp-filter{margin:8px 0 10px;border:1px solid #e5e7eb;border-radius:6px;background:#fff}
.drwp-filter-summary{cursor:pointer;font-weight:600;color:#1d2327;list-style:none;display:flex;align-items:center;gap:6px;padding:8px 12px}
.drwp-filter-summary::-webkit-details-marker{display:none}
.drwp-filter-summary::before{content:'▸';font-size:.8em;color:#6b7280;transition:transform .15s}
.drwp-filter[open] .drwp-filter-summary{border-bottom:1px solid #f1f5f9}
.drwp-filter[open] .drwp-filter-summary::before{transform:rotate(90deg)}
.drwp-filter-summary:hover{color:#2271b1}
.drwp-filter-form{padding:10px 12px}
.drwp-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.drwp-search-input{min-width:220px;flex:1}

.drwp-worker-row.is-retired td { color: #6b7280; }
.drwp-worker-badge { display:inline-block; padding:1px 8px; border-radius:10px; font-size:11px; font-weight:600; }
.drwp-worker-badge.is-active  { background:#dcfce7; color:#166534; }
.drwp-worker-badge.is-retired { background:#f3f4f6; color:#6b7280; }
.drwp-worker-note-icon { cursor: help; margin-left: 4px; }

.drwp-worker-modal{border:0;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.18);padding:0;max-width:560px;width:90vw}
.drwp-worker-modal::backdrop{background:rgba(0,0,0,.45)}
.drwp-worker-modal-header{display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-bottom:1px solid #e5e7eb}
.drwp-worker-modal-header h2{margin:0;font-size:1.1em}
.drwp-worker-modal-close{background:transparent;border:0;font-size:1.6em;cursor:pointer;color:#50575e;line-height:1;padding:0 4px}
.drwp-worker-modal-body{padding:16px 20px}
.drwp-worker-modal-body .form-table th{width:90px;padding:6px 0;vertical-align:top}
.drwp-worker-modal-body .form-table td{padding:6px 0}
.drwp-worker-modal-footer{display:flex;gap:8px;align-items:center;padding:12px 20px;border-top:1px solid #e5e7eb;background:#f6f7f7;border-radius:0 0 12px 12px}
</style>

<script>
(function () {
  var dlg = document.getElementById('drwp-worker-dialog');
  if (!dlg) return;
  var idEl    = document.getElementById('drwp-worker-id');
  var wnameEl = document.getElementById('drwp-worker-name');
  var deptEl  = document.getElementById('drwp-worker-department');
  var hiredEl = document.getElementById('drwp-worker-hired');
  var notesEl = document.getElementById('drwp-worker-notes');
  var titleEl = document.getElementById('drwp-worker-title');
  var editTitle = <?php echo wp_json_encode(__('社員情報を編集', 'drwp-daily-reports')); ?>;

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.drwp-worker-edit-btn');
    if (!btn) return;
    var d = btn.dataset;
    idEl.value    = d.id;
    wnameEl.value = d.worker_name || '';
    deptEl.value  = d.department || '';
    hiredEl.value = d.hired_at || '';
    notesEl.value = d.notes || '';
    titleEl.textContent = editTitle + ' — ' + (d.name || '#' + d.id);
    dlg.showModal();
    wnameEl.focus();
  });

  dlg.addEventListener('click', function (e) {
    if (e.target.classList.contains('drwp-worker-modal-close')) dlg.close();
    if (e.target === dlg) dlg.close();
  });
})();
</script>
