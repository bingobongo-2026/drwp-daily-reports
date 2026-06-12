<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1 class="wp-heading-inline"><?php esc_html_e('操作履歴', 'drwp-daily-reports'); ?></h1>
  <hr class="wp-header-end">

  <?php if (current_user_can('manage_options')):
    $retention = DRWP_Audit::retention_days();
    $grand_total = DRWP_Audit::count();
    $oldest = DRWP_Audit::oldest_at();
    $next_run = wp_next_scheduled(DRWP_Audit::CRON_HOOK);
  ?>
  <?php if (!empty($_GET['retention_saved'])): ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('保存期間を更新しました。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>
  <?php if (isset($_GET['purged'])): ?>
    <div class="notice notice-success is-dismissible">
      <p><?php
        $n = max(0, (int) $_GET['purged']);
        if ($n === 0) {
            esc_html_e('削除対象の古い履歴はありませんでした。', 'drwp-daily-reports');
        } else {
            /* translators: %d: deleted row count */
            printf(esc_html(_n('%d 件の古い履歴を削除しました。', '%d 件の古い履歴を削除しました。', $n, 'drwp-daily-reports')), $n);
        }
      ?></p>
    </div>
  <?php endif; ?>
  <details class="drwp-audit-retention" style="margin:12px 0;border:1px solid #c3c4c7;border-radius:8px;padding:0 14px;">
    <summary style="cursor:pointer;padding:10px 0;font-weight:600;color:#1d2327;">
      ⚙️ <?php esc_html_e('保存期間と自動削除', 'drwp-daily-reports'); ?>
      <span style="color:#646970;font-weight:400;font-size:.92em;">
        ／
        <?php
          /* translators: %d: total stored rows */
          printf(esc_html__('現在 %s 件', 'drwp-daily-reports'), number_format_i18n((int) $grand_total));
        ?>
        ／
        <?php
          if ($retention === 0) {
              esc_html_e('永久保存', 'drwp-daily-reports');
          } else {
              /* translators: %d: retention day count */
              printf(esc_html__('%d 日で自動削除', 'drwp-daily-reports'), $retention);
          }
        ?>
      </span>
    </summary>
    <div style="padding:6px 0 14px;display:flex;flex-direction:column;gap:10px;">
      <p class="description" style="margin:0;">
        <?php
          if ($oldest !== '') {
              printf(
                  esc_html__('最古の記録: %s', 'drwp-daily-reports'),
                  esc_html(wp_date('Y-m-d H:i', strtotime($oldest)))
              );
              echo ' ／ ';
          }
          if ($next_run) {
              printf(
                  esc_html__('次回の自動削除: %s', 'drwp-daily-reports'),
                  esc_html(wp_date('Y-m-d H:i', (int) $next_run))
              );
          }
        ?>
      </p>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <?php wp_nonce_field('drwp_save_audit_retention'); ?>
        <input type="hidden" name="action" value="drwp_save_audit_retention" />
        <label for="drwp-audit-retention-days"><?php esc_html_e('保存期間', 'drwp-daily-reports'); ?></label>
        <select id="drwp-audit-retention-days" name="retention_days">
          <?php
            $presets = [
                30   => __('30 日', 'drwp-daily-reports'),
                90   => __('90 日', 'drwp-daily-reports'),
                180  => __('180 日', 'drwp-daily-reports'),
                365  => __('365 日（推奨）', 'drwp-daily-reports'),
                730  => __('2 年（730 日）', 'drwp-daily-reports'),
                1095 => __('3 年（1095 日）', 'drwp-daily-reports'),
                0    => __('永久保存（自動削除しない）', 'drwp-daily-reports'),
            ];
            foreach ($presets as $val => $label): ?>
            <option value="<?php echo (int) $val; ?>" <?php selected($retention, $val); ?>><?php echo esc_html($label); ?></option>
          <?php endforeach; ?>
        </select>
        <button class="button button-primary"><?php esc_html_e('保存', 'drwp-daily-reports'); ?></button>
        <span class="description"><?php esc_html_e('1 日 1 回、設定期間より古い履歴を自動的に削除します。', 'drwp-daily-reports'); ?></span>
      </form>
      <?php if ($retention > 0 && $grand_total > 0): ?>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:8px;align-items:center;">
        <?php wp_nonce_field('drwp_purge_audit_now'); ?>
        <input type="hidden" name="action" value="drwp_purge_audit_now" />
        <button type="submit" class="button button-link-delete"
                onclick="return confirm('<?php echo esc_js(__('現在の保存期間より古い履歴を今すぐ削除します。よろしいですか？', 'drwp-daily-reports')); ?>');">
          <?php esc_html_e('今すぐ古い履歴を削除', 'drwp-daily-reports'); ?>
        </button>
      </form>
      <?php endif; ?>
    </div>
  </details>
  <?php endif; ?>

  <form method="get" style="margin:12px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <input type="hidden" name="page" value="drwp_audit" />
    <input type="search" name="s" value="<?php echo esc_attr($filters['search']); ?>" placeholder="<?php esc_attr_e('メッセージ / meta / ユーザー名', 'drwp-daily-reports'); ?>" style="min-width:240px;" />
    <select name="event">
      <option value=""><?php esc_html_e('操作すべて', 'drwp-daily-reports'); ?></option>
      <?php foreach ($events as $key => $label): ?>
        <option value="<?php echo esc_attr($key); ?>" <?php selected($filters['event'], $key); ?>><?php echo esc_html($label); ?></option>
      <?php endforeach; ?>
    </select>
    <input type="number" name="report_id" value="<?php echo $filters['report_id'] ? (int) $filters['report_id'] : ''; ?>" placeholder="<?php esc_attr_e('日報ID', 'drwp-daily-reports'); ?>" style="width:90px;" min="0" />
    <input type="number" name="user_id" value="<?php echo $filters['user_id'] ? (int) $filters['user_id'] : ''; ?>" placeholder="<?php esc_attr_e('ユーザーID', 'drwp-daily-reports'); ?>" style="width:100px;" min="0" />
    <input type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>" />
    <input type="date" name="date_to" value="<?php echo esc_attr($filters['date_to']); ?>" />
    <button class="button"><?php esc_html_e('絞り込み', 'drwp-daily-reports'); ?></button>
    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=drwp_audit')); ?>"><?php esc_html_e('クリア', 'drwp-daily-reports'); ?></a>
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
       ?>"><?php esc_html_e('CSV出力', 'drwp-daily-reports'); ?></a>
  </form>

  <p class="description" style="margin:8px 0 12px;">
    <?php
      printf(
          /* translators: %d: total log entry count */
          esc_html(_n('合計 %d 件', '合計 %d 件', (int) $total, 'drwp-daily-reports')),
          (int) $total
      );
    ?>
  </p>

  <table class="widefat striped">
    <thead>
      <tr>
        <th style="width:56px;">ID</th>
        <th style="width:160px;"><?php esc_html_e('日時', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('操作', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('ユーザー', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('日報', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('メッセージ', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('詳細', 'drwp-daily-reports'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($logs)): ?>
        <tr><td colspan="7"><?php esc_html_e('該当する履歴がありません。', 'drwp-daily-reports'); ?></td></tr>
      <?php else: foreach ($logs as $log): ?>
        <tr>
          <td><?php echo (int) $log['id']; ?></td>
          <td><?php echo esc_html($log['created_at']); ?></td>
          <td><code><?php echo esc_html($log['event']); ?></code> <?php echo esc_html($events[$log['event']] ?? ''); ?></td>
          <td><?php echo esc_html(DRWP_User::display_name((int) $log['user_id']) ?: ('#' . (int) $log['user_id'])); ?></td>
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
