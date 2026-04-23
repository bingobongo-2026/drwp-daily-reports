<?php
if (!defined('ABSPATH')) exit;

$action_filter = sanitize_key($_GET['log_action'] ?? '');
$report_id = intval($_GET['report_id'] ?? 0);
$user_id = intval($_GET['user_id'] ?? 0);
$search = sanitize_text_field($_GET['s'] ?? '');

$per_page = 50;
$current_page = max(1, intval($_GET['paged'] ?? 1));
$total_items = DRWP_Audit::count_logs([
    'action' => $action_filter,
    'report_id' => $report_id,
    'user_id' => $user_id,
    'search' => $search,
]);
$total_pages = max(1, (int) ceil($total_items / $per_page));

$logs = DRWP_Audit::get_logs([
    'action' => $action_filter,
    'report_id' => $report_id,
    'user_id' => $user_id,
    'search' => $search,
    'limit' => $per_page,
    'offset' => ($current_page - 1) * $per_page,
]);

$actions = [
    'report_created' => '日報作成',
    'report_updated' => '日報更新',
    'review_status_changed' => 'レビュー状態変更',
    'comment_added' => 'コメント追加',
    'post_created_from_report' => '記事下書き生成',
    'post_resynced' => '既存記事へ再反映',
    'project_created' => '現場作成',
    'project_updated' => '現場更新',
    'license_settings_saved' => 'ライセンス設定更新',
    'license_public_key_fetched' => '公開鍵取得',
];
?>
<div class="wrap">
  <h1 class="wp-heading-inline">操作履歴</h1>
  <hr class="wp-header-end">

  <form method="get" style="margin:12px 0;">
    <input type="hidden" name="page" value="drwp-audit">
    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="メッセージ・ユーザーで検索" style="min-width:220px;">
    <input type="number" name="report_id" value="<?php echo intval($report_id); ?>" placeholder="日報ID">
    <input type="number" name="user_id" value="<?php echo intval($user_id); ?>" placeholder="ユーザーID">
    <select name="log_action">
      <option value="">操作すべて</option>
      <?php foreach ($actions as $key => $label): ?>
        <option value="<?php echo esc_attr($key); ?>" <?php selected($action_filter, $key); ?>><?php echo esc_html($label); ?></option>
      <?php endforeach; ?>
    </select>
    <button class="button">絞り込み</button>
    <a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg([
        'action' => 'drwp_export_audit_csv',
        'log_action' => $action_filter,
        'report_id' => $report_id,
        'user_id' => $user_id,
        's' => $search,
    ], admin_url('admin-post.php')), 'drwp_export_audit_csv')); ?>">CSV出力</a>
  </form>

  <p style="margin:8px 0 12px;">合計 <?php echo intval($total_items); ?> 件</p>

  <table class="widefat striped">
    <thead>
      <tr>
        <th>ID</th>
        <th>日時</th>
        <th>操作</th>
        <th>ユーザー</th>
        <th>日報ID</th>
        <th>内容</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!empty($logs)): ?>
        <?php foreach ($logs as $log): ?>
          <tr>
            <td><?php echo intval($log['id']); ?></td>
            <td><?php echo esc_html($log['created_at']); ?></td>
            <td><?php echo esc_html($actions[$log['action']] ?? $log['action']); ?></td>
            <td><?php echo esc_html($log['display_name'] ?: 'system'); ?></td>
            <td>
              <?php if (!empty($log['report_id'])): ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=drwp-reports&action=edit&id=' . intval($log['report_id']))); ?>">
                  #<?php echo intval($log['report_id']); ?>
                </a>
              <?php else: ?>
                -
              <?php endif; ?>
            </td>
            <td>
              <?php echo esc_html($log['message']); ?>
              <?php if (!empty($log['meta_json'])): ?>
                <details style="margin-top:6px;">
                  <summary>詳細</summary>
                  <pre style="white-space:pre-wrap;"><?php echo esc_html($log['meta_json']); ?></pre>
                </details>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="6">操作履歴はまだありません。</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <?php
  $base_url = add_query_arg([
      'page' => 'drwp-audit',
      'log_action' => $action_filter,
      'report_id' => $report_id,
      'user_id' => $user_id,
      's' => $search,
  ], admin_url('admin.php'));
  $page_links = paginate_links([
      'base' => add_query_arg('paged', '%#%', $base_url),
      'format' => '',
      'current' => max(1, $current_page),
      'total' => max(1, $total_pages),
      'type' => 'array',
  ]);
  if (!empty($page_links)): ?>
    <div class="tablenav" style="margin-top:12px;">
      <div class="tablenav-pages">
        <?php foreach ($page_links as $link) echo $link . ' '; ?>
      </div>
    </div>
  <?php endif; ?>
</div>
