<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1><?php esc_html_e('公開設定', 'drwp-daily-reports'); ?></h1>

  <?php if (!empty($_GET['saved'])): ?>
    <div class="notice notice-success"><p><?php esc_html_e('保存しました。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>

  <p class="description">
    <?php esc_html_e('日報を WordPress 投稿に変換するときの出力先と動作を切り替えます。既に変換済みの記事は元の投稿タイプを保持します（既存リンクが壊れません）。', 'drwp-daily-reports'); ?>
  </p>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:#fff;padding:16px;margin-top:12px;">
    <?php wp_nonce_field('drwp_save_output'); ?>
    <input type="hidden" name="action" value="drwp_save_output" />

    <table class="form-table">
      <tr>
        <th><?php esc_html_e('投稿タイプ', 'drwp-daily-reports'); ?></th>
        <td>
          <fieldset>
            <label>
              <input type="radio" name="post_type" value="post" <?php checked($settings['post_type'], 'post'); ?> />
              <?php esc_html_e('標準投稿 (post)', 'drwp-daily-reports'); ?>
              <span class="description"><?php esc_html_e('— 既存テーマのトップ・記事一覧にそのまま流れます。', 'drwp-daily-reports'); ?></span>
            </label><br />
            <label>
              <input type="radio" name="post_type" value="drwp_report" <?php checked($settings['post_type'], 'drwp_report'); ?> />
              <?php esc_html_e('日報 CPT (drwp_report)', 'drwp-daily-reports'); ?>
              <span class="description"><?php esc_html_e('— 通常の投稿と分離。アーカイブ /drwp-report/ で表示。', 'drwp-daily-reports'); ?></span>
            </label>
          </fieldset>
        </td>
      </tr>
      <tr>
        <th><?php esc_html_e('アイキャッチ画像', 'drwp-daily-reports'); ?></th>
        <td>
          <label>
            <input type="checkbox" name="auto_thumbnail" value="1" <?php checked($settings['auto_thumbnail']); ?> />
            <?php esc_html_e('日報の最初の写真を自動的にアイキャッチ画像に設定する', 'drwp-daily-reports'); ?>
          </label>
          <p class="description"><?php esc_html_e('既にアイキャッチが設定された投稿には影響しません。', 'drwp-daily-reports'); ?></p>
        </td>
      </tr>
    </table>

    <?php submit_button(__('保存', 'drwp-daily-reports')); ?>
  </form>
</div>
