<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1>日報一覧</h1>
  <?php if (isset($_GET['updated'])): ?>
    <div class="notice notice-success"><p><?php echo intval($_GET['updated']); ?> 件更新しました。</p></div>
  <?php endif; ?>
  <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:12px 0;">
    <input type="hidden" name="page" value="drwp_reports" />
    <input type="search" name="s" value="<?php echo esc_attr($filters['search']); ?>" placeholder="本文・公開タイトル・タグ検索" style="min-width:220px;" />
    <select name="review_status">
      <option value="">レビュー状態すべて</option>
      <?php foreach (['pending' => 'レビュー待ち', 'approved' => '承認', 'needs_revision' => '差し戻し'] as $k => $v): ?>
        <option value="<?php echo esc_attr($k); ?>" <?php selected($filters['review_status'], $k); ?>><?php echo esc_html($v); ?></option>
      <?php endforeach; ?>
    </select>
    <select name="post_status">
      <option value="">投稿状態すべて</option>
      <?php foreach (['draft' => '下書き', 'pending' => '保留中', 'future' => '予約', 'publish' => '公開'] as $k => $v): ?>
        <option value="<?php echo esc_attr($k); ?>" <?php selected($filters['post_status'], $k); ?>><?php echo esc_html($v); ?></option>
      <?php endforeach; ?>
    </select>
    <select name="project_id">
      <option value="0">現場すべて</option>
      <?php foreach (($projects ?? []) as $project): ?>
        <option value="<?php echo (int) $project->id; ?>" <?php selected((int) $filters['project_id'], (int) $project->id); ?>><?php echo esc_html($project->name); ?></option>
      <?php endforeach; ?>
    </select>
    <input type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>" />
    <input type="date" name="date_to" value="<?php echo esc_attr($filters['date_to']); ?>" />
    <button class="button">検索</button>
    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=drwp_reports')); ?>">クリア</a>
  </form>

  <p class="description" style="margin:8px 0;">合計 <?php echo (int) $total; ?> 件</p>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
    <?php wp_nonce_field('drwp_bulk_reports'); ?>
    <input type="hidden" name="action" value="drwp_bulk_reports" />

    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px;">
      <select name="bulk_action">
        <option value="">一括操作を選択</option>
        <option value="bulk_approve">一括承認</option>
        <option value="bulk_revision">一括差し戻し</option>
        <option value="bulk_convert">一括で記事作成/更新</option>
        <option value="bulk_update_publish">一括で公開設定を更新</option>
        <option value="bulk_export_csv">選択した日報をCSV出力</option>
      </select>
      <select name="bulk_post_template">
        <option value="standard">standard</option>
        <option value="site_report">site_report</option>
        <option value="before_after">before_after</option>
      </select>
      <?php
        wp_dropdown_categories([
          'show_option_all' => 'カテゴリを選択',
          'hide_empty'      => 0,
          'name'            => 'bulk_post_category_id',
          'selected'        => 0,
          'taxonomy'        => 'category',
          'value_field'     => 'term_id',
        ]);
      ?>
      <input type="text" name="bulk_post_tags" placeholder="タグ（カンマ区切り）" />
      <select name="bulk_post_status">
        <option value="draft">下書き</option>
        <option value="pending">レビュー待ち</option>
        <option value="future">予約投稿</option>
      </select>
      <input type="datetime-local" name="bulk_scheduled_at" />
      <button class="button button-primary">実行</button>
    </div>

    <table class="widefat striped">
      <thead>
        <tr>
          <th><input type="checkbox" onclick="document.querySelectorAll('.drwp-check').forEach(cb => cb.checked = this.checked)" /></th>
          <th>ID</th>
          <th>日付</th>
          <th>現場</th>
          <th>公開タイトル</th>
          <th>レビュー</th>
          <th>カテゴリ</th>
          <th>タグ</th>
          <th>投稿状態</th>
          <th>記事</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($reports)): ?>
          <tr><td colspan="11">データがありません。</td></tr>
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
            <td><?php echo esc_html($report->public_title ?: '（未設定）'); ?></td>
            <td><?php echo esc_html($report->review_status); ?></td>
            <td><?php
              $cat_name = '-';
              if (!empty($report->post_category_id)) {
                  $term = get_term((int) $report->post_category_id, 'category');
                  $cat_name = ($term && !is_wp_error($term)) ? $term->name : (string) $report->post_category_id;
              }
              echo esc_html($cat_name);
            ?></td>
            <td><?php echo esc_html($report->post_tags ?: '-'); ?></td>
            <td><?php echo esc_html($report->post_status ?: 'draft'); ?></td>
            <td><?php echo $report->linked_post_id ? '<a href="' . esc_url(get_edit_post_link((int) $report->linked_post_id)) . '">#' . esc_html($report->linked_post_id) . '</a>' : '-'; ?></td>
            <td>
              <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=drwp_report_edit&id=' . (int) $report->id)); ?>">編集</a>
              <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=drwp_report_preview&id=' . (int) $report->id)); ?>">プレビュー</a>
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
