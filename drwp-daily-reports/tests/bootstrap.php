<?php
/**
 * PHPUnit bootstrap. Loads the WordPress test library and queues up
 * the plugin so its hooks fire under WP_UnitTestCase setUp/tearDown.
 */

$tests_dir = getenv('WP_TESTS_DIR') ?: '/tmp/wordpress-tests-lib';
if (!file_exists($tests_dir . '/includes/functions.php')) {
    fwrite(STDERR, "WP test library not found at {$tests_dir}.\n");
    fwrite(STDERR, "Run: composer test:install (or bash bin/install-wp-tests.sh)\n");
    exit(1);
}

require_once $tests_dir . '/includes/functions.php';

function _drwp_load_plugin() {
    require dirname(__DIR__) . '/drwp-daily-reports.php';
}
tests_add_filter('muplugins_loaded', '_drwp_load_plugin');

require $tests_dir . '/includes/bootstrap.php';
