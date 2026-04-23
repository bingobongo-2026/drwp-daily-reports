<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1>公開プレビュー</h1>
  <?php if (empty($report)): ?>
    <div class="notice notice-error"><p>日報が見つかりません。</p></div>
  <?php else: ?>
    <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=drwp_report_edit&id=' . (int) $report->id)); ?>">編集に戻る</a></p>
    <?php echo DRWP_Post_Converter::build_preview_html($report); ?>
  <?php endif; ?>
</div>
