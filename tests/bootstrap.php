<?php
declare(strict_types=1);

/**
 * Test bootstrap for the plugin: a tiny CakePHP app (tests/test_app) with just this plugin loaded and a MariaDB
 * test database (docker-compose.yml; override with DATABASE_TEST_URL).
 */

use Cake\Chronos\Chronos;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\ConnectionHelper;
use Migrations\TestSuite\Migrator;
use TestApp\Application;

require __DIR__ . '/environment.php';

ConnectionManager::setConfig('test', [
    'className' => 'Cake\Database\Connection',
    'driver' => 'Cake\Database\Driver\Mysql',
    'url' => getenv('DATABASE_TEST_URL') ?: 'mysql://eventbrite:eventbrite@127.0.0.1:3312/eventbrite_test',
    'timezone' => 'UTC',
    'encoding' => 'utf8mb4',
    'quoteIdentifiers' => false,
]);

Chronos::setTestNow(Chronos::now());
session_id('cli');

// Migrations must run against the test connection, so alias it first.
ConnectionHelper::addTestAliases();

// Load the plugin the way a host does, then build the schema from its own migrations.
$app = new Application(CONFIG);
$app->bootstrap();
$app->pluginBootstrap();
(new Migrator())->run(['plugin' => 'TheMusicDev/CakeEventbrite']);
