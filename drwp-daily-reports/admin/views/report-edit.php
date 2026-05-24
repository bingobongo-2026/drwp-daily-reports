<?php if (!defined('ABSPATH')) exit; ?>
<?php
$report_id     = (int) ($report->id ?? 0);
$review_status = (string) ($report->review_status ?? 'pending');
$author_name   = '';
if (!empty($report->user_id)) {
    $author = get_userdata((int) $report->user_id);
    if ($author) $author_name = (string) $author->display_name;
}
?>
<div class="wrap drwp-report-edit">
  <h1><?php esc_html_e('日報編集', 'drwp-daily-reports'); ?></h1>

  <?php if (!empty($_GET['saved'])): ?>
    <div class="notice notice-success"><p><?php esc_html_e('保存しました。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>
  <?php if (!empty($_GET['reviewed'])): ?>
    <div class="notice notice-success"><p><?php esc_html_e('レビュー状態を更新しました。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>
  <?php if (!empty($_GET['commented'])): ?>
    <div class="notice notice-success"><p><?php esc_html_e('コメントを追加しました。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>

  <?php if ($report_id): ?>
    <p class="drwp-meta-line">
      <span><?php esc_html_e('レビュー状態:', 'drwp-daily-reports'); ?></span>
      <strong class="drwp-status status-<?php echo esc_attr($review_status); ?>">
        <?php echo esc_html(DRWP_Labels::review_status($review_status)); ?>
      </strong>
      <?php if ($author_name !== ''): ?>
        <span class="separator">·</span>
        <span><?php esc_html_e('作成者:', 'drwp-daily-reports'); ?> <strong><?php echo esc_html($author_name); ?></strong></span>
      <?php endif; ?>
      <?php if (!empty($report->linked_post_id)): ?>
        <span class="separator">·</span>
        <a href="<?php echo esc_url(get_edit_post_link((int) $report->linked_post_id)); ?>">
          <?php
          printf(
              /* translators: %d: linked post ID */
              esc_html__('連携記事 #%d', 'drwp-daily-reports'),
              (int) $report->linked_post_id
          );
          ?>
        </a>
      <?php endif; ?>
    </p>
  <?php endif; ?>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="drwp-report-form">
    <?php wp_nonce_field('drwp_save_report'); ?>
    <input type="hidden" name="action" value="drwp_save_report" />
    <input type="hidden" name="id" value="<?php echo esc_attr($report_id); ?>" />

    <!-- ============================================================
         基本情報 — site, date, time, work, issues, next plan
         ============================================================ -->
    <div class="drwp-section">
      <h2><?php esc_html_e('基本情報', 'drwp-daily-reports'); ?></h2>
      <table class="form-table" role="presentation">
        <tr>
          <th><label for="drwp-project-id"><?php esc_html_e('現場', 'drwp-daily-reports'); ?></label></th>
          <td>
            <select name="project_id" id="drwp-project-id">
              <option value=""><?php esc_html_e('（未設定）', 'drwp-daily-reports'); ?></option>
              <?php foreach (($projects ?? []) as $project): ?>
                <option value="<?php echo esc_attr($project->id); ?>" <?php selected((int) ($report->project_id ?? 0), (int) $project->id); ?>>
                  <?php echo esc_html($project->name); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>
        <tr>
          <th><label for="drwp-report-date"><?php esc_html_e('日付', 'drwp-daily-reports'); ?></label></th>
          <td><input type="date" id="drwp-report-date" name="report_date"
                     value="<?php echo esc_attr($report->report_date ?? current_time('Y-m-d')); ?>" /></td>
        </tr>
        <tr>
          <th><label><?php esc_html_e('時刻', 'drwp-daily-reports'); ?></label></th>
          <td>
            <input type="time" name="started_at"
                   value="<?php echo esc_attr(substr((string) ($report->started_at ?? ''), 0, 5)); ?>" />
            〜
            <input type="time" name="ended_at"
                   value="<?php echo esc_attr(substr((string) ($report->ended_at ?? ''), 0, 5)); ?>" />
          </td>
        </tr>
        <tr>
          <th><label><?php esc_html_e('作業内容', 'drwp-daily-reports'); ?></label></th>
          <td><textarea name="work_description" rows="5" class="large-text"><?php echo esc_textarea($report->work_description ?? ''); ?></textarea></td>
        </tr>
        <tr>
          <th><label><?php esc_html_e('問題点', 'drwp-daily-reports'); ?></label></th>
          <td><textarea name="issues" rows="4" class="large-text"><?php echo esc_textarea($report->issues ?? ''); ?></textarea></td>
        </tr>
        <tr>
          <th><label><?php esc_html_e('次回予定', 'drwp-daily-reports'); ?></label></th>
          <td><textarea name="next_plan" rows="4" class="large-text"><?php echo esc_textarea($report->next_plan ?? ''); ?></textarea></td>
        </tr>
      </table>
    </div>

    <!-- ============================================================
         公開設定
         ============================================================ -->
    <div class="drwp-section">
      <h2><?php esc_html_e('公開設定', 'drwp-daily-reports'); ?></h2>
      <table class="form-table" role="presentation">
        <tr>
          <th><label><?php esc_html_e('公開タイトル', 'drwp-daily-reports'); ?></label></th>
          <td>
            <input type="text" name="public_title" class="regular-text" value="<?php echo esc_attr($report->public_title ?? ''); ?>" />
            <p class="description"><?php esc_html_e('空欄なら「現場レポート」が自動でタイトルになります。', 'drwp-daily-reports'); ?></p>
          </td>
        </tr>
        <tr>
          <th><label><?php esc_html_e('導入文', 'drwp-daily-reports'); ?></label></th>
          <td><textarea name="public_intro" rows="3" class="large-text"><?php echo esc_textarea($report->public_intro ?? ''); ?></textarea></td>
        </tr>
        <tr>
          <th><label><?php esc_html_e('公開本文', 'drwp-daily-reports'); ?></label></th>
          <td>
            <textarea name="public_body" rows="6" class="large-text"><?php echo esc_textarea($report->public_body ?? ''); ?></textarea>
            <p class="description"><?php esc_html_e('空欄の場合は記事化時に何も出力されません(導入文・写真・次回予定のみ)。', 'drwp-daily-reports'); ?></p>
          </td>
        </tr>
        <tr>
          <th><label><?php esc_html_e('公開用の今後の予定', 'drwp-daily-reports'); ?></label></th>
          <td><textarea name="public_next_plan" rows="3" class="large-text"><?php echo esc_textarea($report->public_next_plan ?? ''); ?></textarea></td>
        </tr>
        <tr>
          <th><label><?php esc_html_e('テンプレート', 'drwp-daily-reports'); ?></label></th>
          <td>
            <select name="post_template">
              <?php foreach (DRWP_Labels::post_template_options() as $tpl_key => $tpl_label): ?>
                <option value="<?php echo esc_attr($tpl_key); ?>" <?php selected($report->post_template ?? 'standard', $tpl_key); ?>><?php echo esc_html($tpl_label); ?></option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>
        <tr>
          <th><label><?php esc_html_e('カテゴリ', 'drwp-daily-reports'); ?></label></th>
          <td>
            <?php wp_dropdown_categories([
              'show_option_all' => __('カテゴリを選択', 'drwp-daily-reports'),
              'hide_empty'      => 0,
              'name'            => 'post_category_id',
              'selected'        => (int) ($report->post_category_id ?? 0),
              'taxonomy'        => 'category',
              'value_field'     => 'term_id',
            ]); ?>
          </td>
        </tr>
        <tr>
          <th><label><?php esc_html_e('タグ', 'drwp-daily-reports'); ?></label></th>
          <td>
            <input type="text" name="post_tags" class="regular-text" value="<?php echo esc_attr($report->post_tags ?? ''); ?>" />
            <p class="description"><?php esc_html_e('カンマ区切りで入力します。例: 外壁補修, 三島市, 現場レポート', 'drwp-daily-reports'); ?></p>
          </td>
        </tr>
        <tr>
          <th><label><?php esc_html_e('投稿状態', 'drwp-daily-reports'); ?></label></th>
          <td>
            <select name="post_status">
              <option value="draft"   <?php selected($report->post_status ?? 'draft', 'draft'); ?>><?php echo esc_html(DRWP_Labels::post_status('draft')); ?></option>
              <option value="pending" <?php selected($report->post_status ?? '', 'pending'); ?>><?php echo esc_html(DRWP_Labels::post_status('pending')); ?></option>
              <option value="future"  <?php selected($report->post_status ?? '', 'future'); ?>><?php echo esc_html(DRWP_Labels::post_status('future')); ?></option>
            </select>
          </td>
        </tr>
        <tr>
          <th><label><?php esc_html_e('予約日時', 'drwp-daily-reports'); ?></label></th>
          <td><input type="datetime-local" name="scheduled_at" value="<?php echo !empty($report->scheduled_at) ? esc_attr(date('Y-m-d\TH:i', strtotime($report->scheduled_at))) : ''; ?>" /></td>
        </tr>
      </table>
    </div>

    <!-- ============================================================
         写真
         ============================================================ -->
    <div class="drwp-section">
      <h2><?php esc_html_e('写真', 'drwp-daily-reports'); ?></h2>
      <p>
        <button type="button" class="button" id="drwp-open-media"><?php esc_html_e('メディアライブラリから選択', 'drwp-daily-reports'); ?></button>
        <label class="button" for="drwp-upload-files"><?php esc_html_e('PC からアップロード', 'drwp-daily-reports'); ?></label>
        <input type="file" id="drwp-upload-files" multiple accept="image/*" style="display:none;" />
        <span id="drwp-upload-status" class="description" style="margin-left:8px;"></span>
      </p>
      <p class="description"><?php esc_html_e('複数選択可。キャプションは任意。カードはドラッグで並べ替え可。', 'drwp-daily-reports'); ?></p>
      <div id="drwp-photo-list" class="drwp-photo-list">
        <?php foreach (($photos ?? []) as $photo): ?>
          <?php $thumb = wp_get_attachment_image_url((int) $photo->attachment_id, 'thumbnail'); ?>
          <div class="drwp-photo-item">
            <a href="#" class="drwp-photo-remove" aria-label="<?php esc_attr_e('削除', 'drwp-daily-reports'); ?>">×</a>
            <?php if ($thumb): ?>
              <img src="<?php echo esc_url($thumb); ?>" alt="" />
            <?php else: ?>
              <em>
                <?php
                printf(
                    /* translators: %d: attachment ID */
                    esc_html__('添付 #%d が見つかりません', 'drwp-daily-reports'),
                    (int) $photo->attachment_id
                );
                ?>
              </em>
            <?php endif; ?>
            <input type="hidden" name="attachment_ids[]" value="<?php echo (int) $photo->attachment_id; ?>" />
            <input type="text" name="attachment_captions[]" class="drwp-photo-caption" placeholder="<?php esc_attr_e('キャプション', 'drwp-daily-reports'); ?>" value="<?php echo esc_attr((string) ($photo->caption ?? '')); ?>" />
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <p class="drwp-form-actions">
      <button type="submit" class="button button-primary"><?php esc_html_e('保存', 'drwp-daily-reports'); ?></button>
      <?php if ($report_id): ?>
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=drwp_report_preview&id=' . $report_id)); ?>">
          <?php esc_html_e('保存済み内容をプレビュー', 'drwp-daily-reports'); ?>
        </a>
      <?php endif; ?>
    </p>
  </form>

  <?php if (!empty($report)): ?>
    <div class="drwp-section">
      <h2><?php esc_html_e('公開プレビュー', 'drwp-daily-reports'); ?></h2>
      <?php echo DRWP_Post_Converter::build_preview_html($report); ?>
    </div>
  <?php else: ?>
    <div class="notice notice-info"><p><?php esc_html_e('保存するとここに公開プレビューが表示されます。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>

  <?php if ($report_id): ?>

    <?php if (current_user_can('edit_others_posts')): ?>
      <div class="drwp-section">
        <h2><?php esc_html_e('レビュー操作', 'drwp-daily-reports'); ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <?php wp_nonce_field('drwp_review_report'); ?>
          <input type="hidden" name="action" value="drwp_review_report" />
          <input type="hidden" name="id" value="<?php echo (int) $report->id; ?>" />
          <p>
            <label><?php esc_html_e('新しい状態:', 'drwp-daily-reports'); ?>
              <select name="review_status">
                <?php
                $review_options = [
                    'pending'        => DRWP_Labels::review_status('pending'),
                    'approved'       => DRWP_Labels::review_status('approved'),
                    'needs_revision' => DRWP_Labels::review_status('needs_revision'),
                    'edit_requested' => DRWP_Labels::review_status('edit_requested'),
                ];
                foreach ($review_options as $val => $label): ?>
                  <option value="<?php echo esc_attr($val); ?>" <?php selected($report->review_status, $val); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </p>
          <p><textarea name="comment" rows="3" class="large-text" placeholder="<?php esc_attr_e('コメント（任意、差し戻し時は具体的に）', 'drwp-daily-reports'); ?>"></textarea></p>
          <p><button type="submit" class="button button-primary"><?php esc_html_e('レビュー結果を反映', 'drwp-daily-reports'); ?></button></p>
        </form>
      </div>
    <?php endif; ?>

    <div class="drwp-section">
      <h2><?php esc_html_e('コメント', 'drwp-daily-reports'); ?></h2>
      <?php $comments = DRWP_Comment::for_report($report->id); ?>
      <?php if (empty($comments)): ?>
        <p><?php esc_html_e('まだコメントはありません。', 'drwp-daily-reports'); ?></p>
      <?php else: ?>
        <ul class="drwp-comment-list" style="padding:0;list-style:none;">
          <?php foreach ($comments as $comment): ?>
            <li>
              <strong><?php echo esc_html($comment->display_name ?: __('（不明）', 'drwp-daily-reports')); ?></strong>
              <span style="color:#50575e;"> — <?php echo esc_html($comment->created_at); ?></span>
              <div><?php echo wp_kses_post(wpautop($comment->body)); ?></div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('drwp_add_comment'); ?>
        <input type="hidden" name="action" value="drwp_add_comment" />
        <input type="hidden" name="id" value="<?php echo (int) $report->id; ?>" />
        <p><textarea name="comment" rows="3" class="large-text" required></textarea></p>
        <p><button type="submit" class="button"><?php esc_html_e('コメントを追加', 'drwp-daily-reports'); ?></button></p>
      </form>
    </div>

    <div class="drwp-section">
      <h2><?php esc_html_e('操作履歴', 'drwp-daily-reports'); ?></h2>
      <?php $audit = DRWP_Audit::for_report($report->id, 50); ?>
      <?php if (empty($audit)): ?>
        <p><?php esc_html_e('まだ履歴はありません。', 'drwp-daily-reports'); ?></p>
      <?php else: ?>
        <table class="widefat striped">
          <thead><tr>
            <th><?php esc_html_e('日時', 'drwp-daily-reports'); ?></th>
            <th><?php esc_html_e('イベント', 'drwp-daily-reports'); ?></th>
            <th><?php esc_html_e('ユーザー', 'drwp-daily-reports'); ?></th>
            <th><?php esc_html_e('メッセージ', 'drwp-daily-reports'); ?></th>
            <th><?php esc_html_e('詳細', 'drwp-daily-reports'); ?></th>
          </tr></thead>
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
    </div>

  <?php endif; ?>
</div>
