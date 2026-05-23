<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
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
  <?php if (!empty($report->id)): ?>
    <p><?php esc_html_e('現在のレビュー状態:', 'drwp-daily-reports'); ?> <strong><?php echo esc_html(DRWP_Labels::review_status((string) ($report->review_status ?: 'pending'))); ?></strong></p>
  <?php endif; ?>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php wp_nonce_field('drwp_save_report'); ?>
    <input type="hidden" name="action" value="drwp_save_report" />
    <input type="hidden" name="id" value="<?php echo esc_attr($report->id ?? 0); ?>" />

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
        <th><label><?php esc_html_e('日付', 'drwp-daily-reports'); ?></label></th>
        <td><input type="date" name="report_date" value="<?php echo esc_attr($report->report_date ?? current_time('Y-m-d')); ?>" /></td>
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
      <tr><th colspan="2"><h2 style="margin:0;"><?php esc_html_e('公開設定', 'drwp-daily-reports'); ?></h2></th></tr>
      <tr>
        <th><label><?php esc_html_e('公開タイトル', 'drwp-daily-reports'); ?></label></th>
        <td><input type="text" name="public_title" class="regular-text" value="<?php echo esc_attr($report->public_title ?? ''); ?>" /></td>
      </tr>
      <tr>
        <th><label><?php esc_html_e('導入文', 'drwp-daily-reports'); ?></label></th>
        <td><textarea name="public_intro" rows="3" class="large-text"><?php echo esc_textarea($report->public_intro ?? ''); ?></textarea></td>
      </tr>
      <tr>
        <th><label><?php esc_html_e('公開本文', 'drwp-daily-reports'); ?></label></th>
        <td><textarea name="public_body" rows="6" class="large-text"><?php echo esc_textarea($report->public_body ?? ''); ?></textarea></td>
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
            <option value="draft" <?php selected($report->post_status ?? 'draft', 'draft'); ?>><?php esc_html_e('下書き', 'drwp-daily-reports'); ?></option>
            <option value="pending" <?php selected($report->post_status ?? '', 'pending'); ?>><?php esc_html_e('レビュー待ち', 'drwp-daily-reports'); ?></option>
            <option value="future" <?php selected($report->post_status ?? '', 'future'); ?>><?php esc_html_e('予約投稿', 'drwp-daily-reports'); ?></option>
          </select>
        </td>
      </tr>
      <tr>
        <th><label><?php esc_html_e('予約日時', 'drwp-daily-reports'); ?></label></th>
        <td><input type="datetime-local" name="scheduled_at" value="<?php echo !empty($report->scheduled_at) ? esc_attr(date('Y-m-d\TH:i', strtotime($report->scheduled_at))) : ''; ?>" /></td>
      </tr>
      <tr>
        <th><label><?php esc_html_e('写真', 'drwp-daily-reports'); ?></label></th>
        <td>
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
        </td>
      </tr>
    </table>

    <?php submit_button(__('保存', 'drwp-daily-reports')); ?>
    <?php if (!empty($report->id)): ?>
      <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=drwp_report_preview&id=' . (int) $report->id)); ?>"><?php esc_html_e('保存済み内容をプレビュー', 'drwp-daily-reports'); ?></a>
    <?php endif; ?>
  </form>

  <?php
    // The entries section needs to be inside the save form because we
    // post entries[idx][...] alongside the report fields. The form is
    // already open above (action=drwp_save_report). We render a fresh
    // <form> only after closing the section.
  ?>
  <h2 style="margin-top:24px;"><?php esc_html_e('現場エントリ (複数現場の場合)', 'drwp-daily-reports'); ?></h2>
  <p class="description">
    <?php esc_html_e('1 日に複数現場を回った場合は、現場ごとにカードを追加してください。エントリが 1 件以上ある日報は、記事化時に現場ごと別記事として作成されます。', 'drwp-daily-reports'); ?>
  </p>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="drwp-entries-form">
    <?php wp_nonce_field('drwp_save_report'); ?>
    <input type="hidden" name="action" value="drwp_save_report" />
    <input type="hidden" name="id" value="<?php echo esc_attr($report->id ?? 0); ?>" />
    <input type="hidden" name="entries_submitted" value="1" />
    <?php
      // Re-post the parent form's fields so save_report still updates
      // the report row in one go. This dual-form layout is a UI
      // compromise — the user fills the top form (flat fields), then
      // hits "現場エントリも保存" below to commit entries too.
      $repost = [
        'project_id'      => $report->project_id ?? '',
        'report_date'     => $report->report_date ?? current_time('Y-m-d'),
        'work_description'=> $report->work_description ?? '',
        'issues'          => $report->issues ?? '',
        'next_plan'       => $report->next_plan ?? '',
        'public_title'    => $report->public_title ?? '',
        'public_intro'    => $report->public_intro ?? '',
        'public_body'     => $report->public_body ?? '',
        'public_next_plan'=> $report->public_next_plan ?? '',
        'post_template'   => $report->post_template ?? 'standard',
        'post_category_id'=> $report->post_category_id ?? '',
        'post_tags'       => $report->post_tags ?? '',
        'post_status'     => $report->post_status ?? 'draft',
        'scheduled_at'    => !empty($report->scheduled_at) ? date('Y-m-d\TH:i', strtotime($report->scheduled_at)) : '',
      ];
      foreach ($repost as $k => $v):
    ?>
      <input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr((string) $v); ?>" />
    <?php endforeach; ?>

    <div id="drwp-entries" class="drwp-entries">
      <?php foreach ($entries as $idx => $entry): ?>
        <?php
          $proj_name = '';
          if (!empty($entry->project_id)) {
              $p = DRWP_Project::find((int) $entry->project_id);
              if ($p) $proj_name = $p->name;
          }
          $entry_photos = DRWP_Media::for_entry((int) $entry->id);
        ?>
        <div class="drwp-entry" data-idx="<?php echo (int) $idx; ?>">
          <div class="drwp-entry-head">
            <strong><?php
              printf(
                /* translators: %d: entry order (1-based) */
                esc_html__('現場 #%d', 'drwp-daily-reports'),
                $idx + 1
              );
            ?></strong>
            <?php if (!empty($entry->linked_post_id)): ?>
              <span class="description">
                <?php esc_html_e('連携記事:', 'drwp-daily-reports'); ?>
                <a href="<?php echo esc_url(get_edit_post_link((int) $entry->linked_post_id)); ?>">#<?php echo (int) $entry->linked_post_id; ?></a>
              </span>
            <?php endif; ?>
            <button type="button" class="button button-link-delete drwp-entry-remove">
              <?php esc_html_e('この現場を削除', 'drwp-daily-reports'); ?>
            </button>
          </div>
          <table class="form-table" role="presentation">
            <tr>
              <th><label><?php esc_html_e('現場', 'drwp-daily-reports'); ?></label></th>
              <td>
                <select name="entries[<?php echo (int) $idx; ?>][project_id]">
                  <option value=""><?php esc_html_e('（未設定）', 'drwp-daily-reports'); ?></option>
                  <?php foreach (($projects ?? []) as $project): ?>
                    <option value="<?php echo esc_attr($project->id); ?>" <?php selected((int) $entry->project_id, (int) $project->id); ?>>
                      <?php echo esc_html($project->name); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
            <tr>
              <th><label><?php esc_html_e('時刻', 'drwp-daily-reports'); ?></label></th>
              <td>
                <input type="time" name="entries[<?php echo (int) $idx; ?>][started_at]" value="<?php echo esc_attr(substr((string) $entry->started_at, 0, 5)); ?>" />
                〜
                <input type="time" name="entries[<?php echo (int) $idx; ?>][ended_at]" value="<?php echo esc_attr(substr((string) $entry->ended_at, 0, 5)); ?>" />
              </td>
            </tr>
            <tr>
              <th><label><?php esc_html_e('作業内容', 'drwp-daily-reports'); ?></label></th>
              <td><textarea name="entries[<?php echo (int) $idx; ?>][work_description]" rows="4" class="large-text"><?php echo esc_textarea((string) $entry->work_description); ?></textarea></td>
            </tr>
            <tr>
              <th><label><?php esc_html_e('問題点', 'drwp-daily-reports'); ?></label></th>
              <td><textarea name="entries[<?php echo (int) $idx; ?>][issues]" rows="2" class="large-text"><?php echo esc_textarea((string) $entry->issues); ?></textarea></td>
            </tr>
            <tr>
              <th><label><?php esc_html_e('次回予定', 'drwp-daily-reports'); ?></label></th>
              <td><textarea name="entries[<?php echo (int) $idx; ?>][next_plan]" rows="2" class="large-text"><?php echo esc_textarea((string) $entry->next_plan); ?></textarea></td>
            </tr>
            <tr><th colspan="2"><em><?php esc_html_e('公開用 (事務所側で記入)', 'drwp-daily-reports'); ?></em></th></tr>
            <tr>
              <th><label><?php esc_html_e('公開タイトル', 'drwp-daily-reports'); ?></label></th>
              <td>
                <input type="text" name="entries[<?php echo (int) $idx; ?>][public_title]" class="regular-text" value="<?php echo esc_attr((string) ($entry->public_title ?? '')); ?>" />
                <p class="description"><?php esc_html_e('空欄なら「現場名 - 日付」が自動でタイトルになります。', 'drwp-daily-reports'); ?></p>
              </td>
            </tr>
            <tr>
              <th><label><?php esc_html_e('公開本文', 'drwp-daily-reports'); ?></label></th>
              <td>
                <textarea name="entries[<?php echo (int) $idx; ?>][public_body]" rows="4" class="large-text"><?php echo esc_textarea((string) ($entry->public_body ?? '')); ?></textarea>
                <p class="description"><?php esc_html_e('空欄なら作業内容が本文に使われます。', 'drwp-daily-reports'); ?></p>
              </td>
            </tr>
            <tr>
              <th><label><?php esc_html_e('写真', 'drwp-daily-reports'); ?></label></th>
              <td>
                <button type="button" class="button drwp-entry-pick"><?php esc_html_e('メディアライブラリから追加', 'drwp-daily-reports'); ?></button>
                <div class="drwp-entry-photos" data-role="photos">
                  <?php foreach ($entry_photos as $photo): ?>
                    <?php $thumb = wp_get_attachment_image_url((int) $photo->attachment_id, 'thumbnail'); ?>
                    <div class="drwp-photo-item">
                      <a href="#" class="drwp-photo-remove" aria-label="<?php esc_attr_e('削除', 'drwp-daily-reports'); ?>">×</a>
                      <?php if ($thumb): ?>
                        <img src="<?php echo esc_url($thumb); ?>" alt="" />
                      <?php endif; ?>
                      <input type="hidden" name="entries[<?php echo (int) $idx; ?>][attachment_ids][]" value="<?php echo (int) $photo->attachment_id; ?>" />
                      <input type="text" name="entries[<?php echo (int) $idx; ?>][attachment_captions][]" class="drwp-photo-caption" placeholder="<?php esc_attr_e('キャプション', 'drwp-daily-reports'); ?>" value="<?php echo esc_attr((string) ($photo->caption ?? '')); ?>" />
                    </div>
                  <?php endforeach; ?>
                </div>
              </td>
            </tr>
          </table>
        </div>
      <?php endforeach; ?>
    </div>

    <p>
      <button type="button" class="button" id="drwp-entry-add">+ <?php esc_html_e('現場を追加', 'drwp-daily-reports'); ?></button>
    </p>

    <?php submit_button(__('現場エントリも保存', 'drwp-daily-reports'), 'primary', 'submit-entries'); ?>
  </form>

  <?php if (empty($entries) && !empty($report)): ?>
    <?php echo DRWP_Post_Converter::build_preview_html($report); ?>
  <?php elseif (empty($report)): ?>
    <div class="notice notice-info"><p><?php esc_html_e('保存するとここに公開プレビューが表示されます。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>

  <template id="drwp-entry-template">
    <div class="drwp-entry" data-idx="__IDX__">
      <div class="drwp-entry-head">
        <strong><?php esc_html_e('現場 #__N__', 'drwp-daily-reports'); ?></strong>
        <button type="button" class="button button-link-delete drwp-entry-remove">
          <?php esc_html_e('この現場を削除', 'drwp-daily-reports'); ?>
        </button>
      </div>
      <table class="form-table" role="presentation">
        <tr>
          <th><label><?php esc_html_e('現場', 'drwp-daily-reports'); ?></label></th>
          <td>
            <select name="entries[__IDX__][project_id]">
              <option value=""><?php esc_html_e('（未設定）', 'drwp-daily-reports'); ?></option>
              <?php foreach (($projects ?? []) as $project): ?>
                <option value="<?php echo esc_attr($project->id); ?>"><?php echo esc_html($project->name); ?></option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>
        <tr>
          <th><label><?php esc_html_e('時刻', 'drwp-daily-reports'); ?></label></th>
          <td>
            <input type="time" name="entries[__IDX__][started_at]" />
            〜
            <input type="time" name="entries[__IDX__][ended_at]" />
          </td>
        </tr>
        <tr>
          <th><label><?php esc_html_e('作業内容', 'drwp-daily-reports'); ?></label></th>
          <td><textarea name="entries[__IDX__][work_description]" rows="4" class="large-text"></textarea></td>
        </tr>
        <tr>
          <th><label><?php esc_html_e('問題点', 'drwp-daily-reports'); ?></label></th>
          <td><textarea name="entries[__IDX__][issues]" rows="2" class="large-text"></textarea></td>
        </tr>
        <tr>
          <th><label><?php esc_html_e('次回予定', 'drwp-daily-reports'); ?></label></th>
          <td><textarea name="entries[__IDX__][next_plan]" rows="2" class="large-text"></textarea></td>
        </tr>
        <tr><th colspan="2"><em><?php esc_html_e('公開用 (事務所側で記入)', 'drwp-daily-reports'); ?></em></th></tr>
        <tr>
          <th><label><?php esc_html_e('公開タイトル', 'drwp-daily-reports'); ?></label></th>
          <td>
            <input type="text" name="entries[__IDX__][public_title]" class="regular-text" value="" />
            <p class="description"><?php esc_html_e('空欄なら「現場名 - 日付」が自動でタイトルになります。', 'drwp-daily-reports'); ?></p>
          </td>
        </tr>
        <tr>
          <th><label><?php esc_html_e('公開本文', 'drwp-daily-reports'); ?></label></th>
          <td>
            <textarea name="entries[__IDX__][public_body]" rows="4" class="large-text"></textarea>
            <p class="description"><?php esc_html_e('空欄なら作業内容が本文に使われます。', 'drwp-daily-reports'); ?></p>
          </td>
        </tr>
        <tr>
          <th><label><?php esc_html_e('写真', 'drwp-daily-reports'); ?></label></th>
          <td>
            <button type="button" class="button drwp-entry-pick"><?php esc_html_e('メディアライブラリから追加', 'drwp-daily-reports'); ?></button>
            <div class="drwp-entry-photos" data-role="photos"></div>
          </td>
        </tr>
      </table>
    </div>
  </template>

  <?php if (!empty($report->id)): ?>

    <?php if (current_user_can('edit_others_posts')): ?>
      <h2 style="margin-top:24px;"><?php esc_html_e('レビュー', 'drwp-daily-reports'); ?></h2>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:#fff;border:1px solid #dcdcde;padding:12px;">
        <?php wp_nonce_field('drwp_review_report'); ?>
        <input type="hidden" name="action" value="drwp_review_report" />
        <input type="hidden" name="id" value="<?php echo (int) $report->id; ?>" />
        <p>
          <label><?php esc_html_e('新しい状態', 'drwp-daily-reports'); ?>
            <select name="review_status">
              <?php
              $review_options = [
                  'pending'        => __('レビュー待ち', 'drwp-daily-reports'),
                  'approved'       => __('承認', 'drwp-daily-reports'),
                  'needs_revision' => __('差し戻し', 'drwp-daily-reports'),
              ];
              foreach ($review_options as $val => $label): ?>
                <option value="<?php echo esc_attr($val); ?>" <?php selected($report->review_status, $val); ?>><?php echo esc_html($label); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </p>
        <p><textarea name="comment" rows="3" class="large-text" placeholder="<?php esc_attr_e('コメント（任意、差し戻し時は具体的に）', 'drwp-daily-reports'); ?>"></textarea></p>
        <?php submit_button(__('レビュー結果を反映', 'drwp-daily-reports'), 'primary', 'submit', false); ?>
      </form>
    <?php endif; ?>

    <h2 style="margin-top:24px;"><?php esc_html_e('コメント', 'drwp-daily-reports'); ?></h2>
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
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:#fff;border:1px solid #dcdcde;padding:12px;">
      <?php wp_nonce_field('drwp_add_comment'); ?>
      <input type="hidden" name="action" value="drwp_add_comment" />
      <input type="hidden" name="id" value="<?php echo (int) $report->id; ?>" />
      <p><textarea name="comment" rows="3" class="large-text" required></textarea></p>
      <?php submit_button(__('コメントを追加', 'drwp-daily-reports'), 'secondary', 'submit', false); ?>
    </form>

    <h2 style="margin-top:24px;"><?php esc_html_e('操作履歴', 'drwp-daily-reports'); ?></h2>
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

  <?php endif; ?>
</div>
