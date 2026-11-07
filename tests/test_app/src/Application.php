<?php
declare(strict_types=1);

namespace TestApp;

use Cake\Core\Configure;
use Cake\Error\Middleware\ErrorHandlerMiddleware;
use Cake\Http\BaseApplication;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\Middleware\RoutingMiddleware;

/**
 * The smallest application that installs the plugin the way a host does.
 * It also sets the test plugin config the way a host would (host values win
 * over the plugin defaults): the encryption key and an ownerResolver bound
 * to the session.
 */
class Application extends BaseApplication
{
    /**
     * @inheritDoc
     */
    public function bootstrap(): void
    {
        $existing = (array)Configure::read('CakeEventbrite', []);
        Configure::write('CakeEventbrite', $existing + [
            'ownerResolver' => static fn(): string => 'user:test',
            'encryptionKey' => 'test-encryption-key-0123456789abcdef0123456789abcdef',
        ]);

        parent::bootstrap();
        $this->addPlugin('TheMusicDev/CakeEventbrite', ['path' => ROOT . DS]);
    }

    /**
     * @inheritDoc
     */
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        return $middlewareQueue
            ->add(new ErrorHandlerMiddleware(Configure::read('Error', [])))
            ->add(new RoutingMiddleware($this));
    }
}
