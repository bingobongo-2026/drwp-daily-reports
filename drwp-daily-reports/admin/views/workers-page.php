<?php if (!defined('ABSPATH')) exit;
// 社員 admin page — list every `edit_posts` user with a 退職 /
// 復帰 toggle. The retired flag blocks all DRWP write paths but
// keeps the user's past reports + attribution intact.
$visible = [];
foreach ($workers as $w) {
    if ($filter === 'active'  && $w->retired) continue;
    if ($filter === 'retired' && !$w->retired) continue;
    $visible[] = $w;
}
?>
<div class="wrap">
  <h1><?php esc_html_e('社員', 'drwp-daily-reports'); ?></h1>

  <?php if (!empty($_GET['saved'])): ?>
    <div class="notice notice-success"><p><?php esc_html_e('社員の状態を更新しました。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>
  <?php if (!empty($_GET['err']) && $_GET['err'] === 'invalid'): ?>
    <div class="notice notice-error"><p><?php esc_html_e('対象のユーザーは作業員ではありません。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>

  <p class="description">
    <?php esc_html_e('退職にすると、その社員はフロント・wp-admin から日報・予定・コメント・写真の書き込みができなくなります。過去の日報データはそのまま残ります。', 'drwp-daily-reports'); ?>
  </p>

  <ul class="subsubsub" style="margin-top:8px;">
    <li><a href="<?php echo esc_url(admin_url('admin.php?page=drwp_workers&view=active')); ?>"
           class="<?php echo $filter === 'active' ? 'current' : ''; ?>"><?php esc_html_e('在籍', 'drwp-daily-reports'); ?></a> |</li>
    <li><a href="<?php echo esc_url(admin_url('admin.php?page=drwp_workers&view=retired')); ?>"
           class="<?php echo $filter === 'retired' ? 'current' : ''; ?>"><?php esc_html_e('退職', 'drwp-daily-reports'); ?></a> |</li>
    <li><a href="<?php echo esc_url(admin_url('admin.php?page=drwp_workers&view=all')); ?>"
           class="<?php echo $filter === 'all' ? 'current' : ''; ?>"><?php esc_html_e('すべて', 'drwp-daily-reports'); ?></a></li>
  </ul>

  <table class="widefat striped" style="clear:both;margin-top:8px;">
    <thead>
      <tr>
        <th><?php esc_html_e('表示名', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('メール', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('ロール', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('最終日報', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('日報数', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('状態', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('操作', 'drwp-daily-reports'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($visible)): ?>
        <tr><td colspan="7"><?php esc_html_e('該当する社員が見つかりません。', 'drwp-daily-reports'); ?></td></tr>
      <?php else: foreach ($visible as $w):
        $roles = implode(', ', array_map('esc_html', $w->roles ?: []));
      ?>
        <tr class="drwp-worker-row <?php echo $w->retired ? 'is-retired' : ''; ?>">
          <td><strong><?php echo esc_html($w->name); ?></strong></td>
          <td><?php echo esc_html($w->email); ?></td>
          <td><?php echo $roles ?: '-'; ?></td>
          <td><?php echo $w->last_date ? esc_html(date_i18n('Y/n/j', strtotime($w->last_date))) : '-'; ?></td>
          <td><?php echo (int) $w->report_cnt; ?></td>
          <td>
            <?php if ($w->retired): ?>
              <span class="drwp-worker-badge is-retired"><?php esc_html_e('退職', 'drwp-daily-reports'); ?></span>
            <?php else: ?>
              <span class="drwp-worker-badge is-active"><?php esc_html_e('在籍', 'drwp-daily-reports'); ?></span>
            <?php endif; ?>
          </td>
          <td>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
              <?php wp_nonce_field('drwp_set_retired'); ?>
              <input type="hidden" name="action" value="drwp_set_retired" />
              <input type="hidden" name="user_id" value="<?php echo (int) $w->id; ?>" />
              <?php if ($w->retired): ?>
                <input type="hidden" name="retired" value="" />
                <button type="submit" class="button button-small"
                        onclick="return confirm('<?php echo esc_js(sprintf(__('%s さんを在籍に戻します。よろしいですか？', 'drwp-daily-reports'), $w->name)); ?>');">
                  <?php esc_html_e('復帰させる', 'drwp-daily-reports'); ?>
                </button>
              <?php else: ?>
                <input type="hidden" name="retired" value="1" />
                <button type="submit" class="button button-small button-link-delete"
                        onclick="return confirm('<?php echo esc_js(sprintf(__('%s さんを退職にします。以降この社員は日報を書けなくなります(過去の日報は残ります)。よろしいですか？', 'drwp-daily-reports'), $w->name)); ?>');">
                  <?php esc_html_e('退職にする', 'drwp-daily-reports'); ?>
                </button>
              <?php endif; ?>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<style>
.drwp-worker-row.is-retired td { color: #6b7280; }
.drwp-worker-badge { display:inline-block; padding:1px 8px; border-radius:10px; font-size:11px; font-weight:600; }
.drwp-worker-badge.is-active  { background:#dcfce7; color:#166534; }
.drwp-worker-badge.is-retired { background:#f3f4f6; color:#6b7280; }
</style>
