<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1><?php esc_html_e('日報一覧', 'drwp-daily-reports'); ?></h1>
  <?php if (isset($_GET['updated'])): ?>
    <div class="notice notice-success"><p>
      <?php
        printf(
            esc_html(_n('%d 件更新しました。', '%d 件更新しました。', intval($_GET['updated']), 'drwp-daily-reports')),
            intval($_GET['updated'])
        );
      ?>
    </p></div>
  <?php endif; ?>

  <!-- ============================================================
       ③ 検索・絞り込み — visually distinct card
       ============================================================ -->
  <div class="drwp-list-card drwp-list-search">
    <h2><?php esc_html_e('検索・絞り込み', 'drwp-daily-reports'); ?></h2>
    <form method="get" class="drwp-list-search-form">
      <input type="hidden" name="page" value="drwp_reports" />
      <div class="drwp-list-search-row">
        <input type="search" name="s" value="<?php echo esc_attr($filters['search']); ?>" placeholder="<?php esc_attr_e('本文・公開タイトル・タグ検索', 'drwp-daily-reports'); ?>" class="drwp-list-search-input" />
        <select name="review_status">
          <option value=""><?php esc_html_e('レビュー状態すべて', 'drwp-daily-reports'); ?></option>
          <?php
          $review_labels = [
              'pending'        => DRWP_Labels::review_status('pending'),
              'approved'       => DRWP_Labels::review_status('approved'),
              'needs_revision' => DRWP_Labels::review_status('needs_revision'),
              'edit_requested' => DRWP_Labels::review_status('edit_requested'),
          ];
          foreach ($review_labels as $k => $v): ?>
            <option value="<?php echo esc_attr($k); ?>" <?php selected($filters['review_status'], $k); ?>><?php echo esc_html($v); ?></option>
          <?php endforeach; ?>
        </select>
        <select name="post_status">
          <option value=""><?php esc_html_e('投稿状態すべて', 'drwp-daily-reports'); ?></option>
          <?php
          $post_status_labels = [
              'draft'   => DRWP_Labels::post_status('draft'),
              'pending' => DRWP_Labels::post_status('pending'),
              'future'  => DRWP_Labels::post_status('future'),
              'publish' => DRWP_Labels::post_status('publish'),
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
      </div>
      <div class="drwp-list-search-row">
        <input type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>" />
        <span>〜</span>
        <input type="date" name="date_to" value="<?php echo esc_attr($filters['date_to']); ?>" />
        <button class="button button-primary"><?php esc_html_e('検索', 'drwp-daily-reports'); ?></button>
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=drwp_reports')); ?>"><?php esc_html_e('クリア', 'drwp-daily-reports'); ?></a>
      </div>
    </form>
  </div>

  <p class="description" style="margin:8px 0;">
    <?php
      printf(
          esc_html(_n('合計 %d 件', '合計 %d 件', (int) $total, 'drwp-daily-reports')),
          (int) $total
      );
    ?>
  </p>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php wp_nonce_field('drwp_bulk_reports'); ?>
    <input type="hidden" name="action" value="drwp_bulk_reports" />

    <!-- ============================================================
         ③ 一括操作 — separate card with distinct styling
         ============================================================ -->
    <div class="drwp-list-card drwp-list-bulk">
      <h2><?php esc_html_e('一括操作', 'drwp-daily-reports'); ?></h2>
      <div class="drwp-list-bulk-row">
        <select name="bulk_action">
          <option value=""><?php esc_html_e('操作を選択', 'drwp-daily-reports'); ?></option>
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
          <option value="draft"><?php echo esc_html(DRWP_Labels::post_status('draft')); ?></option>
          <option value="pending"><?php echo esc_html(DRWP_Labels::post_status('pending')); ?></option>
          <option value="future"><?php echo esc_html(DRWP_Labels::post_status('future')); ?></option>
        </select>
        <input type="datetime-local" name="bulk_scheduled_at" />
        <button class="button button-primary"><?php esc_html_e('実行', 'drwp-daily-reports'); ?></button>
      </div>
    </div>

    <table class="widefat striped" id="drwp-reports-table">
      <thead>
        <tr>
          <th><input type="checkbox" onclick="document.querySelectorAll('.drwp-check').forEach(cb => cb.checked = this.checked)" /></th>
          <th>ID</th>
          <th><?php esc_html_e('日付', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('作成者', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('現場', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('公開タイトル', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('レビュー', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('投稿状態', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('記事', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('操作', 'drwp-daily-reports'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($reports)): ?>
          <tr><td colspan="10"><?php esc_html_e('データがありません。', 'drwp-daily-reports'); ?></td></tr>
        <?php else: foreach ($reports as $report): ?>
          <tr>
            <td><input class="drwp-check" type="checkbox" name="report_ids[]" value="<?php echo esc_attr($report->id); ?>" /></td>
            <td><?php echo esc_html($report->id); ?></td>
            <td><?php echo esc_html(date_i18n('Y年n月j日', strtotime((string) $report->report_date))); ?></td>
            <td><?php
              $author = get_userdata((int) $report->user_id);
              echo esc_html($author ? $author->display_name : ('#' . (int) $report->user_id));
            ?></td>
            <td><?php
              $project_name = '-';
              if (!empty($report->project_id)) {
                  $proj = DRWP_Project::find((int) $report->project_id);
                  $project_name = $proj ? $proj->name : (string) $report->project_id;
              }
              echo esc_html($project_name);
            ?></td>
            <td><?php echo esc_html($report->public_title ?: __('（未設定）', 'drwp-daily-reports')); ?></td>
            <td><?php echo esc_html(DRWP_Labels::review_status((string) $report->review_status)); ?></td>
            <td><?php echo esc_html(DRWP_Labels::post_status((string) ($report->post_status ?: 'draft'))); ?></td>
            <td><?php echo $report->linked_post_id ? '<a href="' . esc_url(get_edit_post_link((int) $report->linked_post_id)) . '">#' . esc_html($report->linked_post_id) . '</a>' : '-'; ?></td>
            <td>
              <button type="button" class="button button-small drwp-view-btn" data-id="<?php echo (int) $report->id; ?>"><?php esc_html_e('詳細', 'drwp-daily-reports'); ?></button>
              <button type="button" class="button button-small drwp-edit-btn" data-id="<?php echo (int) $report->id; ?>"><?php esc_html_e('編集', 'drwp-daily-reports'); ?></button>
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

  <!-- ============================================================
       ② 詳細モーダル — read-only view
       ============================================================ -->
  <dialog id="drwp-view-dialog" class="drwp-modal">
    <div class="drwp-modal-header">
      <h2><?php esc_html_e('日報詳細', 'drwp-daily-reports'); ?></h2>
      <button type="button" class="drwp-modal-close">&times;</button>
    </div>
    <div class="drwp-modal-body" id="drwp-view-body"></div>
    <div class="drwp-modal-footer">
      <button type="button" class="button drwp-modal-close"><?php esc_html_e('閉じる', 'drwp-daily-reports'); ?></button>
    </div>
  </dialog>

  <!-- ============================================================
       ① 編集モーダル — quick edit via REST PATCH
       ============================================================ -->
  <dialog id="drwp-edit-dialog" class="drwp-modal drwp-modal-wide">
    <div class="drwp-modal-header">
      <h2><?php esc_html_e('日報を編集', 'drwp-daily-reports'); ?></h2>
      <button type="button" class="drwp-modal-close">&times;</button>
    </div>
    <div class="drwp-modal-body">
      <input type="hidden" id="drwp-edit-id" />
      <table class="form-table" role="presentation">
        <tr>
          <th><?php esc_html_e('日付', 'drwp-daily-reports'); ?></th>
          <td><input type="date" id="drwp-edit-date" /></td>
        </tr>
        <tr>
          <th><?php esc_html_e('現場', 'drwp-daily-reports'); ?></th>
          <td>
            <select id="drwp-edit-project">
              <option value=""><?php esc_html_e('（未設定）', 'drwp-daily-reports'); ?></option>
              <?php foreach (($projects ?? []) as $p): ?>
                <option value="<?php echo (int) $p->id; ?>"><?php echo esc_html($p->name); ?></option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>
        <tr>
          <th><?php esc_html_e('時刻', 'drwp-daily-reports'); ?></th>
          <td>
            <input type="time" id="drwp-edit-started" /> 〜 <input type="time" id="drwp-edit-ended" />
          </td>
        </tr>
        <tr>
          <th><?php esc_html_e('作業内容', 'drwp-daily-reports'); ?></th>
          <td><textarea id="drwp-edit-work" rows="4" class="large-text"></textarea></td>
        </tr>
        <tr>
          <th><?php esc_html_e('問題点', 'drwp-daily-reports'); ?></th>
          <td><textarea id="drwp-edit-issues" rows="2" class="large-text"></textarea></td>
        </tr>
        <tr>
          <th><?php esc_html_e('次回予定', 'drwp-daily-reports'); ?></th>
          <td><textarea id="drwp-edit-next" rows="2" class="large-text"></textarea></td>
        </tr>
        <tr>
          <th><?php esc_html_e('公開タイトル', 'drwp-daily-reports'); ?></th>
          <td><input type="text" id="drwp-edit-title" class="regular-text" /></td>
        </tr>
      </table>
    </div>
    <div class="drwp-modal-footer">
      <button type="button" class="button button-primary" id="drwp-edit-save"><?php esc_html_e('保存', 'drwp-daily-reports'); ?></button>
      <a id="drwp-edit-fullpage" class="button" href="#"><?php esc_html_e('フルページで編集', 'drwp-daily-reports'); ?></a>
      <button type="button" class="button drwp-modal-close"><?php esc_html_e('キャンセル', 'drwp-daily-reports'); ?></button>
      <span id="drwp-edit-status" style="margin-left:12px;"></span>
    </div>
  </dialog>
</div>
