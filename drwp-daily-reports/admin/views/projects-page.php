<?php if (!defined('ABSPATH')) exit; ?>
<?php
$is_edit = !empty($edit_project);
$form_name   = $is_edit ? (string) $edit_project->name   : '';
$form_status = $is_edit ? (string) $edit_project->status : 'active';
$status_options = [
    'active'   => DRWP_Labels::project_status('active'),
    'inactive' => DRWP_Labels::project_status('inactive'),
];
?>
<div class="wrap">
  <h1><?php esc_html_e('現場', 'drwp-daily-reports'); ?></h1>

  <?php if (!empty($_GET['saved'])): ?>
    <div class="notice notice-success"><p><?php esc_html_e('現場を保存しました。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>
  <?php if (!empty($_GET['error']) && $_GET['error'] === 'missing_name'): ?>
    <div class="notice notice-error"><p><?php esc_html_e('現場名は必須です。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>

  <h2>
    <?php
    echo esc_html($is_edit
        ? sprintf(
            /* translators: %d: project ID */
            __('現場を編集 (#%d)', 'drwp-daily-reports'),
            (int) $edit_project->id
        )
        : __('新しい現場を追加', 'drwp-daily-reports')
    );
    ?>
  </h2>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:#fff;padding:16px;margin-top:8px;border:1px solid #c3c4c7;border-radius:6px;">
    <?php wp_nonce_field('drwp_save_project'); ?>
    <input type="hidden" name="action" value="drwp_save_project" />
    <?php if ($is_edit): ?>
      <input type="hidden" name="id" value="<?php echo (int) $edit_project->id; ?>" />
    <?php endif; ?>
    <table class="form-table">
      <tr>
        <th><label for="drwp-project-name"><?php esc_html_e('現場名', 'drwp-daily-reports'); ?></label></th>
        <td><input type="text" id="drwp-project-name" class="regular-text" name="name"
                   value="<?php echo esc_attr($form_name); ?>" required /></td>
      </tr>
      <tr>
        <th><label for="drwp-project-status"><?php esc_html_e('状態', 'drwp-daily-reports'); ?></label></th>
        <td>
          <select name="status" id="drwp-project-status">
            <?php foreach ($status_options as $val => $label): ?>
              <option value="<?php echo esc_attr($val); ?>" <?php selected($form_status, $val); ?>>
                <?php echo esc_html($label); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </td>
      </tr>
    </table>
    <p>
      <button type="submit" class="button button-primary">
        <?php echo esc_html($is_edit ? __('現場を更新', 'drwp-daily-reports') : __('現場を追加', 'drwp-daily-reports')); ?>
      </button>
      <?php if ($is_edit): ?>
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=drwp_projects')); ?>">
          <?php esc_html_e('新規追加に戻る', 'drwp-daily-reports'); ?>
        </a>
      <?php endif; ?>
    </p>
  </form>

  <h2 style="margin-top:24px;"><?php esc_html_e('登録済みの現場', 'drwp-daily-reports'); ?></h2>
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
        <tr <?php echo ($is_edit && (int) $edit_project->id === (int) $project->id) ? 'style="background:#fef3c7;"' : ''; ?>>
          <td><?php echo (int) $project->id; ?></td>
          <td><?php echo esc_html($project->name); ?></td>
          <td><?php echo esc_html(DRWP_Labels::project_status((string) $project->status)); ?></td>
          <td><?php echo esc_html($project->updated_at); ?></td>
          <td>
            <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=drwp_projects&edit_id=' . (int) $project->id)); ?>">
              <?php esc_html_e('編集', 'drwp-daily-reports'); ?>
            </a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
