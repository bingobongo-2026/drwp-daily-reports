<?php if (!defined('ABSPATH')) exit;

// Merged "日報一覧" page — combines the old reports-list and
// operations-list into a single table-driven view. Each row exposes
// 確認 (paper-style view in a modal) and 編集 (inline edit modal)
// buttons; the filter card + calendar + group axes come from the
// reports-list side, the bulk operations + checkbox column come
// from the operations-list side.

$review_labels = [
    'pending'        => DRWP_Labels::review_status('pending'),
    'approved'       => DRWP_Labels::review_status('approved'),
    'needs_revision' => DRWP_Labels::review_status('needs_revision'),
    'edit_requested' => DRWP_Labels::review_status('edit_requested'),
];

$can_review = current_user_can('edit_others_posts');
?>
<div class="wrap">
  <h1><?php esc_html_e('日報一覧', 'drwp-daily-reports'); ?></h1>

  <?php if (DRWP_AI::is_enabled()): ?>
    <?php
      $advise_disabled = empty($filtered_ids);
      $advise_n = !$advise_disabled ? min(count($filtered_ids), DRWP_AI::ADVISE_MAX) : 0;
      $plan_ok = DRWP_License::plan_allows('ai');
    ?>
    <div class="drwp-ai-toolbar">
      <div class="drwp-ai-card">
        <button type="button" class="button drwp-ai-action" id="drwp-ai-alerts-btn"
                <?php disabled(!$plan_ok); ?>
                title="<?php esc_attr_e('期間内の特記事項から、いま対応が必要な項目を AI が抽出', 'drwp-daily-reports'); ?>">
          <span class="drwp-ai-emoji">⚠️</span>
          <span class="drwp-ai-label"><?php esc_html_e('対応アラート', 'drwp-daily-reports'); ?></span>
          <?php if (!$plan_ok): ?><span class="drwp-ai-pro">Pro</span><?php endif; ?>
        </button>
        <span class="drwp-ai-sub"><?php esc_html_e('短期: いま急ぐべき項目を抽出', 'drwp-daily-reports'); ?></span>
      </div>
      <div class="drwp-ai-card">
        <button type="button" class="button drwp-ai-action" id="drwp-ai-advise-btn"
                <?php disabled(!$plan_ok || $advise_disabled); ?>
                title="<?php esc_attr_e('絞り込み中の日報を AI が分析し、今後の向き合い方を提案', 'drwp-daily-reports'); ?>">
          <span class="drwp-ai-emoji">🧭</span>
          <span class="drwp-ai-label"><?php esc_html_e('振り返りアドバイス', 'drwp-daily-reports'); ?></span>
          <?php if (!$plan_ok): ?><span class="drwp-ai-pro">Pro</span><?php endif; ?>
        </button>
        <span class="drwp-ai-sub">
          <?php if (!$plan_ok): ?>
            <?php esc_html_e('長期: 今後の方針を提案', 'drwp-daily-reports'); ?>
          <?php elseif ($advise_disabled): ?>
            <?php esc_html_e('長期: 絞り込み対象が空のため利用不可', 'drwp-daily-reports'); ?>
          <?php else: ?>
            <?php /* translators: %d is the number of reports the advisor will read */
                  printf(esc_html__('長期: 最新 %d 件を分析して方針を提案', 'drwp-daily-reports'), $advise_n); ?>
          <?php endif; ?>
        </span>
      </div>
    </div>
  <?php endif; ?>

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

  <!-- 検索・絞り込み — details で折りたたみ、条件があれば自動展開 -->
  <details class="drwp-filter" <?php echo ($filters['search'] || $filters['review_status'] || $filters['project_id'] || !empty($filters['customer_group_id']) || !empty($filters['project_group_id']) || $filters['date_from'] || $filters['date_to'] || !empty($filters['user_id'])) ? 'open' : ''; ?>>
    <summary class="drwp-filter-summary"><?php esc_html_e('検索・絞り込み', 'drwp-daily-reports'); ?></summary>
    <form method="get" class="drwp-filter-form">
      <input type="hidden" name="page" value="drwp_reports" />
      <div class="drwp-row">
        <input type="search" name="s" value="<?php echo esc_attr($filters['search']); ?>" placeholder="<?php esc_attr_e('本文・公開タイトル・タグ検索', 'drwp-daily-reports'); ?>" class="drwp-search-input" />
        <select name="review_status">
          <option value=""><?php esc_html_e('レビュー状態すべて', 'drwp-daily-reports'); ?></option>
          <?php foreach ($review_labels as $k => $v): ?>
            <option value="<?php echo esc_attr($k); ?>" <?php selected($filters['review_status'], $k); ?>><?php echo esc_html($v); ?></option>
          <?php endforeach; ?>
        </select>
        <select name="project_id">
          <option value="0"><?php esc_html_e('案件すべて', 'drwp-daily-reports'); ?></option>
          <?php foreach (($projects ?? []) as $project): ?>
            <option value="<?php echo (int) $project->id; ?>" <?php selected((int) $filters['project_id'], (int) $project->id); ?>><?php echo esc_html($project->name); ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (!empty($reporters)): ?>
        <select name="user_id">
          <option value="0"><?php esc_html_e('報告者すべて', 'drwp-daily-reports'); ?></option>
          <?php foreach ($reporters as $uid => $rname): ?>
            <option value="<?php echo (int) $uid; ?>" <?php selected((int) ($filters['user_id'] ?? 0), (int) $uid); ?>><?php echo esc_html($rname); ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <?php if (current_user_can(DRWP_Reports::CAP_ARCHIVE)): ?>
        <select name="archived">
          <?php $arch = $filters['archived'] ?? 'active'; ?>
          <option value="active" <?php selected($arch, 'active'); ?>><?php esc_html_e('通常のみ', 'drwp-daily-reports'); ?></option>
          <option value="with"   <?php selected($arch, 'with'); ?>><?php esc_html_e('アーカイブ含む', 'drwp-daily-reports'); ?></option>
          <option value="only"   <?php selected($arch, 'only'); ?>><?php esc_html_e('アーカイブのみ', 'drwp-daily-reports'); ?></option>
        </select>
        <?php endif; ?>
        <?php if (!empty($project_groups)): ?>
        <select name="project_group_id">
          <option value="0"><?php esc_html_e('案件グループすべて', 'drwp-daily-reports'); ?></option>
          <?php foreach ($project_groups as $g): ?>
            <option value="<?php echo (int) $g->id; ?>" <?php selected((int) ($filters['project_group_id'] ?? 0), (int) $g->id); ?>><?php echo esc_html($g->name); ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <?php if (!empty($customer_groups)): ?>
        <select name="customer_group_id">
          <option value="0"><?php esc_html_e('顧客グループすべて', 'drwp-daily-reports'); ?></option>
          <?php foreach ($customer_groups as $g): ?>
            <option value="<?php echo (int) $g->id; ?>" <?php selected((int) ($filters['customer_group_id'] ?? 0), (int) $g->id); ?>><?php echo esc_html($g->name); ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
      </div>
      <div class="drwp-row">
        <input type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>" />
        <span>〜</span>
        <input type="date" name="date_to" value="<?php echo esc_attr($filters['date_to']); ?>" />
        <label for="drwp-per-page" style="margin-left:6px;"><?php esc_html_e('表示件数', 'drwp-daily-reports'); ?></label>
        <select name="per_page" id="drwp-per-page">
          <?php foreach (DRWP_Admin::PER_PAGE_CHOICES as $choice): ?>
            <option value="<?php echo (int) $choice; ?>" <?php selected((int) $per_page, (int) $choice); ?>>
              <?php /* translators: %d is the per-page row count */
                    printf(esc_html__('%d 件', 'drwp-daily-reports'), (int) $choice); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button class="button button-primary"><?php esc_html_e('検索', 'drwp-daily-reports'); ?></button>
        <a class="button-link" href="<?php echo esc_url(admin_url('admin.php?page=drwp_reports')); ?>"><?php esc_html_e('クリア', 'drwp-daily-reports'); ?></a>
      </div>
    </form>
  </details>

  <?php
    // 「絞り込み中の全件を CSV 出力」 — 一括操作のチェック選択
    // とは独立した GET 送信。HTML5 の `form=` 属性で外部フォーム
    // (#drwp-csv-form) を参照することで、入れ子 form を避けつつ
    // 1 行のツールバーに収める。
    $csv_query = array_filter([
        's'                 => $filters['search'],
        'review_status'     => $filters['review_status'],
        'project_id'        => $filters['project_id'] ?: '',
        'user_id'           => $filters['user_id'] ?? '' ?: '',
        'customer_group_id' => $filters['customer_group_id'] ?? '' ?: '',
        'project_group_id'  => $filters['project_group_id'] ?? '' ?: '',
        'date_from'         => $filters['date_from'],
        'date_to'           => $filters['date_to'],
    ], function ($v) { return $v !== '' && $v !== 0; });
  ?>
  <form id="drwp-csv-form" method="get" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" hidden>
    <input type="hidden" name="action" value="drwp_export_reports_csv" />
    <?php wp_nonce_field('drwp_export_reports_csv'); ?>
    <?php foreach ($csv_query as $k => $v): ?>
      <input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>" />
    <?php endforeach; ?>
  </form>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php wp_nonce_field('drwp_bulk_reports'); ?>
    <input type="hidden" name="action" value="drwp_bulk_reports" />
    <input type="hidden" name="redirect_page" value="drwp_reports" />

    <div class="drwp-table-toolbar">
      <span class="drwp-counter">
        <?php
          printf(
              esc_html(_n('合計 %d 件', '合計 %d 件', (int) $total, 'drwp-daily-reports')),
              (int) $total
          );
        ?>
      </span>
      <span class="drwp-toolbar-sep">|</span>
      <label for="drwp-bulk-action"><?php esc_html_e('一括操作:', 'drwp-daily-reports'); ?></label>
      <select name="bulk_action" id="drwp-bulk-action">
        <option value=""><?php esc_html_e('操作を選択', 'drwp-daily-reports'); ?></option>
        <option value="bulk_approve"><?php esc_html_e('一括承認', 'drwp-daily-reports'); ?></option>
        <option value="bulk_revision"><?php esc_html_e('一括差し戻し', 'drwp-daily-reports'); ?></option>
      </select>
      <button class="button"><?php esc_html_e('実行', 'drwp-daily-reports'); ?></button>

      <button type="submit" form="drwp-csv-form" class="button drwp-csv-btn"
              <?php disabled((int) $total === 0); ?>>
        ⬇ <?php
            /* translators: %d is the matched row count for the CSV export */
            printf(esc_html__('絞り込み全件CSV (%d件)', 'drwp-daily-reports'), (int) $total);
          ?>
      </button>
    </div>

    <table class="widefat striped" id="drwp-reports-table">
      <thead>
        <tr>
          <th><input type="checkbox" id="drwp-check-all" /></th>
          <?php
            // ソート可能なヘッダ — クエリは現在のページパスから orderby/order
            // を取り除いたものをベースにする。
            $sort_base = remove_query_arg(['orderby', 'order'], $_SERVER['REQUEST_URI'] ?? '');
            list($sort_field, $sort_order) = DRWP_Admin::parse_sort($_GET, ['id', 'report_date'], 'report_date', 'desc');
          ?>
          <th><?php echo DRWP_Admin::sortable_th_link('ID', 'id', $sort_field, $sort_order, $sort_base); ?></th>
          <th><?php echo DRWP_Admin::sortable_th_link(__('日付', 'drwp-daily-reports'), 'report_date', $sort_field, $sort_order, $sort_base); ?></th>
          <th><?php esc_html_e('報告者', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('案件', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('レビュー', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('操作', 'drwp-daily-reports'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($reports)): ?>
          <tr><td colspan="7"><?php esc_html_e('該当する日報がありません。', 'drwp-daily-reports'); ?></td></tr>
        <?php else: foreach ($reports as $report):
          $author = get_userdata((int) $report->user_id);
          $project_name = '-';
          if (!empty($report->project_id)) {
              $proj = DRWP_Project::find((int) $report->project_id);
              $project_name = $proj ? $proj->name : (string) $report->project_id;
          }
          $status_class = 'is-' . sanitize_html_class((string) $report->review_status);
        ?>
          <tr>
            <td><input class="drwp-check" type="checkbox" name="report_ids[]" value="<?php echo (int) $report->id; ?>" /></td>
            <td><?php echo (int) $report->id; ?></td>
            <td><?php echo esc_html(date_i18n('Y年n月j日', strtotime((string) $report->report_date))); ?></td>
            <td><?php echo esc_html(DRWP_User::display_name((int) $report->user_id) ?: ('#' . (int) $report->user_id)); ?></td>
            <td><?php echo esc_html($project_name); ?></td>
            <td><span class="drwp-page-status <?php echo esc_attr($status_class); ?>"><?php echo esc_html(DRWP_Labels::review_status((string) $report->review_status)); ?></span></td>
            <td style="white-space:nowrap;">
              <button type="button" class="button button-small drwp-view-btn" data-id="<?php echo (int) $report->id; ?>">
                <?php esc_html_e('確認', 'drwp-daily-reports'); ?>
              </button>
              <button type="button" class="button button-small drwp-edit-btn" data-id="<?php echo (int) $report->id; ?>">
                <?php esc_html_e('編集', 'drwp-daily-reports'); ?>
              </button>
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
                      's'                 => $filters['search'],
                      'review_status'     => $filters['review_status'],
                      'project_id'        => $filters['project_id'] ?: '',
                      'user_id'           => $filters['user_id'] ?? '' ?: '',
                      'customer_group_id' => $filters['customer_group_id'] ?? '' ?: '',
                      'project_group_id'  => $filters['project_group_id'] ?? '' ?: '',
                      'date_from'         => $filters['date_from'],
                      'date_to'           => $filters['date_to'],
                      'per_page'          => ((int) $per_page === DRWP_Admin::PER_PAGE) ? '' : (int) $per_page,
                      'orderby'           => ($sort_field !== 'report_date') ? $sort_field : '',
                      'order'             => ($sort_order !== 'desc') ? $sort_order : '',
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

  <!-- 確認モーダル — 紙面風表示 + レビュー + コメント -->
  <dialog id="drwp-view-dialog" class="drwp-modal drwp-modal-wide">
    <div class="drwp-modal-header">
      <h2 id="drwp-view-title"><?php esc_html_e('日報の内容', 'drwp-daily-reports'); ?></h2>
      <button type="button" class="drwp-modal-close" aria-label="<?php esc_attr_e('閉じる', 'drwp-daily-reports'); ?>">&times;</button>
    </div>
    <div class="drwp-modal-body">
      <p class="drwp-view-loading" id="drwp-view-loading"><?php esc_html_e('読み込み中…', 'drwp-daily-reports'); ?></p>
      <article class="drwp-page" id="drwp-view-page" hidden>
        <div class="drwp-page-title"><?php esc_html_e('作業日報', 'drwp-daily-reports'); ?></div>
        <table class="drwp-page-meta">
          <colgroup>
            <col class="drwp-meta-col-head" />
            <col class="drwp-meta-col-val" />
            <col class="drwp-meta-col-head" />
            <col class="drwp-meta-col-val" />
          </colgroup>
          <tr>
            <th><?php esc_html_e('案件名', 'drwp-daily-reports'); ?></th>
            <td colspan="3" id="drwp-view-project"></td>
          </tr>
          <tr>
            <th><?php esc_html_e('日付', 'drwp-daily-reports'); ?></th>
            <td id="drwp-view-date"></td>
            <th><?php esc_html_e('作業時間', 'drwp-daily-reports'); ?></th>
            <td id="drwp-view-time"></td>
          </tr>
          <tr>
            <th><?php esc_html_e('報告者', 'drwp-daily-reports'); ?></th>
            <td id="drwp-view-author"></td>
            <th><?php esc_html_e('レビュー', 'drwp-daily-reports'); ?></th>
            <td><span id="drwp-view-status" class="drwp-page-status"></span></td>
          </tr>
        </table>

        <table class="drwp-page-section">
          <tr><th class="drwp-page-section-head"><?php esc_html_e('作業内容', 'drwp-daily-reports'); ?></th></tr>
          <tr><td class="drwp-page-section-body"><div class="drwp-page-text" id="drwp-view-work"></div></td></tr>
        </table>
        <table class="drwp-page-section">
          <tr><th class="drwp-page-section-head"><?php esc_html_e('特記事項（反省・連絡・相談・提案）', 'drwp-daily-reports'); ?></th></tr>
          <tr><td class="drwp-page-section-body"><div class="drwp-page-text" id="drwp-view-issues"></div></td></tr>
        </table>
        <table class="drwp-page-section">
          <tr><th class="drwp-page-section-head"><?php esc_html_e('次回予定', 'drwp-daily-reports'); ?></th></tr>
          <tr><td class="drwp-page-section-body"><div class="drwp-page-text" id="drwp-view-next"></div></td></tr>
        </table>
        <table class="drwp-page-section">
          <tr><th class="drwp-page-section-head"><?php esc_html_e('写真', 'drwp-daily-reports'); ?></th></tr>
          <tr><td class="drwp-page-section-body"><div class="drwp-page-photos" id="drwp-view-photos"></div></td></tr>
        </table>
      </article>

      <?php if ($can_review): ?>
      <section class="drwp-card drwp-review-card">
        <h3><?php esc_html_e('レビュー操作', 'drwp-daily-reports'); ?></h3>
        <div class="drwp-row">
          <select id="drwp-review-status">
            <?php foreach ($review_labels as $k => $v): ?>
              <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($v); ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" id="drwp-review-comment" placeholder="<?php esc_attr_e('コメント（任意）', 'drwp-daily-reports'); ?>" class="drwp-review-input" />
          <button type="button" class="button button-primary" id="drwp-review-submit"><?php esc_html_e('レビュー送信', 'drwp-daily-reports'); ?></button>
          <span id="drwp-review-msg"></span>
        </div>
      </section>
      <?php endif; ?>

      <section class="drwp-card drwp-comments-card">
        <h3><?php esc_html_e('コメント', 'drwp-daily-reports'); ?></h3>
        <div id="drwp-comments-list"><p class="description"><?php esc_html_e('読み込み中…', 'drwp-daily-reports'); ?></p></div>
        <div class="drwp-row">
          <textarea id="drwp-comment-body" rows="2" class="large-text" placeholder="<?php esc_attr_e('コメントを入力…', 'drwp-daily-reports'); ?>"></textarea>
          <button type="button" class="button" id="drwp-comment-submit" style="white-space:nowrap;"><?php esc_html_e('送信', 'drwp-daily-reports'); ?></button>
        </div>
      </section>

      <?php if (current_user_can(DRWP_Reports::CAP_ARCHIVE)): ?>
      <section class="drwp-card drwp-archive-card" id="drwp-archive-section" style="background:#fef3c7;">
        <h3><?php esc_html_e('アーカイブ', 'drwp-daily-reports'); ?></h3>
        <p class="description" style="margin:0 0 8px;">
          <?php esc_html_e('アーカイブすると一覧から非表示になります。実体は残るので「アーカイブのみ」フィルタから復元できます。法定保存期間中の日報は完全削除せず、アーカイブ状態のままにしてください。', 'drwp-daily-reports'); ?>
        </p>
        <div class="drwp-row">
          <button type="button" class="button button-secondary" id="drwp-archive-btn">
            🗄 <?php esc_html_e('アーカイブする', 'drwp-daily-reports'); ?>
          </button>
          <button type="button" class="button" id="drwp-restore-btn" hidden>
            ↩ <?php esc_html_e('アーカイブから復元', 'drwp-daily-reports'); ?>
          </button>
          <?php if (current_user_can(DRWP_Reports::CAP_PURGE)): ?>
          <button type="button" class="button button-link-delete" id="drwp-purge-btn" hidden>
            🗑 <?php esc_html_e('完全削除 (取り消し不可)', 'drwp-daily-reports'); ?>
          </button>
          <?php endif; ?>
          <span id="drwp-archive-status" style="margin-left:8px;font-size:.92em;color:#475569;"></span>
        </div>
      </section>
      <?php endif; ?>
    </div>
  </dialog>

  <!-- 編集モーダル — フォーム -->
  <dialog id="drwp-edit-dialog" class="drwp-modal drwp-modal-wide">
    <div class="drwp-modal-header">
      <h2><?php esc_html_e('日報を編集', 'drwp-daily-reports'); ?></h2>
      <button type="button" class="drwp-modal-close" aria-label="<?php esc_attr_e('閉じる', 'drwp-daily-reports'); ?>">&times;</button>
    </div>
    <div class="drwp-modal-body">
      <input type="hidden" id="drwp-edit-id" />
      <table class="form-table" role="presentation">
        <tr><th><?php esc_html_e('日付', 'drwp-daily-reports'); ?></th>
            <td><input type="date" id="drwp-edit-date" /></td></tr>
        <tr><th><?php esc_html_e('案件', 'drwp-daily-reports'); ?></th>
            <td>
              <select id="drwp-edit-project">
                <option value=""><?php esc_html_e('（未設定）', 'drwp-daily-reports'); ?></option>
                <?php foreach (($projects ?? []) as $p): ?>
                  <option value="<?php echo (int) $p->id; ?>"><?php echo esc_html($p->name); ?></option>
                <?php endforeach; ?>
              </select>
            </td></tr>
        <tr><th><?php esc_html_e('時刻', 'drwp-daily-reports'); ?></th>
            <td><input type="time" id="drwp-edit-started" /> 〜 <input type="time" id="drwp-edit-ended" /></td></tr>
        <tr><th><?php esc_html_e('作業内容', 'drwp-daily-reports'); ?></th>
            <td><textarea id="drwp-edit-work" rows="4" class="large-text"></textarea></td></tr>
        <tr><th><?php esc_html_e('特記事項', 'drwp-daily-reports'); ?></th>
            <td><textarea id="drwp-edit-issues" rows="3" class="large-text"></textarea></td></tr>
        <tr><th><?php esc_html_e('次回予定', 'drwp-daily-reports'); ?></th>
            <td><textarea id="drwp-edit-next" rows="3" class="large-text"></textarea></td></tr>
        <tr><th><?php esc_html_e('写真', 'drwp-daily-reports'); ?></th>
            <td>
              <div id="drwp-edit-photos" class="drwp-edit-photo-list"></div>
              <label class="drwp-edit-photo-pick">+ <?php esc_html_e('写真を追加', 'drwp-daily-reports'); ?>
                <input type="file" accept="image/*" multiple id="drwp-edit-photo-input" />
              </label>
              <p class="description" id="drwp-edit-photo-status"></p>
            </td></tr>
      </table>
    </div>
    <div class="drwp-modal-footer">
      <button type="button" class="button button-primary" id="drwp-edit-save"><?php esc_html_e('保存', 'drwp-daily-reports'); ?></button>
      <button type="button" class="button drwp-modal-close"><?php esc_html_e('キャンセル', 'drwp-daily-reports'); ?></button>
      <span id="drwp-edit-status" style="margin-left:12px;"></span>
    </div>
  </dialog>

  <?php if (DRWP_AI::is_enabled() && DRWP_License::plan_allows('ai')): ?>
  <!-- AI 対応アラート モーダル -->
  <dialog id="drwp-ai-alerts-dialog" class="drwp-modal drwp-modal-wide">
    <div class="drwp-modal-header">
      <h2><?php esc_html_e('AI 対応アラート', 'drwp-daily-reports'); ?></h2>
      <button type="button" class="drwp-modal-close" aria-label="<?php esc_attr_e('閉じる', 'drwp-daily-reports'); ?>">&times;</button>
    </div>
    <div class="drwp-modal-body">
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">
        <?php $alert_now_ts = (int) current_time('timestamp'); ?>
        <input type="date" id="drwp-ai-alerts-from" value="<?php echo esc_attr(date('Y-m-d', $alert_now_ts - 30 * DAY_IN_SECONDS)); ?>" />
        〜
        <input type="date" id="drwp-ai-alerts-to" value="<?php echo esc_attr(date('Y-m-d', $alert_now_ts)); ?>" />
        <button type="button" class="button button-primary" id="drwp-ai-alerts-run"><?php esc_html_e('抽出', 'drwp-daily-reports'); ?></button>
      </div>
      <div id="drwp-ai-alerts-status" style="margin:6px 0;color:#64748b;"></div>
      <div id="drwp-ai-alerts-output" style="white-space:pre-wrap;font-family:inherit;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:14px;min-height:160px;line-height:1.7;"></div>
    </div>
    <div class="drwp-modal-footer">
      <button type="button" class="button drwp-modal-close"><?php esc_html_e('閉じる', 'drwp-daily-reports'); ?></button>
    </div>
  </dialog>

  <!-- AI 振り返りアドバイス モーダル -->
  <dialog id="drwp-ai-advise-dialog" class="drwp-modal drwp-modal-wide">
    <div class="drwp-modal-header">
      <h2><?php esc_html_e('AI 振り返りアドバイス', 'drwp-daily-reports'); ?></h2>
      <button type="button" class="drwp-modal-close" aria-label="<?php esc_attr_e('閉じる', 'drwp-daily-reports'); ?>">&times;</button>
    </div>
    <div class="drwp-modal-body">
      <p class="description" style="margin:0 0 8px;">
        <?php esc_html_e('画面で絞り込み中の日報から、成功例 / つまずき / 今後の向き合い方を AI がまとめます。', 'drwp-daily-reports'); ?>
      </p>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">
        <button type="button" class="button button-primary" id="drwp-ai-advise-run"
                <?php echo empty($filtered_ids) ? 'disabled' : ''; ?>>
          <?php esc_html_e('アドバイスを生成', 'drwp-daily-reports'); ?>
        </button>
        <span class="description">
          <?php if (!empty($filtered_ids)):
            printf(esc_html__('対象: 最新 %d 件', 'drwp-daily-reports'), min(count($filtered_ids), DRWP_AI::ADVISE_MAX));
          else:
            esc_html_e('対象がありません。絞り込み条件を見直してください。', 'drwp-daily-reports');
          endif; ?>
        </span>
      </div>
      <div id="drwp-ai-advise-status" style="margin:6px 0;color:#64748b;"></div>
      <div id="drwp-ai-advise-output" style="white-space:pre-wrap;font-family:inherit;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:14px;min-height:200px;line-height:1.7;"></div>
    </div>
    <div class="drwp-modal-footer">
      <button type="button" class="button drwp-modal-close"><?php esc_html_e('閉じる', 'drwp-daily-reports'); ?></button>
    </div>
  </dialog>
  <?php endif; ?>
</div>

<style>
/* 検索・絞り込み — `<details>` で控えめにまとめる。アクセントカラ
   ーを使わず、薄いグレーの枠だけで囲んで「補助領域」感を出す。 */
.drwp-filter{margin-bottom:10px;border:1px solid #e5e7eb;border-radius:6px;background:#fff}
.drwp-filter-summary{cursor:pointer;font-weight:600;color:#1d2327;list-style:none;display:flex;align-items:center;gap:6px;padding:8px 12px}
.drwp-filter-summary::-webkit-details-marker{display:none}
.drwp-filter-summary::before{content:'▸';font-size:.8em;color:#6b7280;transition:transform .15s}
.drwp-filter[open] .drwp-filter-summary{border-bottom:1px solid #f1f5f9}
.drwp-filter[open] .drwp-filter-summary::before{transform:rotate(90deg)}
.drwp-filter-summary:hover{color:#2271b1}
.drwp-filter-form{padding:10px 12px}
.drwp-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:8px}
.drwp-row:last-child{margin-bottom:0}
.drwp-search-input{min-width:240px;flex:1}

/* AI ツールバー — 2 つの AI 操作を「短期/長期」のコントラストが
   伝わる形にコンパクトにまとめる。各ボタンの下に短い説明を 1 行。 */
.drwp-ai-toolbar{display:flex;flex-wrap:wrap;gap:18px;margin:8px 0 14px;padding:10px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px}
.drwp-ai-card{display:flex;flex-direction:column;gap:2px;min-width:180px}
.drwp-ai-action{display:inline-flex;align-items:center;gap:6px;font-weight:600}
.drwp-ai-action[disabled]{opacity:.55;cursor:not-allowed}
.drwp-ai-emoji{font-size:1.05em;line-height:1}
.drwp-ai-label{line-height:1.2}
.drwp-ai-pro{background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;font-size:.72em;font-weight:700;padding:1px 7px;border-radius:999px;margin-left:2px}
.drwp-ai-sub{font-size:.78em;color:#64748b;padding-left:4px;line-height:1.4}

/* テーブル直上のツールバー — 「件数 / 一括操作 / CSV」を 1 行に
   まとめる。CSV ボタンは margin-left:auto で右端に寄せる。 */
.drwp-table-toolbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:10px 0 8px;padding:8px 10px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;font-size:.92em;color:#475569}
.drwp-table-toolbar label{font-weight:600;color:#1d2327;margin-left:4px}
.drwp-table-toolbar .drwp-counter{font-weight:600;color:#1d2327}
.drwp-table-toolbar .drwp-toolbar-sep{color:#cbd5e1;margin:0 2px}
.drwp-table-toolbar .drwp-csv-btn{margin-left:auto}

/* ソート可能ヘッダ — 文字色は地味に、ホバーで濃く。アクティブなときは
   矢印を残しつつ太字に。 */
.drwp-sortable{color:#475569;text-decoration:none;display:inline-flex;align-items:center;gap:2px;white-space:nowrap}
.drwp-sortable:hover{color:#1d2327}
.drwp-sortable.is-active{color:#1d2327;font-weight:700}
.drwp-sort-arrow{color:#94a3b8;font-size:.78em;font-weight:400}
.drwp-sortable.is-active .drwp-sort-arrow{color:#2271b1}

/* 共通モーダル — 確認 / 編集 で同じスタイル */
.drwp-modal{border:0;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.18);padding:0;max-width:640px;width:90vw}
.drwp-modal-wide{max-width:860px}
.drwp-modal::backdrop{background:rgba(0,0,0,.45)}
.drwp-modal-header{display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-bottom:1px solid #e5e7eb}
.drwp-modal-header h2{margin:0;font-size:1.1em}
.drwp-modal-close{background:transparent;border:0;font-size:1.6em;cursor:pointer;color:#50575e;line-height:1;padding:0 4px}
.drwp-modal-body{padding:16px 20px;max-height:78vh;overflow-y:auto}
.drwp-modal-body .form-table th{width:100px;padding:6px 0}
.drwp-modal-body .form-table td{padding:6px 0}
.drwp-modal-footer{display:flex;gap:8px;align-items:center;padding:12px 20px;border-top:1px solid #e5e7eb;background:#f6f7f7;border-radius:0 0 12px 12px}
.drwp-view-loading{color:#64748b;text-align:center;padding:32px 0}

/* 編集モーダル — 写真リスト + 追加ボタン */
.drwp-edit-photo-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px;margin-bottom:6px}
.drwp-edit-photo-item{position:relative;padding:6px;border:1px solid #d1d5db;border-radius:8px;background:#fff;transition:border-color .15s,background .15s}
.drwp-edit-photo-item.is-before{border-color:#3b82f6;background:#eff6ff}
.drwp-edit-photo-item.is-after{border-color:#16a34a;background:#f0fdf4}
.drwp-edit-photo-item img{display:block;width:100%;height:80px;object-fit:cover;border-radius:4px;background:#f3f4f6}
.drwp-edit-photo-item input[type=text]{width:100%;margin-top:4px;padding:4px 6px;border:1px solid #d1d5db;border-radius:4px;font-size:.78em;box-sizing:border-box}
.drwp-edit-photo-kind{width:100%;margin-top:4px;padding:3px 4px;border:1px solid #d1d5db;border-radius:4px;font-size:.78em;box-sizing:border-box;background:#fff}
.drwp-edit-photo-remove{position:absolute;top:-6px;right:-6px;width:22px;height:22px;border-radius:50%;background:#ef4444;color:#fff;border:0;font-size:14px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center}
.drwp-edit-photo-pick{display:inline-block;padding:6px 12px;background:#f1f5f9;border:1px dashed #94a3b8;border-radius:6px;cursor:pointer;font-size:.9em;color:#1e293b}
.drwp-edit-photo-pick input{display:none}
#drwp-edit-photo-status{margin:4px 0 0;font-size:.85em;color:#64748b;min-height:1.2em}

/* 紙面風表示 — 確認モーダル内 */
.drwp-page{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px 24px;margin-bottom:14px;font-family:"Hiragino Sans","Yu Gothic","Noto Sans JP",sans-serif;color:#1f2937}
.drwp-page-title{text-align:center;font-size:1.25em;font-weight:700;background:#e5e7eb;border:1px solid #1f2937;padding:6px 0;margin-bottom:12px}
.drwp-page-meta{width:100%;border-collapse:collapse;margin-bottom:12px;table-layout:fixed}
.drwp-page-meta th,.drwp-page-meta td{border:1px solid #1f2937;padding:6px 10px;font-size:.92em;vertical-align:middle}
.drwp-page-meta th{background:#e5e7eb;text-align:center;font-weight:600;white-space:nowrap}
.drwp-meta-col-head{width:14%}
.drwp-meta-col-val{width:36%}
.drwp-page-status{display:inline-block;padding:2px 10px;border-radius:999px;font-size:.8em;font-weight:600;background:#f1f5f9;color:#475569}
.drwp-page-status.is-approved{background:#dcfce7;color:#166534}
.drwp-page-status.is-needs_revision{background:#fee2e2;color:#991b1b}
.drwp-page-status.is-edit_requested{background:#fef3c7;color:#92400e}
.drwp-page-status.is-pending{background:#e0e7ff;color:#3730a3}
.drwp-page-section{width:100%;border-collapse:collapse;margin-top:10px;table-layout:fixed}
.drwp-page-section-head{background:#e5e7eb;border:1px solid #1f2937;text-align:center;font-weight:700;font-size:.95em;padding:5px}
.drwp-page-section-body{border:1px solid #1f2937;padding:12px 14px;vertical-align:top;min-height:60px}
.drwp-page-text{white-space:pre-wrap;line-height:1.6;color:#1f2937}
.drwp-page-photos{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px}
.drwp-page-photos figure{margin:0}
.drwp-page-photos img{width:100%;aspect-ratio:1;object-fit:cover;border:1px solid #c3c4c7;display:block}
.drwp-page-photos figcaption{font-size:.8em;color:#64748b;margin-top:2px}

.drwp-review-card{background:#f0fdf4}
.drwp-review-input{flex:1;min-width:200px}
.drwp-comments-card{background:#fff}
.drwp-comment-item{padding:8px 0;border-bottom:1px solid #f1f5f9}
.drwp-comment-meta{font-size:.8em;color:#64748b;margin-bottom:2px}
.drwp-comment-body{white-space:pre-wrap;font-size:.92em;color:#1f2937}
</style>

<script>
(function(){
  var rest = <?php echo wp_json_encode([
      'url'           => esc_url_raw(rest_url('drwp/v1')),
      'nonce'         => wp_create_nonce('wp_rest'),
      'labels'        => $review_labels,
      // クライアント側で先にファイルサイズを弾けるよう php.ini の値を渡す。
      // post_max_size の方が低いことがあるので min(upload_max, post_max) を採用。
      'maxUploadBytes'   => min(wp_max_upload_size(), (int) wp_convert_hr_to_bytes((string) ini_get('post_max_size'))),
      'maxUploadDisplay' => size_format((float) min(wp_max_upload_size(), (int) wp_convert_hr_to_bytes((string) ini_get('post_max_size')))),
  ]); ?>;

  var checkAll = document.getElementById('drwp-check-all');
  if (checkAll) {
    checkAll.addEventListener('change', function () {
      document.querySelectorAll('.drwp-check').forEach(function (cb) {
        cb.checked = checkAll.checked;
      });
    });
  }

  function esc(s){var d=document.createElement('div');d.textContent=String(s==null?'':s);return d.innerHTML;}
  function api(path, opts){
    opts = opts || {}; opts.credentials = 'same-origin';
    opts.headers = Object.assign({'X-WP-Nonce': rest.nonce}, opts.headers || {});
    return fetch(rest.url + path, opts).then(function (r) {
      return r.json().then(function (j) {
        if (!r.ok) {
          // Preserve the WP_Error code + data so callers can branch on
          // specific failures (e.g. license inactive → render a link
          // straight to the settings page instead of a flat message).
          var e = new Error(j.message || 'HTTP ' + r.status);
          e.code = j.code || '';
          e.data = j.data || {};
          throw e;
        }
        return j;
      });
    });
  }

  // ライセンス無効時は本文を「お決まりの 1 文」に差し替えて、
  // 横に「ライセンス設定を開く」ボタンを並べる。これで「何が起きて
  // どう直せばよいか」が 1 行で完結する。
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
    target.textContent = err && err.message ? err.message : '';
  }
  function formatDate(d){
    if(!d) return '';
    var dt = new Date(d + 'T00:00:00');
    if(isNaN(dt)) return d;
    var w = ['日','月','火','水','木','金','土'][dt.getDay()];
    return dt.getFullYear() + '年' + (dt.getMonth()+1) + '月' + dt.getDate() + '日（' + w + '）';
  }

  /* ---- 確認モーダル ---- */
  var viewDlg = document.getElementById('drwp-view-dialog');
  var viewPage = document.getElementById('drwp-view-page');
  var viewLoading = document.getElementById('drwp-view-loading');
  var viewTitle = document.getElementById('drwp-view-title');
  var currentViewId = 0;

  function loadComments(id) {
    var list = document.getElementById('drwp-comments-list');
    if (!list) return;
    list.innerHTML = '<p class="description"><?php echo esc_js(__('読み込み中…', 'drwp-daily-reports')); ?></p>';
    api('/reports/' + id + '/comments').then(function (r) {
      // Stale check — operator may have closed/reopened on a different row.
      if (currentViewId !== id) return;
      var items = r.items || [];
      if (!items.length) {
        list.innerHTML = '<p class="description"><?php echo esc_js(__('コメントはありません。', 'drwp-daily-reports')); ?></p>';
        return;
      }
      var html = '';
      items.forEach(function (c) {
        html += '<div class="drwp-comment-item">' +
                  '<div class="drwp-comment-meta">' + esc(c.display_name || ('#' + c.user_id)) + ' — ' + esc(c.created_at) + '</div>' +
                  '<div class="drwp-comment-body">' + esc(c.body) + '</div>' +
                '</div>';
      });
      list.innerHTML = html;
    }).catch(function () {
      list.innerHTML = '<p class="description" style="color:#991b1b;"><?php echo esc_js(__('コメントの読み込みに失敗しました。', 'drwp-daily-reports')); ?></p>';
    });
  }

  function openViewModal(id) {
    currentViewId = id;
    viewTitle.textContent = '<?php echo esc_js(__('日報の内容', 'drwp-daily-reports')); ?> (#' + id + ')';
    viewLoading.hidden = false;
    viewPage.hidden = true;
    // Reset review/comment UI before showing the modal so a stale
    // value doesn't flash up while the API call is in flight.
    var statusSel = document.getElementById('drwp-review-status');
    if (statusSel) statusSel.value = 'pending';
    var commentEl = document.getElementById('drwp-review-comment');
    if (commentEl) commentEl.value = '';
    var msgEl = document.getElementById('drwp-review-msg');
    if (msgEl) { msgEl.textContent = ''; msgEl.style.color = ''; }
    viewDlg.showModal();

    api('/reports/' + id).then(function (d) {
      if (currentViewId !== id) return;
      document.getElementById('drwp-view-project').textContent = d.project_name || '<?php echo esc_js(__('（案件未設定）', 'drwp-daily-reports')); ?>';
      document.getElementById('drwp-view-date').textContent = formatDate(d.report_date);
      var time = '';
      if (d.started_at) time += String(d.started_at).substring(0, 5);
      if (d.started_at && d.ended_at) time += ' 〜 ';
      if (d.ended_at) time += String(d.ended_at).substring(0, 5);
      document.getElementById('drwp-view-time').textContent = time;
      document.getElementById('drwp-view-author').textContent = d.author_name || '';
      var status = document.getElementById('drwp-view-status');
      status.textContent = rest.labels[d.review_status] || d.review_status;
      status.className = 'drwp-page-status is-' + d.review_status;
      document.getElementById('drwp-view-work').textContent = d.work_description || '';
      document.getElementById('drwp-view-issues').textContent = d.issues || '';
      document.getElementById('drwp-view-next').textContent = d.next_plan || '';
      var photosEl = document.getElementById('drwp-view-photos');
      photosEl.innerHTML = '';
      if (d.photos && d.photos.length) {
        var html = '';
        d.photos.forEach(function (p) {
          html += '<figure><img src="' + esc(p.url) + '" alt="" />' +
                  (p.caption ? '<figcaption>' + esc(p.caption) + '</figcaption>' : '') +
                  '</figure>';
        });
        photosEl.innerHTML = html;
      }
      if (statusSel) statusSel.value = d.review_status || 'pending';
      // アーカイブ状態に応じてボタンを出し分け。完全削除は管理者
      // かつ archived から PURGE_MIN_DAYS 経過した時のみ。
      updateArchiveUI(d);
      viewLoading.hidden = true;
      viewPage.hidden = false;
      loadComments(id);
    }).catch(function (err) {
      viewLoading.textContent = (err && err.message) || '<?php echo esc_js(__('読み込みに失敗しました。', 'drwp-daily-reports')); ?>';
    });
  }

  // Review submit (only present when capability allows it).
  var reviewBtn = document.getElementById('drwp-review-submit');
  if (reviewBtn) {
    reviewBtn.addEventListener('click', function () {
      var id = currentViewId;
      if (!id) return;
      var msg = document.getElementById('drwp-review-msg');
      msg.textContent = '<?php echo esc_js(__('送信中…', 'drwp-daily-reports')); ?>';
      msg.style.color = '';
      reviewBtn.disabled = true;
      api('/reports/' + id + '/review', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          review_status: document.getElementById('drwp-review-status').value,
          comment: document.getElementById('drwp-review-comment').value
        })
      }).then(function () {
        msg.textContent = '<?php echo esc_js(__('送信しました', 'drwp-daily-reports')); ?>';
        msg.style.color = '#166534';
        reviewBtn.disabled = false;
        document.getElementById('drwp-review-comment').value = '';
        var newStatus = document.getElementById('drwp-review-status').value;
        var statusBadge = document.getElementById('drwp-view-status');
        statusBadge.textContent = rest.labels[newStatus] || newStatus;
        statusBadge.className = 'drwp-page-status is-' + newStatus;
        loadComments(id);
      }).catch(function (err) {
        msg.style.color = '#991b1b';
        renderApiError(msg, err);
        reviewBtn.disabled = false;
      });
    });
  }

  document.getElementById('drwp-comment-submit').addEventListener('click', function () {
    var id = currentViewId;
    if (!id) return;
    var bodyEl = document.getElementById('drwp-comment-body');
    var text = bodyEl.value.trim();
    if (!text) return;
    var btn = this;
    btn.disabled = true;
    api('/reports/' + id + '/comments', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({body: text})
    }).then(function () { bodyEl.value = ''; btn.disabled = false; loadComments(id); })
      .catch(function () { btn.disabled = false; });
  });

  /* ---- アーカイブ / 復元 / 完全削除 ---- */
  var archiveSection = document.getElementById('drwp-archive-section');
  var archiveBtn     = document.getElementById('drwp-archive-btn');
  var restoreBtn     = document.getElementById('drwp-restore-btn');
  var purgeBtn       = document.getElementById('drwp-purge-btn');
  var archiveStatus  = document.getElementById('drwp-archive-status');

  function updateArchiveUI(d) {
    if (!archiveSection) return;
    archiveStatus.textContent = '';
    archiveStatus.style.color = '#475569';
    var archived = !!d.archived_at;
    if (archiveBtn) archiveBtn.hidden = archived;
    if (restoreBtn) restoreBtn.hidden = !archived;
    if (purgeBtn) {
      // 完全削除ボタンはアーカイブ済み + 経過日数 >= purge_min_days
      // の両方を満たした時のみ表示。それ以外は隠す。
      var days = Number(d.days_archived || 0);
      var min  = Number(d.purge_min_days || 30);
      purgeBtn.hidden = !(archived && days >= min);
      if (archived && days < min) {
        archiveStatus.textContent = '<?php echo esc_js(__('完全削除は %d 日経過後 (現在 %d 日経過)', 'drwp-daily-reports')); ?>'
          .replace('%d', min).replace('%d', days);
      } else if (archived) {
        archiveStatus.textContent = '<?php echo esc_js(__('アーカイブ済み (%d 日経過)', 'drwp-daily-reports')); ?>'.replace('%d', days);
      }
    } else if (archived) {
      archiveStatus.textContent = '<?php echo esc_js(__('アーカイブ済み', 'drwp-daily-reports')); ?>';
    }
  }

  if (archiveBtn) {
    archiveBtn.addEventListener('click', function () {
      var id = currentViewId;
      if (!id) return;
      if (!window.confirm('<?php echo esc_js(__('この日報をアーカイブします。一覧から非表示になりますが、復元は可能です。よろしいですか？', 'drwp-daily-reports')); ?>')) return;
      archiveBtn.disabled = true;
      api('/reports/' + id + '/archive', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({})
      }).then(function () {
        viewDlg.close();
        location.reload();
      }).catch(function (err) {
        renderApiError(archiveStatus, err);
        archiveBtn.disabled = false;
      });
    });
  }

  if (restoreBtn) {
    restoreBtn.addEventListener('click', function () {
      var id = currentViewId;
      if (!id) return;
      restoreBtn.disabled = true;
      api('/reports/' + id + '/restore', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({})
      }).then(function () {
        viewDlg.close();
        location.reload();
      }).catch(function (err) {
        renderApiError(archiveStatus, err);
        restoreBtn.disabled = false;
      });
    });
  }

  if (purgeBtn) {
    purgeBtn.addEventListener('click', function () {
      var id = currentViewId;
      if (!id) return;
      if (!window.confirm('<?php echo esc_js(__('この日報を完全に削除します。コメント・写真も含めて元に戻せません。本当によろしいですか？', 'drwp-daily-reports')); ?>')) return;
      if (!window.confirm('<?php echo esc_js(__('最終確認: この操作は取り消せません。続行しますか？', 'drwp-daily-reports')); ?>')) return;
      purgeBtn.disabled = true;
      api('/reports/' + id + '/purge', { method: 'DELETE' })
        .then(function () {
          viewDlg.close();
          location.reload();
        }).catch(function (err) {
          renderApiError(archiveStatus, err);
          purgeBtn.disabled = false;
        });
    });
  }

  /* ---- 編集モーダル ---- */
  var editDlg = document.getElementById('drwp-edit-dialog');

  /* ---- 写真リスト helpers ---- */
  var photosEl = document.getElementById('drwp-edit-photos');
  var photoInput = document.getElementById('drwp-edit-photo-input');
  var photoStatus = document.getElementById('drwp-edit-photo-status');

  function renderEditPhoto(id, url, caption, kind) {
    var div = document.createElement('div');
    div.className = 'drwp-edit-photo-item';
    // 種別セレクト: 通常 / Before / After。Before/After を選ぶと
    // 「ビフォーアフター」テンプレートで記事化したときに左右ペアの
    // 該当列に振り分けられる。
    var kindOpts =
      '<option value="normal"><?php echo esc_js(__('通常', 'drwp-daily-reports')); ?></option>' +
      '<option value="before">Before</option>' +
      '<option value="after">After</option>';
    div.innerHTML =
      '<img alt="" />' +
      '<input type="hidden" name="attachment_ids" />' +
      '<select name="attachment_kinds" class="drwp-edit-photo-kind">' + kindOpts + '</select>' +
      '<input type="text" name="attachment_captions" placeholder="<?php echo esc_js(__('キャプション', 'drwp-daily-reports')); ?>" />' +
      '<button type="button" class="drwp-edit-photo-remove" aria-label="<?php echo esc_js(__('削除', 'drwp-daily-reports')); ?>">×</button>';
    div.querySelector('img').src = url || '';
    div.querySelector('input[type=hidden]').value = String(id);
    div.querySelector('input[type=text]').value = caption || '';
    // 不正値はサーバ側でも 'normal' に倒すので、フロントでは緩めに受け取る
    var sel = div.querySelector('select.drwp-edit-photo-kind');
    var k = (kind || 'normal').toLowerCase();
    if (k !== 'before' && k !== 'after') k = 'normal';
    sel.value = k;
    applyKindBadge(div, sel);
    sel.addEventListener('change', function () { applyKindBadge(div, sel); });
    return div;
  }

  // 写真カードの枠色を Before / After / 通常で変えると、ぱっと見で
  // どれがどちらか分かる。Before = 青、After = 緑、通常 = 灰。
  function applyKindBadge(item, sel) {
    item.classList.remove('is-before', 'is-after');
    if (sel.value === 'before') item.classList.add('is-before');
    if (sel.value === 'after')  item.classList.add('is-after');
  }

  function clearEditPhotos() {
    while (photosEl.firstChild) photosEl.removeChild(photosEl.firstChild);
  }

  // 既存写真の click → 削除
  photosEl.addEventListener('click', function (e) {
    if (e.target.classList.contains('drwp-edit-photo-remove')) {
      var item = e.target.closest('.drwp-edit-photo-item');
      if (item) item.remove();
    }
  });

  // モーダルを開いたときに現在の最大アップロードサイズをヒント表示。
  // サーバ側で UPLOAD_ERR_INI_SIZE が出る前に「これ以上は無理」を知らせる。
  if (rest.maxUploadDisplay) {
    var photoHint = document.createElement('p');
    photoHint.className = 'description';
    photoHint.style.cssText = 'margin:6px 0 0;color:#64748b;font-size:.82em;';
    photoHint.textContent = '<?php echo esc_js(__('1 ファイルあたりの上限: ', 'drwp-daily-reports')); ?>' + rest.maxUploadDisplay;
    photoStatus.parentNode.insertBefore(photoHint, photoStatus);
  }

  // ファイル選択 → REST /upload-photo に逐次アップロードしてリストに追加
  photoInput.addEventListener('change', function () {
    var files = Array.from(this.files || []);
    if (!files.length) return;

    // クライアント側で先にサイズチェック。サーバの 1 (UPLOAD_ERR_INI_SIZE)
    // を「黙って待たされた末の失敗」じゃなく「即時に分かるエラー」にする。
    if (rest.maxUploadBytes) {
      var tooBig = files.filter(function (f) { return f.size > rest.maxUploadBytes; });
      if (tooBig.length) {
        var names = tooBig.map(function (f) { return f.name; }).join(', ');
        photoStatus.style.color = '#991b1b';
        photoStatus.textContent = '<?php echo esc_js(__('ファイルサイズが大きすぎます (上限 ', 'drwp-daily-reports')); ?>'
          + rest.maxUploadDisplay
          + '<?php echo esc_js(__('): ', 'drwp-daily-reports')); ?>'
          + names;
        this.value = '';
        return;
      }
    }
    photoStatus.style.color = '';
    var input = this;
    var i = 0;
    function next() {
      if (i >= files.length) {
        input.value = '';
        photoStatus.textContent = '';
        return;
      }
      var f = files[i++];
      photoStatus.textContent = '<?php echo esc_js(__('アップロード中…', 'drwp-daily-reports')); ?> (' + i + '/' + files.length + ')';
      var body = new FormData();
      body.append('file', f, f.name);
      fetch(rest.url + '/upload-photo', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-WP-Nonce': rest.nonce },
        body: body
      }).then(function (r) {
        return r.json().then(function (j) {
          if (!r.ok) throw new Error(j.message || 'HTTP ' + r.status);
          return j;
        });
      }).then(function (j) {
        photosEl.appendChild(renderEditPhoto(j.id, j.thumbnail_url || j.full_url || '', '', 'normal'));
        next();
      }).catch(function (err) {
        photoStatus.style.color = '#991b1b';
        photoStatus.textContent = err.message || '<?php echo esc_js(__('アップロード失敗', 'drwp-daily-reports')); ?>';
        input.value = '';
      });
    }
    next();
  });

  function openEditModal(id) {
    document.getElementById('drwp-edit-id').value = id;
    document.getElementById('drwp-edit-status').textContent = '';
    ['drwp-edit-date','drwp-edit-started','drwp-edit-ended'].forEach(function (k) { document.getElementById(k).value = ''; });
    ['drwp-edit-work','drwp-edit-issues','drwp-edit-next'].forEach(function (k) { document.getElementById(k).value = ''; });
    document.getElementById('drwp-edit-project').value = '';
    clearEditPhotos();
    photoStatus.textContent = '';
    editDlg.showModal();
    api('/reports/' + id).then(function (d) {
      document.getElementById('drwp-edit-date').value = d.report_date || '';
      document.getElementById('drwp-edit-project').value = d.project_id || '';
      document.getElementById('drwp-edit-started').value = (d.started_at || '').substring(0, 5);
      document.getElementById('drwp-edit-ended').value = (d.ended_at || '').substring(0, 5);
      document.getElementById('drwp-edit-work').value = d.work_description || '';
      document.getElementById('drwp-edit-issues').value = d.issues || '';
      document.getElementById('drwp-edit-next').value = d.next_plan || '';
      (d.photos || []).forEach(function (p) {
        photosEl.appendChild(renderEditPhoto(p.attachment_id, p.url, p.caption || '', p.kind || 'normal'));
      });
    }).catch(function (err) {
      renderApiError(document.getElementById('drwp-edit-status'), err);
    });
  }

  document.getElementById('drwp-edit-save').addEventListener('click', function () {
    var id = document.getElementById('drwp-edit-id').value;
    var st = document.getElementById('drwp-edit-status');
    st.textContent = '<?php echo esc_js(__('保存中…', 'drwp-daily-reports')); ?>';
    this.disabled = true;
    var self = this;
    // 写真リストを attachment_ids / attachment_captions / attachment_kinds
    // の並列配列に。並びは photosEl の DOM 順をそのまま使う(既存 +
    // 新規追加分)。
    var ids = Array.from(photosEl.querySelectorAll('input[name="attachment_ids"]')).map(function (i) { return Number(i.value); });
    var caps = Array.from(photosEl.querySelectorAll('input[name="attachment_captions"]')).map(function (i) { return i.value; });
    var kinds = Array.from(photosEl.querySelectorAll('select[name="attachment_kinds"]')).map(function (s) { return s.value; });
    api('/reports/' + id, {
      method: 'PATCH',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        report_date:         document.getElementById('drwp-edit-date').value,
        project_id:          Number(document.getElementById('drwp-edit-project').value) || null,
        started_at:          document.getElementById('drwp-edit-started').value || null,
        ended_at:            document.getElementById('drwp-edit-ended').value || null,
        work_description:    document.getElementById('drwp-edit-work').value,
        issues:              document.getElementById('drwp-edit-issues').value,
        next_plan:           document.getElementById('drwp-edit-next').value,
        attachment_ids:      ids,
        attachment_captions: caps,
        attachment_kinds:    kinds
      })
    }).then(function () { editDlg.close(); location.reload(); })
      .catch(function (err) { renderApiError(st, err); self.disabled = false; });
  });

  /* ---- テーブルクリック委譲 ---- */
  var table = document.getElementById('drwp-reports-table');
  if (table) {
    table.addEventListener('click', function (e) {
      var viewBtn = e.target.closest('.drwp-view-btn');
      if (viewBtn) { openViewModal(parseInt(viewBtn.dataset.id, 10)); return; }
      var editBtn = e.target.closest('.drwp-edit-btn');
      if (editBtn) { openEditModal(parseInt(editBtn.dataset.id, 10)); return; }
    });
  }

  /* ---- AI 対応アラート ---- */
  var alertsBtn = document.getElementById('drwp-ai-alerts-btn');
  var alertsDlg = document.getElementById('drwp-ai-alerts-dialog');
  if (alertsBtn && alertsDlg) {
    alertsBtn.addEventListener('click', function () { alertsDlg.showModal(); });
    document.getElementById('drwp-ai-alerts-run').addEventListener('click', function () {
      var st = document.getElementById('drwp-ai-alerts-status');
      var out = document.getElementById('drwp-ai-alerts-output');
      var from = document.getElementById('drwp-ai-alerts-from').value;
      var to = document.getElementById('drwp-ai-alerts-to').value;
      st.style.color = '#64748b';
      st.textContent = '<?php echo esc_js(__('抽出中… 数秒〜数分かかる場合があります', 'drwp-daily-reports')); ?>';
      out.textContent = '';
      this.disabled = true;
      var self = this;
      fetch(rest.url + '/ai/alerts', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': rest.nonce },
        body: JSON.stringify({ date_from: from, date_to: to })
      }).then(function (r) {
        return r.json().then(function (j) { if (!r.ok) throw new Error(j.message || 'HTTP ' + r.status); return j; });
      }).then(function (d) {
        st.textContent = (d.date_from && d.date_to) ? ('対象: ' + d.date_from + ' 〜 ' + d.date_to) : '';
        out.textContent = d.response || '（応答なし）';
        self.disabled = false;
      }).catch(function (err) {
        st.style.color = '#991b1b';
        st.textContent = 'エラー: ' + err.message;
        self.disabled = false;
      });
    });
  }

  /* ---- AI 振り返りアドバイス ---- */
  var adviseBtn = document.getElementById('drwp-ai-advise-btn');
  var adviseDlg = document.getElementById('drwp-ai-advise-dialog');
  // フィルタ条件にマッチした日報 ID 配列(コントローラーが先頭から
  // ADVISE_MAX 件まで詰めて渡してくれる)。これを丸ごと POST する。
  var adviseIds = <?php echo wp_json_encode(array_values($filtered_ids ?? [])); ?>;
  if (adviseBtn && adviseDlg) {
    adviseBtn.addEventListener('click', function () { adviseDlg.showModal(); });
    var adviseRun = document.getElementById('drwp-ai-advise-run');
    if (adviseRun) {
      adviseRun.addEventListener('click', function () {
        var st = document.getElementById('drwp-ai-advise-status');
        var out = document.getElementById('drwp-ai-advise-output');
        st.style.color = '#64748b';
        st.textContent = '<?php echo esc_js(__('生成中… 数秒〜数分かかる場合があります', 'drwp-daily-reports')); ?>';
        out.textContent = '';
        this.disabled = true;
        var self = this;
        fetch(rest.url + '/ai/advise', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': rest.nonce },
          body: JSON.stringify({ report_ids: adviseIds })
        }).then(function (r) {
          return r.json().then(function (j) { if (!r.ok) throw new Error(j.message || 'HTTP ' + r.status); return j; });
        }).then(function (d) {
          st.textContent = d.count ? ('読み込み: ' + d.count + ' 件') : '';
          out.textContent = d.response || '（応答なし）';
          self.disabled = false;
        }).catch(function (err) {
          st.style.color = '#991b1b';
          st.textContent = 'エラー: ' + err.message;
          self.disabled = false;
        });
      });
    }
  }

  /* ---- モーダル共通: × ボタン / 背景クリック ---- */
  [viewDlg, editDlg, alertsDlg, adviseDlg].forEach(function (dlg) {
    if (!dlg) return;
    dlg.addEventListener('click', function (e) {
      if (e.target.classList.contains('drwp-modal-close')) dlg.close();
      if (e.target === dlg) dlg.close();
    });
  });
})();
</script>
