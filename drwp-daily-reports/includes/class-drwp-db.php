<?php
if (!defined('ABSPATH')) exit;

class DRWP_DB {
    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $reports = $wpdb->prefix . 'drwp_reports';
        $projects = $wpdb->prefix . 'drwp_projects';

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

        add_option('drwp_license_api_url', 'https://license.example.com');
        add_option('drwp_public_key', '');
    }
}
