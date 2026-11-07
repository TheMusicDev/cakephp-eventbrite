<?php
declare(strict_types=1);

use Cake\Routing\RouteBuilder;

return static function (RouteBuilder $builder): void {
    // OAuth and webhook endpoints ship with the plugin so every host mounts
    // them by just loading it (routesEnabled). Controllers refuse requests
    // lacking host configuration — connecting fails closed.
    $builder->plugin(
        'TheMusicDev/CakeEventbrite',
        ['path' => '/eventbrite'],
        static function (RouteBuilder $routes): void {
            $routes->get('/oauth/login', ['controller' => 'OAuth', 'action' => 'login']);
            $routes->get('/oauth/callback', ['controller' => 'OAuth', 'action' => 'callback']);
            $routes->post('/webhook/{secret}', ['controller' => 'Webhook', 'action' => 'receive'])
                ->setPatterns(['secret' => '[0-9a-f]+'])
                ->setPass(['secret']);
        },
    );
};
