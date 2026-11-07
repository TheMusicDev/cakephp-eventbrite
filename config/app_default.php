<?php
declare(strict_types=1);

/**
 * TheMusicDev/CakeEventbrite ship-with defaults. Merged UNDER host values by the
 * plugin's config/bootstrap.php — hosts win, per the IdentityBridge
 * convention (cakephp-conventions: plugins.md).
 *
 * Credentials come from the environment; see README for the variable list.
 * Everything is under the 'CakeEventbrite' key.
 */
return [
    'CakeEventbrite' => [
        /**
         * Key used to encrypt access tokens. No fallback: without a key configured,
         * saving a token throws (see docs/eventbrite-plugin-design.md — a fallback to
         * Security.salt would silently destroy stored tokens when the salt rotates).
         * Any non-empty string works; generate one with bin2hex(random_bytes(16)).
         */
        'encryptionKey' => env('EVENTBRITE_ENCRYPTION_KEY', ''),

        /**
         * Private token (EVENTBRITE_TOKEN) for apps that skip OAuth entirely.
         */
        'privateToken' => env('EVENTBRITE_TOKEN', ''),

        /**
         * Who is connecting. Required for the OAuth routes: a callable returning
         * an owner key string (e.g. 'user:42'), or null/''/'' to refuse the request.
         * Without it every visitor could connect an account or replace one.
         * Example in config: `'ownerResolver' => fn () => 'user:' . $userId,`
         * must be set from runtime context (middleware/controller), not statically.
         */
        'ownerResolver' => null,

        'oauth' => [
            'clientId' => env('EVENTBRITE_OAUTH_CLIENT_ID', ''),
            'clientSecret' => env('EVENTBRITE_OAUTH_CLIENT_SECRET', ''),
            /** Must exactly match a redirect URI registered on the Eventbrite app. */
            'redirectUri' => env('EVENTBRITE_OAUTH_REDIRECT_URI', ''),
        ],

        /**
         * Where the OAuth controllers send the browser after the callback.
         * Targets come from config, never from request input (no open redirect).
         */
        'redirects' => [
            'connected' => '/',
            'failed' => '/',
        ],

        'webhook' => [
            /**
             * Public base URL of the host app (e.g. 'https://example.com'). The
             * registration service builds '/eventbrite/webhook/{secret}' under it.
             */
            'baseUrl' => env('EVENTBRITE_WEBHOOK_BASE_URL', ''),
            /**
             * Action codes registered by the webhook enrollment. Check-in actions
             * are named barcode.* on the Eventbrite API, not attendee.*.
             */
            'actions' => [
                'attendee.updated',
                'barcode.checked_in',
                'barcode.un_checked_in',
                'order.placed',
                'order.refunded',
                'event.published',
                'event.updated',
                'event.created',
            ],
        ],

        /**
         * Optional PSR-16 cache config name (Cake\Cache\Cache::get/set pool) for
         * GET responses. Null is the default: responses contain attendee personal
         * data, so hosts should choose where those are stored. Never enable for
         * webhook intake — webhook fetches always bypass the cache.
         * 'logger' is a PSR-3 channel name (Cake\Log\Log::engine()) for 429 backoff.
         */
        'cache' => null,
        'logger' => null,
    ],
];
