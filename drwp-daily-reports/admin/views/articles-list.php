<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1><?php esc_html_e('記事作成', 'drwp-daily-reports'); ?></h1>
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

  <p class="description"><?php esc_html_e('承認済みの日報のみ表示されます。', 'drwp-daily-reports'); ?></p>

  <!-- 検索・絞り込み — details で折りたたみ、条件があれば自動展開 -->
  <details class="drwp-list-filter" <?php echo ($filters['search'] || $filters['post_status'] || $filters['project_id'] || $filters['date_from'] || $filters['date_to']) ? 'open' : ''; ?>>
    <summary class="drwp-list-filter-summary"><?php esc_html_e('検索・絞り込み', 'drwp-daily-reports'); ?></summary>
    <form method="get" class="drwp-list-filter-form">
      <input type="hidden" name="page" value="drwp_articles" />
      <div class="drwp-list-filter-row">
        <input type="search" name="s" value="<?php echo esc_attr($filters['search']); ?>" placeholder="<?php esc_attr_e('本文・公開タイトル・タグ検索', 'drwp-daily-reports'); ?>" class="drwp-list-filter-input" />
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
          <option value="0"><?php esc_html_e('案件すべて', 'drwp-daily-reports'); ?></option>
          <?php foreach (($projects ?? []) as $project): ?>
            <option value="<?php echo (int) $project->id; ?>" <?php selected((int) $filters['project_id'], (int) $project->id); ?>><?php echo esc_html($project->name); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="drwp-list-filter-row">
        <input type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>" />
        <span>〜</span>
        <input type="date" name="date_to" value="<?php echo esc_attr($filters['date_to']); ?>" />
        <button class="button button-primary"><?php esc_html_e('検索', 'drwp-daily-reports'); ?></button>
        <a class="button-link" href="<?php echo esc_url(admin_url('admin.php?page=drwp_articles')); ?>"><?php esc_html_e('クリア', 'drwp-daily-reports'); ?></a>
      </div>
    </form>
  </details>

  <p class="description" style="margin:8px 0;">
    <?php
      printf(
          esc_html(_n('合計 %d 件', '合計 %d 件', (int) $total, 'drwp-daily-reports')),
          (int) $total
      );
    ?>
  </p>

  <?php
    // ページャー (上下に同じものを表示)。検索/フィルタ/ソート/per_page
    // は現在 URL から `paged` だけ削れば維持される。
    $pager_base = remove_query_arg('paged', $_SERVER['REQUEST_URI'] ?? '');
    echo DRWP_Admin::render_pager($paged, $pages, $pager_base, $total);
  ?>

    <table class="widefat striped" id="drwp-articles-table">
      <thead>
        <tr>
          <?php
            $sort_base = remove_query_arg(['orderby', 'order'], $_SERVER['REQUEST_URI'] ?? '');
            list($sort_field, $sort_order) = DRWP_Admin::parse_sort($_GET, ['id', 'report_date'], 'report_date', 'desc');
          ?>
          <th><?php echo DRWP_Admin::sortable_th_link('ID', 'id', $sort_field, $sort_order, $sort_base); ?></th>
          <th><?php echo DRWP_Admin::sortable_th_link(__('日付', 'drwp-daily-reports'), 'report_date', $sort_field, $sort_order, $sort_base); ?></th>
          <th><?php esc_html_e('作成者', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('案件', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('公開タイトル', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('投稿状態', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('記事', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('操作', 'drwp-daily-reports'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($reports)): ?>
          <tr><td colspan="8"><?php esc_html_e('承認済みの日報がありません。', 'drwp-daily-reports'); ?></td></tr>
        <?php else: foreach ($reports as $report): ?>
          <tr>
            <td><?php echo esc_html($report->id); ?></td>
            <td><?php echo esc_html(date_i18n('Y年n月j日', strtotime((string) $report->report_date))); ?></td>
            <td><?php
              echo esc_html(DRWP_User::display_name((int) $report->user_id) ?: ('#' . (int) $report->user_id));
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
            <td><?php
              $display_post_status = $report->post_status ?: 'draft';
              if ($report->linked_post_id) {
                  $linked = get_post((int) $report->linked_post_id);
                  if ($linked) {
                      $display_post_status = $linked->post_status;
                  }
              }
              echo esc_html(DRWP_Labels::post_status((string) $display_post_status));
            ?></td>
            <td><?php echo $report->linked_post_id ? '<a href="' . esc_url(get_edit_post_link((int) $report->linked_post_id)) . '">#' . esc_html($report->linked_post_id) . '</a>' : '-'; ?></td>
            <td style="white-space:nowrap;">
              <button type="button" class="button button-small drwp-article-btn" data-id="<?php echo (int) $report->id; ?>"><?php echo $report->linked_post_id ? esc_html__('記事更新', 'drwp-daily-reports') : esc_html__('記事作成', 'drwp-daily-reports'); ?></button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>

  <?php
    $pager_base = remove_query_arg('paged', $_SERVER['REQUEST_URI'] ?? '');
    echo DRWP_Admin::render_pager($paged, $pages, $pager_base, $total);
  ?>

  <!-- ============================================================
       記事作成モーダル — view + content editor + publish settings
       ============================================================ -->
  <dialog id="drwp-article-dialog" class="drwp-modal drwp-modal-wide">
    <div class="drwp-modal-header">
      <h2><?php esc_html_e('記事を作成 / 更新', 'drwp-daily-reports'); ?></h2>
      <button type="button" class="drwp-modal-close">&times;</button>
    </div>
    <div class="drwp-modal-body">
      <input type="hidden" id="drwp-conv-id" />

      <!-- ① 元の日報 — 編集対象ではなく参考表示。デフォルトは折りたたんで
           モーダルを開いた瞬間にメイン編集領域 (②) が見える状態にする。 -->
      <details class="drwp-conv-collapse">
        <summary>
          <span class="drwp-conv-collapse-icon">📋</span>
          <span class="drwp-conv-collapse-title"><?php esc_html_e('元の日報（参考表示）', 'drwp-daily-reports'); ?></span>
          <span class="drwp-conv-collapse-hint"><?php esc_html_e('クリックして開閉。下の編集には影響しません', 'drwp-daily-reports'); ?></span>
        </summary>
        <div id="drwp-view-body"><p>読み込み中…</p></div>
      </details>

      <!-- ② 作成する記事の中身 — メイン編集領域。常時開いた状態。 -->
      <div class="drwp-article-section drwp-article-main">
        <h3 class="drwp-article-main-head">
          <span class="drwp-conv-collapse-icon">✏️</span>
          <?php esc_html_e('作成する記事の中身', 'drwp-daily-reports'); ?>
          <?php
            // AI 補助ボタン — タイトル / 導入文 / 本文 / 今後の予定 を
            // まとめて生成 (Pro プランかつ AI 機能 ON のときだけ表示)。
            $ai_enabled = class_exists('DRWP_AI') && DRWP_AI::is_enabled();
            $ai_allowed = $ai_enabled && class_exists('DRWP_License') && DRWP_License::plan_allows('ai');
          ?>
          <?php if ($ai_allowed): ?>
          <span class="drwp-article-main-ai">
            <button type="button" class="button" id="drwp-conv-ai-btn">
              ✨ <?php esc_html_e('AI で下書きを生成', 'drwp-daily-reports'); ?>
            </button>
            <span id="drwp-conv-ai-status" class="description" style="margin-left:8px;"></span>
          </span>
          <?php elseif ($ai_enabled): ?>
          <span class="drwp-article-main-ai">
            <button type="button" class="button" disabled title="<?php esc_attr_e('Pro プランで利用可能です', 'drwp-daily-reports'); ?>">
              ✨ <?php esc_html_e('AI で下書きを生成', 'drwp-daily-reports'); ?>
              <span class="drwp-pro-pill">Pro</span>
            </button>
          </span>
          <?php endif; ?>
        </h3>
        <table class="form-table" role="presentation">
          <tr>
            <th><?php esc_html_e('公開タイトル', 'drwp-daily-reports'); ?></th>
            <td>
              <input type="text" id="drwp-conv-title" class="large-text" />
              <p class="description"><?php esc_html_e('WordPress 投稿のタイトルになります。入力中に同名記事を自動検出します。', 'drwp-daily-reports'); ?></p>
              <div id="drwp-dup-result" style="margin-top:4px;"></div>
            </td>
          </tr>
          <tr>
            <th><?php esc_html_e('テンプレート', 'drwp-daily-reports'); ?></th>
            <td>
              <select id="drwp-conv-template">
                <?php foreach (DRWP_Labels::post_template_options() as $key => $label): ?>
                  <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
              </select>
              <p class="description"><?php esc_html_e('記事の見た目の組み方。標準は本文＋写真ギャラリー、案件レポートは冒頭に案件メタ表、ビフォーアフターは写真を 2 列で並べます。', 'drwp-daily-reports'); ?></p>
              <p class="description" id="drwp-conv-template-hint" style="margin:4px 0 0;display:none;color:#92400e;">
                <?php esc_html_e('この日報は写真が添付されていないため、「ビフォーアフター」テンプレートは選択できません。日報編集モーダルから写真を追加してください。', 'drwp-daily-reports'); ?>
              </p>
              <!-- ビフォーアフター選択時のみ表示 — 各写真に通常/Before/After を割り当て -->
              <div id="drwp-conv-photo-kinds" class="drwp-conv-photo-kinds" hidden>
                <p class="description" style="margin:8px 0 4px;color:#1e40af;font-weight:600;">
                  <?php esc_html_e('🖼 写真を Before / After に振り分け', 'drwp-daily-reports'); ?>
                </p>
                <p class="description" style="margin:0 0 8px;">
                  <?php esc_html_e('「ビフォーアフター」テンプレートでは、各写真がどちらの列に並ぶかを指定してください。「通常」のままの写真はグリッドの下に普通に並びます。', 'drwp-daily-reports'); ?>
                </p>
                <div id="drwp-conv-photo-kinds-grid"></div>
              </div>
            </td>
          </tr>
          <tr>
            <th><?php esc_html_e('導入文', 'drwp-daily-reports'); ?></th>
            <td>
              <textarea id="drwp-conv-intro" rows="3" class="large-text"></textarea>
              <p class="description"><?php esc_html_e('記事冒頭、本文の前に出る短い紹介文（任意）。', 'drwp-daily-reports'); ?></p>
            </td>
          </tr>
          <tr>
            <th><?php esc_html_e('本文', 'drwp-daily-reports'); ?></th>
            <td>
              <?php wp_editor('', 'drwp-conv-body', [
                  'textarea_rows' => 10,
                  'media_buttons' => true,
                  'teeny'         => false,
                  'quicktags'     => true,
                  'tinymce'       => ['init_instance_callback' => 'function(e){e.hide()}'],
              ]); ?>
              <p class="description"><?php esc_html_e('メイン本文。日報の作業内容から自動で引き継がれます。記事として読みやすく整えてください。', 'drwp-daily-reports'); ?></p>
            </td>
          </tr>
          <tr>
            <th><?php esc_html_e('今後の予定', 'drwp-daily-reports'); ?></th>
            <td>
              <textarea id="drwp-conv-next-plan" rows="3" class="large-text"></textarea>
              <p class="description"><?php esc_html_e('記事末尾に「今後の予定」セクションとして表示されます（任意）。', 'drwp-daily-reports'); ?></p>
            </td>
          </tr>
          <tr>
            <th><?php esc_html_e('カテゴリ', 'drwp-daily-reports'); ?></th>
            <td>
              <?php
                wp_dropdown_categories([
                  'show_option_all' => __('カテゴリを選択', 'drwp-daily-reports'),
                  'hide_empty'      => 0,
                  'name'            => 'conv_post_category_id',
                  'id'              => 'drwp-conv-category',
                  'selected'        => 0,
                  'taxonomy'        => 'category',
                  'value_field'     => 'term_id',
                ]);
              ?>
              <p class="description"><?php esc_html_e('未選択なら WordPress のデフォルトカテゴリが使われます。', 'drwp-daily-reports'); ?></p>
            </td>
          </tr>
          <tr>
            <th><?php esc_html_e('タグ', 'drwp-daily-reports'); ?></th>
            <td>
              <input type="text" id="drwp-conv-tags" class="regular-text" placeholder="<?php esc_attr_e('カンマ区切り', 'drwp-daily-reports'); ?>" />
              <p class="description"><?php esc_html_e('カンマ区切りで複数指定可。例: 新築, 木造, 外壁', 'drwp-daily-reports'); ?></p>
            </td>
          </tr>
          <tr>
            <th><?php esc_html_e('投稿状態', 'drwp-daily-reports'); ?></th>
            <td>
              <select id="drwp-conv-post-status">
                <option value="draft"><?php echo esc_html(DRWP_Labels::post_status('draft')); ?></option>
                <option value="pending"><?php echo esc_html(DRWP_Labels::post_status('pending')); ?></option>
                <option value="future"><?php echo esc_html(DRWP_Labels::post_status('future')); ?></option>
              </select>
              <p class="description"><?php esc_html_e('下書き = 非公開のまま保存、レビュー待ち = WordPress 編集者の確認後に公開、予約投稿 = 下の予約日時に自動公開。', 'drwp-daily-reports'); ?></p>
            </td>
          </tr>
          <tr>
            <th><?php esc_html_e('予約日時', 'drwp-daily-reports'); ?></th>
            <td>
              <input type="datetime-local" id="drwp-conv-scheduled" />
              <p class="description"><?php esc_html_e('「投稿状態」を予約投稿にしたときに反映されます。', 'drwp-daily-reports'); ?></p>
            </td>
          </tr>
        </table>
        <p id="drwp-conv-linked" class="description" style="display:none;"></p>
      </div> <!-- /.drwp-article-main -->
    </div>
    <div id="drwp-conv-pii-panel" class="drwp-conv-pii-panel" hidden></div>
    <div class="drwp-modal-footer">
      <button type="button" class="button button-primary" id="drwp-conv-submit"><?php esc_html_e('記事を作成', 'drwp-daily-reports'); ?></button>
      <button type="button" class="button drwp-modal-close"><?php esc_html_e('キャンセル', 'drwp-daily-reports'); ?></button>
      <span id="drwp-conv-status" style="margin-left:12px;"></span>
    </div>
  </dialog>

  <style>
  /* 検索・絞り込み — details で折りたたみ、アクセントカラー無しの
     薄いグレー枠だけで囲って補助領域感を出す。 */
  .drwp-list-filter{margin-bottom:10px;border:1px solid #e5e7eb;border-radius:6px;background:#fff}
  .drwp-list-filter-summary{cursor:pointer;font-weight:600;color:#1d2327;list-style:none;display:flex;align-items:center;gap:6px;padding:8px 12px}
  .drwp-list-filter-summary::-webkit-details-marker{display:none}
  .drwp-list-filter-summary::before{content:'▸';font-size:.8em;color:#6b7280;transition:transform .15s}
  .drwp-list-filter[open] .drwp-list-filter-summary{border-bottom:1px solid #f1f5f9}
  .drwp-list-filter[open] .drwp-list-filter-summary::before{transform:rotate(90deg)}
  .drwp-list-filter-summary:hover{color:#2271b1}
  .drwp-list-filter-form{padding:10px 12px}
  .drwp-list-filter-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;width:100%;margin-bottom:8px}
  .drwp-list-filter-row:last-child{margin-bottom:0}
  .drwp-list-filter-input{min-width:200px;flex:1}

  /* 一括操作 — カード化はやめてテーブル直上のインライン行に。
     公開設定の追加項目だけ details で畳んでおく。 */
  .drwp-list-bulk-inline{display:flex;align-items:center;gap:8px;margin-bottom:8px;padding:6px 0;font-size:.92em;color:#475569}
  .drwp-list-bulk-inline label{font-weight:600;color:#1d2327}
  .drwp-list-bulk-sub{margin-bottom:10px;padding:0 0 0 4px;border-left:2px solid #e5e7eb}
  .drwp-list-bulk-sub-summary{cursor:pointer;font-size:.88em;color:#475569;font-weight:600;padding:4px 8px;list-style:none}
  .drwp-list-bulk-sub-summary::-webkit-details-marker{display:none}
  .drwp-list-bulk-sub-summary::before{content:'▸';font-size:.8em;color:#6b7280;margin-right:4px;transition:transform .15s}
  .drwp-list-bulk-sub[open] .drwp-list-bulk-sub-summary::before{transform:rotate(90deg)}
  .drwp-list-bulk-sub-row{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;padding:4px 8px 8px}
  .drwp-list-bulk-sub-row label{display:flex;flex-direction:column;gap:2px;min-width:120px}
  .drwp-list-bulk-sub-row label>span{font-size:.8em;color:#50575e;font-weight:600}
  .drwp-modal{border:0;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.18);padding:0;max-width:640px;width:90vw}
  .drwp-modal-wide{max-width:780px}
  .drwp-modal::backdrop{background:rgba(0,0,0,.45)}
  .drwp-modal-header{display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-bottom:1px solid #e5e7eb}
  .drwp-modal-header h2{margin:0;font-size:1.1em}
  .drwp-modal-close{background:transparent;border:0;font-size:1.6em;cursor:pointer;color:#50575e;line-height:1;padding:0 4px}
  .drwp-modal-body{padding:16px 20px;max-height:65vh;overflow-y:auto}
  .drwp-modal-body .form-table th{width:100px;padding:6px 0}
  .drwp-modal-body .form-table td{padding:6px 0}
  .drwp-modal-footer{display:flex;gap:8px;align-items:center;padding:12px 20px;border-top:1px solid #e5e7eb;background:#f6f7f7;border-radius:0 0 12px 12px}
  .drwp-view-text{white-space:pre-wrap}
  .drwp-view-photos{display:flex;gap:8px;flex-wrap:wrap;margin-top:4px}
  .drwp-view-photos img{width:100px;height:100px;object-fit:cover;border-radius:4px;border:1px solid #e5e7eb}
  .drwp-article-section{margin-bottom:16px;padding-bottom:4px;border-bottom:1px solid #e5e7eb}
  .drwp-article-section:last-child{border-bottom:0;margin-bottom:0}
  .drwp-article-section>h3{margin:0 0 8px;font-size:.95em;color:#1d2327}

  /* 元の日報の参考表示 — 行ごとに余白を取り、本文系は line-height を
     確保して「読み物」として読めるように。ラベル列は 6em で揃える。 */
  .drwp-ref-table{width:100%;border-collapse:separate;border-spacing:0}
  .drwp-ref-table th{width:6em;font-weight:700;color:#374151;font-size:.9em;padding:10px 16px 10px 0;vertical-align:top;white-space:nowrap;text-align:left}
  .drwp-ref-table td{padding:10px 0;font-size:.95em;line-height:1.75;color:#1f2937;word-break:break-word}
  .drwp-ref-table tr + tr th,
  .drwp-ref-table tr + tr td{border-top:1px solid #e5e7eb}
  .drwp-view-text{white-space:pre-wrap;line-height:1.75}
  .drwp-view-photos{margin-top:12px}

  /* メイン編集領域 (作成する記事の中身) は他より少し強調する。 */
  .drwp-article-main{background:#f8fafc;border:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;border-radius:6px;padding:14px 16px;margin-bottom:14px}
  .drwp-article-main>h3{margin-top:0;font-size:1em;color:#1d2327;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
  .drwp-article-main-ai{margin-left:auto;display:inline-flex;align-items:center;gap:4px}
  .drwp-pro-pill{display:inline-block;margin-left:4px;padding:1px 7px;border-radius:999px;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;font-size:.72em;font-weight:700;vertical-align:middle}

  /* ビフォーアフター用 — 写真ごとに 通常/Before/After を切り替える UI。
     テンプレートで before_after を選んだ時だけ出る。 */
  .drwp-conv-photo-kinds{margin-top:10px;padding:10px 12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px}
  #drwp-conv-photo-kinds-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px;margin-top:4px}
  .drwp-pk-item{position:relative;padding:6px;border:1px solid #d1d5db;border-radius:8px;background:#fff;transition:border-color .15s,background .15s}
  .drwp-pk-item.is-before{border-color:#3b82f6;background:#eff6ff}
  .drwp-pk-item.is-after{border-color:#16a34a;background:#f0fdf4}
  .drwp-pk-item img{display:block;width:100%;height:70px;object-fit:cover;border-radius:4px;background:#f3f4f6}
  .drwp-pk-radios{display:flex;gap:4px;margin-top:4px}
  .drwp-pk-radios label{flex:1;text-align:center;padding:3px 0;border:1px solid #d1d5db;border-radius:4px;font-size:.74em;cursor:pointer;background:#fff;color:#475569;font-weight:600;line-height:1.2}
  .drwp-pk-radios label.is-checked{background:#1f2937;color:#fff;border-color:#1f2937}
  .drwp-pk-radios label.is-checked[data-k="before"]{background:#2563eb;border-color:#2563eb}
  .drwp-pk-radios label.is-checked[data-k="after"]{background:#16a34a;border-color:#16a34a}
  .drwp-pk-radios input{display:none}

  /* 個人情報チェックの警告帯 — モーダルフッター直上に出して、保存
     直前に必ず目に入るようにする。 */
  .drwp-conv-pii-panel{margin:0 20px;padding:10px 14px;background:#fef3c7;border:1px solid #fcd34d;border-radius:6px;color:#92400e;font-size:.88em;line-height:1.55;display:flex;flex-wrap:wrap;gap:8px;align-items:center}
  .drwp-conv-pii-panel strong{color:#7c2d12;font-weight:700}
  .drwp-conv-pii-panel .drwp-conv-pii-kinds{flex:1;min-width:200px}
  .drwp-conv-pii-panel code{background:#fff7ed;padding:1px 5px;border-radius:3px;font-size:.92em;color:#7c2d12}
  /* description は main 直下/ネスト内 (詳細設定) どちらでも同じ
     サイズに揃える。WP デフォルトの 13px ではなく .85em を使う。 */
  .drwp-article-main .description,
  .drwp-article-main details.drwp-conv-collapse .description,
  .drwp-article-main details.drwp-conv-collapse p.description{margin:4px 0 0;color:#64748b;font-size:.85em;line-height:1.5}

  /* 折りたたみセクション (元の日報 / 詳細設定) — モーダル内の補助領域。
     開閉ボタン感を強くしてユーザーが触れることを知らせる。 */
  .drwp-conv-collapse{margin-bottom:12px;border:1px solid #e5e7eb;border-radius:6px;background:#fff;overflow:hidden}
  .drwp-conv-collapse>summary{cursor:pointer;padding:10px 14px;list-style:none;display:flex;align-items:center;gap:8px;background:#f6f7f9;font-weight:600;color:#1d2327}
  .drwp-conv-collapse>summary::-webkit-details-marker{display:none}
  .drwp-conv-collapse>summary::before{content:'▸';color:#6b7280;font-size:.85em;transition:transform .15s}
  .drwp-conv-collapse[open]>summary::before{transform:rotate(90deg)}
  .drwp-conv-collapse[open]>summary{border-bottom:1px solid #e5e7eb}
  .drwp-conv-collapse>summary:hover{background:#eef2ff}
  .drwp-conv-collapse-icon{font-size:1.05em;line-height:1}
  .drwp-conv-collapse-title{flex:1;min-width:0}
  .drwp-conv-collapse-hint{font-size:.8em;color:#94a3b8;font-weight:400;white-space:nowrap}
  .drwp-conv-collapse>div,
  .drwp-conv-collapse>table,
  .drwp-conv-collapse>p{padding:12px 14px;margin:0}
  .drwp-conv-collapse>table.form-table{padding:8px 14px}
  </style>

  <script>
  (function(){
    var rest = <?php echo wp_json_encode([
        'url'       => esc_url_raw(rest_url('drwp/v1')),
        'wpurl'     => esc_url_raw(rest_url('wp/v2')),
        'nonce'     => wp_create_nonce('wp_rest'),
        'projects'  => (object) DRWP_Admin::project_map_public(),
        'locations' => (object) DRWP_Admin::project_location_map(),
    ]); ?>;
    var table=document.getElementById('drwp-articles-table');
    if(!table)return;
    var dlg=document.getElementById('drwp-article-dialog');

    function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}

    // ビフォーアフター用テンプレートのゲート。写真が無い日報では
    // option を disabled にしてヒントを表示。既にビフォーアフターが
    // 選ばれていた場合は標準に戻す。
    function applyTemplateGate(noPhotos) {
      var sel  = document.getElementById('drwp-conv-template');
      var hint = document.getElementById('drwp-conv-template-hint');
      var opt  = sel.querySelector('option[value="before_after"]');
      if (opt) opt.disabled = !!noPhotos;
      if (hint) hint.style.display = noPhotos ? '' : 'none';
      if (noPhotos && sel.value === 'before_after') sel.value = 'standard';
    }

    // テンプレ = before_after の時だけ、写真ごとに 通常/Before/After を
    // 選ぶ UI を出す。データはモーダル内の photoKinds マップに持ち、
    // 保存時に PATCH /reports/{id} で同期 → /convert に進む。
    var photoData = []; // [{ attachment_id, url, caption, kind }]
    function loadPhotoData(d) {
      photoData = (d.photos || []).map(function (p) {
        return {
          attachment_id: Number(p.attachment_id),
          url: p.url || '',
          caption: p.caption || '',
          kind: (p.kind && (p.kind === 'before' || p.kind === 'after')) ? p.kind : 'normal',
        };
      });
    }
    function renderPhotoKindsPanel() {
      var panel = document.getElementById('drwp-conv-photo-kinds');
      var grid  = document.getElementById('drwp-conv-photo-kinds-grid');
      if (!panel || !grid) return;
      var tpl = document.getElementById('drwp-conv-template').value;
      if (tpl !== 'before_after' || photoData.length === 0) {
        panel.hidden = true;
        return;
      }
      panel.hidden = false;
      grid.innerHTML = '';
      photoData.forEach(function (p, i) {
        var item = document.createElement('div');
        item.className = 'drwp-pk-item';
        if (p.kind === 'before') item.classList.add('is-before');
        if (p.kind === 'after')  item.classList.add('is-after');
        item.innerHTML =
          '<img src="' + esc(p.url) + '" alt="" />' +
          '<div class="drwp-pk-radios">' +
            '<label data-k="normal"' + (p.kind === 'normal' ? ' class="is-checked"' : '') + '><input type="radio" name="pk_' + i + '" value="normal"' + (p.kind === 'normal' ? ' checked' : '') + ' />通常</label>' +
            '<label data-k="before"' + (p.kind === 'before' ? ' class="is-checked"' : '') + '><input type="radio" name="pk_' + i + '" value="before"' + (p.kind === 'before' ? ' checked' : '') + ' />Before</label>' +
            '<label data-k="after"' + (p.kind === 'after' ? ' class="is-checked"' : '') + '><input type="radio" name="pk_' + i + '" value="after"' + (p.kind === 'after' ? ' checked' : '') + ' />After</label>' +
          '</div>';
        item.addEventListener('change', function (e) {
          if (!e.target.matches('input[type=radio]')) return;
          p.kind = e.target.value;
          item.classList.remove('is-before', 'is-after');
          if (p.kind === 'before') item.classList.add('is-before');
          if (p.kind === 'after')  item.classList.add('is-after');
          item.querySelectorAll('.drwp-pk-radios label').forEach(function (l) {
            l.classList.toggle('is-checked', l.dataset.k === p.kind);
          });
        });
        grid.appendChild(item);
      });
    }
    document.getElementById('drwp-conv-template').addEventListener('change', renderPhotoKindsPanel);
    function api(path,opts){
      opts=opts||{};opts.credentials='same-origin';
      opts.headers=Object.assign({'X-WP-Nonce':rest.nonce},opts.headers||{});
      return fetch(rest.url+path,opts).then(function(r){
        return r.json().then(function(j){
          if (!r.ok) {
            // WP_Error の code / data を JS 側でも参照できるようにして、
            // ライセンスエラー時にはインラインで「設定を開く」ボタンを
            // 出せるようにする。
            var er = new Error(j.message || 'HTTP ' + r.status);
            er.code = j.code || '';
            er.data = j.data || {};
            throw er;
          }
          return j;
        });
      });
    }

    function renderApiError(target, err) {
      target.innerHTML = '';
      if (err && err.code === 'drwp_license' && err.data && err.data.settings_url) {
        var msg = document.createElement('span');
        msg.textContent = '<?php echo esc_js(__('ライセンスがアクティブではありません。ライセンスサーバの状態を確認してください。', 'drwp-daily-reports')); ?>';
        msg.style.color = '#991b1b';
        target.appendChild(msg);
        var link = document.createElement('a');
        link.href = err.data.settings_url;
        link.className = 'button button-small';
        link.style.marginLeft = '8px';
        link.textContent = '<?php echo esc_js(__('ライセンス設定を開く', 'drwp-daily-reports')); ?>';
        target.appendChild(link);
        return;
      }
      target.style.color = '#991b1b';
      target.textContent = err && err.message ? err.message : '';
    }

    var editorId='drwp-conv-body';

    function setEditorContent(content){
      var ed=typeof tinymce!=='undefined'&&tinymce.get(editorId);
      if(ed){ed.setContent(content||'');}
      document.getElementById(editorId).value=content||'';
    }
    function getEditorContent(){
      var ed=typeof tinymce!=='undefined'&&tinymce.get(editorId);
      if(ed)ed.save();
      return document.getElementById(editorId).value;
    }
    function showEditor(){
      var ed=typeof tinymce!=='undefined'&&tinymce.get(editorId);
      if(ed)ed.show();
    }

    if(dlg){
      dlg.addEventListener('click',function(e){
        if(e.target.classList.contains('drwp-modal-close'))dlg.close();
        if(e.target===dlg)dlg.close();
      });
    }

    /* ---- 重複確認 — タイトル入力中に自動で発火 (800ms debounce)。
            ボタンを廃止して「入力したらサイレントに調べる」運用に。 */
    var dupTimer = null;
    document.getElementById('drwp-conv-title').addEventListener('input', function () {
      if (dupTimer) clearTimeout(dupTimer);
      dupTimer = setTimeout(runDuplicateCheck, 800);
    });

    /* ---- 個人情報チェック --------------------------------------------
       公開記事はサイト外から読まれるので、顧客名 / 電話番号 / メール /
       郵便番号 が残ったまま公開されないよう、保存前に検知して警告する。
       マスクボタンで一発置換もできる。完璧な検知は無理なので「気づか
       せる」のが主目的。 */
    var piiCandidates = [];
    // 半角ハイフン / 全角ハイフン / なしを許容。10-11 桁 (0XXXXXXXXX)
    // と 携帯 (090-XXXX-XXXX) など複数パターンを 1 本にまとめる。
    var PII_PHONE  = /0\d{1,4}[-ー－]?\d{1,4}[-ー－]?\d{3,4}/g;
    var PII_EMAIL  = /[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/g;
    var PII_POSTAL = /\d{3}[-ー－]?\d{4}/g;
    var PII_LABELS = { name: '個人名', phone: '電話番号', email: 'メール', postal: '郵便番号' };
    var PII_MASK_REPLACEMENTS = {
      phone:  '○○○-○○○○-○○○○',
      email:  '○○○@○○○.○○',
      postal: '○○○-○○○○',
    };

    function piiCollectText() {
      // タイトル / 導入文 / 本文 (HTML タグは外す) / 今後の予定 をまとめる。
      var body = (getEditorContent() || '').replace(/<[^>]*>/g, ' ');
      return [
        document.getElementById('drwp-conv-title').value || '',
        document.getElementById('drwp-conv-intro').value || '',
        body,
        document.getElementById('drwp-conv-next-plan').value || '',
      ].join('\n');
    }

    function scanPII(text) {
      var hits = { name: [], phone: [], email: [], postal: [] };
      piiCandidates.forEach(function (name) {
        if (!name) return;
        if (text.indexOf(name) !== -1 && hits.name.indexOf(name) === -1) hits.name.push(name);
      });
      (text.match(PII_PHONE)  || []).forEach(function (m) { if (hits.phone.indexOf(m) === -1) hits.phone.push(m); });
      (text.match(PII_EMAIL)  || []).forEach(function (m) { if (hits.email.indexOf(m) === -1) hits.email.push(m); });
      (text.match(PII_POSTAL) || []).forEach(function (m) { if (hits.postal.indexOf(m) === -1) hits.postal.push(m); });
      // 郵便番号と電話番号は形が似ているので、電話番号にも入っていれば郵便番号側は外す。
      hits.postal = hits.postal.filter(function (p) { return hits.phone.indexOf(p) === -1; });
      return hits;
    }

    // 候補名 → ローマ字頭文字に変換するための辞書。
    // 1) 上位姓 (2 文字以上の漢字フルマッチ) — 「藤」だけだと "F" にも
    //    "T" にもなりうるため、姓まるごとで持たせて読みのブレを潰す
    // 2) ひらがな・カタカナ単独の頭文字
    // 3) 1) に無い漢字単独の最頻読み (フォールバック)
    // どれにも当たらなければ '○' を返して「○様」とする (誤読より無難)
    var SURNAME_INITIAL = {
      // 全国上位の姓 (60-70 種ほど)。読みが分かれる字は姓まるごとで対応。
      '佐藤':'S','鈴木':'S','高橋':'T','田中':'T','伊藤':'I','渡辺':'W','渡部':'W',
      '山本':'Y','中村':'N','小林':'K','加藤':'K','吉田':'Y','山田':'Y',
      '佐々木':'S','山口':'Y','松本':'M','井上':'I','木村':'K','林':'H',
      '斎藤':'S','齋藤':'S','齊藤':'S','清水':'S','山崎':'Y','山﨑':'Y','森':'M',
      '阿部':'A','池田':'I','橋本':'H','石川':'I','山下':'Y','小川':'O','中島':'N',
      '前田':'M','藤田':'F','後藤':'G','岡田':'O','長谷川':'H','近藤':'K','村上':'M',
      '青木':'A','坂本':'S','遠藤':'E','藤原':'F','太田':'O','金子':'K','中川':'N',
      '原':'H','中野':'N','内田':'U','小野':'O','田村':'T','竹内':'T','金田':'K',
      '柴田':'S','酒井':'S','工藤':'K','原田':'H','高木':'T','横山':'Y','和田':'W',
      '宮崎':'M','中山':'N','菅原':'S','平野':'H','桜井':'S','大野':'O','松井':'M',
      '丸山':'M','今井':'I','増田':'M','大塚':'O','小島':'K','平田':'H','藤井':'F',
      '河野':'K','武田':'T','村田':'M','上田':'U','杉山':'S','千葉':'C','岩崎':'I',
      '野村':'N','松尾':'M','菊池':'K','菊地':'K','木下':'K','川崎':'K','飯田':'I',
      '村山':'M','野田':'N','辻':'T','熊谷':'K','水野':'M','小沢':'O','青山':'A',
      '岩本':'I','三浦':'M','石井':'I','野口':'N','高田':'T','松田':'M','新井':'A',
      '川口':'K','谷口':'T','松浦':'M','片山':'K','秋山':'A','安藤':'A',
    };
    // ひらがな / カタカナ 単独 → 頭文字
    var KANA_INITIAL = {
      'あ':'A','い':'I','う':'U','え':'E','お':'O',
      'か':'K','き':'K','く':'K','け':'K','こ':'K',
      'が':'G','ぎ':'G','ぐ':'G','げ':'G','ご':'G',
      'さ':'S','し':'S','す':'S','せ':'S','そ':'S',
      'ざ':'Z','じ':'J','ず':'Z','ぜ':'Z','ぞ':'Z',
      'た':'T','ち':'C','つ':'T','て':'T','と':'T',
      'だ':'D','ぢ':'J','づ':'Z','で':'D','ど':'D',
      'な':'N','に':'N','ぬ':'N','ね':'N','の':'N',
      'は':'H','ひ':'H','ふ':'F','へ':'H','ほ':'H',
      'ば':'B','び':'B','ぶ':'B','べ':'B','ぼ':'B',
      'ぱ':'P','ぴ':'P','ぷ':'P','ぺ':'P','ぽ':'P',
      'ま':'M','み':'M','む':'M','め':'M','も':'M',
      'や':'Y','ゆ':'Y','よ':'Y',
      'ら':'R','り':'R','る':'R','れ':'R','ろ':'R',
      'わ':'W','を':'W','ん':'N',
      'ア':'A','イ':'I','ウ':'U','エ':'E','オ':'O',
      'カ':'K','キ':'K','ク':'K','ケ':'K','コ':'K',
      'ガ':'G','ギ':'G','グ':'G','ゲ':'G','ゴ':'G',
      'サ':'S','シ':'S','ス':'S','セ':'S','ソ':'S',
      'ザ':'Z','ジ':'J','ズ':'Z','ゼ':'Z','ゾ':'Z',
      'タ':'T','チ':'C','ツ':'T','テ':'T','ト':'T',
      'ダ':'D','ヂ':'J','ヅ':'Z','デ':'D','ド':'D',
      'ナ':'N','ニ':'N','ヌ':'N','ネ':'N','ノ':'N',
      'ハ':'H','ヒ':'H','フ':'F','ヘ':'H','ホ':'H',
      'バ':'B','ビ':'B','ブ':'B','ベ':'B','ボ':'B',
      'パ':'P','ピ':'P','プ':'P','ペ':'P','ポ':'P',
      'マ':'M','ミ':'M','ム':'M','メ':'M','モ':'M',
      'ヤ':'Y','ユ':'Y','ヨ':'Y',
      'ラ':'R','リ':'R','ル':'R','レ':'R','ロ':'R',
      'ワ':'W','ヲ':'W','ン':'N',
    };
    // 単独漢字 → 最頻読み の頭文字 (姓辞書で当たらなかった時のフォールバック)
    var KANJI_FIRST = {
      '安':'A','青':'A','阿':'A','東':'A','秋':'A','足':'A','朝':'A',
      '伊':'I','池':'I','井':'I','石':'I','今':'I','岩':'I','飯':'I',
      '宇':'U','上':'U','内':'U','植':'U',
      '江':'E','榎':'E','遠':'E',
      '岡':'O','尾':'O','織':'O','大':'O','奥':'O','小':'O',
      '加':'K','勝':'K','金':'K','河':'K','神':'K','川':'K','木':'K',
      '北':'K','工':'K','熊':'K','黒':'K','近':'K','菊':'K','倉':'K',
      '坂':'S','佐':'S','酒':'S','桜':'S','里':'S','沢':'S','澤':'S',
      '篠':'S','柴':'S','島':'S','清':'S','杉':'S','鈴':'S','瀬':'S','関':'S',
      '田':'T','高':'T','武':'T','谷':'T','玉':'T','津':'T','土':'T',
      '辻':'T','寺':'T','竹':'T','立':'T','滝':'T',
      '中':'N','長':'N','永':'N','名':'N','南':'N','西':'N','野':'N','西':'N',
      '橋':'H','畑':'H','原':'H','林':'H','平':'H','広':'H','本':'H','東':'H',
      '松':'M','丸':'M','三':'M','宮':'M','村':'M','森':'M','元':'M','増':'M',
      '水':'M','溝':'M','南':'M','三':'M',
      '山':'Y','柳':'Y','吉':'Y','横':'Y',
      '若':'W','渡':'W','和':'W',
      '藤':'F','福':'F','冨':'F','富':'F','船':'F','古':'F',
      '後':'G','郷':'G','五':'G',
      '堂':'D','土':'D','土':'D',
    };

    function nameToInitial(name) {
      if (!name) return '○';
      var firstWord = name.split(/[\s　]+/)[0];
      if (!firstWord) return '○';
      // 1) ASCII (ローマ字氏名)
      if (/^[A-Za-z]/.test(firstWord)) return firstWord.charAt(0).toUpperCase();
      // 2) 姓辞書 (最長一致 — 「佐々木」 > 「佐藤」 > 「佐」)
      for (var len = Math.min(firstWord.length, 4); len >= 1; len--) {
        var sub = firstWord.substring(0, len);
        if (SURNAME_INITIAL[sub]) return SURNAME_INITIAL[sub];
      }
      // 3) ひらがな / カタカナ
      var first = firstWord.charAt(0);
      if (KANA_INITIAL[first]) return KANA_INITIAL[first];
      // 4) 単独漢字フォールバック
      if (KANJI_FIRST[first]) return KANJI_FIRST[first];
      return '○';
    }

    // 候補名は ローマ字頭文字 + 様 で置換 (例「山田 太郎」→「Y様」)、
    // 電話/メール/郵便番号はパターン丸ごと差し替え。
    function maskText(text) {
      piiCandidates.forEach(function (name) {
        if (!name) return;
        var initial = nameToInitial(name);
        text = text.split(name).join(initial + '様');
      });
      text = text.replace(PII_PHONE,  PII_MASK_REPLACEMENTS.phone);
      text = text.replace(PII_EMAIL,  PII_MASK_REPLACEMENTS.email);
      text = text.replace(PII_POSTAL, PII_MASK_REPLACEMENTS.postal);
      return text;
    }

    function renderPiiWarning() {
      var panel = document.getElementById('drwp-conv-pii-panel');
      if (!panel) return;
      var hits = scanPII(piiCollectText());
      var total = hits.name.length + hits.phone.length + hits.email.length + hits.postal.length;
      if (!total) { panel.hidden = true; panel.innerHTML = ''; return; }
      panel.hidden = false;
      var kindsHtml = '';
      ['name', 'phone', 'email', 'postal'].forEach(function (k) {
        if (!hits[k].length) return;
        var codes = hits[k].map(function (v) { return '<code>' + esc(v) + '</code>'; }).join(' ');
        kindsHtml += '<div>⚠️ <strong>' + PII_LABELS[k] + ':</strong> ' + codes + '</div>';
      });
      panel.innerHTML =
        '<div class="drwp-conv-pii-kinds"><strong>個人情報の可能性</strong>'
        + ' — 公開前にご確認ください。' + kindsHtml + '</div>'
        + '<button type="button" class="button button-small" id="drwp-conv-pii-mask">'
        + '自動マスク</button>';
      var maskBtn = document.getElementById('drwp-conv-pii-mask');
      if (maskBtn) maskBtn.addEventListener('click', maskAllFields);
    }

    function maskAllFields() {
      if (!window.confirm('<?php echo esc_js(__('入力済みの公開タイトル / 導入文 / 本文 / 今後の予定 を自動マスクします。よろしいですか？', 'drwp-daily-reports')); ?>')) return;
      var titleEl = document.getElementById('drwp-conv-title');
      var introEl = document.getElementById('drwp-conv-intro');
      var nextEl  = document.getElementById('drwp-conv-next-plan');
      titleEl.value = maskText(titleEl.value);
      introEl.value = maskText(introEl.value);
      setEditorContent(maskText(getEditorContent() || ''));
      nextEl.value  = maskText(nextEl.value);
      renderPiiWarning();
      runDuplicateCheck();
    }

    // フィールドの変更を debounce で拾って再スキャン。
    var piiTimer = null;
    function schedulePiiScan() {
      if (piiTimer) clearTimeout(piiTimer);
      piiTimer = setTimeout(renderPiiWarning, 400);
    }
    ['drwp-conv-title', 'drwp-conv-intro', 'drwp-conv-next-plan'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.addEventListener('input', schedulePiiScan);
    });
    // 本文 (TinyMCE) は input イベントが取りにくいので、保存直前に
    // 必ず走らせる + AI 生成後にも renderPiiWarning() を呼ぶ。

    function runDuplicateCheck() {
      var title=document.getElementById('drwp-conv-title').value.trim();
      var res=document.getElementById('drwp-dup-result');
      if(!title){res.innerHTML='';return;}
      var headers={'X-WP-Nonce':rest.nonce};
      var enc=encodeURIComponent(title);
      Promise.all([
        fetch(rest.wpurl+'/posts?search='+enc+'&per_page=100&status=publish,draft,pending,future,private',{headers:headers,credentials:'same-origin'}).then(function(r){return r.json();}),
        fetch(rest.wpurl+'/pages?search='+enc+'&per_page=100&status=publish,draft,pending,future,private',{headers:headers,credentials:'same-origin'}).then(function(r){return r.json();})
      ]).then(function(results){
        var all=[].concat(results[0]||[],results[1]||[]);
        var dupes=all.filter(function(p){
          var t=(p.title&&p.title.rendered||'').replace(/&amp;/g,'&').replace(/&#039;/g,"'").replace(/&quot;/g,'"').replace(/&lt;/g,'<').replace(/&gt;/g,'>');
          return t===title;
        });
        if(dupes.length){
          var links=dupes.map(function(p){return '<a href="'+esc(p.link)+'" target="_blank">#'+p.id+' '+esc(p.title.rendered)+'</a>';});
          res.innerHTML='<span style="color:#991b1b;font-weight:600;font-size:.9em;">⚠ 同じタイトルの記事が '+dupes.length+' 件あります:</span><br>'+links.join('<br>');
        } else {
          // 重複なしの時は静かに (auto-fire するので毎回緑バッジは煩い)。
          res.innerHTML='';
        }
      }).catch(function(){/* 静かに失敗 — auto-fire なのでユーザーへ通知すべきエラーではない */});
    }

    /* ---- モーダルを開く ---- */
    table.addEventListener('click',function(e){
      var btn=e.target.closest('.drwp-article-btn');
      if(!btn)return;
      var id=btn.dataset.id;
      document.getElementById('drwp-conv-id').value=id;
      document.getElementById('drwp-conv-status').textContent='';
      document.getElementById('drwp-conv-submit').disabled=false;
      var aiSt = document.getElementById('drwp-conv-ai-status');
      if (aiSt) { aiSt.textContent = ''; aiSt.style.color = ''; }
      var aiBtnEl = document.getElementById('drwp-conv-ai-btn');
      if (aiBtnEl) aiBtnEl.disabled = false;
      // PII パネルをリセット
      var piiPanel = document.getElementById('drwp-conv-pii-panel');
      if (piiPanel) { piiPanel.hidden = true; piiPanel.innerHTML = ''; }
      piiCandidates = [];
      // Before/After 振り分けパネルもリセット
      photoData = [];
      var pkPanel = document.getElementById('drwp-conv-photo-kinds');
      if (pkPanel) pkPanel.hidden = true;
      var pkGrid = document.getElementById('drwp-conv-photo-kinds-grid');
      if (pkGrid) pkGrid.innerHTML = '';
      document.getElementById('drwp-conv-title').value='';
      document.getElementById('drwp-dup-result').innerHTML='';
      document.getElementById('drwp-conv-intro').value='';
      setEditorContent('');
      document.getElementById('drwp-conv-next-plan').value='';
      document.getElementById('drwp-conv-tags').value='';
      document.getElementById('drwp-conv-template').value='standard';
      applyTemplateGate(false); // モーダルリセット時はいったん全許可
      document.getElementById('drwp-conv-category').value='0';
      document.getElementById('drwp-conv-post-status').value='draft';
      document.getElementById('drwp-conv-scheduled').value='';
      document.getElementById('drwp-conv-linked').style.display='none';
      var viewBody=document.getElementById('drwp-view-body');
      viewBody.innerHTML='<p>読み込み中…</p>';
      dlg.showModal();
      setTimeout(showEditor,50);
      api('/reports/'+id).then(function(d){
        var time='';
        if(d.started_at)time+=d.started_at.substring(0,5);
        if(d.started_at&&d.ended_at)time+=' — ';
        if(d.ended_at)time+=d.ended_at.substring(0,5);
        var h='<table class="drwp-ref-table">';
        h+='<tr><th>日付</th><td>'+esc(d.report_date)+'</td></tr>';
        h+='<tr><th>案件</th><td>'+esc(d.project_id?(rest.projects&&rest.projects[d.project_id]||'#'+d.project_id):'（未設定）')+'</td></tr>';
        if(time)h+='<tr><th>時刻</th><td>'+esc(time)+'</td></tr>';
        h+='<tr><th>作業内容</th><td class="drwp-view-text">'+esc(d.work_description||'')+'</td></tr>';
        if(d.issues)h+='<tr><th>特記事項</th><td class="drwp-view-text">'+esc(d.issues)+'</td></tr>';
        if(d.next_plan)h+='<tr><th>次回予定</th><td class="drwp-view-text">'+esc(d.next_plan)+'</td></tr>';
        h+='</table>';
        if(d.photos&&d.photos.length){
          h+='<div class="drwp-view-photos">';
          d.photos.forEach(function(p){
            h+='<figure><img src="'+esc(p.url)+'" alt="" />'+(p.caption?'<figcaption>'+esc(p.caption)+'</figcaption>':'')+'</figure>';
          });
          h+='</div>';
        }
        viewBody.innerHTML=h;
        var autoTitle=d.public_title||'';
        if(!autoTitle&&d.project_id&&rest.locations&&rest.locations[d.project_id]){
          autoTitle=rest.locations[d.project_id];
        }
        document.getElementById('drwp-conv-title').value=autoTitle;
        // 初期表示でも同名記事が無いか自動チェック。
        runDuplicateCheck();
        document.getElementById('drwp-conv-intro').value=d.public_intro||'';
        setEditorContent(d.public_body||(d.work_description||''));
        document.getElementById('drwp-conv-next-plan').value=d.public_next_plan||'';
        // 個人情報チェック用に「警告すべき名前候補」を読み込む。
        piiCandidates = (d.pii_candidates || []).filter(function(s){ return s && s.length >= 2; });
        renderPiiWarning();
        // 写真データ (Before/After 振り分けパネル用) も保持。
        loadPhotoData(d);
        document.getElementById('drwp-conv-tags').value=d.post_tags||'';
        // テンプレ select の出し分け — ビフォーアフターは写真が
        // 無いと意味が無い (左右ペアが空になる) ので、写真ゼロの
        // 日報では選択肢から落としつつヒントを出す。
        applyTemplateGate(!d.photos || !d.photos.length);
        if(d.post_template)document.getElementById('drwp-conv-template').value=d.post_template;
        // テンプレが before_after だった or 初期で選んだ場合に panel を出す
        renderPhotoKindsPanel();
        if(d.post_category_id)document.getElementById('drwp-conv-category').value=d.post_category_id;
        if(d.post_status)document.getElementById('drwp-conv-post-status').value=d.post_status;
        if(d.scheduled_at)document.getElementById('drwp-conv-scheduled').value=d.scheduled_at.substring(0,16);
        if(d.linked_post_id){
          var el=document.getElementById('drwp-conv-linked');
          el.innerHTML='連携記事: #'+esc(String(d.linked_post_id))+' — 記事を更新します。';
          el.style.display='';
          document.getElementById('drwp-conv-submit').textContent='記事を更新';
        } else {
          document.getElementById('drwp-conv-submit').textContent='記事を作成';
        }
      }).catch(function(err){viewBody.innerHTML='<p style="color:#991b1b;">'+esc(err.message)+'</p>';});
    });

    /* ---- AI で下書きを生成 ---- */
    var aiBtn = document.getElementById('drwp-conv-ai-btn');
    if (aiBtn) {
      aiBtn.addEventListener('click', function () {
        var id = document.getElementById('drwp-conv-id').value;
        if (!id) return;
        var st = document.getElementById('drwp-conv-ai-status');
        // 既に書いたタイトル/本文を上書きするので一応確認。完全な空欄なら
        // 黙って実行する。
        var titleEl = document.getElementById('drwp-conv-title');
        var introEl = document.getElementById('drwp-conv-intro');
        var bodyText = getEditorContent();
        var nextEl = document.getElementById('drwp-conv-next-plan');
        var anyFilled = (titleEl.value.trim() !== '')
                     || (introEl.value.trim() !== '')
                     || (bodyText.replace(/<[^>]*>/g, '').trim() !== '')
                     || (nextEl.value.trim() !== '');
        if (anyFilled && !window.confirm('<?php echo esc_js(__('入力済みの内容を AI の下書きで上書きします。よろしいですか？', 'drwp-daily-reports')); ?>')) {
          return;
        }
        aiBtn.disabled = true;
        st.style.color = '#64748b';
        st.textContent = '<?php echo esc_js(__('生成中… 数秒〜数分かかる場合があります', 'drwp-daily-reports')); ?>';
        fetch(rest.url + '/ai/draft-report', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': rest.nonce },
          body: JSON.stringify({ report_id: Number(id) })
        }).then(function (r) {
          return r.json().then(function (j) { if (!r.ok) throw new Error(j.message || 'HTTP ' + r.status); return j; });
        }).then(function (d) {
          if (d.public_title) titleEl.value = d.public_title;
          introEl.value = d.public_intro || '';
          setEditorContent(d.public_body || '');
          nextEl.value = d.public_next_plan || '';
          // 重複チェックも再走させる (タイトルが変わるので)。
          runDuplicateCheck();
          // AI で本文も差し替わるので個人情報スキャンも回す。
          renderPiiWarning();
          st.style.color = '#15803d';
          st.textContent = '<?php echo esc_js(__('下書きを生成しました。内容を確認して保存してください。', 'drwp-daily-reports')); ?>';
          aiBtn.disabled = false;
        }).catch(function (err) {
          st.style.color = '#991b1b';
          st.textContent = 'エラー: ' + (err.message || '<?php echo esc_js(__('生成に失敗しました', 'drwp-daily-reports')); ?>');
          aiBtn.disabled = false;
        });
      });
    }

    /* ---- 記事作成/更新 ---- */
    document.getElementById('drwp-conv-submit').addEventListener('click',function(){
      var id=document.getElementById('drwp-conv-id').value;
      var st=document.getElementById('drwp-conv-status');
      // 送信直前にも個人情報スキャン (本文 TinyMCE は input が拾えない
      // ことがあるので保険として)。検知ありなら確認ダイアログ。
      renderPiiWarning();
      var piiPanel = document.getElementById('drwp-conv-pii-panel');
      if (piiPanel && !piiPanel.hidden) {
        if (!window.confirm('<?php echo esc_js(__('個人情報の可能性が検知されています。このまま公開記事化してよろしいですか？', 'drwp-daily-reports')); ?>')) {
          return;
        }
      }
      st.textContent='処理中…';st.style.color='';this.disabled=true;var self=this;

      var tpl = document.getElementById('drwp-conv-template').value;

      // テンプレ=before_after の場合は、まず写真の Before/After を
      // PATCH で日報に保存してから /convert に進む。それ以外はその
      // まま /convert。
      var preflight = Promise.resolve();
      if (tpl === 'before_after' && photoData.length) {
        preflight = api('/reports/'+id, {
          method: 'PATCH',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({
            attachment_ids:      photoData.map(function (p) { return p.attachment_id; }),
            attachment_captions: photoData.map(function (p) { return p.caption || ''; }),
            attachment_kinds:    photoData.map(function (p) { return p.kind || 'normal'; }),
          })
        });
      }

      preflight.then(function () {
        return api('/reports/'+id+'/convert',{
          method:'POST',
          headers:{'Content-Type':'application/json'},
          body:JSON.stringify({
            public_title:document.getElementById('drwp-conv-title').value,
            public_intro:document.getElementById('drwp-conv-intro').value,
            public_body:getEditorContent(),
            public_next_plan:document.getElementById('drwp-conv-next-plan').value,
            post_template:tpl,
            post_category_id:document.getElementById('drwp-conv-category').value,
            post_tags:document.getElementById('drwp-conv-tags').value,
            post_status:document.getElementById('drwp-conv-post-status').value,
            scheduled_at:document.getElementById('drwp-conv-scheduled').value||null
          })
        });
      }).then(function(){dlg.close();location.reload();})
        .catch(function(err){renderApiError(st,err);self.disabled=false;});
    });
  })();
  </script>

</div>
