<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap drwp-help">
  <h1 class="wp-heading-inline"><?php esc_html_e('日報マン 使い方ガイド', 'drwp-daily-reports'); ?></h1>
  <a class="page-title-action" href="<?php echo esc_url(add_query_arg('print', 1, $base_url)); ?>" target="_blank" rel="noopener">
    <?php esc_html_e('PDFで出力', 'drwp-daily-reports'); ?>
  </a>
  <hr class="wp-header-end">

  <p class="description" style="max-width:760px;">
    <?php esc_html_e('現場の作業日報を WordPress 上で運用するためのプラグインです。下のタブから知りたい項目を開いてください。リンクのままブックマーク・社内共有できます。「PDFで出力」を押すと全タブを連結した印刷ビューが開き、ブラウザの印刷ダイアログから「PDF として保存」できます。', 'drwp-daily-reports'); ?>
  </p>

  <h2 class="nav-tab-wrapper" style="margin-top:18px;">
    <?php foreach ($tabs as $tslug => $label):
        $class = 'nav-tab' . ($current === $tslug ? ' nav-tab-active' : '');
        $url = $tslug === 'intro' ? $base_url : add_query_arg('tab', $tslug, $base_url);
    ?>
      <a class="<?php echo esc_attr($class); ?>" href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a>
    <?php endforeach; ?>
  </h2>

  <style>
    .drwp-help .card { background:#fff; border:1px solid #c3c4c7; border-radius:8px; padding:18px 22px; margin:14px 0; max-width:880px; }
    .drwp-help .card h2 { margin-top:0; }
    .drwp-help .card h3 { margin-top:20px; font-size:1.05em; color:#1d2327; }
    .drwp-help .card ol, .drwp-help .card ul { margin:8px 0 12px 22px; line-height:1.8; }
    .drwp-help .card p { line-height:1.8; }
    .drwp-help .pill { display:inline-block; padding:1px 8px; border-radius:999px; font-size:.78em; background:#e0e7ff; color:#3730a3; vertical-align:middle; }
    .drwp-help .pill.pro { background:#fef3c7; color:#92400e; }
    .drwp-help .pill.admin { background:#fee2e2; color:#991b1b; }
    .drwp-help .pill.reviewer { background:#dcfce7; color:#166534; }
    .drwp-help .tip { background:#eff6ff; border-left:4px solid #3b82f6; padding:10px 14px; margin:12px 0; border-radius:0 6px 6px 0; }
    .drwp-help .warn { background:#fef3c7; border-left:4px solid #f59e0b; padding:10px 14px; margin:12px 0; border-radius:0 6px 6px 0; }
    .drwp-help kbd { background:#f6f7f7; border:1px solid #c3c4c7; border-bottom-width:2px; border-radius:4px; padding:1px 6px; font-size:.85em; }
    .drwp-help code { background:#f6f7f7; padding:1px 6px; border-radius:4px; font-size:.92em; }
  </style>

  <?php DRWP_Help::render_section($current); ?>
</div>
