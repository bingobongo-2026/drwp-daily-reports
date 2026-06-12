<?php
/**
 * Print / PDF view of the 使い方ガイド. WP admin chrome is suppressed
 * and the page auto-opens the browser print dialog so the operator can
 * "save as PDF" in one step — same approach as DRWP_Print to dodge
 * the Japanese-font headache of server-side PDF libs.
 */
if (!defined('ABSPATH')) exit;

nocache_headers();
?><!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <title><?php echo esc_html__('日報マン 使い方ガイド', 'drwp-daily-reports'); ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Hiragino Kaku Gothic ProN", "Yu Gothic", "Meiryo", sans-serif; color:#1d2327; margin:0; padding:24px 40px; background:#fff; line-height:1.75; }
    .drwp-help { max-width:880px; margin:0 auto; }
    .drwp-help .doc-head { display:flex; justify-content:space-between; align-items:baseline; border-bottom:2px solid #1d2327; padding-bottom:8px; margin-bottom:16px; }
    .drwp-help h1 { font-size:1.6em; margin:0; }
    .drwp-help .doc-head .meta { color:#646970; font-size:.9em; }
    .drwp-help .toc { background:#f6f7f7; border:1px solid #c3c4c7; border-radius:6px; padding:10px 18px; margin:0 0 24px; }
    .drwp-help .toc h2 { font-size:1em; margin:0 0 6px; color:#3c434a; }
    .drwp-help .toc ol { margin:0 0 0 22px; columns:2; column-gap:24px; }
    .drwp-help .toc li { line-height:1.7; break-inside:avoid; }
    .drwp-help .toc a { color:#1d2327; text-decoration:none; }
    .drwp-help .section { page-break-before:always; break-before:page; }
    .drwp-help .section:first-of-type { page-break-before:auto; break-before:auto; }
    .drwp-help .section-anchor { display:block; height:0; visibility:hidden; }
    .drwp-help .card { background:#fff; border:1px solid #c3c4c7; border-radius:8px; padding:18px 22px; margin:0 0 18px; }
    .drwp-help .card h2 { margin-top:0; }
    .drwp-help .card h3 { margin-top:18px; font-size:1.05em; color:#1d2327; }
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
    .drwp-help .toolbar { position:sticky; top:0; background:#fff; padding:6px 0 10px; border-bottom:1px solid #dcdcde; margin-bottom:16px; display:flex; gap:8px; justify-content:flex-end; }
    .drwp-help .toolbar button, .drwp-help .toolbar a {
      padding:6px 14px; border:1px solid #2271b1; border-radius:4px; background:#2271b1; color:#fff;
      font-size:.92em; cursor:pointer; text-decoration:none;
    }
    .drwp-help .toolbar .secondary { background:#f6f7f7; color:#2271b1; }
    .drwp-help .card { break-inside:avoid; }
    @media print {
      body { padding:0; }
      .drwp-help .toolbar { display:none; }
      .drwp-help .card { border:0; padding:0; }
      .drwp-help .toc { background:transparent; }
      @page { margin:18mm 16mm 18mm 16mm; }
    }
  </style>
</head>
<body>
<div class="drwp-help">
  <div class="toolbar">
    <button type="button" onclick="window.print()"><?php esc_html_e('印刷 / PDF として保存', 'drwp-daily-reports'); ?></button>
    <a class="secondary" href="<?php echo esc_url($base_url); ?>"><?php esc_html_e('ガイドに戻る', 'drwp-daily-reports'); ?></a>
  </div>

  <div class="doc-head">
    <h1><?php esc_html_e('日報マン 使い方ガイド', 'drwp-daily-reports'); ?></h1>
    <div class="meta">
      <?php
        printf(
          /* translators: 1: plugin version, 2: today's date */
          esc_html__('Version %1$s — %2$s 出力', 'drwp-daily-reports'),
          esc_html(defined('DRWP_VERSION') ? DRWP_VERSION : ''),
          esc_html(wp_date('Y-m-d'))
        );
      ?>
    </div>
  </div>

  <div class="toc">
    <h2><?php esc_html_e('目次', 'drwp-daily-reports'); ?></h2>
    <ol>
      <?php foreach ($tabs as $tslug => $label): ?>
        <li><a href="#sec-<?php echo esc_attr($tslug); ?>"><?php echo esc_html($label); ?></a></li>
      <?php endforeach; ?>
    </ol>
  </div>

  <?php foreach ($tabs as $tslug => $label): ?>
    <section class="section" id="sec-<?php echo esc_attr($tslug); ?>">
      <span class="section-anchor"></span>
      <?php DRWP_Help::render_section($tslug); ?>
    </section>
  <?php endforeach; ?>
</div>
<script>
  // Open the print dialog automatically — operator just confirms
  // "destination: Save as PDF". Delayed by a tick so the layout
  // settles and Japanese fonts finish swapping in.
  window.addEventListener('load', function () {
    setTimeout(function () { window.print(); }, 200);
  });
</script>
</body>
</html>
