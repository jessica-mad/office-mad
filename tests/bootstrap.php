<?php
/**
 * PHPUnit bootstrap. Works with wp-env (`npx wp-env run tests-cli --env-cwd=wp-content/plugins/office-mad vendor/bin/phpunit`)
 * or with a local WordPress test library pointed to by WP_TESTS_DIR.
 */
if ( PHP_SAPI !== 'cli' ) exit;

$tests_dir = getenv('WP_TESTS_DIR') ?: rtrim(sys_get_temp_dir(), '/\\') . '/wordpress-tests-lib';
if ( ! file_exists($tests_dir . '/includes/functions.php') ) {
    fwrite(STDERR, "WordPress test library not found in {$tests_dir}. Set WP_TESTS_DIR.\n");
    exit(1);
}

if ( ! getenv('WP_TESTS_PHPUNIT_POLYFILLS_PATH') && file_exists(dirname(__DIR__) . '/vendor/yoast/phpunit-polyfills') ) {
    putenv('WP_TESTS_PHPUNIT_POLYFILLS_PATH=' . dirname(__DIR__) . '/vendor/yoast/phpunit-polyfills');
}
define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', getenv('WP_TESTS_PHPUNIT_POLYFILLS_PATH'));

require_once $tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', function(){
    require dirname(__DIR__) . '/mad-oficina.php';
});

// Every test starts with the Block 1 modules enabled and the schema installed.
tests_add_filter('setup_theme', function(){
    MAD_Oficina\Core\Installer::install();
    update_option(MAD_Oficina_Core::ENABLED_MODULES_OPTION, ['clients', 'google']);
});

require $tests_dir . '/includes/bootstrap.php';
require_once __DIR__ . '/php/TestCase.php';
