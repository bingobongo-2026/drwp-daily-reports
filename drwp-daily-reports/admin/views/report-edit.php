<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1>日報編集</h1>
  <?php if (!empty($_GET['saved'])): ?>
    <div class="notice notice-success"><p>保存しました。</p></div>
  <?php endif; ?>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php wp_nonce_field('drwp_save_report'); ?>
    <input type="hidden" name="action" value="drwp_save_report" />
    <input type="hidden" name="id" value="<?php echo esc_attr($report->id ?? 0); ?>" />

    <table class="form-table" role="presentation">
      <tr>
        <th><label>日付</label></th>
        <td><input type="date" name="report_date" value="<?php echo esc_attr($report->report_date ?? date('Y-m-d')); ?>" /></td>
      </tr>
      <tr>
        <th><label>作業内容</label></th>
        <td><textarea name="work_description" rows="5" class="large-text"><?php echo esc_textarea($report->work_description ?? ''); ?></textarea></td>
      </tr>
      <tr>
        <th><label>問題点</label></th>
        <td><textarea name="issues" rows="4" class="large-text"><?php echo esc_textarea($report->issues ?? ''); ?></textarea></td>
      </tr>
      <tr>
        <th><label>次回予定</label></th>
        <td><textarea name="next_plan" rows="4" class="large-text"><?php echo esc_textarea($report->next_plan ?? ''); ?></textarea></td>
      </tr>
      <tr><th colspan="2"><h2 style="margin:0;">公開設定</h2></th></tr>
      <tr>
        <th><label>公開タイトル</label></th>
        <td><input type="text" name="public_title" class="regular-text" value="<?php echo esc_attr($report->public_title ?? ''); ?>" /></td>
      </tr>
      <tr>
        <th><label>導入文</label></th>
        <td><textarea name="public_intro" rows="3" class="large-text"><?php echo esc_textarea($report->public_intro ?? ''); ?></textarea></td>
      </tr>
      <tr>
        <th><label>公開本文</label></th>
        <td><textarea name="public_body" rows="6" class="large-text"><?php echo esc_textarea($report->public_body ?? ''); ?></textarea></td>
      </tr>
      <tr>
        <th><label>公開用の今後の予定</label></th>
        <td><textarea name="public_next_plan" rows="3" class="large-text"><?php echo esc_textarea($report->public_next_plan ?? ''); ?></textarea></td>
      </tr>
      <tr>
        <th><label>テンプレート</label></th>
        <td>
          <select name="post_template">
            <?php foreach (['standard','site_report','before_after'] as $tpl): ?>
              <option value="<?php echo esc_attr($tpl); ?>" <?php selected($report->post_template ?? 'standard', $tpl); ?>><?php echo esc_html($tpl); ?></option>
            <?php endforeach; ?>
          </select>
        </td>
      </tr>
      <tr>
        <th><label>カテゴリ</label></th>
        <td>
          <?php wp_dropdown_categories([
            'show_option_all' => 'カテゴリを選択',
            'hide_empty'      => 0,
            'name'            => 'post_category_id',
            'selected'        => (int) ($report->post_category_id ?? 0),
            'taxonomy'        => 'category',
            'value_field'     => 'term_id',
          ]); ?>
        </td>
      </tr>
      <tr>
        <th><label>タグ</label></th>
        <td>
          <input type="text" name="post_tags" class="regular-text" value="<?php echo esc_attr($report->post_tags ?? ''); ?>" />
          <p class="description">カンマ区切りで入力します。例: 外壁補修, 三島市, 現場レポート</p>
        </td>
      </tr>
      <tr>
        <th><label>投稿状態</label></th>
        <td>
          <select name="post_status">
            <option value="draft" <?php selected($report->post_status ?? 'draft', 'draft'); ?>>下書き</option>
            <option value="pending" <?php selected($report->post_status ?? '', 'pending'); ?>>レビュー待ち</option>
            <option value="future" <?php selected($report->post_status ?? '', 'future'); ?>>予約投稿</option>
          </select>
        </td>
      </tr>
      <tr>
        <th><label>予約日時</label></th>
        <td><input type="datetime-local" name="scheduled_at" value="<?php echo !empty($report->scheduled_at) ? esc_attr(date('Y-m-d\TH:i', strtotime($report->scheduled_at))) : ''; ?>" /></td>
      </tr>
    </table>

    <?php submit_button('保存'); ?>
    <?php if (!empty($report->id)): ?>
      <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=drwp_report_preview&id=' . (int) $report->id)); ?>">保存済み内容をプレビュー</a>
    <?php endif; ?>
  </form>

  <?php if (!empty($report)): ?>
    <?php echo DRWP_Post_Converter::build_preview_html($report); ?>
  <?php else: ?>
    <div class="notice notice-info"><p>保存するとここに公開プレビューが表示されます。</p></div>
  <?php endif; ?>
</div>
