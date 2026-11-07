<?php
declare(strict_types=1);

use Cake\Core\Configure;

/**
 * Plugin config bootstrap (auto-required by BasePlugin::bootstrap()).
 *
 * Hosts define their own 'CakeEventbrite' block in config/app.php — write the
 * defaults under any keys the host hasn't set, never overwrite host values
 * (deep-defaults merge: hosts win, IdentityBridge convention).
 */
$existing = (array)Configure::read('CakeEventbrite', []);
$existing['oauth'] = (array)($existing['oauth'] ?? []);
$existing['redirects'] = (array)($existing['redirects'] ?? []);
$existing['webhook'] = (array)($existing['webhook'] ?? []);
$defaults = (require __DIR__ . '/app_default.php')['CakeEventbrite'];
$defaults['oauth'] = $existing['oauth'] + $defaults['oauth'];
$defaults['redirects'] = $existing['redirects'] + $defaults['redirects'];
$defaults['webhook'] = $existing['webhook'] + $defaults['webhook'];
$existing = array_intersect_key($existing, $defaults) + $defaults;
Configure::write('CakeEventbrite', $existing);
