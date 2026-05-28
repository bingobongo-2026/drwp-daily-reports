<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap drwp-print-wrap">
  <h1 class="drwp-no-print"><?php esc_html_e('PDF出力', 'drwp-daily-reports'); ?></h1>

  <div class="drwp-no-print drwp-print-card">
    <h2><?php esc_html_e('絞り込み条件', 'drwp-daily-reports'); ?></h2>
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
          <th><?php esc_html_e('レビュー状態', 'drwp-daily-reports'); ?></th>
          <td>
            <select name="review_status">
              <option value=""><?php esc_html_e('すべて', 'drwp-daily-reports'); ?></option>
              <?php foreach (['pending','approved','needs_revision','edit_requested'] as $k): ?>
                <option value="<?php echo esc_attr($k); ?>" <?php selected($filters['review_status'], $k); ?>><?php echo esc_html(DRWP_Labels::review_status($k)); ?></option>
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
        <tr>
          <th><?php esc_html_e('グループ化', 'drwp-daily-reports'); ?></th>
          <td>
            <label><input type="radio" name="group_by" value="none" <?php checked($filters['group_by'], 'none'); ?> /> <?php esc_html_e('なし（日報ごと）', 'drwp-daily-reports'); ?></label>
            <label style="margin-left:12px;"><input type="radio" name="group_by" value="date" <?php checked($filters['group_by'], 'date'); ?> /> <?php esc_html_e('日付ごと', 'drwp-daily-reports'); ?></label>
            <label style="margin-left:12px;"><input type="radio" name="group_by" value="project" <?php checked($filters['group_by'], 'project'); ?> /> <?php esc_html_e('現場ごと', 'drwp-daily-reports'); ?></label>
          </td>
        </tr>
        <tr>
          <th><?php esc_html_e('オプション', 'drwp-daily-reports'); ?></th>
          <td>
            <label><input type="checkbox" name="include_photos" value="1" <?php checked($filters['include_photos']); ?> /> <?php esc_html_e('写真を含める', 'drwp-daily-reports'); ?></label>
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
        <?php esc_html_e('印刷ダイアログで「PDFとして保存」を選択してください。', 'drwp-daily-reports'); ?>
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
        <p><?php esc_html_e('該当する日報がありません。', 'drwp-daily-reports'); ?></p>
      <?php else:
        $groups = DRWP_Print::group_reports($reports, $filters['group_by']);
        $first_group = true;
        foreach ($groups as $group_key => $group_reports):
          if (!$first_group): ?><div class="drwp-print-pagebreak"></div><?php endif;
          $first_group = false;
          if ($filters['group_by'] !== 'none' && $group_key !== ''): ?>
            <h2 class="drwp-print-group-title">
              <?php
                if ($filters['group_by'] === 'date') {
                    echo esc_html(date_i18n('Y年n月j日', strtotime($group_key)));
                } else {
                    echo esc_html($group_key);
                }
              ?>
            </h2>
          <?php endif;
          $first_in_group = true;
          foreach ($group_reports as $r):
            $author = get_userdata((int) $r->user_id);
            $project_name = '-';
            if (!empty($r->project_id)) {
                $proj = DRWP_Project::find((int) $r->project_id);
                $project_name = $proj ? $proj->name : ('#' . (int) $r->project_id);
            }
            if (!$first_in_group): ?><div class="drwp-print-divider"></div><?php endif;
            $first_in_group = false;
          ?>
          <article class="drwp-print-report">
            <header class="drwp-print-report-header">
              <div class="drwp-print-report-title">
                <?php echo esc_html(date_i18n('Y年n月j日', strtotime((string) $r->report_date))); ?>
                <span class="drwp-print-report-project"><?php echo esc_html($project_name); ?></span>
              </div>
              <div class="drwp-print-report-meta">
                ID: <?php echo (int) $r->id; ?>
                <?php if ($author): ?> ／ 作成者: <?php echo esc_html($author->display_name); ?><?php endif; ?>
                <?php if ($r->started_at || $r->ended_at): ?>
                  ／ 時刻: <?php echo esc_html(substr((string) $r->started_at, 0, 5)); ?>〜<?php echo esc_html(substr((string) $r->ended_at, 0, 5)); ?>
                <?php endif; ?>
                ／ レビュー: <?php echo esc_html(DRWP_Labels::review_status((string) $r->review_status)); ?>
              </div>
            </header>
            <div class="drwp-print-report-body">
              <?php if ($r->work_description): ?>
                <section><h4><?php esc_html_e('作業内容', 'drwp-daily-reports'); ?></h4><div class="drwp-print-text"><?php echo nl2br(esc_html((string) $r->work_description)); ?></div></section>
              <?php endif; ?>
              <?php if ($r->issues): ?>
                <section><h4><?php esc_html_e('問題点', 'drwp-daily-reports'); ?></h4><div class="drwp-print-text"><?php echo nl2br(esc_html((string) $r->issues)); ?></div></section>
              <?php endif; ?>
              <?php if ($r->next_plan): ?>
                <section><h4><?php esc_html_e('次回予定', 'drwp-daily-reports'); ?></h4><div class="drwp-print-text"><?php echo nl2br(esc_html((string) $r->next_plan)); ?></div></section>
              <?php endif; ?>
              <?php if (!empty($filters['include_photos'])):
                $photos = DRWP_Media::for_report((int) $r->id);
                if ($photos): ?>
                <section>
                  <h4><?php esc_html_e('写真', 'drwp-daily-reports'); ?></h4>
                  <div class="drwp-print-photos">
                    <?php foreach ($photos as $ph):
                      $url = wp_get_attachment_image_url((int) $ph->attachment_id, 'medium');
                      if (!$url) continue; ?>
                      <figure>
                        <img src="<?php echo esc_url($url); ?>" alt="" />
                        <?php if ($ph->caption): ?><figcaption><?php echo esc_html($ph->caption); ?></figcaption><?php endif; ?>
                      </figure>
                    <?php endforeach; ?>
                  </div>
                </section>
              <?php endif; endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      <?php endforeach; endif; ?>
    </div>
  <?php endif; ?>
</div>

<style>
.drwp-print-card{background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:12px 16px;margin-bottom:12px}
.drwp-print-card>h2{margin:0 0 8px;font-size:.95em;color:#1d2327;border-bottom:1px solid #e5e7eb;padding-bottom:6px}
.drwp-print-area{background:#fff;padding:24px;border:1px solid #c3c4c7;border-radius:4px;font-family:"Noto Sans JP","Hiragino Sans","Yu Gothic",sans-serif;color:#1d2327;line-height:1.55}
.drwp-print-group-title{font-size:1.4em;margin:0 0 12px;padding-bottom:6px;border-bottom:2px solid #1d2327}
.drwp-print-report{margin-bottom:20px}
.drwp-print-report-header{margin-bottom:10px}
.drwp-print-report-title{font-size:1.15em;font-weight:700;display:flex;gap:12px;align-items:baseline;flex-wrap:wrap}
.drwp-print-report-project{font-size:.9em;font-weight:500;color:#475569}
.drwp-print-report-meta{font-size:.85em;color:#64748b;margin-top:2px}
.drwp-print-report-body section{margin-top:8px}
.drwp-print-report-body h4{font-size:.95em;margin:0 0 2px;color:#1d2327;border-left:3px solid #2271b1;padding-left:6px}
.drwp-print-text{white-space:pre-wrap;font-size:.95em}
.drwp-print-photos{display:flex;flex-wrap:wrap;gap:10px;margin-top:4px}
.drwp-print-photos figure{margin:0;width:200px}
.drwp-print-photos img{max-width:200px;height:auto;border:1px solid #e5e7eb}
.drwp-print-photos figcaption{font-size:.8em;color:#475569;margin-top:2px}
.drwp-print-divider{border-top:1px dashed #cbd5e1;margin:14px 0}
.drwp-print-pagebreak{page-break-after:always;border-top:2px dashed #94a3b8;margin:24px 0}
@media print{
  body{background:#fff !important}
  #adminmenumain,#wpadminbar,#wpfooter,.update-nag,.drwp-no-print{display:none !important}
  #wpcontent,#wpbody-content{margin-left:0 !important;padding:0 !important}
  .wrap{margin:0 !important;padding:0 !important}
  .drwp-print-area{border:0 !important;padding:0 !important}
  .drwp-print-report{page-break-inside:avoid;break-inside:avoid}
  .drwp-print-pagebreak{page-break-after:always;border:0;margin:0}
  @page{margin:15mm}
}
</style>
