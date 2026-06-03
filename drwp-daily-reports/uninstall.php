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
    $wpdb->prefix . 'drwp_comments',
    $wpdb->prefix . 'drwp_audit_logs',
    $wpdb->prefix . 'drwp_report_photos',
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
    'drwp_ai_url',
    'drwp_ai_model',
    'drwp_ai_enabled',
    // Notification settings
    'drwp_notify_enabled',
    'drwp_notify_on_pending',
    'drwp_notify_on_review',
    'drwp_notify_on_comment',
    'drwp_notify_from_email',
    // Legacy
    'drwp_public_key',
];
foreach ($options as $option) {
    delete_option($option);
}

// Per-user CSV import flash transients.
foreach (get_users(['fields' => ['ID']]) as $user) {
    delete_transient('drwp_csv_import_result_' . (int) $user->ID);
}
