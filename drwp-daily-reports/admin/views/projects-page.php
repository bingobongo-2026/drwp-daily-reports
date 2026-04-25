<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1><?php esc_html_e('現場', 'drwp-daily-reports'); ?></h1>

  <?php if (!empty($_GET['saved'])): ?>
    <div class="notice notice-success"><p><?php esc_html_e('現場を保存しました。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>
  <?php if (!empty($_GET['error']) && $_GET['error'] === 'missing_name'): ?>
    <div class="notice notice-error"><p><?php esc_html_e('現場名は必須です。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:#fff;padding:16px;margin-top:12px;">
    <?php wp_nonce_field('drwp_save_project'); ?>
    <input type="hidden" name="action" value="drwp_save_project" />
    <table class="form-table">
      <tr>
        <th><label for="drwp-project-name"><?php esc_html_e('現場名', 'drwp-daily-reports'); ?></label></th>
        <td><input type="text" id="drwp-project-name" class="regular-text" name="name" required /></td>
      </tr>
      <tr>
        <th><label for="drwp-project-status"><?php esc_html_e('状態', 'drwp-daily-reports'); ?></label></th>
        <td>
          <select name="status" id="drwp-project-status">
            <option value="active">active</option>
            <option value="inactive">inactive</option>
          </select>
        </td>
      </tr>
    </table>
    <?php submit_button(__('現場を追加', 'drwp-daily-reports')); ?>
  </form>

  <table class="widefat striped" style="margin-top:16px;">
    <thead>
      <tr>
        <th>ID</th>
        <th><?php esc_html_e('現場名', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('状態', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('更新日時', 'drwp-daily-reports'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($projects)): ?>
        <tr><td colspan="4"><?php esc_html_e('まだ現場がありません。', 'drwp-daily-reports'); ?></td></tr>
      <?php else: foreach ($projects as $project): ?>
        <tr>
          <td><?php echo (int) $project->id; ?></td>
          <td><?php echo esc_html($project->name); ?></td>
          <td><?php echo esc_html($project->status); ?></td>
          <td><?php echo esc_html($project->updated_at); ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
