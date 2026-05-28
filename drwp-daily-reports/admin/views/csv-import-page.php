<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1><?php esc_html_e('CSV インポート', 'drwp-daily-reports'); ?></h1>

  <?php if (!empty($result)): ?>
    <?php if (!empty($result['ok'])): ?>
      <div class="notice notice-success"><p><?php echo esc_html($result['message']); ?></p></div>
      <?php if (!empty($result['errors'])): ?>
        <div class="notice notice-warning">
          <p><?php
            printf(
                /* translators: %d: error count */
                esc_html(_n('%d 件のエラーがありました:', '%d 件のエラーがありました:', count($result['errors']), 'drwp-daily-reports')),
                count($result['errors'])
            );
          ?></p>
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

  <p class="description"><?php esc_html_e('UTF-8 (BOM 可) の CSV を受け付けます。最大 5MB。', 'drwp-daily-reports'); ?></p>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" style="background:#fff;padding:16px;margin-top:12px;">
    <?php wp_nonce_field('drwp_import_csv'); ?>
    <input type="hidden" name="action" value="drwp_import_csv" />
    <p>
      <input type="file" name="csv" accept=".csv,text/csv" required />
    </p>
    <?php submit_button(__('インポート', 'drwp-daily-reports')); ?>
  </form>

  <h2><?php esc_html_e('列の仕様', 'drwp-daily-reports'); ?></h2>
  <p class="description"><?php esc_html_e('1 行 = 1 日報。', 'drwp-daily-reports'); ?></p>
  <table class="widefat striped" style="max-width:760px;">
    <thead><tr>
      <th><?php esc_html_e('列名', 'drwp-daily-reports'); ?></th>
      <th><?php esc_html_e('必須', 'drwp-daily-reports'); ?></th>
      <th><?php esc_html_e('説明', 'drwp-daily-reports'); ?></th>
    </tr></thead>
    <tbody>
      <?php
      $required = __('必須', 'drwp-daily-reports');
      $optional = __('任意', 'drwp-daily-reports');
      $rows = [
          ['report_date',       $required, 'YYYY-MM-DD'],
          ['work_description',  $required, __('作業内容', 'drwp-daily-reports')],
          ['project_name',      $optional, __('未登録なら自動作成', 'drwp-daily-reports')],
          ['started_at',        $optional, 'HH:MM'],
          ['ended_at',          $optional, 'HH:MM'],
          ['issues',            $optional, __('特記事項（反省・連絡・相談・提案）', 'drwp-daily-reports')],
          ['next_plan',         $optional, __('次回予定', 'drwp-daily-reports')],
          ['public_title',      $optional, __('公開タイトル', 'drwp-daily-reports')],
          ['public_intro',      $optional, __('公開導入', 'drwp-daily-reports')],
          ['public_body',       $optional, __('公開本文', 'drwp-daily-reports')],
          ['public_next_plan',  $optional, __('公開用今後の予定', 'drwp-daily-reports')],
          ['post_template',     $optional, 'standard / site_report / before_after'],
          ['post_tags',         $optional, __('カンマ区切り', 'drwp-daily-reports')],
      ];
      foreach ($rows as $r) {
          echo '<tr><td><code>' . esc_html($r[0]) . '</code></td><td>' . esc_html($r[1]) . '</td><td>' . esc_html($r[2]) . '</td></tr>';
      }
      ?>
    </tbody>
  </table>
</div>
