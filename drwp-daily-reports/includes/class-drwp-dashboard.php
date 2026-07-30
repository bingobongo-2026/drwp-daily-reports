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
            __('日報マン', 'drwp-daily-reports'),
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
        $counts = ['pending' => 0, 'needs_revision' => 0, 'approved' => 0, 'edit_requested' => 0];
        foreach ($by_status as $row) {
            $counts[(string) $row->review_status] = (int) $row->c;
        }

        $today_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE report_date = %s $scope_sql",
            current_time('Y-m-d')
        ));

        $recent = $wpdb->get_results(
            "SELECT id, report_date, project_id, public_title, review_status
             FROM $table
             WHERE 1=1 $scope_sql
             ORDER BY id DESC
             LIMIT 5"
        );

        $list_url = admin_url('admin.php?page=drwp_reports');

        // 集計タイル: 値は従来のインライン指定と同一。status ごとの配色は
        // 修飾クラス is-<status> で表す。
        $tiles = [
            ['key' => 'today',          'label' => __('本日の日報', 'drwp-daily-reports'),   'count' => $today_count, 'link' => ''],
            ['key' => 'pending',        'label' => __('日報承認待ち', 'drwp-daily-reports'), 'count' => $counts['pending'],        'link' => add_query_arg('review_status', 'pending', $list_url)],
            ['key' => 'needs_revision', 'label' => __('差し戻し', 'drwp-daily-reports'),     'count' => $counts['needs_revision'], 'link' => add_query_arg('review_status', 'needs_revision', $list_url)],
            ['key' => 'approved',       'label' => __('承認済み', 'drwp-daily-reports'),     'count' => $counts['approved'],       'link' => add_query_arg('review_status', 'approved', $list_url)],
            ['key' => 'edit_requested', 'label' => __('編集依頼中', 'drwp-daily-reports'),   'count' => $counts['edit_requested'], 'link' => add_query_arg('review_status', 'edit_requested', $list_url)],
        ];
        ?>
        <style>
        /* WP ダッシュボードには admin.css を配信していないため、
           ウィジェット専用のスタイルはここに1箇所へ集約する。 */
        .drwp-dashboard-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;list-style:none;margin:0 0 12px;padding:0}
        .drwp-dashboard-tile{padding:10px;border-radius:6px}
        .drwp-dashboard-tile a{text-decoration:none;color:inherit}
        .drwp-dashboard-tile-label{font-size:.85em}
        .drwp-dashboard-tile-count{font-size:1.4em}
        .drwp-dashboard-tile.is-today{background:#f0f0f1}.drwp-dashboard-tile.is-today .drwp-dashboard-tile-label{color:#50575e}
        .drwp-dashboard-tile.is-pending{background:#fef3c7}.drwp-dashboard-tile.is-pending .drwp-dashboard-tile-label{color:#78350f}
        .drwp-dashboard-tile.is-needs_revision{background:#fee2e2}.drwp-dashboard-tile.is-needs_revision .drwp-dashboard-tile-label{color:#991b1b}
        .drwp-dashboard-tile.is-approved{background:#dcfce7}.drwp-dashboard-tile.is-approved .drwp-dashboard-tile-label{color:#166534}
        .drwp-dashboard-tile.is-edit_requested{background:#dbeafe}.drwp-dashboard-tile.is-edit_requested .drwp-dashboard-tile-label{color:#1e40af}
        .drwp-dashboard h3{margin:12px 0 6px;font-size:.95em}
        .drwp-dashboard-empty{color:#50575e}
        .drwp-dashboard-recent{margin:0;padding:0;list-style:none}
        .drwp-dashboard-recent li{padding:6px 0;border-bottom:1px solid #f0f0f1}
        .drwp-dashboard-recent a{text-decoration:none;color:inherit}
        .drwp-dashboard-recent .sep{color:#50575e;margin:0 4px}
        .drwp-dashboard-recent .sep-light{color:#94a3b8;margin:0 4px}
        .drwp-dashboard-recent .title{color:#475569}
        .drwp-dashboard-recent .status{float:right;color:#50575e;font-size:.85em}
        .drwp-dashboard-actions{margin-top:12px}
        </style>
        <div class="drwp-dashboard">
          <ul class="drwp-dashboard-tiles">
            <?php foreach ($tiles as $tile): ?>
              <li class="drwp-dashboard-tile is-<?php echo esc_attr($tile['key']); ?>">
                <div class="drwp-dashboard-tile-label"><?php echo esc_html($tile['label']); ?></div>
                <?php if ($tile['link']): ?>
                  <a href="<?php echo esc_url($tile['link']); ?>"><strong class="drwp-dashboard-tile-count"><?php echo (int) $tile['count']; ?></strong></a>
                <?php else: ?>
                  <strong class="drwp-dashboard-tile-count"><?php echo (int) $tile['count']; ?></strong>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>

          <h3><?php esc_html_e('最近の日報', 'drwp-daily-reports'); ?></h3>
          <?php if (empty($recent)): ?>
            <p class="drwp-dashboard-empty"><?php esc_html_e('まだ日報がありません。', 'drwp-daily-reports'); ?></p>
          <?php else: ?>
            <ul class="drwp-dashboard-recent">
              <?php foreach ($recent as $r):
                  $proj = $r->project_id ? DRWP_Project::find((int) $r->project_id) : null;
                  $proj_name = $proj ? $proj->name : __('（未設定）', 'drwp-daily-reports');
                  $title = $r->public_title ?: '-';
                  $date_label = date_i18n('Y年n月j日', strtotime((string) $r->report_date));
              ?>
                <li>
                  <a href="<?php echo esc_url(admin_url('admin.php?page=drwp_reports&view=' . (int) $r->id)); ?>">
                    <strong><?php echo esc_html($date_label); ?></strong>
                    <span class="sep">—</span>
                    <span><?php echo esc_html($proj_name); ?></span>
                    <span class="sep-light">|</span>
                    <span class="title"><?php echo esc_html($title); ?></span>
                  </a>
                  <span class="status"><?php echo esc_html(DRWP_Labels::review_status((string) $r->review_status)); ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <p class="drwp-dashboard-actions">
            <a class="button button-primary button-small" href="<?php echo esc_url(admin_url('admin.php?page=drwp_reports&new=1')); ?>"><?php esc_html_e('日報を作成', 'drwp-daily-reports'); ?></a>
            <a class="button button-small" href="<?php echo esc_url($list_url); ?>"><?php esc_html_e('一覧を開く', 'drwp-daily-reports'); ?></a>
            <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=drwp_help')); ?>"><?php esc_html_e('使い方', 'drwp-daily-reports'); ?></a>
          </p>
        </div>
        <?php
    }
}
