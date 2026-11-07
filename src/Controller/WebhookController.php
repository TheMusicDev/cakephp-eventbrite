<?php
declare(strict_types=1);

namespace TheMusicDev\CakeEventbrite\Controller;

use Cake\Controller\Controller;
use Cake\Event\Event;
use Cake\Http\Response;
use Cake\Log\Log;
use RuntimeException;
use TheMusicDev\CakeEventbrite\EventbriteClientFactory;
use TheMusicDev\CakeEventbrite\Model\Table\EventbriteConnectionsTable;
use TheMusicDev\Eventbrite\EventbriteApiException;
use TheMusicDev\Eventbrite\EventbriteClient;
use Throwable;

/**
 * Receive Eventbrite webhook deliveries: POST /eventbrite/webhook/{secret}.
 *
 * Eventbrite's payload is only a pointer ({"api_url": "..."}) and its
 * deliveries carry no signature, so the per-connection secret in the path
 * finds the connection (and the token to fetch with). The payload is never
 * trusted: the pointed URL is fetched through the client, which refuses
 * anything outside the Eventbrite API and skips the cache. After the fetch
 * the plugin fires 'CakeEventbrite.Webhook.received'; the app stores.
 *
 * Every response of this controller carries meaning: 400/404 refuse,
 * 502/500 make Eventbrite retry (it retries non-2xx every ten minutes up to
 * ten attempts, so listeners must be idempotent). Never 410 — it
 * auto-unsubscribes the endpoint.
 */
final class WebhookController extends Controller
{
    /**
     * @var string
     */
    public const EVENT_RECEIVED = 'CakeEventbrite.Webhook.received';

    /**
     * Handle one delivery.
     *
     * @param string $secret The {secret} path segment identifying the connection.
     * @return \Cake\Http\Response
     */
    public function receive(string $secret = ''): Response
    {
        $connection = $this->connections()->forWebhookSecret($secret);
        if ($connection === null) {
            Log::info('Eventbrite webhook refused: unknown connection secret');

            return $this->json(['error' => 'unknown_secret'], 404);
        }

        $body = json_decode((string)$this->request->getBody(), true);
        $payload = is_array($body) ? $body : [];
        $apiUrl = (string)($payload['api_url'] ?? '');
        if ($apiUrl === '') {
            return $this->json(['error' => 'no_api_url'], 400);
        }
        if (!str_starts_with($apiUrl, EventbriteClient::BASE_URL)) {
            // Not an Eventbrite pointer: refuse, but never 410 (auto-unsubscribes).
            Log::warning('Eventbrite webhook refused: unexpected api_url host', [
                'host' => (string)(parse_url($apiUrl, PHP_URL_HOST) ?? 'unparseable'),
            ]);

            return $this->json(['error' => 'untrusted_api_url'], 400);
        }

        $kind = $this->kindFromUrl($apiUrl);

        try {
            $object = $this->factory()->forConnection($connection)->fetchUrl($apiUrl);
        } catch (EventbriteApiException | RuntimeException $e) {
            // 502 makes Eventbrite retry. Log the reason, never the token.
            Log::error('Eventbrite webhook fetch failed: {message}', ['message' => $e->getMessage()]);

            return $this->json(['error' => 'fetch_failed'], 502);
        }

        try {
            $this->getEventManager()->dispatch(new Event(
                self::EVENT_RECEIVED,
                $this,
                [
                    'connection' => $connection,
                    'kind' => $kind,
                    'apiUrl' => $apiUrl,
                    'object' => $object,
                ],
            ));
        } catch (Throwable $e) {
            // A failed listener is a failed delivery: 500 makes Eventbrite retry.
            Log::error('Eventbrite webhook listener failed: {message}', ['message' => $e->getMessage()]);

            return $this->json(['error' => 'listener_failed'], 500);
        }

        return $this->json(['status' => 'processed', 'kind' => $kind]);
    }

    /**
     * The kind of object the URL points at ('event', 'order', 'attendee', ...),
     * parsed from the API path — never from the delivery's own claims.
     *
     * @param string $apiUrl An Eventbrite API URL.
     * @return string
     */
    private function kindFromUrl(string $apiUrl): string
    {
        $path = substr($apiUrl, strlen(EventbriteClient::BASE_URL));
        $parts = array_values(array_filter(
            explode('/', rtrim($path, '/')),
            static fn($part): bool => $part !== '',
        ));
        $kind = $parts[2] ?? ($parts[0] ?? '');
        $kind = match ($kind) {
            'events' => 'event',
            'orders' => 'order',
            'attendees' => 'attendee',
            'ticket_classes' => 'ticket_class',
            'webhooks' => 'webhook',
            'organizations' => 'organization',
            'users' => 'user',
            'barcode' => 'barcode',
            default => $kind,
        };

        return $kind !== '' ? $kind : 'unknown';
    }

    /**
     * @return \TheMusicDev\CakeEventbrite\EventbriteClientFactory
     */
    private function factory(): EventbriteClientFactory
    {
        return new EventbriteClientFactory();
    }

    /**
     * @return \TheMusicDev\CakeEventbrite\Model\Table\EventbriteConnectionsTable
     */
    private function connections(): EventbriteConnectionsTable
    {
        /** @var \TheMusicDev\CakeEventbrite\Model\Table\EventbriteConnectionsTable */
        return $this->fetchTable('TheMusicDev/CakeEventbrite.EventbriteConnections');
    }

    /**
     * @param array<string, mixed> $data Response body (never includes tokens).
     * @param int $status HTTP status.
     * @return \Cake\Http\Response
     */
    private function json(array $data, int $status = 200): Response
    {
        $response = $this->response->withType('application/json');

        return $response
            ->withStatus($status)
            ->withStringBody((string)json_encode($data, JSON_UNESCAPED_SLASHES));
    }
}
