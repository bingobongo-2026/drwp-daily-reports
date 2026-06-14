<?php
if (!defined('ABSPATH')) exit;

/**
 * Schema management.
 *
 * The plugin's data model is "1 report = 1 site visit", flat —
 * a worker who visits two sites in a day files two reports.
 * (An earlier 1-report × N-entries model from v1.9 was removed in
 * v1.11; the entries table is dropped on upgrade and the per-
 * entry photo link is cleared. See maybe_upgrade() for the
 * one-time migration.)
 */
class DRWP_DB {
    const OPT_SCHEMA_VERSION = 'drwp_schema_version';

    public static function maybe_upgrade() {
        $current = (string) get_option(self::OPT_SCHEMA_VERSION, '');
        if ($current === DRWP_VERSION) return;

        // dbDelta handles ADD COLUMN on the reports table (started_at /
        // ended_at) and is a no-op on the other tables.
        self::activate();

        // One-time data migration: drop the entries table and orphan
        // any photos that were pointing at entries. Photo rows
        // themselves stay (they still have a valid report_id), the
        // entry_id reference just goes away.
        if ($current === '' || version_compare($current, '1.11.0', '<')) {
            global $wpdb;
            $wpdb->query("UPDATE {$wpdb->prefix}drwp_report_photos SET entry_id = NULL WHERE entry_id IS NOT NULL");
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}drwp_report_entries");
        }

        update_option(self::OPT_SCHEMA_VERSION, DRWP_VERSION);
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $reports = $wpdb->prefix . 'drwp_reports';
        $projects = $wpdb->prefix . 'drwp_projects';
        $customers = $wpdb->prefix . 'drwp_customers';
        $customer_groups = $wpdb->prefix . 'drwp_customer_groups';
        $customer_group_map = $wpdb->prefix . 'drwp_customer_group_map';
        $project_groups = $wpdb->prefix . 'drwp_project_groups';
        $project_group_map = $wpdb->prefix . 'drwp_project_group_map';
        $plans = $wpdb->prefix . 'drwp_plans';
        $comments = $wpdb->prefix . 'drwp_comments';
        $audit = $wpdb->prefix . 'drwp_audit_logs';
        $photos = $wpdb->prefix . 'drwp_report_photos';
        $customer_photos = $wpdb->prefix . 'drwp_customer_photos';

        // Customer ("顧客") — owns address / phone / email and is
        // referenced by 0..N projects. Project-level address fields
        // remain as overrides for the per-project "現場が違う" case.
        $sql0 = "CREATE TABLE $customers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            postal_code VARCHAR(10) NULL,
            prefecture VARCHAR(10) NULL,
            city VARCHAR(100) NULL,
            street VARCHAR(255) NULL,
            building VARCHAR(255) NULL,
            address VARCHAR(500) NULL,
            phone VARCHAR(30) NULL,
            email VARCHAR(255) NULL,
            notes TEXT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset;";
        dbDelta($sql0);

        $sql1 = "CREATE TABLE $projects (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            customer_id BIGINT UNSIGNED NULL,
            postal_code VARCHAR(10) NULL,
            prefecture VARCHAR(10) NULL,
            city VARCHAR(100) NULL,
            street VARCHAR(255) NULL,
            building VARCHAR(255) NULL,
            address VARCHAR(500) NULL,
            phone VARCHAR(30) NULL,
            job_description TEXT NULL,
            client_name VARCHAR(255) NULL,
            contact_person VARCHAR(255) NULL,
            notes TEXT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY customer_id (customer_id)
        ) $charset;";
        dbDelta($sql1);

        // started_at / ended_at moved onto the report itself in v1.11.
        // They're the start / end clock times for the visit (not a
        // datetime — DATE lives on report_date, time-of-day lives in
        // these TIME columns).
        $sql2 = "CREATE TABLE $reports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            report_date DATE NOT NULL,
            started_at TIME NULL,
            ended_at TIME NULL,
            work_description LONGTEXT NULL,
            issues LONGTEXT NULL,
            next_plan LONGTEXT NULL,
            review_status VARCHAR(32) NOT NULL DEFAULT 'pending',
            public_title VARCHAR(255) NULL,
            public_intro LONGTEXT NULL,
            public_body LONGTEXT NULL,
            public_next_plan LONGTEXT NULL,
            post_template VARCHAR(64) NOT NULL DEFAULT 'standard',
            post_category_id BIGINT UNSIGNED NULL,
            post_tags TEXT NULL,
            post_status VARCHAR(32) NOT NULL DEFAULT 'draft',
            scheduled_at DATETIME NULL,
            linked_post_id BIGINT UNSIGNED NULL,
            archived_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY report_date (report_date),
            KEY review_status (review_status),
            KEY linked_post_id (linked_post_id),
            KEY archived_at (archived_at)
        ) $charset;";
        dbDelta($sql2);

        $sql3 = "CREATE TABLE $comments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            report_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            body LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY report_id (report_id)
        ) $charset;";
        dbDelta($sql3);

        $sql4 = "CREATE TABLE $audit (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            report_id BIGINT UNSIGNED NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            event VARCHAR(64) NOT NULL,
            message VARCHAR(255) NULL,
            meta_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY report_id (report_id),
            KEY event (event)
        ) $charset;";
        dbDelta($sql4);

        // entry_id is kept as a NULLable column for backward storage
        // shape — new rows always set it NULL. The DROP TABLE for
        // drwp_report_entries (in maybe_upgrade) leaves the column
        // pointing at nothing, but the WHERE conditions and JOIN
        // sites no longer reference it.
        $sql5 = "CREATE TABLE $photos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            report_id BIGINT UNSIGNED NOT NULL,
            entry_id BIGINT UNSIGNED NULL,
            attachment_id BIGINT UNSIGNED NOT NULL,
            caption VARCHAR(255) NULL,
            photo_kind VARCHAR(16) NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY report_id (report_id),
            KEY entry_id (entry_id)
        ) $charset;";
        dbDelta($sql5);

        // Customer groups ("グループ") — many-to-many tagging of
        // customers. Each customer can belong to 0..N groups, and a
        // group lists 0..N customers. The map's composite primary
        // key (customer_id, group_id) makes (un)assigning idempotent
        // and the reverse-lookup index on group_id keeps the groups
        // admin page fast.
        $sql6 = "CREATE TABLE $customer_groups (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            color VARCHAR(7) NULL,
            notes TEXT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset;";
        dbDelta($sql6);

        $sql7 = "CREATE TABLE $customer_group_map (
            customer_id BIGINT UNSIGNED NOT NULL,
            group_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (customer_id, group_id),
            KEY group_id (group_id)
        ) $charset;";
        dbDelta($sql7);

        // Project groups ("案件グループ") — mirror of customer groups
        // but anchored on 案件 instead of 顧客. Same shape on purpose
        // so the M:N API in DRWP_Project_Group stays a near-copy of
        // DRWP_Customer_Group.
        $sql8 = "CREATE TABLE $project_groups (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            color VARCHAR(7) NULL,
            notes TEXT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset;";
        dbDelta($sql8);

        $sql9 = "CREATE TABLE $project_group_map (
            project_id BIGINT UNSIGNED NOT NULL,
            group_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (project_id, group_id),
            KEY group_id (group_id)
        ) $charset;";
        dbDelta($sql9);

        // Customer photos — many-per-customer gallery (logo, 名刺,
        // 外観 etc). Mirrors drwp_report_photos minus the entry_id
        // legacy column. Captions are optional.
        $sql_cp = "CREATE TABLE $customer_photos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id BIGINT UNSIGNED NOT NULL,
            attachment_id BIGINT UNSIGNED NOT NULL,
            caption VARCHAR(255) NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY customer_id (customer_id)
        ) $charset;";
        dbDelta($sql_cp);

        // 予定 — planned site visits, sibling to drwp_reports but
        // with no review / publish lifecycle. `user_id` is the
        // assignee (NULL = unassigned), `created_by` records who
        // entered the plan (operator vs. worker). `linked_report_id`
        // becomes non-null once the visit actually happens and the
        // worker (or operator) ties the plan back to the report row
        // that recorded it.
        $sql10 = "CREATE TABLE $plans (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NULL,
            user_id BIGINT UNSIGNED NULL,
            planned_date DATE NOT NULL,
            started_at TIME NULL,
            ended_at TIME NULL,
            notes TEXT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            linked_report_id BIGINT UNSIGNED NULL,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY planned_date (planned_date),
            KEY user_id (user_id),
            KEY project_id (project_id),
            KEY linked_report_id (linked_report_id)
        ) $charset;";
        dbDelta($sql10);

        add_option('drwp_license_api_url', 'https://license.example.com');
        add_option('drwp_public_key', '');
    }
}
