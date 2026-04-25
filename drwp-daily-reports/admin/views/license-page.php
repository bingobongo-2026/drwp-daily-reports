<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1><?php esc_html_e('ライセンス', 'drwp-daily-reports'); ?></h1>

  <?php if (!empty($_GET['saved'])): ?>
    <div class="notice notice-success"><p><?php esc_html_e('設定を保存しました。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>
  <?php if (!empty($_GET['checked'])): ?>
    <div class="notice notice-success"><p><?php esc_html_e('ライセンスサーバに照会しました。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>
  <?php if (!empty($_GET['key_fetched'])): ?>
    <div class="notice notice-success"><p><?php esc_html_e('公開鍵を取得しました。以降のライセンス照会では署名検証が行われます。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>
  <?php if (!empty($_GET['error'])): ?>
    <div class="notice notice-error"><p>
      <?php
        printf(
            /* translators: %s: error message */
            esc_html__('処理に失敗しました: %s', 'drwp-daily-reports'),
            esc_html($license['message'])
        );
      ?>
    </p></div>
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
        <th><label for="drwp-license-key"><?php esc_html_e('ライセンスキー', 'drwp-daily-reports'); ?></label></th>
        <td><input type="text" id="drwp-license-key" class="regular-text" name="license_key" value="<?php echo esc_attr($license['license_key']); ?>" /></td>
      </tr>
    </table>
    <?php submit_button(__('設定を保存', 'drwp-daily-reports')); ?>
  </form>

  <div style="display:flex;gap:12px;margin-top:12px;flex-wrap:wrap;">
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <?php wp_nonce_field('drwp_check_license'); ?>
      <input type="hidden" name="action" value="drwp_check_license" />
      <?php submit_button(__('いま照会する', 'drwp-daily-reports'), 'secondary', 'submit', false); ?>
    </form>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <?php wp_nonce_field('drwp_fetch_public_key'); ?>
      <input type="hidden" name="action" value="drwp_fetch_public_key" />
      <?php submit_button(__('公開鍵を取得', 'drwp-daily-reports'), 'secondary', 'submit', false); ?>
    </form>
  </div>

  <h2><?php esc_html_e('現在の状態', 'drwp-daily-reports'); ?></h2>
  <table class="widefat striped" style="max-width:720px;">
    <tbody>
      <tr><th><?php esc_html_e('有効判定', 'drwp-daily-reports'); ?></th><td><?php echo esc_html($license['status']); ?></td></tr>
      <tr><th><?php esc_html_e('サーバ応答', 'drwp-daily-reports'); ?></th><td><?php echo esc_html($license['raw_status'] ?: '-'); ?></td></tr>
      <tr><th><?php esc_html_e('プラン', 'drwp-daily-reports'); ?></th><td><?php echo esc_html($license['plan'] ?: '-'); ?></td></tr>
      <tr><th><?php esc_html_e('有効期限', 'drwp-daily-reports'); ?></th><td><?php echo esc_html($license['expires_at'] ?: '-'); ?></td></tr>
      <tr><th><?php esc_html_e('最終照会', 'drwp-daily-reports'); ?></th><td><?php echo $license['checked_at'] ? esc_html(wp_date('Y-m-d H:i:s', $license['checked_at'])) : '-'; ?></td></tr>
      <tr><th><?php esc_html_e('最終有効', 'drwp-daily-reports'); ?></th><td><?php echo $license['last_valid_at'] ? esc_html(wp_date('Y-m-d H:i:s', $license['last_valid_at'])) : '-'; ?></td></tr>
      <tr><th><?php esc_html_e('メッセージ', 'drwp-daily-reports'); ?></th><td><?php echo esc_html($license['message'] ?: '-'); ?></td></tr>
      <tr>
        <th><?php esc_html_e('公開鍵', 'drwp-daily-reports'); ?></th>
        <td>
          <?php if ($license['public_key'] !== ''): ?>
            <code style="word-break:break-all;"><?php echo esc_html($license['public_key']); ?></code>
          <?php else: ?>
            <em><?php esc_html_e('未取得（未取得の間は署名検証がスキップされます）', 'drwp-daily-reports'); ?></em>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <th><?php esc_html_e('署名検証', 'drwp-daily-reports'); ?></th>
        <td>
          <?php
          $map = [
              'valid'   => __('有効', 'drwp-daily-reports'),
              'invalid' => __('無効', 'drwp-daily-reports'),
              'missing' => __('応答に署名なし', 'drwp-daily-reports'),
              'error'   => __('検証エラー', 'drwp-daily-reports'),
              'skipped' => __('スキップ（公開鍵未取得）', 'drwp-daily-reports'),
          ];
          echo esc_html($map[$license['signature_valid']] ?? '-');
          ?>
        </td>
      </tr>
    </tbody>
  </table>
</div>
