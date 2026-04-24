<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1>ライセンス</h1>

  <?php if (!empty($_GET['saved'])): ?>
    <div class="notice notice-success"><p>設定を保存しました。</p></div>
  <?php endif; ?>
  <?php if (!empty($_GET['checked'])): ?>
    <div class="notice notice-success"><p>ライセンスサーバに照会しました。</p></div>
  <?php endif; ?>
  <?php if (!empty($_GET['error'])): ?>
    <div class="notice notice-error"><p>照会に失敗しました: <?php echo esc_html($license['message']); ?></p></div>
  <?php endif; ?>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:#fff;padding:16px;margin-top:12px;">
    <?php wp_nonce_field('drwp_save_license'); ?>
    <input type="hidden" name="action" value="drwp_save_license" />
    <table class="form-table">
      <tr>
        <th><label for="drwp-api-url">API URL</label></th>
        <td><input type="url" id="drwp-api-url" class="regular-text" name="api_url" value="<?php echo esc_attr($license['api_url']); ?>" placeholder="https://license.example.com" /></td>
      </tr>
      <tr>
        <th><label for="drwp-license-key">ライセンスキー</label></th>
        <td><input type="text" id="drwp-license-key" class="regular-text" name="license_key" value="<?php echo esc_attr($license['license_key']); ?>" /></td>
      </tr>
    </table>
    <?php submit_button('設定を保存'); ?>
  </form>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
    <?php wp_nonce_field('drwp_check_license'); ?>
    <input type="hidden" name="action" value="drwp_check_license" />
    <?php submit_button('いま照会する', 'secondary', 'submit', false); ?>
  </form>

  <h2>現在の状態</h2>
  <table class="widefat striped" style="max-width:720px;">
    <tbody>
      <tr><th>有効判定</th><td><?php echo esc_html($license['status']); ?></td></tr>
      <tr><th>サーバ応答</th><td><?php echo esc_html($license['raw_status'] ?: '-'); ?></td></tr>
      <tr><th>プラン</th><td><?php echo esc_html($license['plan'] ?: '-'); ?></td></tr>
      <tr><th>有効期限</th><td><?php echo esc_html($license['expires_at'] ?: '-'); ?></td></tr>
      <tr><th>最終照会</th><td><?php echo $license['checked_at'] ? esc_html(wp_date('Y-m-d H:i:s', $license['checked_at'])) : '-'; ?></td></tr>
      <tr><th>最終有効</th><td><?php echo $license['last_valid_at'] ? esc_html(wp_date('Y-m-d H:i:s', $license['last_valid_at'])) : '-'; ?></td></tr>
      <tr><th>メッセージ</th><td><?php echo esc_html($license['message'] ?: '-'); ?></td></tr>
    </tbody>
  </table>
</div>
