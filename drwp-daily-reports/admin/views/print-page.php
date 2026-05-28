<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap drwp-print-wrap">
  <h1 class="drwp-no-print"><?php esc_html_e('PDF出力', 'drwp-daily-reports'); ?></h1>

  <div class="drwp-no-print drwp-print-card">
    <h2><?php esc_html_e('絞り込み条件（承認済みの日報のみ）', 'drwp-daily-reports'); ?></h2>
    <form method="get">
      <input type="hidden" name="page" value="drwp_print" />
      <table class="form-table" role="presentation">
        <tr>
          <th><?php esc_html_e('日付範囲', 'drwp-daily-reports'); ?></th>
          <td>
            <input type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>" />
            〜
            <input type="date" name="date_to" value="<?php echo esc_attr($filters['date_to']); ?>" />
          </td>
        </tr>
        <tr>
          <th><?php esc_html_e('現場', 'drwp-daily-reports'); ?></th>
          <td>
            <select name="project_id">
              <option value="0"><?php esc_html_e('現場すべて', 'drwp-daily-reports'); ?></option>
              <?php foreach (($projects ?? []) as $p): ?>
                <option value="<?php echo (int) $p->id; ?>" <?php selected((int) $filters['project_id'], (int) $p->id); ?>><?php echo esc_html($p->name); ?></option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>
        <tr>
          <th><?php esc_html_e('ID指定', 'drwp-daily-reports'); ?></th>
          <td>
            <input type="text" name="ids" value="<?php echo esc_attr($filters['ids']); ?>" class="regular-text" placeholder="<?php esc_attr_e('例: 12, 15, 18（指定時は他の条件を無視）', 'drwp-daily-reports'); ?>" />
          </td>
        </tr>
      </table>
      <p>
        <button class="button button-primary" name="go" value="1"><?php esc_html_e('プレビュー表示', 'drwp-daily-reports'); ?></button>
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=drwp_print')); ?>"><?php esc_html_e('クリア', 'drwp-daily-reports'); ?></a>
      </p>
    </form>
  </div>

  <?php if (!empty($filters['go'])): ?>
    <div class="drwp-no-print drwp-print-card" style="margin-bottom:8px;">
      <button type="button" class="button button-primary" onclick="window.print()"><?php esc_html_e('印刷 / PDF保存', 'drwp-daily-reports'); ?></button>
      <span class="description" style="margin-left:8px;">
        <?php esc_html_e('印刷ダイアログで「PDFとして保存」を選択してください。1日報につき1ページで出力されます。', 'drwp-daily-reports'); ?>
      </span>
      <span style="margin-left:12px;">
        <?php
          printf(
              esc_html(_n('対象 %d 件', '対象 %d 件', count($reports), 'drwp-daily-reports')),
              count($reports)
          );
        ?>
      </span>
    </div>

    <div class="drwp-print-area">
      <?php if (empty($reports)): ?>
        <p><?php esc_html_e('該当する承認済みの日報がありません。', 'drwp-daily-reports'); ?></p>
      <?php else:
        $last_index = count($reports) - 1;
        foreach ($reports as $i => $r):
          $author = get_userdata((int) $r->user_id);
          $project_name = '';
          if (!empty($r->project_id)) {
              $proj = DRWP_Project::find((int) $r->project_id);
              $project_name = $proj ? $proj->name : ('#' . (int) $r->project_id);
          }
          $date_ts = strtotime((string) $r->report_date);
        ?>
        <article class="drwp-sheet">
          <div class="drwp-sheet-title"><?php esc_html_e('作業日報', 'drwp-daily-reports'); ?></div>

          <div class="drwp-sheet-top">
            <table class="drwp-sheet-meta">
              <tr>
                <th><?php esc_html_e('現場名', 'drwp-daily-reports'); ?></th>
                <td colspan="3"><?php echo esc_html($project_name); ?></td>
              </tr>
              <tr>
                <th><?php esc_html_e('日付', 'drwp-daily-reports'); ?></th>
                <td>
                  <?php
                    if ($date_ts) {
                        echo esc_html(date_i18n('Y', $date_ts)) . ' 年 '
                           . esc_html(date_i18n('n', $date_ts)) . ' 月 '
                           . esc_html(date_i18n('j', $date_ts)) . ' 日';
                    }
                  ?>
                </td>
                <?php
                  $start = substr((string) $r->started_at, 0, 5);
                  $end   = substr((string) $r->ended_at, 0, 5);
                  if ($start !== '' || $end !== ''):
                ?>
                <th><?php esc_html_e('作業時間', 'drwp-daily-reports'); ?></th>
                <td><?php echo esc_html($start); ?> 〜 <?php echo esc_html($end); ?></td>
                <?php else: ?>
                <th></th><td></td>
                <?php endif; ?>
              </tr>
              <tr>
                <th><?php esc_html_e('氏名', 'drwp-daily-reports'); ?></th>
                <td colspan="3"><?php echo esc_html($author ? $author->display_name : ''); ?></td>
              </tr>
            </table>

            <table class="drwp-sheet-stamps">
              <tr><td></td><td></td><td></td></tr>
              <tr><td></td><td></td><td></td></tr>
            </table>
          </div>

          <table class="drwp-sheet-section">
            <tr><th class="drwp-sheet-section-head"><?php esc_html_e('業務内容', 'drwp-daily-reports'); ?></th></tr>
            <tr><td class="drwp-sheet-section-body drwp-sheet-section-body-lg"><?php echo nl2br(esc_html((string) $r->work_description)); ?></td></tr>
          </table>

          <table class="drwp-sheet-section">
            <tr><th class="drwp-sheet-section-head"><?php esc_html_e('特記事項（反省・連絡・相談・提案）', 'drwp-daily-reports'); ?></th></tr>
            <tr><td class="drwp-sheet-section-body drwp-sheet-section-body-md"><?php echo nl2br(esc_html((string) $r->issues)); ?></td></tr>
          </table>

          <table class="drwp-sheet-section">
            <tr><th class="drwp-sheet-section-head"><?php esc_html_e('次回業務', 'drwp-daily-reports'); ?></th></tr>
            <tr><td class="drwp-sheet-section-body drwp-sheet-section-body-md"><?php echo nl2br(esc_html((string) $r->next_plan)); ?></td></tr>
          </table>
        </article>
        <?php if ($i < $last_index): ?><div class="drwp-print-pagebreak"></div><?php endif; ?>
      <?php endforeach; endif; ?>
    </div>
  <?php endif; ?>
</div>

<style>
.drwp-print-card{background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:12px 16px;margin-bottom:12px}
.drwp-print-card>h2{margin:0 0 8px;font-size:.95em;color:#1d2327;border-bottom:1px solid #e5e7eb;padding-bottom:6px}
.drwp-print-area{background:#f3f4f6;padding:24px}
.drwp-print-pagebreak{height:24px}
.drwp-sheet{background:#fff;padding:18mm;margin:0 auto 16px;max-width:210mm;min-height:280mm;box-sizing:border-box;font-family:"Noto Sans JP","Hiragino Sans","Yu Gothic",sans-serif;color:#1d2327;font-size:11pt;line-height:1.5;display:flex;flex-direction:column}
.drwp-sheet-title{text-align:center;font-size:18pt;font-weight:700;margin:0;padding:8px 0;background:#e5e7eb;border:1px solid #1d2327}
.drwp-sheet-top{display:flex;gap:12px;margin:10mm 0 8px;align-items:flex-start}
.drwp-sheet-meta{border-collapse:collapse;flex:1}
.drwp-sheet-meta th,.drwp-sheet-meta td{border:1px solid #1d2327;padding:4px 8px;font-size:10pt}
.drwp-sheet-meta th{background:#e5e7eb;width:50px;text-align:center;font-weight:700;white-space:nowrap}
.drwp-sheet-meta td{min-width:180px}
.drwp-sheet-stamps{border-collapse:collapse}
.drwp-sheet-stamps td{border:1px solid #1d2327;width:42px;height:42px}
.drwp-sheet-section{width:100%;border-collapse:collapse;margin-top:8px;table-layout:fixed}
.drwp-sheet-section-head{background:#e5e7eb;border:1px solid #1d2327;text-align:center;font-weight:700;font-size:11pt;padding:4px}
.drwp-sheet-section-body{border:1px solid #1d2327;padding:8px;vertical-align:top;white-space:pre-wrap;word-break:break-all}
.drwp-sheet-section-body-lg{height:120mm}
.drwp-sheet-section-body-md{height:30mm}
@media print{
  body{background:#fff !important}
  #adminmenumain,#wpadminbar,#wpfooter,.update-nag,.drwp-no-print{display:none !important}
  #wpcontent,#wpbody-content{margin-left:0 !important;padding:0 !important}
  .wrap{margin:0 !important;padding:0 !important}
  .drwp-print-area{background:#fff !important;padding:0 !important}
  .drwp-print-pagebreak{display:none}
  .drwp-sheet{margin:0;padding:0;min-height:auto;max-width:none;page-break-after:always;break-after:page;page-break-inside:avoid}
  .drwp-sheet-top{margin:10mm 0 8px !important}
  .drwp-sheet:last-child{page-break-after:auto}
  @page{margin:15mm;size:A4 portrait}
}
</style>
