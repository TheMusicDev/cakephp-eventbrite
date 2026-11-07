<?php
declare(strict_types=1);

namespace TheMusicDev\CakeEventbrite;

use Cake\Core\Configure;
use Cake\Http\ServerRequest;

/**
 * Resolves the owner key for OAuth requests from CakeEventbrite.ownerResolver.
 *
 * Connecting fails closed: when nothing resolves an owner, the OAuth routes
 * refuse the request — otherwise any visitor could connect an account, or
 * replace one. The host sets this from its own authentication context, e.g.
 * in a controller or middleware:
 *
 * `Configure::write('CakeEventbrite.ownerResolver', fn () => 'user:' . $user->id);`
 *
 * `Configure::write('CakeEventbrite.ownerResolver', fn () => 'user:' . $user->id);`
 *
 * The owner is resolved on both the login and the
 * callback request, and never carried in a URL. */
final class OwnerResolver
{
    /**
     * @param \Cake\Http\ServerRequest $request The current request (usable by the resolver).
     * @return string|null The owner key, or null to refuse the OAuth flow.
     */
    public static function resolve(ServerRequest $request): ?string
    {
        $resolver = Configure::read('CakeEventbrite.ownerResolver');
        if (!is_callable($resolver)) {
            return null;
        }

        $owner = $resolver($request);

        return is_string($owner) && $owner !== '' ? $owner : null;
    }
}
