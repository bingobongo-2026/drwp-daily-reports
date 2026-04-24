<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1 class="wp-heading-inline">操作履歴</h1>
  <hr class="wp-header-end">

  <form method="get" style="margin:12px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <input type="hidden" name="page" value="drwp_audit" />
    <input type="search" name="s" value="<?php echo esc_attr($filters['search']); ?>" placeholder="メッセージ / meta / ユーザー名" style="min-width:240px;" />
    <select name="event">
      <option value="">操作すべて</option>
      <?php foreach ($events as $key => $label): ?>
        <option value="<?php echo esc_attr($key); ?>" <?php selected($filters['event'], $key); ?>><?php echo esc_html($label); ?></option>
      <?php endforeach; ?>
    </select>
    <input type="number" name="report_id" value="<?php echo $filters['report_id'] ? (int) $filters['report_id'] : ''; ?>" placeholder="日報ID" style="width:90px;" min="0" />
    <input type="number" name="user_id" value="<?php echo $filters['user_id'] ? (int) $filters['user_id'] : ''; ?>" placeholder="ユーザーID" style="width:100px;" min="0" />
    <input type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>" />
    <input type="date" name="date_to" value="<?php echo esc_attr($filters['date_to']); ?>" />
    <button class="button">絞り込み</button>
    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=drwp_audit')); ?>">クリア</a>
    <a class="button"
       href="<?php
         echo esc_url(wp_nonce_url(
             add_query_arg(
                 array_merge(
                     ['action' => 'drwp_export_audit_csv'],
                     array_filter($filters, function ($v) { return $v !== '' && $v !== 0; })
                 ),
                 admin_url('admin-post.php')
             ),
             'drwp_export_audit_csv'
         ));
       ?>">CSV出力</a>
  </form>

  <p class="description" style="margin:8px 0 12px;">合計 <?php echo (int) $total; ?> 件</p>

  <table class="widefat striped">
    <thead>
      <tr>
        <th style="width:56px;">ID</th>
        <th style="width:160px;">日時</th>
        <th>操作</th>
        <th>ユーザー</th>
        <th>日報</th>
        <th>メッセージ</th>
        <th>詳細</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($logs)): ?>
        <tr><td colspan="7">該当する履歴がありません。</td></tr>
      <?php else: foreach ($logs as $log): ?>
        <tr>
          <td><?php echo (int) $log['id']; ?></td>
          <td><?php echo esc_html($log['created_at']); ?></td>
          <td><code><?php echo esc_html($log['event']); ?></code> <?php echo esc_html($events[$log['event']] ?? ''); ?></td>
          <td><?php echo esc_html($log['display_name'] ?: '#' . (int) $log['user_id']); ?></td>
          <td>
            <?php if (!empty($log['report_id'])): ?>
              <a href="<?php echo esc_url(admin_url('admin.php?page=drwp_report_edit&id=' . (int) $log['report_id'])); ?>">#<?php echo (int) $log['report_id']; ?></a>
            <?php else: ?>-<?php endif; ?>
          </td>
          <td><?php echo esc_html($log['message']); ?></td>
          <td>
            <?php if (!empty($log['meta_json'])): ?>
              <details><summary>meta</summary><pre style="white-space:pre-wrap;margin:4px 0;"><?php echo esc_html($log['meta_json']); ?></pre></details>
            <?php else: ?>-<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <?php
  if ($pages > 1):
      $base = add_query_arg(
          array_merge(
              ['page' => 'drwp_audit'],
              array_filter($filters, function ($v) { return $v !== '' && $v !== 0; })
          ),
          admin_url('admin.php')
      );
      $page_links = paginate_links([
          'base'      => add_query_arg('paged', '%#%', $base),
          'format'    => '',
          'current'   => $paged,
          'total'     => $pages,
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
