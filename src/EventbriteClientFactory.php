<?php
declare(strict_types=1);

namespace TheMusicDev\CakeEventbrite;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Http\Client;
use Cake\Http\RequestFactory;
use Cake\Http\StreamFactory;
use Cake\Log\Log;
use RuntimeException;
use TheMusicDev\CakeEventbrite\Model\Entity\EventbriteConnection;
use TheMusicDev\Eventbrite\EventbriteClient;

/**
 * Builds EventbriteClients wired to CakePHP's own PSR implementations:
 * Cake\Http\Client (PSR-18), its PSR-17 factories, Cache::pool() (PSR-16)
 * and Log::engine() (PSR-3).
 *
 * The main seam of the plugin: one client per token, because the client
 * takes its token in the constructor — the container holds this factory,
 * never a shared client.
 *
 * No 429 retries outside the CLI: the client's backoff calls sleep() (up to
 * 7s), which would hold a web worker; a webhook failure returns 502 and
 * Eventbrite retries instead. No cache by default: responses contain
 * attendee personal data, and hosts choose where those are stored.
 */
final class EventbriteClientFactory
{
    /**
     * A client for an already-stored connection; the token is decrypted here,
     * never exposed by the entity.
     *
     * @param \TheMusicDev\CakeEventbrite\Model\Entity\EventbriteConnection $connection The connected row.
     * @return \TheMusicDev\Eventbrite\EventbriteClient
     * @throws \RuntimeException When the token cannot be decrypted.
     */
    public function forConnection(EventbriteConnection $connection): EventbriteClient
    {
        return $this->client(EventbriteCrypto::decryptToken($connection->access_token));
    }

    /**
     * @param string $token A private or OAuth access token (never log it).
     * @return \TheMusicDev\Eventbrite\EventbriteClient
     */
    public function forToken(string $token): EventbriteClient
    {
        return $this->client($token);
    }

    /**
     * A client for the configured private token (apps that skip OAuth).
     *
     * @return \TheMusicDev\Eventbrite\EventbriteClient
     * @throws \RuntimeException When EVENTBRITE_TOKEN / CakeEventbrite.privateToken is empty.
     */
    public function forPrivateToken(): EventbriteClient
    {
        $token = (string)Configure::read('CakeEventbrite.privateToken', '');

        return $this->client($token !== '' ? $token : throw new RuntimeException(
            'No private Eventbrite token configured. Set EVENTBRITE_TOKEN (never commit it) '
            . 'or use the OAuth flow.',
        ));
    }

    /**
     * The plugin's wiring of the client. Only called per use — a client never
     * lives past the object that built it, so no token lingers statefully.
     *
     * @param string $token The token for this client.
     * @return \TheMusicDev\Eventbrite\EventbriteClient
     */
    private function client(string $token): EventbriteClient
    {
        $cacheName = Configure::read('CakeEventbrite.cache');
        $cache = is_string($cacheName) && $cacheName !== ''
            ? Cache::pool($cacheName)
            : null;

        $loggerName = Configure::read('CakeEventbrite.logger');
        $logger = is_string($loggerName) && $loggerName !== ''
            ? Log::engine($loggerName)
            : null;

        return new EventbriteClient(
            new Client(),
            new RequestFactory(),
            new StreamFactory(),
            $token,
            $cache,
            $logger,
            // php_sapi_name() is not CLI in web workers; there sleep-based
            // 429 backoff is off and failures turn into 502 responses.
            PHP_SAPI === 'cli' ? 3 : 0,
        );
    }
}
