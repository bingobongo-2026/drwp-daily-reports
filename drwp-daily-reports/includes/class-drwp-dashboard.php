<?php
if (!defined('ABSPATH')) exit;

class DRWP_Dashboard {
    public static function init() {
        add_action('wp_dashboard_setup', [__CLASS__, 'register_widget']);
    }

    public static function register_widget() {
        if (!current_user_can('edit_posts')) return;
        wp_add_dashboard_widget(
            'drwp_dashboard_widget',
            __('日報管理', 'drwp-daily-reports'),
            [__CLASS__, 'render_widget']
        );
    }

    public static function render_widget() {
        global $wpdb;
        $table = $wpdb->prefix . 'drwp_reports';

        $is_reviewer = current_user_can('edit_others_posts');
        $scope_sql = $is_reviewer ? '' : ' AND user_id = ' . (int) get_current_user_id();

        $by_status = $wpdb->get_results(
            "SELECT review_status, COUNT(*) AS c
             FROM $table
             WHERE 1=1 $scope_sql
             GROUP BY review_status"
        );
        $counts = ['pending' => 0, 'needs_revision' => 0, 'approved' => 0];
        foreach ($by_status as $row) {
            $counts[(string) $row->review_status] = (int) $row->c;
        }

        $today_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE report_date = %s $scope_sql",
            current_time('Y-m-d')
        ));

        $recent = $wpdb->get_results(
            "SELECT id, report_date, public_title, review_status
             FROM $table
             WHERE 1=1 $scope_sql
             ORDER BY id DESC
             LIMIT 5"
        );

        $list_url = admin_url('admin.php?page=drwp_reports');
        ?>
        <div class="drwp-dashboard">
          <ul style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;list-style:none;margin:0 0 12px;padding:0;">
            <li style="background:#f0f0f1;padding:10px;border-radius:6px;">
              <div style="color:#50575e;font-size:.85em;"><?php esc_html_e('本日の日報', 'drwp-daily-reports'); ?></div>
              <strong style="font-size:1.4em;"><?php echo (int) $today_count; ?></strong>
            </li>
            <li style="background:#fef3c7;padding:10px;border-radius:6px;">
              <div style="color:#78350f;font-size:.85em;"><?php esc_html_e('レビュー待ち', 'drwp-daily-reports'); ?></div>
              <a href="<?php echo esc_url(add_query_arg('review_status', 'pending', $list_url)); ?>" style="text-decoration:none;color:inherit;">
                <strong style="font-size:1.4em;"><?php echo (int) $counts['pending']; ?></strong>
              </a>
            </li>
            <li style="background:#fee2e2;padding:10px;border-radius:6px;">
              <div style="color:#991b1b;font-size:.85em;"><?php esc_html_e('差し戻し', 'drwp-daily-reports'); ?></div>
              <a href="<?php echo esc_url(add_query_arg('review_status', 'needs_revision', $list_url)); ?>" style="text-decoration:none;color:inherit;">
                <strong style="font-size:1.4em;"><?php echo (int) $counts['needs_revision']; ?></strong>
              </a>
            </li>
            <li style="background:#dcfce7;padding:10px;border-radius:6px;">
              <div style="color:#166534;font-size:.85em;"><?php esc_html_e('承認済み', 'drwp-daily-reports'); ?></div>
              <a href="<?php echo esc_url(add_query_arg('review_status', 'approved', $list_url)); ?>" style="text-decoration:none;color:inherit;">
                <strong style="font-size:1.4em;"><?php echo (int) $counts['approved']; ?></strong>
              </a>
            </li>
          </ul>

          <h3 style="margin:12px 0 6px;font-size:.95em;"><?php esc_html_e('最近の日報', 'drwp-daily-reports'); ?></h3>
          <?php if (empty($recent)): ?>
            <p style="color:#50575e;"><?php esc_html_e('まだ日報がありません。', 'drwp-daily-reports'); ?></p>
          <?php else: ?>
            <ul style="margin:0;padding:0;list-style:none;">
              <?php foreach ($recent as $r): ?>
                <li style="padding:6px 0;border-bottom:1px solid #f0f0f1;">
                  <a href="<?php echo esc_url(admin_url('admin.php?page=drwp_report_edit&id=' . (int) $r->id)); ?>">
                    <?php echo esc_html($r->report_date); ?> — <?php echo esc_html($r->public_title ?: __('（未設定）', 'drwp-daily-reports')); ?>
                  </a>
                  <span style="float:right;color:#50575e;font-size:.85em;"><?php echo esc_html(DRWP_Labels::review_status((string) $r->review_status)); ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <p style="margin-top:12px;">
            <a class="button button-primary button-small" href="<?php echo esc_url(admin_url('admin.php?page=drwp_report_edit')); ?>"><?php esc_html_e('日報を作成', 'drwp-daily-reports'); ?></a>
            <a class="button button-small" href="<?php echo esc_url($list_url); ?>"><?php esc_html_e('一覧を開く', 'drwp-daily-reports'); ?></a>
          </p>
        </div>
        <?php
    }
}
