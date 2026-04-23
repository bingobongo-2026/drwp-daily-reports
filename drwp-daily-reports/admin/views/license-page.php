<div class="wrap">
  <h1>ライセンス</h1>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:#fff;padding:16px;margin-top:12px;">
    <?php wp_nonce_field('drwp_save_license'); ?>
    <input type="hidden" name="action" value="drwp_save_license">
    <table class="form-table">
      <tr><th>API URL</th><td><input type="url" class="regular-text" name="api_url" value="<?php echo esc_attr(get_option('drwp_license_api_url', '')); ?>"></td></tr>
      <tr><th>ライセンスキー</th><td><input type="text" class="regular-text" name="license_key" value="<?php echo esc_attr(get_option('drwp_license_key', '')); ?>"></td></tr>
    </table>
    <?php submit_button('保存'); ?>
  </form>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
    <?php wp_nonce_field('drwp_fetch_public_key'); ?>
    <input type="hidden" name="action" value="drwp_fetch_public_key">
    <?php submit_button('公開鍵を取得して再検証', 'secondary', '', false); ?>
  </form>

  <h2>現在の状態</h2>
  <table class="widefat striped" style="max-width:900px;">
    <tbody>
      <tr><th>status</th><td><?php echo esc_html($license['status'] ?? ''); ?></td></tr>
      <tr><th>message</th><td><?php echo esc_html($license['message'] ?? ''); ?></td></tr>
      <tr><th>plan</th><td><?php echo esc_html($license['plan'] ?? ''); ?></td></tr>
      <tr><th>expires_at</th><td><?php echo esc_html($license['expires_at'] ?? ''); ?></td></tr>
      <tr><th>checked_at</th><td><?php echo !empty($license['checked_at']) ? esc_html(date('Y-m-d H:i:s', intval($license['checked_at']))) : ''; ?></td></tr>
      <tr><th>last_valid_at</th><td><?php echo !empty($license['last_valid_at']) ? esc_html(date('Y-m-d H:i:s', intval($license['last_valid_at']))) : ''; ?></td></tr>
      <tr><th>grace_until</th><td><?php echo !empty($license['grace_until']) ? esc_html(date('Y-m-d H:i:s', intval($license['grace_until']))) : ''; ?></td></tr>
      <tr><th>signature_valid</th><td><?php echo !empty($license['signature_valid']) ? 'yes' : 'no'; ?></td></tr>
    </tbody>
  </table>
</div>
