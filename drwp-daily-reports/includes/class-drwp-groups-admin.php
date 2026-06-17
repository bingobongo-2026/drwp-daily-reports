<?php
if (!defined('ABSPATH')) exit;

/**
 * 「グループ」 — unified admin page that hosts both 顧客グループ
 * and 案件グループ CRUD behind a tab switcher.
 *
 * Owns no data of its own; renders a wrap + nav + delegates to the
 * existing `DRWP_Customer_Group` / `DRWP_Project_Group` view files
 * which retain their tables, modals, and save handlers. The save
 * routines redirect back to `?page=drwp_groups&tab=...` so the
 * operator stays on the same tab after submitting.
 */
class DRWP_Groups_Admin {
    const SLUG = 'drwp_groups';

    public static function init() {
        // No-op; menu registration is centralized in
        // DRWP_Admin::menu() so the sidebar order lives in one
        // file.
    }

    /**
     * Active tab from `?tab=` — defaults to customer, anything
     * other than 'project' falls back to 'customer' so a typo'd
     * URL doesn't render a blank page.
     */
    public static function active_tab() {
        $tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'customer';
        return $tab === 'project' ? 'project' : 'customer';
    }

    /**
     * URL for the given tab, preserving `saved` / `error` flash
     * args so the inner view's notice still fires after a redirect.
     */
    public static function tab_url($tab, $extra = []) {
        $args = array_merge(['page' => self::SLUG, 'tab' => $tab], $extra);
        return admin_url('admin.php?' . http_build_query($args));
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('権限がありません', 'drwp-daily-reports'));
        }
        $tab = self::active_tab();
        ?>
        <div class="wrap">
          <h1><?php esc_html_e('グループ', 'drwp-daily-reports'); ?></h1>
          <h2 class="nav-tab-wrapper">
            <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG . '&tab=customer')); ?>"
               class="nav-tab <?php echo $tab === 'customer' ? 'nav-tab-active' : ''; ?>">
              <?php esc_html_e('顧客グループ', 'drwp-daily-reports'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG . '&tab=project')); ?>"
               class="nav-tab <?php echo $tab === 'project' ? 'nav-tab-active' : ''; ?>">
              <?php esc_html_e('案件グループ', 'drwp-daily-reports'); ?>
            </a>
          </h2>
        <?php
        if ($tab === 'customer') {
            $groups = DRWP_Customer_Group::all();
            $counts = DRWP_Customer_Group::customer_counts();
            list($sort_field, $sort_order) = DRWP_Admin::parse_sort($_GET, ['id'], 'id', 'desc');
            usort($groups, function ($a, $b) use ($sort_order) {
                $cmp = (int) $a->id <=> (int) $b->id;
                return $sort_order === 'desc' ? -$cmp : $cmp;
            });
            $pager = DRWP_Admin::paginate_array($groups);
            $groups = $pager['items'];
            $total  = $pager['total'];
            $paged  = $pager['paged'];
            $pages  = $pager['pages'];
            include DRWP_PATH . 'admin/views/customer-groups-page.php';
        } else {
            $groups = DRWP_Project_Group::all();
            $counts = DRWP_Project_Group::project_counts();
            list($sort_field, $sort_order) = DRWP_Admin::parse_sort($_GET, ['id'], 'id', 'desc');
            usort($groups, function ($a, $b) use ($sort_order) {
                $cmp = (int) $a->id <=> (int) $b->id;
                return $sort_order === 'desc' ? -$cmp : $cmp;
            });
            $pager = DRWP_Admin::paginate_array($groups);
            $groups = $pager['items'];
            $total  = $pager['total'];
            $paged  = $pager['paged'];
            $pages  = $pager['pages'];
            include DRWP_PATH . 'admin/views/project-groups-page.php';
        }
        ?>
        </div>
        <?php
    }
}
