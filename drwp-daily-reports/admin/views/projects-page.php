<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1>現場</h1>

  <?php if (!empty($_GET['saved'])): ?>
    <div class="notice notice-success"><p>現場を保存しました。</p></div>
  <?php endif; ?>
  <?php if (!empty($_GET['error']) && $_GET['error'] === 'missing_name'): ?>
    <div class="notice notice-error"><p>現場名は必須です。</p></div>
  <?php endif; ?>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:#fff;padding:16px;margin-top:12px;">
    <?php wp_nonce_field('drwp_save_project'); ?>
    <input type="hidden" name="action" value="drwp_save_project" />
    <table class="form-table">
      <tr>
        <th><label for="drwp-project-name">現場名</label></th>
        <td><input type="text" id="drwp-project-name" class="regular-text" name="name" required /></td>
      </tr>
      <tr>
        <th><label for="drwp-project-status">状態</label></th>
        <td>
          <select name="status" id="drwp-project-status">
            <option value="active">active</option>
            <option value="inactive">inactive</option>
          </select>
        </td>
      </tr>
    </table>
    <?php submit_button('現場を追加'); ?>
  </form>

  <table class="widefat striped" style="margin-top:16px;">
    <thead>
      <tr><th>ID</th><th>現場名</th><th>状態</th><th>更新日時</th></tr>
    </thead>
    <tbody>
      <?php if (empty($projects)): ?>
        <tr><td colspan="4">まだ現場がありません。</td></tr>
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
