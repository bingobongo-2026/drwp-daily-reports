<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1><?php esc_html_e('案件グループ', 'drwp-daily-reports'); ?></h1>

  <?php if (!empty($_GET['saved'])): ?>
    <div class="notice notice-success"><p><?php esc_html_e('案件グループを保存しました。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>
  <?php if (!empty($_GET['error']) && $_GET['error'] === 'missing_name'): ?>
    <div class="notice notice-error"><p><?php esc_html_e('グループ名は必須です。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>

  <p>
    <button type="button" class="button button-primary" id="drwp-pg-add-btn">
      + <?php esc_html_e('新しい案件グループを追加', 'drwp-daily-reports'); ?>
    </button>
  </p>

  <table class="widefat striped" style="margin-top:8px;">
    <thead>
      <tr>
        <th>ID</th>
        <th><?php esc_html_e('グループ名', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('色', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('案件数', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('メモ', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('状態', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('操作', 'drwp-daily-reports'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($groups)): ?>
        <tr><td colspan="7"><?php esc_html_e('まだ案件グループがありません。', 'drwp-daily-reports'); ?></td></tr>
      <?php else: foreach ($groups as $g):
        $color = (string) ($g->color ?? '');
        $count = (int) ($counts[(int) $g->id] ?? 0);
      ?>
        <tr>
          <td><?php echo (int) $g->id; ?></td>
          <td><?php echo esc_html($g->name); ?></td>
          <td>
            <?php if ($color !== ''): ?>
              <span class="drwp-cg-swatch" style="background:<?php echo esc_attr($color); ?>;" aria-hidden="true"></span>
              <code><?php echo esc_html($color); ?></code>
            <?php else: ?>
              -
            <?php endif; ?>
          </td>
          <td><?php echo (int) $count; ?></td>
          <td><?php echo esc_html(wp_strip_all_tags((string) ($g->notes ?? ''))); ?></td>
          <td><?php echo esc_html(DRWP_Labels::project_status((string) $g->status)); ?></td>
          <td>
            <button type="button" class="button button-small drwp-pg-edit-btn"
                    data-id="<?php echo (int) $g->id; ?>"
                    data-name="<?php echo esc_attr($g->name); ?>"
                    data-color="<?php echo esc_attr($color); ?>"
                    data-notes="<?php echo esc_attr($g->notes ?? ''); ?>"
                    data-status="<?php echo esc_attr($g->status); ?>">
              <?php esc_html_e('編集', 'drwp-daily-reports'); ?>
            </button>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <dialog id="drwp-pg-dialog" class="drwp-cg-modal">
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <?php wp_nonce_field('drwp_save_project_group'); ?>
      <input type="hidden" name="action" value="drwp_save_project_group" />
      <input type="hidden" name="id" id="drwp-pg-id" value="0" />

      <div class="drwp-cg-modal-header">
        <h2 id="drwp-pg-title"><?php esc_html_e('新しい案件グループを追加', 'drwp-daily-reports'); ?></h2>
        <button type="button" class="drwp-cg-modal-close">&times;</button>
      </div>

      <div class="drwp-cg-modal-body">
        <table class="form-table">
          <tr>
            <th><label for="drwp-pg-name"><?php esc_html_e('グループ名', 'drwp-daily-reports'); ?> <em style="color:#b91c1c;">*</em></label></th>
            <td><input type="text" id="drwp-pg-name" name="name" class="regular-text" required /></td>
          </tr>
          <tr>
            <th><label for="drwp-pg-color"><?php esc_html_e('色', 'drwp-daily-reports'); ?></label></th>
            <td>
              <input type="color" id="drwp-pg-color" name="color" value="#94a3b8" />
              <button type="button" class="button button-small" id="drwp-pg-color-clear">
                <?php esc_html_e('色なし', 'drwp-daily-reports'); ?>
              </button>
              <span class="description"><?php esc_html_e('案件一覧の見出しに点として表示されます。', 'drwp-daily-reports'); ?></span>
            </td>
          </tr>
          <tr>
            <th><label for="drwp-pg-notes"><?php esc_html_e('メモ', 'drwp-daily-reports'); ?></label></th>
            <td><textarea id="drwp-pg-notes" name="notes" rows="3" class="large-text"></textarea></td>
          </tr>
          <tr>
            <th><label for="drwp-pg-status"><?php esc_html_e('状態', 'drwp-daily-reports'); ?></label></th>
            <td>
              <select name="status" id="drwp-pg-status">
                <option value="active"><?php echo esc_html(DRWP_Labels::project_status('active')); ?></option>
                <option value="inactive"><?php echo esc_html(DRWP_Labels::project_status('inactive')); ?></option>
              </select>
            </td>
          </tr>
        </table>
      </div>

      <div class="drwp-cg-modal-footer">
        <button type="submit" class="button button-primary" id="drwp-pg-submit">
          <?php esc_html_e('保存', 'drwp-daily-reports'); ?>
        </button>
        <button type="button" class="button drwp-cg-modal-close">
          <?php esc_html_e('キャンセル', 'drwp-daily-reports'); ?>
        </button>
      </div>
    </form>
  </dialog>
</div>

<style>
.drwp-cg-swatch{display:inline-block;width:14px;height:14px;border-radius:50%;border:1px solid rgba(0,0,0,.1);vertical-align:middle;margin-right:6px}
.drwp-cg-modal{border:0;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.18);padding:0;max-width:560px;width:90vw}
.drwp-cg-modal::backdrop{background:rgba(0,0,0,.45)}
.drwp-cg-modal-header{display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-bottom:1px solid #e5e7eb}
.drwp-cg-modal-header h2{margin:0;font-size:1.1em}
.drwp-cg-modal-close{background:transparent;border:0;font-size:1.6em;cursor:pointer;color:#50575e;line-height:1;padding:0 4px}
.drwp-cg-modal-body{padding:16px 20px;max-height:65vh;overflow-y:auto}
.drwp-cg-modal-body .form-table th{width:120px;padding:6px 0;vertical-align:top}
.drwp-cg-modal-body .form-table td{padding:6px 0}
.drwp-cg-modal-footer{display:flex;gap:8px;align-items:center;padding:12px 20px;border-top:1px solid #e5e7eb;background:#f6f7f7;border-radius:0 0 12px 12px}
</style>

<script>
(function () {
  var dlg = document.getElementById('drwp-pg-dialog');
  if (!dlg) return;

  var idEl     = document.getElementById('drwp-pg-id');
  var nameEl   = document.getElementById('drwp-pg-name');
  var colorEl  = document.getElementById('drwp-pg-color');
  var notesEl  = document.getElementById('drwp-pg-notes');
  var statusEl = document.getElementById('drwp-pg-status');
  var titleEl  = document.getElementById('drwp-pg-title');
  var submitEl = document.getElementById('drwp-pg-submit');

  var addTitle  = <?php echo wp_json_encode(__('新しい案件グループを追加', 'drwp-daily-reports')); ?>;
  var editTitle = <?php echo wp_json_encode(__('案件グループを編集', 'drwp-daily-reports')); ?>;
  var addLabel  = <?php echo wp_json_encode(__('追加', 'drwp-daily-reports')); ?>;
  var saveLabel = <?php echo wp_json_encode(__('更新', 'drwp-daily-reports')); ?>;

  function setColor(v) {
    if (v && /^#[0-9a-fA-F]{6}$/.test(v)) {
      colorEl.value = v;
      colorEl.dataset.empty = '0';
    } else {
      colorEl.value = '#94a3b8';
      colorEl.dataset.empty = '1';
    }
  }

  function clearForm() {
    idEl.value = '0';
    nameEl.value = '';
    notesEl.value = '';
    statusEl.value = 'active';
    setColor('');
  }

  document.getElementById('drwp-pg-add-btn').addEventListener('click', function () {
    clearForm();
    titleEl.textContent = addTitle;
    submitEl.textContent = addLabel;
    dlg.showModal();
    nameEl.focus();
  });

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.drwp-pg-edit-btn');
    if (!btn) return;
    var d = btn.dataset;
    titleEl.textContent = editTitle + ' (#' + d.id + ')';
    submitEl.textContent = saveLabel;
    idEl.value = d.id;
    nameEl.value = d.name || '';
    notesEl.value = d.notes || '';
    statusEl.value = d.status || 'active';
    setColor(d.color || '');
    dlg.showModal();
    nameEl.focus();
  });

  document.getElementById('drwp-pg-color-clear').addEventListener('click', function () {
    setColor('');
  });

  colorEl.addEventListener('input', function () {
    colorEl.dataset.empty = '0';
  });

  dlg.querySelector('form').addEventListener('submit', function () {
    if (colorEl.dataset.empty === '1') colorEl.value = '';
  });

  dlg.addEventListener('click', function (e) {
    if (e.target.classList.contains('drwp-cg-modal-close')) dlg.close();
    if (e.target === dlg) dlg.close();
  });
})();
</script>
