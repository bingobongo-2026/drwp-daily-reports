<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1><?php esc_html_e('公開プレビュー', 'drwp-daily-reports'); ?></h1>
  <?php if (empty($report)): ?>
    <div class="notice notice-error"><p><?php esc_html_e('日報が見つかりません。', 'drwp-daily-reports'); ?></p></div>
  <?php else: ?>
    <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=drwp_report_edit&id=' . (int) $report->id)); ?>"><?php esc_html_e('編集に戻る', 'drwp-daily-reports'); ?></a></p>
    <?php echo DRWP_Post_Converter::build_preview_html($report); ?>
  <?php endif; ?>
</div>
