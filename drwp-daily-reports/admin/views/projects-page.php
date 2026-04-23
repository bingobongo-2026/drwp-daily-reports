<?php
if (!defined('ABSPATH')) exit;
global $wpdb;
$table = $wpdb->prefix . 'drwp_projects';
$projects = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC", ARRAY_A);
?>
<div class="wrap">
  <h1>現場</h1>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:#fff;padding:16px;margin:12px 0;">
    <?php wp_nonce_field('drwp_save_project'); ?>
    <input type="hidden" name="action" value="drwp_save_project">
    <table class="form-table">
      <tr><th>現場名</th><td><input type="text" name="name" class="regular-text"></td></tr>
      <tr><th>顧客名</th><td><input type="text" name="client_name" class="regular-text"></td></tr>
      <tr><th>住所</th><td><input type="text" name="site_address" class="regular-text"></td></tr>
      <tr><th>状態</th><td><select name="status"><option value="active">active</option><option value="paused">paused</option><option value="completed">completed</option></select></td></tr>
    </table>
    <?php submit_button('保存'); ?>
  </form>

  <table class="widefat striped">
    <thead><tr><th>ID</th><th>現場名</th><th>顧客名</th><th>住所</th><th>状態</th></tr></thead>
    <tbody>
    <?php foreach ($projects as $p): ?>
      <tr><td><?php echo intval($p['id']); ?></td><td><?php echo esc_html($p['name']); ?></td><td><?php echo esc_html($p['client_name']); ?></td><td><?php echo esc_html($p['site_address']); ?></td><td><?php echo esc_html($p['status']); ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
