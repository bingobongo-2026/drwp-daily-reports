<?php
if (!defined('ABSPATH')) exit;

class DRWP_DB {
    const OPT_SCHEMA_VERSION = 'drwp_schema_version';

    public static function maybe_upgrade() {
        if (get_option(self::OPT_SCHEMA_VERSION) === DRWP_VERSION) return;
        self::activate();
        update_option(self::OPT_SCHEMA_VERSION, DRWP_VERSION);
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $reports = $wpdb->prefix . 'drwp_reports';
        $projects = $wpdb->prefix . 'drwp_projects';
        $comments = $wpdb->prefix . 'drwp_comments';
        $audit = $wpdb->prefix . 'drwp_audit_logs';
        $photos = $wpdb->prefix . 'drwp_report_photos';

        $sql1 = "CREATE TABLE $projects (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset;";
        dbDelta($sql1);

        $sql2 = "CREATE TABLE $reports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            report_date DATE NOT NULL,
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
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY report_date (report_date),
            KEY review_status (review_status),
            KEY linked_post_id (linked_post_id)
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

        // entry_id is NULL for legacy report-level photos (pre-1.9
        // single-site reports). When the report has entries, photos
        // link to a specific entry via this column.
        $sql5 = "CREATE TABLE $photos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            report_id BIGINT UNSIGNED NOT NULL,
            entry_id BIGINT UNSIGNED NULL,
            attachment_id BIGINT UNSIGNED NOT NULL,
            caption VARCHAR(255) NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY report_id (report_id),
            KEY entry_id (entry_id)
        ) $charset;";
        dbDelta($sql5);

        // 1 report : N site entries. A trade where a worker visits
        // several jobsites in a single day stores one row per visit,
        // each carrying its own work_description / issues / next_plan
        // and (via $photos.entry_id) its own photo set. project_id on
        // the parent report stays for legacy single-site reports.
        // public_title / public_body are office-curated, entry-level
        // overrides used by the post converter. Field workers leave
        // them blank; the office editor fills them when polishing the
        // multi-entry submission for publication.
        $entries = $wpdb->prefix . 'drwp_report_entries';
        $sql6 = "CREATE TABLE $entries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            report_id BIGINT UNSIGNED NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            project_id BIGINT UNSIGNED NULL,
            started_at TIME NULL,
            ended_at TIME NULL,
            work_description LONGTEXT NULL,
            issues LONGTEXT NULL,
            next_plan LONGTEXT NULL,
            public_title VARCHAR(255) NULL,
            public_body LONGTEXT NULL,
            linked_post_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY report_id (report_id),
            KEY project_id (project_id),
            KEY linked_post_id (linked_post_id)
        ) $charset;";
        dbDelta($sql6);

        add_option('drwp_license_api_url', 'https://license.example.com');
        add_option('drwp_public_key', '');
    }
}
