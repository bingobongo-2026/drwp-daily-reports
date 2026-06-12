<?php
/**
 * Uninstall handler. Runs when the plugin is deleted from
 * Plugins → Installed Plugins → Delete (not on deactivate).
 */
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

global $wpdb;

$tables = [
    $wpdb->prefix . 'drwp_reports',
    $wpdb->prefix . 'drwp_projects',
    $wpdb->prefix . 'drwp_customers',
    $wpdb->prefix . 'drwp_comments',
    $wpdb->prefix . 'drwp_audit_logs',
    $wpdb->prefix . 'drwp_report_photos',
    $wpdb->prefix . 'drwp_customer_photos',
    $wpdb->prefix . 'drwp_plans',
    $wpdb->prefix . 'drwp_customer_groups',
    $wpdb->prefix . 'drwp_customer_group_map',
    $wpdb->prefix . 'drwp_project_groups',
    $wpdb->prefix . 'drwp_project_group_map',
];
foreach ($tables as $table) {
    // dbDelta only adds columns; we have to drop tables ourselves.
    $wpdb->query("DROP TABLE IF EXISTS $table");
}

wp_unschedule_hook('drwp_license_check');

$options = [
    'drwp_schema_version',
    // License client state
    'drwp_license_api_url',
    'drwp_license_key',
    'drwp_license_status',
    'drwp_license_plan',
    'drwp_license_expires_at',
    'drwp_license_checked_at',
    'drwp_license_last_valid_at',
    'drwp_license_last_message',
    'drwp_license_public_key',
    'drwp_license_previous_keys',
    'drwp_license_signature_valid',
    'drwp_license_admin_token',
    // Output settings
    'drwp_output_post_type',
    'drwp_output_auto_thumbnail',
    // AI settings
    'drwp_ai_provider',
    'drwp_ai_url',
    'drwp_ai_model',
    'drwp_ai_api_key',
    'drwp_ai_enabled',
    // Notification settings
    'drwp_notify_enabled',
    'drwp_notify_on_pending',
    'drwp_notify_on_review',
    'drwp_notify_on_comment',
    'drwp_notify_from_email',
    // Login / front-end gateway settings
    'drwp_login_page_id',
    'drwp_login_redirect_enabled',
    'drwp_login_lostpass_page_id',
    'drwp_login_admin_lockdown',
    'drwp_login_logo_url',
    // Legacy
    'drwp_public_key',
];
foreach ($options as $option) {
    delete_option($option);
}

// Stale flash transients from the (removed) CSV importer. Harmless if
// nothing matches — kept so an uninstall on an older install still
// cleans up.
foreach (get_users(['fields' => ['ID']]) as $user) {
    delete_transient('drwp_csv_import_result_' . (int) $user->ID);
}
