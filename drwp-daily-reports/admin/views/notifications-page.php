<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1>通知設定</h1>

  <?php if (!empty($_GET['saved'])): ?>
    <div class="notice notice-success"><p>保存しました。</p></div>
  <?php endif; ?>

  <p class="description">メールは WordPress の <code>wp_mail()</code> で送信されます。SMTP の設定は別途プラグインが必要です。</p>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:#fff;padding:16px;margin-top:12px;">
    <?php wp_nonce_field('drwp_save_notifications'); ?>
    <input type="hidden" name="action" value="drwp_save_notifications" />

    <table class="form-table">
      <tr>
        <th>マスタースイッチ</th>
        <td>
          <label><input type="checkbox" name="enabled" value="1" <?php checked($settings['enabled']); ?> /> メール通知を有効にする</label>
          <p class="description">オフの場合、以下の個別トグルにかかわらずメールは送信されません。</p>
        </td>
      </tr>
      <tr>
        <th>レビュー待ち通知</th>
        <td>
          <label><input type="checkbox" name="on_pending" value="1" <?php checked($settings['on_pending']); ?> /> 日報が <code>pending</code> で保存されたらレビュアに通知</label>
          <p class="description">送信先: <code>edit_others_posts</code> 権限を持つ全ユーザー。</p>
        </td>
      </tr>
      <tr>
        <th>レビュー結果通知</th>
        <td>
          <label><input type="checkbox" name="on_review" value="1" <?php checked($settings['on_review']); ?> /> <code>approved</code> / <code>needs_revision</code> に変わったら作成者に通知</label>
        </td>
      </tr>
      <tr>
        <th>コメント通知</th>
        <td>
          <label><input type="checkbox" name="on_comment" value="1" <?php checked($settings['on_comment']); ?> /> 新しいコメントが追加されたら関係者に通知</label>
          <p class="description">作成者 + レビュア（コメント投稿者本人は除外）。</p>
        </td>
      </tr>
      <tr>
        <th><label for="drwp-from-email">送信元メールアドレス</label></th>
        <td>
          <input type="email" id="drwp-from-email" class="regular-text" name="from_email" value="<?php echo esc_attr($settings['from_email']); ?>" placeholder="noreply@example.com" />
          <p class="description">空の場合は WordPress のデフォルト (<code>wordpress@...</code>) が使われます。</p>
        </td>
      </tr>
    </table>

    <?php submit_button('保存'); ?>
  </form>
</div>
