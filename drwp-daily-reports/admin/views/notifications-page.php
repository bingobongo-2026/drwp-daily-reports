<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1><?php esc_html_e('通知設定', 'drwp-daily-reports'); ?></h1>

  <?php if (!empty($_GET['saved'])): ?>
    <div class="notice notice-success"><p><?php esc_html_e('保存しました。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>

  <p class="description">
    <?php
      printf(
          /* translators: %s: wp_mail() function reference */
          esc_html__('メールは WordPress の %s で送信されます。SMTP の設定は別途プラグインが必要です。', 'drwp-daily-reports'),
          '<code>wp_mail()</code>'
      );
    ?>
  </p>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:#fff;padding:16px;margin-top:12px;">
    <?php wp_nonce_field('drwp_save_notifications'); ?>
    <input type="hidden" name="action" value="drwp_save_notifications" />

    <table class="form-table">
      <tr>
        <th><?php esc_html_e('マスタースイッチ', 'drwp-daily-reports'); ?></th>
        <td>
          <label><input type="checkbox" name="enabled" value="1" <?php checked($settings['enabled']); ?> />
            <?php esc_html_e('メール通知を有効にする', 'drwp-daily-reports'); ?>
          </label>
          <p class="description"><?php esc_html_e('オフの場合、以下の個別トグルにかかわらずメールは送信されません。', 'drwp-daily-reports'); ?></p>
        </td>
      </tr>
      <tr>
        <th><?php esc_html_e('レビュー待ち通知', 'drwp-daily-reports'); ?></th>
        <td>
          <label><input type="checkbox" name="on_pending" value="1" <?php checked($settings['on_pending']); ?> />
            <?php
              printf(
                  /* translators: 1: pending status code 2: review capability */
                  esc_html__('日報が %1$s で保存されたら %2$s 権限を持つユーザーに通知', 'drwp-daily-reports'),
                  '<code>pending</code>',
                  '<code>edit_others_posts</code>'
              );
            ?>
          </label>
        </td>
      </tr>
      <tr>
        <th><?php esc_html_e('レビュー結果通知', 'drwp-daily-reports'); ?></th>
        <td>
          <label><input type="checkbox" name="on_review" value="1" <?php checked($settings['on_review']); ?> />
            <?php
              printf(
                  /* translators: 1: approved status 2: needs_revision status */
                  esc_html__('%1$s / %2$s に変わったら作成者に通知', 'drwp-daily-reports'),
                  '<code>approved</code>',
                  '<code>needs_revision</code>'
              );
            ?>
          </label>
        </td>
      </tr>
      <tr>
        <th><?php esc_html_e('コメント通知', 'drwp-daily-reports'); ?></th>
        <td>
          <label><input type="checkbox" name="on_comment" value="1" <?php checked($settings['on_comment']); ?> />
            <?php esc_html_e('新しいコメントが追加されたら関係者に通知', 'drwp-daily-reports'); ?>
          </label>
          <p class="description"><?php esc_html_e('作成者 + レビュア（コメント投稿者本人は除外）。', 'drwp-daily-reports'); ?></p>
        </td>
      </tr>
      <tr>
        <th><label for="drwp-from-email"><?php esc_html_e('送信元メールアドレス', 'drwp-daily-reports'); ?></label></th>
        <td>
          <input type="email" id="drwp-from-email" class="regular-text" name="from_email" value="<?php echo esc_attr($settings['from_email']); ?>" placeholder="noreply@example.com" />
          <p class="description"><?php esc_html_e('空の場合は WordPress のデフォルトが使われます。', 'drwp-daily-reports'); ?></p>
        </td>
      </tr>
    </table>

    <?php submit_button(__('保存', 'drwp-daily-reports')); ?>
  </form>
</div>
