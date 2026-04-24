<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1>日報編集</h1>
  <?php if (!empty($_GET['saved'])): ?>
    <div class="notice notice-success"><p>保存しました。</p></div>
  <?php endif; ?>
  <?php if (!empty($_GET['reviewed'])): ?>
    <div class="notice notice-success"><p>レビュー状態を更新しました。</p></div>
  <?php endif; ?>
  <?php if (!empty($_GET['commented'])): ?>
    <div class="notice notice-success"><p>コメントを追加しました。</p></div>
  <?php endif; ?>
  <?php if (!empty($report->id)): ?>
    <p>現在のレビュー状態: <strong><?php echo esc_html($report->review_status ?: 'pending'); ?></strong></p>
  <?php endif; ?>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php wp_nonce_field('drwp_save_report'); ?>
    <input type="hidden" name="action" value="drwp_save_report" />
    <input type="hidden" name="id" value="<?php echo esc_attr($report->id ?? 0); ?>" />

    <table class="form-table" role="presentation">
      <tr>
        <th><label for="drwp-project-id">現場</label></th>
        <td>
          <select name="project_id" id="drwp-project-id">
            <option value="">（未設定）</option>
            <?php foreach (($projects ?? []) as $project): ?>
              <option value="<?php echo esc_attr($project->id); ?>" <?php selected((int) ($report->project_id ?? 0), (int) $project->id); ?>>
                <?php echo esc_html($project->name); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </td>
      </tr>
      <tr>
        <th><label>日付</label></th>
        <td><input type="date" name="report_date" value="<?php echo esc_attr($report->report_date ?? current_time('Y-m-d')); ?>" /></td>
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
      <tr>
        <th><label>写真</label></th>
        <td>
          <p><button type="button" class="button" id="drwp-open-media">写真を選択</button>
          <span class="description">メディアライブラリから画像を追加。キャプションは任意。ドラッグ可。</span></p>
          <div id="drwp-photo-list" class="drwp-photo-list">
            <?php foreach (($photos ?? []) as $photo): ?>
              <?php $thumb = wp_get_attachment_image_url((int) $photo->attachment_id, 'thumbnail'); ?>
              <div class="drwp-photo-item">
                <a href="#" class="drwp-photo-remove" aria-label="削除">×</a>
                <?php if ($thumb): ?>
                  <img src="<?php echo esc_url($thumb); ?>" alt="" />
                <?php else: ?>
                  <em>添付 #<?php echo (int) $photo->attachment_id; ?> が見つかりません</em>
                <?php endif; ?>
                <input type="hidden" name="attachment_ids[]" value="<?php echo (int) $photo->attachment_id; ?>" />
                <input type="text" name="attachment_captions[]" class="drwp-photo-caption" placeholder="キャプション" value="<?php echo esc_attr((string) ($photo->caption ?? '')); ?>" />
              </div>
            <?php endforeach; ?>
          </div>
        </td>
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

  <?php if (!empty($report->id)): ?>

    <?php if (current_user_can('edit_others_posts')): ?>
      <h2 style="margin-top:24px;">レビュー</h2>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:#fff;border:1px solid #dcdcde;padding:12px;">
        <?php wp_nonce_field('drwp_review_report'); ?>
        <input type="hidden" name="action" value="drwp_review_report" />
        <input type="hidden" name="id" value="<?php echo (int) $report->id; ?>" />
        <p>
          <label>新しい状態
            <select name="review_status">
              <?php foreach (['pending' => 'レビュー待ち', 'approved' => '承認', 'needs_revision' => '差し戻し'] as $val => $label): ?>
                <option value="<?php echo esc_attr($val); ?>" <?php selected($report->review_status, $val); ?>><?php echo esc_html($label); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </p>
        <p><textarea name="comment" rows="3" class="large-text" placeholder="コメント（任意、差し戻し時は具体的に）"></textarea></p>
        <?php submit_button('レビュー結果を反映', 'primary', 'submit', false); ?>
      </form>
    <?php endif; ?>

    <h2 style="margin-top:24px;">コメント</h2>
    <?php $comments = DRWP_Comment::for_report($report->id); ?>
    <?php if (empty($comments)): ?>
      <p>まだコメントはありません。</p>
    <?php else: ?>
      <ul class="drwp-comment-list" style="padding:0;list-style:none;">
        <?php foreach ($comments as $comment): ?>
          <li>
            <strong><?php echo esc_html($comment->display_name ?: '（不明）'); ?></strong>
            <span style="color:#50575e;"> — <?php echo esc_html($comment->created_at); ?></span>
            <div><?php echo wp_kses_post(wpautop($comment->body)); ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:#fff;border:1px solid #dcdcde;padding:12px;">
      <?php wp_nonce_field('drwp_add_comment'); ?>
      <input type="hidden" name="action" value="drwp_add_comment" />
      <input type="hidden" name="id" value="<?php echo (int) $report->id; ?>" />
      <p><textarea name="comment" rows="3" class="large-text" required></textarea></p>
      <?php submit_button('コメントを追加', 'secondary', 'submit', false); ?>
    </form>

    <h2 style="margin-top:24px;">操作履歴</h2>
    <?php $audit = DRWP_Audit::for_report($report->id, 50); ?>
    <?php if (empty($audit)): ?>
      <p>まだ履歴はありません。</p>
    <?php else: ?>
      <table class="widefat striped">
        <thead><tr><th>日時</th><th>イベント</th><th>ユーザー</th><th>メッセージ</th><th>詳細</th></tr></thead>
        <tbody>
          <?php foreach ($audit as $row): ?>
            <tr>
              <td><?php echo esc_html($row->created_at); ?></td>
              <td><code><?php echo esc_html($row->event); ?></code></td>
              <td><?php echo esc_html($row->display_name ?: ('#' . (int) $row->user_id)); ?></td>
              <td><?php echo esc_html($row->message); ?></td>
              <td>
                <?php if (!empty($row->meta_json)): ?>
                  <details><summary>meta</summary><pre style="white-space:pre-wrap;margin:4px 0;"><?php echo esc_html($row->meta_json); ?></pre></details>
                <?php else: ?>-<?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

  <?php endif; ?>
</div>
