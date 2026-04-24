<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1>CSV インポート</h1>

  <?php if (!empty($result)): ?>
    <?php if (!empty($result['ok'])): ?>
      <div class="notice notice-success"><p><?php echo esc_html($result['message']); ?></p></div>
      <?php if (!empty($result['errors'])): ?>
        <div class="notice notice-warning">
          <p><?php echo count($result['errors']); ?> 件のエラーがありました:</p>
          <ul style="margin-left:20px;">
            <?php foreach ($result['errors'] as $err): ?>
              <li><?php echo esc_html($err); ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="notice notice-error"><p><?php echo esc_html($result['message']); ?></p></div>
    <?php endif; ?>
  <?php endif; ?>

  <p class="description">UTF-8 (BOM 可) の CSV を受け付けます。最大 5MB。</p>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" style="background:#fff;padding:16px;margin-top:12px;">
    <?php wp_nonce_field('drwp_import_csv'); ?>
    <input type="hidden" name="action" value="drwp_import_csv" />
    <p>
      <input type="file" name="csv" accept=".csv,text/csv" required />
    </p>
    <?php submit_button('インポート'); ?>
  </form>

  <h2>列の仕様</h2>
  <table class="widefat striped" style="max-width:760px;">
    <thead><tr><th>列名</th><th>必須</th><th>説明</th></tr></thead>
    <tbody>
      <tr><td><code>report_date</code></td><td>必須</td><td>YYYY-MM-DD</td></tr>
      <tr><td><code>work_description</code></td><td>必須</td><td>作業内容</td></tr>
      <tr><td><code>project_name</code></td><td>任意</td><td>未登録なら自動作成</td></tr>
      <tr><td><code>issues</code></td><td>任意</td><td>問題点</td></tr>
      <tr><td><code>next_plan</code></td><td>任意</td><td>次回予定</td></tr>
      <tr><td><code>public_title</code></td><td>任意</td><td>公開タイトル</td></tr>
      <tr><td><code>public_intro</code></td><td>任意</td><td>公開導入</td></tr>
      <tr><td><code>public_body</code></td><td>任意</td><td>公開本文</td></tr>
      <tr><td><code>public_next_plan</code></td><td>任意</td><td>公開用今後の予定</td></tr>
      <tr><td><code>post_template</code></td><td>任意</td><td>standard / site_report / before_after</td></tr>
      <tr><td><code>post_tags</code></td><td>任意</td><td>カンマ区切り</td></tr>
    </tbody>
  </table>
</div>
