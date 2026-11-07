<?php
declare(strict_types=1);

use Cake\Routing\RouteBuilder;

// The test app loads the plugin, which ships /eventbrite/* routes; the app itself needs none.
return static function (RouteBuilder $routes): void {
};
