<?php
declare(strict_types=1);

/**
 * Test environment: constants, the autoloader and CakePHP's core configuration, with no database. Shared by the test
 * bootstrap (which adds the MariaDB connection and the schema) and by phpstan (which only needs the classes).
 */

use Cake\Cache\Cache;
use Cake\Core\Configure;

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}
define('ROOT', dirname(__DIR__));
define('CAKE_CORE_INCLUDE_PATH', ROOT . DS . 'vendor' . DS . 'cakephp' . DS . 'cakephp');
define('CORE_PATH', CAKE_CORE_INCLUDE_PATH . DS);
define('CAKE', CORE_PATH . 'src' . DS);
define('TESTS', ROOT . DS . 'tests' . DS);
define('APP', ROOT . DS . 'tests' . DS . 'test_app' . DS);
define('APP_DIR', 'src');
define('WEBROOT_DIR', 'webroot');
define('WWW_ROOT', APP . 'webroot' . DS);
define('TMP', sys_get_temp_dir() . DS . 'eventbrite-tests' . DS);
define('CONFIG', APP . 'config' . DS);
define('CACHE', TMP . 'cache' . DS);
define('LOGS', TMP . 'logs' . DS);

foreach ([TMP, CACHE, LOGS] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

require ROOT . '/vendor/autoload.php';
require CORE_PATH . 'config' . DS . 'bootstrap.php';
require CAKE . 'functions.php';

Configure::write('App', [
    'namespace' => 'TestApp',
    'encoding' => 'UTF-8',
    'base' => false,
    'baseUrl' => false,
    'dir' => 'src',
    'webroot' => 'webroot',
    'wwwRoot' => WWW_ROOT,
    'fullBaseUrl' => 'http://localhost',
    'paths' => [
        'templates' => [APP . 'templates' . DS],
    ],
]);
Configure::write('debug', true);
Configure::write('Security.salt', 'eventbrite-tests-only-salt-00000000000000000000000000000000000');

Cache::setConfig([
    '_cake_core_' => ['engine' => 'Array', 'prefix' => 'eventbrite_cake_core_'],
    '_cake_translations_' => ['engine' => 'Array', 'prefix' => 'eventbrite_cake_translations_'],
    '_cake_model_' => ['engine' => 'Array', 'prefix' => 'eventbrite_cake_model_'],
]);
