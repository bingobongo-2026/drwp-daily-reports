<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1><?php esc_html_e('現場', 'drwp-daily-reports'); ?></h1>

  <?php if (!empty($_GET['saved'])): ?>
    <div class="notice notice-success"><p><?php esc_html_e('現場を保存しました。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>
  <?php if (!empty($_GET['error']) && $_GET['error'] === 'missing_name'): ?>
    <div class="notice notice-error"><p><?php esc_html_e('現場名は必須です。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>

  <p>
    <button type="button" class="button button-primary" id="drwp-project-add-btn">
      + <?php esc_html_e('新しい現場を追加', 'drwp-daily-reports'); ?>
    </button>
  </p>

  <table class="widefat striped" style="margin-top:8px;">
    <thead>
      <tr>
        <th>ID</th>
        <th><?php esc_html_e('現場名', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('状態', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('更新日時', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('操作', 'drwp-daily-reports'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($projects)): ?>
        <tr><td colspan="5"><?php esc_html_e('まだ現場がありません。', 'drwp-daily-reports'); ?></td></tr>
      <?php else: foreach ($projects as $project): ?>
        <tr>
          <td><?php echo (int) $project->id; ?></td>
          <td><?php echo esc_html($project->name); ?></td>
          <td><?php echo esc_html(DRWP_Labels::project_status((string) $project->status)); ?></td>
          <td><?php echo esc_html($project->updated_at); ?></td>
          <td>
            <button type="button" class="button button-small drwp-project-edit-btn"
                    data-id="<?php echo (int) $project->id; ?>"
                    data-name="<?php echo esc_attr($project->name); ?>"
                    data-status="<?php echo esc_attr($project->status); ?>">
              <?php esc_html_e('編集', 'drwp-daily-reports'); ?>
            </button>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <!-- Modal for add / edit -->
  <dialog id="drwp-project-dialog" class="drwp-project-modal">
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <?php wp_nonce_field('drwp_save_project'); ?>
      <input type="hidden" name="action" value="drwp_save_project" />
      <input type="hidden" name="id" id="drwp-project-modal-id" value="0" />

      <div class="drwp-project-modal-header">
        <h2 id="drwp-project-modal-title"><?php esc_html_e('新しい現場を追加', 'drwp-daily-reports'); ?></h2>
        <button type="button" class="drwp-project-modal-close">&times;</button>
      </div>

      <div class="drwp-project-modal-body">
        <table class="form-table">
          <tr>
            <th><label for="drwp-project-modal-name"><?php esc_html_e('現場名', 'drwp-daily-reports'); ?></label></th>
            <td><input type="text" id="drwp-project-modal-name" name="name" class="regular-text" required /></td>
          </tr>
          <tr>
            <th><label for="drwp-project-modal-status"><?php esc_html_e('状態', 'drwp-daily-reports'); ?></label></th>
            <td>
              <select name="status" id="drwp-project-modal-status">
                <option value="active"><?php echo esc_html(DRWP_Labels::project_status('active')); ?></option>
                <option value="inactive"><?php echo esc_html(DRWP_Labels::project_status('inactive')); ?></option>
              </select>
            </td>
          </tr>
        </table>
      </div>

      <div class="drwp-project-modal-footer">
        <button type="submit" class="button button-primary" id="drwp-project-modal-submit">
          <?php esc_html_e('保存', 'drwp-daily-reports'); ?>
        </button>
        <button type="button" class="button drwp-project-modal-close">
          <?php esc_html_e('キャンセル', 'drwp-daily-reports'); ?>
        </button>
      </div>
    </form>
  </dialog>
</div>

<style>
.drwp-project-modal{border:0;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.18);padding:0;max-width:520px;width:90vw}
.drwp-project-modal::backdrop{background:rgba(0,0,0,.45)}
.drwp-project-modal-header{display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-bottom:1px solid #e5e7eb}
.drwp-project-modal-header h2{margin:0;font-size:1.1em}
.drwp-project-modal-close{background:transparent;border:0;font-size:1.6em;cursor:pointer;color:#50575e;line-height:1;padding:0 4px}
.drwp-project-modal-body{padding:16px 20px}
.drwp-project-modal-body .form-table th{width:80px;padding:8px 0}
.drwp-project-modal-body .form-table td{padding:8px 0}
.drwp-project-modal-footer{display:flex;gap:8px;align-items:center;padding:12px 20px;border-top:1px solid #e5e7eb;background:#f6f7f7;border-radius:0 0 12px 12px}
</style>

<script>
(function(){
  var dlg = document.getElementById('drwp-project-dialog');
  if (!dlg) return;

  var titleEl  = document.getElementById('drwp-project-modal-title');
  var idEl     = document.getElementById('drwp-project-modal-id');
  var nameEl   = document.getElementById('drwp-project-modal-name');
  var statusEl = document.getElementById('drwp-project-modal-status');
  var submitEl = document.getElementById('drwp-project-modal-submit');

  var addTitle  = <?php echo wp_json_encode(__('新しい現場を追加', 'drwp-daily-reports')); ?>;
  var editTitle = <?php echo wp_json_encode(__('現場を編集', 'drwp-daily-reports')); ?>;
  var addLabel  = <?php echo wp_json_encode(__('追加', 'drwp-daily-reports')); ?>;
  var saveLabel = <?php echo wp_json_encode(__('更新', 'drwp-daily-reports')); ?>;

  // Add button
  document.getElementById('drwp-project-add-btn').addEventListener('click', function(){
    titleEl.textContent = addTitle;
    submitEl.textContent = addLabel;
    idEl.value = '0';
    nameEl.value = '';
    statusEl.value = 'active';
    dlg.showModal();
    nameEl.focus();
  });

  // Edit buttons (event delegation)
  document.addEventListener('click', function(e){
    var btn = e.target.closest('.drwp-project-edit-btn');
    if (!btn) return;
    titleEl.textContent = editTitle + ' (#' + btn.dataset.id + ')';
    submitEl.textContent = saveLabel;
    idEl.value = btn.dataset.id;
    nameEl.value = btn.dataset.name;
    statusEl.value = btn.dataset.status;
    dlg.showModal();
    nameEl.focus();
  });

  // Close
  dlg.addEventListener('click', function(e){
    if (e.target.classList.contains('drwp-project-modal-close')) dlg.close();
    if (e.target === dlg) dlg.close();
  });
})();
</script>
