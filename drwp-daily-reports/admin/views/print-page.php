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
          <th><?php esc_html_e('案件', 'drwp-daily-reports'); ?></th>
          <td>
            <select name="project_id">
              <option value="0"><?php esc_html_e('案件すべて', 'drwp-daily-reports'); ?></option>
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
    <div class="drwp-no-print drwp-print-card drwp-print-toolbar">
      <div class="drwp-print-toolbar-left">
        <button type="button" class="button button-primary" onclick="window.print()"><?php esc_html_e('印刷 / PDF保存', 'drwp-daily-reports'); ?></button>
        <span class="description">
          <?php esc_html_e('印刷ダイアログで「PDFとして保存」を選択してください。1日報につき1ページで出力されます。', 'drwp-daily-reports'); ?>
        </span>
        <span class="drwp-print-count">
          <?php
            printf(
                esc_html(_n('対象 %d 件', '対象 %d 件', count($reports), 'drwp-daily-reports')),
                count($reports)
            );
          ?>
        </span>
      </div>
      <?php if (count($reports) > 1): ?>
      <div class="drwp-print-nav">
        <button type="button" id="drwp-prev" class="button" aria-label="<?php esc_attr_e('前の日報', 'drwp-daily-reports'); ?>">◀</button>
        <span class="counter" id="drwp-counter" aria-live="polite">1 / <?php echo (int) count($reports); ?></span>
        <button type="button" id="drwp-next" class="button" aria-label="<?php esc_attr_e('次の日報', 'drwp-daily-reports'); ?>">▶</button>
      </div>
      <?php endif; ?>
    </div>

    <div class="drwp-print-area is-focus-mode">
      <?php if (empty($reports)): ?>
        <p><?php esc_html_e('該当する承認済みの日報がありません。', 'drwp-daily-reports'); ?></p>
      <?php else: ?>
        <?php if (count($reports) > 1): ?>
        <aside class="drwp-print-toc drwp-no-print" aria-label="<?php esc_attr_e('日報目次', 'drwp-daily-reports'); ?>">
          <h3><?php esc_html_e('目次', 'drwp-daily-reports'); ?></h3>
          <ol>
            <?php foreach ($reports as $i => $r):
              $toc_author = get_userdata((int) $r->user_id);
              $toc_project = '';
              if (!empty($r->project_id)) {
                  $tp = DRWP_Project::find((int) $r->project_id);
                  $toc_project = $tp ? $tp->name : ('#' . (int) $r->project_id);
              }
              $toc_ts = strtotime((string) $r->report_date);
              $toc_date = $toc_ts ? date_i18n('n/j', $toc_ts) : '';
              $toc_meta_parts = array_filter([$toc_project, $toc_author ? $toc_author->display_name : '']);
            ?>
            <li>
              <a href="#drwp-sheet-<?php echo (int) $i; ?>" data-index="<?php echo (int) $i; ?>">
                <span class="toc-date"><?php echo esc_html($toc_date); ?></span>
                <span class="toc-meta"><?php echo esc_html(implode(' / ', $toc_meta_parts)); ?></span>
              </a>
            </li>
            <?php endforeach; ?>
          </ol>
        </aside>
        <?php endif; ?>

        <div class="drwp-print-sheets">
      <?php
        $last_index = count($reports) - 1;
        foreach ($reports as $i => $r):
          $author = get_userdata((int) $r->user_id);
          $project_name = '';
          if (!empty($r->project_id)) {
              $proj = DRWP_Project::find((int) $r->project_id);
              $project_name = $proj ? $proj->name : ('#' . (int) $r->project_id);
          }
          $date_ts = strtotime((string) $r->report_date);
          $submitted_ts = strtotime((string) $r->created_at);

          $start = substr((string) $r->started_at, 0, 5);
          $end   = substr((string) $r->ended_at, 0, 5);
          $work_time_text = '';
          if ($start !== '' || $end !== '') {
              $work_time_text = $start . ' 〜 ' . $end;
              // Total elapsed time. Negative spans (e.g., a typo) are
              // dropped silently — printing "-1時間" on a paper form is
              // worse than printing nothing.
              $s = strtotime($r->report_date . ' ' . $start);
              $e = strtotime($r->report_date . ' ' . $end);
              if ($s && $e && $e > $s) {
                  $mins = (int) (($e - $s) / 60);
                  $h = intdiv($mins, 60);
                  $m = $mins % 60;
                  $total = $h . ' 時間' . ($m > 0 ? ' ' . $m . ' 分' : '');
                  $work_time_text .= '（合計 ' . $total . '）';
              }
          }

          $approval = $approvals[(int) $r->id] ?? null;

          // Photo attachments for the report. When present we both
          // (a) print a "別紙に画像あり" notice on the cover sheet and
          // (b) emit one or more 6-up "別紙" photo sheets right after
          // this article so they land on the immediately following PDF
          // pages.
          $report_photos = DRWP_Media::for_report((int) $r->id);
          $photo_chunks  = $report_photos ? array_chunk($report_photos, 6) : [];
          $photo_pages   = count($photo_chunks);
        ?>
        <article class="drwp-sheet<?php echo $i === 0 ? ' is-current' : ''; ?>" id="drwp-sheet-<?php echo (int) $i; ?>" data-index="<?php echo (int) $i; ?>">
          <div class="drwp-sheet-title"><?php esc_html_e('作業日報', 'drwp-daily-reports'); ?></div>

          <table class="drwp-sheet-meta">
            <colgroup>
              <col class="drwp-sheet-meta-label" />
              <col />
              <col class="drwp-sheet-meta-label" />
              <col />
            </colgroup>
            <tr>
              <th><?php esc_html_e('日報 No.', 'drwp-daily-reports'); ?></th>
              <td colspan="3"><?php echo esc_html('#' . (int) $r->id); ?></td>
            </tr>
            <tr>
              <th><?php esc_html_e('案件名', 'drwp-daily-reports'); ?></th>
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
              <th><?php esc_html_e('作業時間', 'drwp-daily-reports'); ?></th>
              <td><?php echo esc_html($work_time_text); ?></td>
            </tr>
            <tr>
              <th><?php esc_html_e('記入者', 'drwp-daily-reports'); ?></th>
              <td><?php echo esc_html($author ? $author->display_name : ''); ?></td>
              <th><?php esc_html_e('提出日', 'drwp-daily-reports'); ?></th>
              <td>
                <?php
                  if ($submitted_ts) {
                      echo esc_html(date_i18n('Y 年 n 月 j 日', $submitted_ts));
                  }
                ?>
              </td>
            </tr>
          </table>

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

          <?php if ($photo_pages > 0): ?>
            <p class="drwp-sheet-attach-notice">
              <?php
                printf(
                    esc_html(_n('※ 別紙に画像あり（%d 枚）', '※ 別紙に画像あり（%d 枚）', count($report_photos), 'drwp-daily-reports')),
                    count($report_photos)
                );
              ?>
            </p>
          <?php endif; ?>

          <p class="drwp-sheet-approval">
            <?php if ($approval):
              $approved_ts = strtotime((string) $approval->created_at);
              $approved_date = $approved_ts ? date_i18n('Y 年 n 月 j 日', $approved_ts) : '';
              $approver = $approval->display_name ?: __('（不明）', 'drwp-daily-reports');
            ?>
              <?php echo esc_html($approved_date); ?>　<?php esc_html_e('確認者：', 'drwp-daily-reports'); ?><?php echo esc_html($approver); ?>
            <?php else: ?>
              <?php esc_html_e('確認日：　　　　年　　月　　日　　確認者：', 'drwp-daily-reports'); ?>
            <?php endif; ?>
          </p>
        </article>

        <?php
          // Photo "別紙" pages — six photos per page in a 2x3 grid.
          // Each chunk becomes its own <article> with data-index =
          // parent report index so the focus-mode JS can show/hide it
          // alongside the cover sheet on screen, and so each prints on
          // its own page.
          foreach ($photo_chunks as $chunk_idx => $chunk):
        ?>
        <article class="drwp-photo-sheet<?php echo $i === 0 ? ' is-current' : ''; ?>"
                 data-index="<?php echo (int) $i; ?>">
          <div class="drwp-photo-sheet-head">
            <span class="drwp-photo-sheet-head-left">
              <?php echo esc_html('#' . (int) $r->id); ?>
              <?php if ($project_name !== ''): ?>
                ／ <?php echo esc_html($project_name); ?>
              <?php endif; ?>
              <?php if ($date_ts): ?>
                ／ <?php echo esc_html(date_i18n('Y 年 n 月 j 日', $date_ts)); ?>
              <?php endif; ?>
            </span>
            <span class="drwp-photo-sheet-head-right">
              <?php
                printf(
                    esc_html__('別紙 %1$d / %2$d', 'drwp-daily-reports'),
                    (int) $chunk_idx + 1,
                    (int) $photo_pages
                );
              ?>
            </span>
          </div>
          <div class="drwp-photo-grid">
            <?php
              $filled = 0;
              foreach ($chunk as $photo):
                $img_url = wp_get_attachment_image_url((int) $photo->attachment_id, 'large');
                if (!$img_url) continue;
                $filled++;
            ?>
            <figure class="drwp-photo-cell">
              <div class="drwp-photo-cell-img">
                <img src="<?php echo esc_url($img_url); ?>" alt="" />
              </div>
              <?php if (!empty($photo->caption)): ?>
                <figcaption><?php echo esc_html((string) $photo->caption); ?></figcaption>
              <?php endif; ?>
            </figure>
            <?php endforeach; ?>
            <?php
              // Always reserve six cells per 別紙 page so the layout
              // doesn't reflow when the last chunk has fewer photos —
              // the operator wants every page to show the same 2x3
              // frame whether there are 1 photo or 6.
              for ($empty_idx = $filled; $empty_idx < 6; $empty_idx++):
            ?>
            <div class="drwp-photo-cell drwp-photo-cell-empty" aria-hidden="true"></div>
            <?php endfor; ?>
          </div>
        </article>
        <?php endforeach; ?>

        <?php if ($i < $last_index): ?><div class="drwp-print-pagebreak"></div><?php endif; ?>
      <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<style>
.drwp-print-card{background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:12px 16px;margin-bottom:12px}
.drwp-print-card>h2{margin:0 0 8px;font-size:.95em;color:#1d2327;border-bottom:1px solid #e5e7eb;padding-bottom:6px}
/* Sticky so the print button stays reachable after the user scrolls
   through the body of a sheet. 32px clears the WP admin bar; for a
   logged-out / front-end render the offset still works because the
   sheet area has plenty of padding above. */
.drwp-print-toolbar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;position:sticky;top:32px;z-index:10}
.drwp-print-toolbar-left{display:flex;align-items:center;gap:10px;flex-wrap:wrap;flex:1;min-width:0}
.drwp-print-toolbar-left .description{color:#475569}
.drwp-print-toolbar-left .drwp-print-count{color:#1d2327;font-weight:600;white-space:nowrap}
.drwp-print-nav{display:inline-flex;align-items:center;gap:8px;padding-left:12px;border-left:1px solid #e5e7eb}
.drwp-print-nav .counter{font-variant-numeric:tabular-nums;color:#475569;min-width:60px;text-align:center}
.drwp-print-area{background:#f3f4f6;padding:24px;display:flex;gap:16px;align-items:flex-start}
.drwp-print-pagebreak{height:24px}
.drwp-print-toc{position:sticky;top:96px;width:240px;flex-shrink:0;background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:10px 0;max-height:calc(100vh - 120px);overflow:auto;font-size:.92em}
.drwp-print-toc h3{margin:0 12px 6px;font-size:.95em;color:#1d2327;border-bottom:1px solid #e5e7eb;padding-bottom:6px}
.drwp-print-toc ol{list-style:none;margin:0;padding:0;counter-reset:drwp-toc}
.drwp-print-toc li{counter-increment:drwp-toc}
.drwp-print-toc a{display:block;padding:6px 12px;color:#1d2327;text-decoration:none;border-left:3px solid transparent;line-height:1.35}
.drwp-print-toc a:hover{background:#f1f5f9}
.drwp-print-toc a.is-current{background:#dbeafe;border-left-color:#2563eb}
.drwp-print-toc a::before{content:counter(drwp-toc) ". ";color:#64748b;font-variant-numeric:tabular-nums}
.drwp-print-toc .toc-date{font-weight:600}
.drwp-print-toc .toc-meta{color:#475569;font-size:.92em;display:block;margin-left:1.4em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.drwp-print-sheets{flex:1;min-width:0}
.drwp-sheet{background:#fff;padding:18mm;margin:0 auto 16px;max-width:210mm;min-height:280mm;box-sizing:border-box;font-family:"Noto Sans JP","Hiragino Sans","Yu Gothic",sans-serif;color:#1d2327;font-size:11pt;line-height:1.5;display:flex;flex-direction:column}
.drwp-sheet-title{text-align:center;font-size:18pt;font-weight:700;margin:0;padding:8px 0;background:#e5e7eb;border:1px solid #1d2327}
.drwp-sheet-meta{width:100%;border-collapse:collapse;margin-top:6px;table-layout:fixed}
.drwp-sheet-meta th,.drwp-sheet-meta td{border:1px solid #1d2327;padding:3px 8px;font-size:10pt}
.drwp-sheet-meta th{background:#e5e7eb;text-align:center;font-weight:700;white-space:nowrap}
.drwp-sheet-meta-label{width:80px}
.drwp-sheet-section{width:100%;border-collapse:collapse;margin-top:6px;table-layout:fixed}
.drwp-sheet-section-head{background:#e5e7eb;border:1px solid #1d2327;text-align:center;font-weight:700;font-size:11pt;padding:3px}
.drwp-sheet-section-body{border:1px solid #1d2327;padding:8px;vertical-align:top;white-space:pre-wrap;word-break:break-all}
/* Sized so the whole cover (title + meta + three sections + bottom
   band) stays under A4 printable height (~267mm with 15mm @page
   margins). Earlier values (lg 110mm / md 28mm) tipped the cover
   over the page edge, so the 別紙バナー + 承認行 spilled to a
   second page on every report — that's the "改ページが多い" the
   operator was seeing. */
.drwp-sheet-section-body-lg{height:88mm}
.drwp-sheet-section-body-md{height:22mm}
.drwp-sheet-approval{margin:6px 0 0;padding:4px 8px;font-size:10.5pt;text-align:right}
.drwp-sheet-attach-notice{margin:6px 0 0;padding:4px 10px;font-size:10pt;font-weight:600;border:1px solid #1d2327;background:#fef9c3;text-align:center}

/* Photo "別紙" sheet — six photos per A4 page in a 2x3 grid. Shares
   sizing with .drwp-sheet so it lives in the same on-screen stack and
   prints on its own page. */
.drwp-photo-sheet{background:#fff;padding:18mm;margin:0 auto 16px;max-width:210mm;min-height:280mm;box-sizing:border-box;font-family:"Noto Sans JP","Hiragino Sans","Yu Gothic",sans-serif;color:#1d2327;font-size:11pt;line-height:1.5;display:flex;flex-direction:column}
.drwp-photo-sheet-head{display:flex;justify-content:space-between;align-items:baseline;gap:12px;border-bottom:1px solid #1d2327;padding-bottom:6px;margin-bottom:10px;font-size:10pt}
.drwp-photo-sheet-head-left{font-weight:600}
.drwp-photo-sheet-head-right{color:#475569;white-space:nowrap}
.drwp-photo-grid{flex:1;display:grid;grid-template-columns:1fr 1fr;grid-template-rows:repeat(3, 1fr);gap:6mm;min-height:0}
.drwp-photo-cell{border:1px solid #1d2327;padding:3mm;margin:0;display:flex;flex-direction:column;min-height:0;overflow:hidden}
/* Empty slots keep their bordered frame so every 別紙 page shows a
   uniform 2x3 layout even when the last chunk only carries 1-5
   photos. Lighter dashed border so the placeholder reads as
   intentionally blank rather than a missing image. */
.drwp-photo-cell-empty{border:1px dashed #94a3b8}
.drwp-photo-cell-img{flex:1;min-height:0;display:flex;align-items:center;justify-content:center;overflow:hidden}
.drwp-photo-cell-img img{max-width:100%;max-height:100%;object-fit:contain}
.drwp-photo-cell figcaption{font-size:9pt;line-height:1.3;margin-top:4px;text-align:center;color:#1d2327;flex-shrink:0;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}

/* Focus mode: hide all sheets except the current one. Pagebreaks too,
   so there's no phantom 24mm gap above the visible sheet. Photo
   sheets follow their parent report — they share the same data-index
   so the focus-mode JS toggles them together. */
.drwp-print-area.is-focus-mode .drwp-sheet{display:none}
.drwp-print-area.is-focus-mode .drwp-sheet.is-current{display:flex}
.drwp-print-area.is-focus-mode .drwp-photo-sheet{display:none}
.drwp-print-area.is-focus-mode .drwp-photo-sheet.is-current{display:flex}
.drwp-print-area.is-focus-mode .drwp-print-pagebreak{display:none}

/* Narrow screens: TOC moves above the sheet stack and shrinks. */
@media (max-width:880px){
  .drwp-print-area{flex-direction:column}
  .drwp-print-toc{width:auto;position:static;max-height:200px}
}

@media print{
  html,body{margin:0 !important;padding:0 !important;background:#fff !important}
  #adminmenumain,#wpadminbar,#wpfooter,.update-nag,.drwp-no-print{display:none !important}
  #wpcontent,#wpbody-content{margin-left:0 !important;padding:0 !important}
  .wrap{margin:0 !important;padding:0 !important}
  .drwp-print-area{background:#fff !important;padding:0 !important;display:block !important}
  .drwp-print-sheets{display:block}
  .drwp-print-pagebreak{display:none}
  /* display:block (not flex) keeps page-break behavior predictable —
     Chrome/Firefox both treat flex containers oddly across page
     boundaries and can leak a phantom blank page. */
  .drwp-sheet{margin:0;padding:0;min-height:auto;max-width:none;display:block !important;page-break-after:always;break-after:page;page-break-inside:avoid}
  /* The first sheet must not request a leading page break; the last
     element in the stack (could be a photo sheet) must not request a
     trailing one. Reset BOTH the legacy `page-break-*` and the modern
     `break-*` shorthand so neither branch fires a phantom blank page. */
  .drwp-sheet:first-child{page-break-before:avoid !important;break-before:avoid !important}
  .drwp-print-sheets > article:last-child{page-break-after:auto !important;break-after:auto !important}
  /* Print always shows every sheet, even if focus mode is toggled on. */
  .drwp-print-area.is-focus-mode .drwp-sheet{display:block !important}

  /* Photo "別紙" sheets — each chunk lives on its own A4 page right
     after the cover sheet for that report. Same display:block trick
     as .drwp-sheet to keep page-break behavior predictable across
     Chrome/Firefox (flex containers leak phantom pages). The grid
     itself stays display:grid for the 2x3 layout. */
  .drwp-photo-sheet{margin:0;padding:0;min-height:auto;max-width:none;height:auto;display:block !important;page-break-after:always;break-after:page;page-break-inside:avoid}
  .drwp-print-area.is-focus-mode .drwp-photo-sheet{display:block !important}
  /* Give the grid an explicit height so photos size against the
     printable area rather than collapsing to intrinsic dimensions.
     Printable height is ~267mm (297mm − 30mm @page margin); header
     and its margin take ~20mm. */
  .drwp-photo-sheet .drwp-photo-grid{height:247mm}

  @page{margin:15mm;size:A4 portrait}
}
</style>

<?php if (!empty($filters['go']) && count($reports) > 1): ?>
<script>
(function () {
  var area = document.querySelector('.drwp-print-area');
  var sheets = Array.prototype.slice.call(document.querySelectorAll('.drwp-sheet'));
  if (!area || sheets.length < 2) return;

  var prevBtn = document.getElementById('drwp-prev');
  var nextBtn = document.getElementById('drwp-next');
  var counter = document.getElementById('drwp-counter');
  var tocLinks = Array.prototype.slice.call(document.querySelectorAll('.drwp-print-toc a'));
  // Photo "別紙" sheets are tagged with data-index matching their
  // parent report so focus mode can show them alongside the cover.
  var photoSheets = Array.prototype.slice.call(document.querySelectorAll('.drwp-photo-sheet'));

  var current = 0;
  var total = sheets.length;

  function setCurrent(i, scroll) {
    current = Math.max(0, Math.min(total - 1, i));
    for (var s = 0; s < sheets.length; s++) {
      sheets[s].classList.toggle('is-current', s === current);
    }
    for (var p = 0; p < photoSheets.length; p++) {
      var idx = parseInt(photoSheets[p].dataset.index, 10);
      photoSheets[p].classList.toggle('is-current', idx === current);
    }
    for (var t = 0; t < tocLinks.length; t++) {
      tocLinks[t].classList.toggle('is-current', t === current);
    }
    counter.textContent = (current + 1) + ' / ' + total;
    if (scroll) {
      // Snap to the top of the (newly visible) sheet so the operator
      // doesn't have to scroll back up to read it from the start.
      sheets[current].scrollIntoView({behavior: 'auto', block: 'start'});
    }
  }

  prevBtn.addEventListener('click', function () { setCurrent(current - 1, true); });
  nextBtn.addEventListener('click', function () { setCurrent(current + 1, true); });

  for (var i = 0; i < tocLinks.length; i++) {
    (function (link) {
      link.addEventListener('click', function (e) {
        e.preventDefault();
        var idx = parseInt(link.dataset.index, 10);
        if (!isNaN(idx)) setCurrent(idx, true);
      });
    })(tocLinks[i]);
  }

  document.addEventListener('keydown', function (e) {
    var t = e.target.tagName;
    if (t === 'INPUT' || t === 'TEXTAREA' || t === 'SELECT') return;
    if (e.metaKey || e.ctrlKey || e.altKey) return;
    if (e.key === 'ArrowLeft' || e.key === 'PageUp') {
      e.preventDefault(); setCurrent(current - 1, true);
    } else if (e.key === 'ArrowRight' || e.key === 'PageDown') {
      e.preventDefault(); setCurrent(current + 1, true);
    }
  });

  setCurrent(0, false);
})();
</script>
<?php endif; ?>
