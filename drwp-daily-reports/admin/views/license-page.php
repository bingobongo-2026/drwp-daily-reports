<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1><?php esc_html_e('ライセンス', 'drwp-daily-reports'); ?></h1>

  <?php
    // ライセンスが active 以外で、かつ最低限の設定が入っている時に
    // 「いま照会する」を押してね という促し帯を出す。設定空っぽの時は
    // まず「設定 → 保存」が先なので、別の文言にする。
    $is_active = (string) $license['status'] === 'active';
    $is_configured = ((string) $license['api_url']) !== '' && ((string) $license['license_key']) !== '';
    if (!$is_active):
  ?>
    <div class="notice notice-warning" style="border-left-width:4px;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
      <div style="flex:1;min-width:280px;">
        <p style="margin:0 0 4px;font-weight:600;color:#1d2327;">
          <?php esc_html_e('ライセンスがアクティブではありません。', 'drwp-daily-reports'); ?>
        </p>
        <p style="margin:0;color:#50575e;">
          <?php if ($is_configured): ?>
            <?php esc_html_e('最新の状態を取得するため、下の「いま照会する」を押してください。それでも有効にならない場合は、ライセンスサーバ側の状態（停止 / 期限切れ）を確認してください。', 'drwp-daily-reports'); ?>
          <?php else: ?>
            <?php esc_html_e('まず上のフォームで API URL とライセンスキーを保存してから「いま照会する」を押してください。', 'drwp-daily-reports'); ?>
          <?php endif; ?>
        </p>
      </div>
      <?php if ($is_configured): ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
          <?php wp_nonce_field('drwp_check_license'); ?>
          <input type="hidden" name="action" value="drwp_check_license" />
          <button type="submit" class="button button-primary">
            <?php esc_html_e('いま照会する', 'drwp-daily-reports'); ?>
          </button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($_GET['saved'])): ?>
    <div class="notice notice-success"><p><?php esc_html_e('設定を保存しました。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>
  <?php if (!empty($_GET['checked'])): ?>
    <div class="notice notice-success"><p><?php esc_html_e('ライセンスサーバに照会しました。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>
  <?php if (!empty($_GET['key_fetched'])): ?>
    <div class="notice notice-success"><p><?php esc_html_e('公開鍵を取得しました。以降のライセンス照会では署名検証が行われます。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>
  <?php if (!empty($_GET['rotated'])): ?>
    <div class="notice notice-success"><p><?php esc_html_e('署名鍵をローテートしました。古い署名は previous_keys 経由で引き続き検証できます。', 'drwp-daily-reports'); ?></p></div>
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

  <?php
  $token_result = get_transient('drwp_token_write_result');
  if ($token_result) {
      delete_transient('drwp_token_write_result');
      $cls = !empty($token_result['ok']) ? 'notice-success' : 'notice-warning';
      printf('<div class="notice %s"><p>%s</p></div>', esc_attr($cls), esc_html($token_result['message']));
  }
  ?>

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
  <p class="description">
    <?php esc_html_e('署名鍵のローテーションはライセンス提供者側で行います。新しい公開鍵が配布されたら「公開鍵を取得」を実行してください。', 'drwp-daily-reports'); ?>
  </p>

  <h2><?php esc_html_e('現在の状態', 'drwp-daily-reports'); ?></h2>
  <table class="widefat striped" style="max-width:720px;">
    <tbody>
      <tr><th><?php esc_html_e('有効判定', 'drwp-daily-reports'); ?></th><td><?php echo esc_html($license['status']); ?></td></tr>
      <tr><th><?php esc_html_e('サーバ応答', 'drwp-daily-reports'); ?></th><td><?php echo esc_html($license['raw_status'] ?: '-'); ?></td></tr>
      <tr>
        <th><?php esc_html_e('プラン', 'drwp-daily-reports'); ?></th>
        <td>
          <?php
            $plan_slug = strtolower(trim((string) $license['plan']));
            if ($plan_slug === 'pro') {
                echo '<span class="drwp-plan-pill is-pro">Pro</span>';
                echo ' <span class="description">' . esc_html__('AI 機能を含むすべての機能が利用できます。', 'drwp-daily-reports') . '</span>';
            } elseif ($plan_slug === 'basic') {
                echo '<span class="drwp-plan-pill is-basic">Basic</span>';
                echo ' <span class="description">' . esc_html__('AI 以外の機能が利用できます。', 'drwp-daily-reports') . '</span>';
            } elseif ($plan_slug !== '') {
                echo esc_html($license['plan']);
                echo ' <span class="description">' . esc_html__('未知のプラン名のため Basic として扱われます。', 'drwp-daily-reports') . '</span>';
            } else {
                echo '-';
            }
          ?>
        </td>
      </tr>
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

<style>
.drwp-plan-pill{display:inline-block;padding:2px 10px;border-radius:999px;font-size:.8em;font-weight:700;color:#fff;letter-spacing:.04em}
.drwp-plan-pill.is-pro{background:linear-gradient(135deg,#6366f1,#8b5cf6)}
.drwp-plan-pill.is-basic{background:#475569}
</style>
