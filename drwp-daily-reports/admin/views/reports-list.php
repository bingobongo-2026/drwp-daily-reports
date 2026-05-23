<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1><?php esc_html_e('日報一覧', 'drwp-daily-reports'); ?></h1>
  <?php if (isset($_GET['updated'])): ?>
    <div class="notice notice-success"><p>
      <?php
        printf(
            /* translators: %d: number of updated rows */
            esc_html(_n('%d 件更新しました。', '%d 件更新しました。', intval($_GET['updated']), 'drwp-daily-reports')),
            intval($_GET['updated'])
        );
      ?>
    </p></div>
  <?php endif; ?>
  <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:12px 0;">
    <input type="hidden" name="page" value="drwp_reports" />
    <input type="search" name="s" value="<?php echo esc_attr($filters['search']); ?>" placeholder="<?php esc_attr_e('本文・公開タイトル・タグ検索', 'drwp-daily-reports'); ?>" style="min-width:220px;" />
    <select name="review_status">
      <option value=""><?php esc_html_e('レビュー状態すべて', 'drwp-daily-reports'); ?></option>
      <?php
      $review_labels = [
          'pending'        => __('レビュー待ち', 'drwp-daily-reports'),
          'approved'       => __('承認', 'drwp-daily-reports'),
          'needs_revision' => __('差し戻し', 'drwp-daily-reports'),
      ];
      foreach ($review_labels as $k => $v): ?>
        <option value="<?php echo esc_attr($k); ?>" <?php selected($filters['review_status'], $k); ?>><?php echo esc_html($v); ?></option>
      <?php endforeach; ?>
    </select>
    <select name="post_status">
      <option value=""><?php esc_html_e('投稿状態すべて', 'drwp-daily-reports'); ?></option>
      <?php
      $post_status_labels = [
          'draft'   => __('下書き', 'drwp-daily-reports'),
          'pending' => __('保留中', 'drwp-daily-reports'),
          'future'  => __('予約', 'drwp-daily-reports'),
          'publish' => __('公開', 'drwp-daily-reports'),
      ];
      foreach ($post_status_labels as $k => $v): ?>
        <option value="<?php echo esc_attr($k); ?>" <?php selected($filters['post_status'], $k); ?>><?php echo esc_html($v); ?></option>
      <?php endforeach; ?>
    </select>
    <select name="project_id">
      <option value="0"><?php esc_html_e('現場すべて', 'drwp-daily-reports'); ?></option>
      <?php foreach (($projects ?? []) as $project): ?>
        <option value="<?php echo (int) $project->id; ?>" <?php selected((int) $filters['project_id'], (int) $project->id); ?>><?php echo esc_html($project->name); ?></option>
      <?php endforeach; ?>
    </select>
    <input type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>" />
    <input type="date" name="date_to" value="<?php echo esc_attr($filters['date_to']); ?>" />
    <button class="button"><?php esc_html_e('検索', 'drwp-daily-reports'); ?></button>
    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=drwp_reports')); ?>"><?php esc_html_e('クリア', 'drwp-daily-reports'); ?></a>
  </form>

  <p class="description" style="margin:8px 0;">
    <?php
      printf(
          /* translators: %d: total report count */
          esc_html(_n('合計 %d 件', '合計 %d 件', (int) $total, 'drwp-daily-reports')),
          (int) $total
      );
    ?>
  </p>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
    <?php wp_nonce_field('drwp_bulk_reports'); ?>
    <input type="hidden" name="action" value="drwp_bulk_reports" />

    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px;">
      <select name="bulk_action">
        <option value=""><?php esc_html_e('一括操作を選択', 'drwp-daily-reports'); ?></option>
        <option value="bulk_approve"><?php esc_html_e('一括承認', 'drwp-daily-reports'); ?></option>
        <option value="bulk_revision"><?php esc_html_e('一括差し戻し', 'drwp-daily-reports'); ?></option>
        <option value="bulk_convert"><?php esc_html_e('一括で記事作成/更新', 'drwp-daily-reports'); ?></option>
        <option value="bulk_update_publish"><?php esc_html_e('一括で公開設定を更新', 'drwp-daily-reports'); ?></option>
        <option value="bulk_export_csv"><?php esc_html_e('選択した日報をCSV出力', 'drwp-daily-reports'); ?></option>
      </select>
      <select name="bulk_post_template">
        <?php foreach (DRWP_Labels::post_template_options() as $key => $label): ?>
          <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
        <?php endforeach; ?>
      </select>
      <?php
        wp_dropdown_categories([
          'show_option_all' => __('カテゴリを選択', 'drwp-daily-reports'),
          'hide_empty'      => 0,
          'name'            => 'bulk_post_category_id',
          'selected'        => 0,
          'taxonomy'        => 'category',
          'value_field'     => 'term_id',
        ]);
      ?>
      <input type="text" name="bulk_post_tags" placeholder="<?php esc_attr_e('タグ（カンマ区切り）', 'drwp-daily-reports'); ?>" />
      <select name="bulk_post_status">
        <option value="draft"><?php esc_html_e('下書き', 'drwp-daily-reports'); ?></option>
        <option value="pending"><?php esc_html_e('レビュー待ち', 'drwp-daily-reports'); ?></option>
        <option value="future"><?php esc_html_e('予約投稿', 'drwp-daily-reports'); ?></option>
      </select>
      <input type="datetime-local" name="bulk_scheduled_at" />
      <button class="button button-primary"><?php esc_html_e('実行', 'drwp-daily-reports'); ?></button>
    </div>

    <table class="widefat striped">
      <thead>
        <tr>
          <th><input type="checkbox" onclick="document.querySelectorAll('.drwp-check').forEach(cb => cb.checked = this.checked)" /></th>
          <th>ID</th>
          <th><?php esc_html_e('日付', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('現場', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('公開タイトル', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('レビュー', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('カテゴリ', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('タグ', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('投稿状態', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('記事', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('操作', 'drwp-daily-reports'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($reports)): ?>
          <tr><td colspan="11"><?php esc_html_e('データがありません。', 'drwp-daily-reports'); ?></td></tr>
        <?php else: foreach ($reports as $report): ?>
          <tr>
            <td><input class="drwp-check" type="checkbox" name="report_ids[]" value="<?php echo esc_attr($report->id); ?>" /></td>
            <td><?php echo esc_html($report->id); ?></td>
            <td><?php echo esc_html($report->report_date); ?></td>
            <td><?php
              $project_name = '-';
              if (!empty($report->project_id)) {
                  $project = DRWP_Project::find((int) $report->project_id);
                  $project_name = $project ? $project->name : (string) $report->project_id;
              }
              echo esc_html($project_name);
            ?></td>
            <td><?php echo esc_html($report->public_title ?: __('（未設定）', 'drwp-daily-reports')); ?></td>
            <td><?php echo esc_html(DRWP_Labels::review_status((string) $report->review_status)); ?></td>
            <td><?php
              $cat_name = '-';
              if (!empty($report->post_category_id)) {
                  $term = get_term((int) $report->post_category_id, 'category');
                  $cat_name = ($term && !is_wp_error($term)) ? $term->name : (string) $report->post_category_id;
              }
              echo esc_html($cat_name);
            ?></td>
            <td><?php echo esc_html($report->post_tags ?: '-'); ?></td>
            <td><?php echo esc_html(DRWP_Labels::post_status((string) ($report->post_status ?: 'draft'))); ?></td>
            <td><?php echo $report->linked_post_id ? '<a href="' . esc_url(get_edit_post_link((int) $report->linked_post_id)) . '">#' . esc_html($report->linked_post_id) . '</a>' : '-'; ?></td>
            <td>
              <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=drwp_report_edit&id=' . (int) $report->id)); ?>"><?php esc_html_e('編集', 'drwp-daily-reports'); ?></a>
              <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=drwp_report_preview&id=' . (int) $report->id)); ?>"><?php esc_html_e('プレビュー', 'drwp-daily-reports'); ?></a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </form>

  <?php
  if ($pages > 1):
      $base = add_query_arg(
          array_merge(
              ['page' => 'drwp_reports'],
              array_filter(
                  [
                      's'             => $filters['search'],
                      'review_status' => $filters['review_status'],
                      'post_status'   => $filters['post_status'],
                      'project_id'    => $filters['project_id'] ?: '',
                      'date_from'     => $filters['date_from'],
                      'date_to'       => $filters['date_to'],
                  ],
                  function ($v) { return $v !== '' && $v !== 0; }
              )
          ),
          admin_url('admin.php')
      );
      $page_links = paginate_links([
          'base'      => add_query_arg('paged', '%#%', $base),
          'format'    => '',
          'current'   => (int) $paged,
          'total'     => (int) $pages,
          'type'      => 'array',
          'prev_text' => '‹',
          'next_text' => '›',
      ]);
  ?>
    <div class="tablenav" style="margin-top:12px;">
      <div class="tablenav-pages">
        <?php foreach (($page_links ?: []) as $link) echo $link . ' '; ?>
      </div>
    </div>
  <?php endif; ?>
</div>
