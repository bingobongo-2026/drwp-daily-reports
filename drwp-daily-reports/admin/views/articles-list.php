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
  if ($pages > 1):
      $base = add_query_arg(
          array_merge(
              ['page' => 'drwp_articles'],
              array_filter(
                  [
                      's'           => $filters['search'],
                      'post_status' => $filters['post_status'],
                      'project_id'  => $filters['project_id'] ?: '',
                      'date_from'   => $filters['date_from'],
                      'date_to'     => $filters['date_to'],
                      'orderby'     => ($sort_field !== 'report_date') ? $sort_field : '',
                      'order'       => ($sort_order !== 'desc') ? $sort_order : '',
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
        <h3>
          <span class="drwp-conv-collapse-icon">✏️</span>
          <?php esc_html_e('作成する記事の中身', 'drwp-daily-reports'); ?>
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
  .drwp-article-main>h3{margin-top:0;font-size:1em;color:#1d2327;display:flex;align-items:center;gap:6px}
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
        document.getElementById('drwp-conv-tags').value=d.post_tags||'';
        // テンプレ select の出し分け — ビフォーアフターは写真が
        // 無いと意味が無い (左右ペアが空になる) ので、写真ゼロの
        // 日報では選択肢から落としつつヒントを出す。
        applyTemplateGate(!d.photos || !d.photos.length);
        if(d.post_template)document.getElementById('drwp-conv-template').value=d.post_template;
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

    /* ---- 記事作成/更新 ---- */
    document.getElementById('drwp-conv-submit').addEventListener('click',function(){
      var id=document.getElementById('drwp-conv-id').value;
      var st=document.getElementById('drwp-conv-status');
      st.textContent='処理中…';st.style.color='';this.disabled=true;var self=this;
      api('/reports/'+id+'/convert',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({
          public_title:document.getElementById('drwp-conv-title').value,
          public_intro:document.getElementById('drwp-conv-intro').value,
          public_body:getEditorContent(),
          public_next_plan:document.getElementById('drwp-conv-next-plan').value,
          post_template:document.getElementById('drwp-conv-template').value,
          post_category_id:document.getElementById('drwp-conv-category').value,
          post_tags:document.getElementById('drwp-conv-tags').value,
          post_status:document.getElementById('drwp-conv-post-status').value,
          scheduled_at:document.getElementById('drwp-conv-scheduled').value||null
        })
      }).then(function(){dlg.close();location.reload();})
        .catch(function(err){renderApiError(st,err);self.disabled=false;});
    });
  })();
  </script>

</div>
