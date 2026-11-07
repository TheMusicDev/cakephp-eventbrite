<?php
declare(strict_types=1);

namespace TheMusicDev\CakeEventbrite\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\Http\Client\Response as ClientResponse;
use Cake\Http\TestSuite\HttpClientTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Closure;
use RuntimeException;
use TestApp\Application;
use TheMusicDev\CakeEventbrite\Model\Entity\EventbriteConnection;

/**
 * POST /eventbrite/webhook/{secret} — the delivery receiver.
 *
 * Response table (docs/eventbrite-plugin-design.md):
 * handled → 200, unknown secret → 404, missing/foreign api_url → 400,
 * fetch failed → 502, listener threw → 500 — and never 410.
 */
final class WebhookControllerTest extends TestCase
{
    use IntegrationTestTrait;
    use HttpClientTrait;

    private const TEST_KEY = 'webhook-test-key-0123456789abcdef0123456789abcdef';
    private const ATTENDEE_URL = 'https://www.eventbriteapi.com/v3/events/222/attendees/333/';

    private $connections;

    /**
     * @var array<int, Event>
     */
    private array $receivedEvents = [];

    /**
     * @var callable|null
     */
    private $listener;

    public function setUp(): void
    {
        parent::setUp();
        $this->configApplication(Application::class, [CONFIG]);
        Configure::write('CakeEventbrite.encryptionKey', self::TEST_KEY);
        $this->connections = $this->getTableLocator()->get('TheMusicDev/CakeEventbrite.EventbriteConnections');
        $this->connections->deleteAll([]);
    }

    public function tearDown(): void
    {
        if ($this->listener instanceof Closure) {
            EventManager::instance()->off('CakeEventbrite.Webhook.received', $this->listener);
        }
        $this->connections->deleteAll([]);
        parent::tearDown();
    }

    /**
     * A stored connection whose secret addresses the endpoint.
     *
     * @return \TheMusicDev\CakeEventbrite\Model\Entity\EventbriteConnection
     */
    private function connect(): EventbriteConnection
    {
        $connection = $this->connections->connectFromResponse(
            'owner:listens',
            ['access_token' => 'webhook-owner-token'],
            ['id' => '90'],
        );

        return $connection;
    }

    /**
     * @param array<mixed> $payload The delivery body.
     * @param string|null $secret The path secret; defaults to the stored one.
     * @return void
     */
    private function postWebhook(array $payload, ?string $secret = null): void
    {
        $secret ??= $this->connections->find()->firstOrFail()->webhook_secret;
        $this->post('/eventbrite/webhook/' . $secret, (string)json_encode($payload));
    }

    /**
     * Record all dispatched deliveries on the global manager.
     *
     * @return void
     */
    private function recordEvents(): void
    {
        $this->listener = function (Event $event): void {
            $this->receivedEvents[] = $event;
        };
        EventManager::instance()->on('CakeEventbrite.Webhook.received', $this->listener);
    }

    public function testAttendeeDeliveryDispatchesAndAnswers200(): void
    {
        $connection = $this->connect();
        $this->recordEvents();
        $this->mockClientGet(
            self::ATTENDEE_URL,
            new ClientResponse(['HTTP/1.1 200', 'content-type: application/json'], (string)json_encode([
                'id' => '333',
                'event_id' => '222',
                'checked_in' => true,
                'profile' => ['name' => 'Delivery Test', 'email' => 'buyer@example.com'],
            ])),
        );

        $this->postWebhook(['api_url' => self::ATTENDEE_URL]);

        $this->assertResponseCode(200);
        $this->assertResponseContains('"processed"');
        $this->assertResponseContains('"kind":"attendee"');
        $this->assertCount(1, $this->receivedEvents, 'the plugin dispatches; the app stores');
        $event = $this->receivedEvents[0];
        $this->assertSame('attendee', $event->getData('kind'));
        $this->assertSame(self::ATTENDEE_URL, $event->getData('apiUrl'));
        $this->assertSame($connection->id, $event->getData('connection')->id);
        $this->assertSame('333', $event->getData('object')['id']);
        $this->assertStringNotContainsString('webhook-owner-token', (string)$this->_response);
    }

    public function testUnknownSecretRefusedWith404(): void
    {
        $this->connect();

        $this->postWebhook(['api_url' => self::ATTENDEE_URL], str_repeat('a', 32));

        $this->assertResponseCode(404);
        $this->assertResponseContains('unknown_secret');
        $this->assertNotSame(410, $this->_response->getStatusCode());
    }

    public function testMissingApiUrlRefusedWith400(): void
    {
        $this->connect();

        $this->postWebhook(['nope' => 'nada']);

        $this->assertResponseCode(400);
        $this->assertResponseContains('no_api_url');
    }

    public function testForeignApiUrlRefusedWith400(): void
    {
        $this->connect();

        $this->postWebhook(['api_url' => 'https://evil.example.com/v3/events/123/']);

        $this->assertResponseCode(400);
        $this->assertResponseContains('untrusted_api_url');
        $this->assertNotSame(410, $this->_response->getStatusCode());
    }

    public function testApiUrlFromAnotherApiRootRefusedWith400(): void
    {
        $this->connect();
        $lookalike = 'https://www.eventbriteapi.com.evil.com/v3/orders/1/';

        $this->postWebhook(['api_url' => $lookalike]);

        $this->assertResponseCode(400);
    }

    public function testFetchFailureAnswers502ForRetry(): void
    {
        $this->connect();
        $this->mockClientGet(
            'https://www.eventbriteapi.com/v3/events/222/',
            new ClientResponse(['HTTP/1.1 500'], 'boom'),
        );

        $this->postWebhook(['api_url' => 'https://www.eventbriteapi.com/v3/events/222/']);

        $this->assertResponseCode(502);
        $this->assertResponseContains('fetch_failed');
        $this->assertNotSame(410, $this->_response->getStatusCode());
    }

    public function testListenerFailureAnswers500ForRetry(): void
    {
        $this->connect();
        $this->listener = function (): void {
            throw new RuntimeException('store exploded');
        };
        EventManager::instance()->on('CakeEventbrite.Webhook.received', $this->listener);
        $this->mockClientGet(
            'https://www.eventbriteapi.com/v3/orders/16060230483/',
            $this->json(200, ['id' => '16060230483', 'event_id' => '222', 'status' => 'placed']),
        );

        $this->postWebhook(['api_url' => 'https://www.eventbriteapi.com/v3/orders/16060230483/']);

        $this->assertResponseCode(500);
        $this->assertResponseContains('listener_failed');
        $this->assertNotSame(410, $this->_response->getStatusCode());
    }

    public function testGetIsRefused(): void
    {
        $this->get('/eventbrite/webhook/' . str_repeat('a', 32));

        $this->assertSame(404, $this->_response->getStatusCode(), 'only POST is routed');
    }

    public function testOrderKindParsedWithoutEventIdInPath(): void
    {
        $this->connect();
        $this->recordEvents();
        $this->mockClientGet(
            'https://www.eventbriteapi.com/v3/orders/1/',
            $this->json(200, ['id' => '1']),
        );

        $this->postWebhook(['api_url' => 'https://www.eventbriteapi.com/v3/orders/1/']);

        $this->assertResponseCode(200);
        $this->assertSame('order', $this->receivedEvents[0]->getData('kind'));
    }

    public function testUnplannedObjectStillAcknowledged(): void
    {
        $this->connect();
        $this->recordEvents();
        $this->mockClientGet(
            'https://www.eventbriteapi.com/v3/users/42/',
            $this->json(200, ['id' => '42']),
        );

        $this->postWebhook(['api_url' => 'https://www.eventbriteapi.com/v3/users/42/']);

        $this->assertResponseCode(200);
        $this->assertSame('user', $this->receivedEvents[0]->getData('kind'));
    }

    /**
     * A mocked Cake HTTP JSON response with the given status.
     *
     * @param int $code The HTTP status.
     * @param array<mixed> $data The JSON body.
     * @return \Cake\Http\Client\Response
     */
    private function json(int $code, array $data): ClientResponse
    {
        return new ClientResponse(["HTTP/1.1 $code", 'content-type: application/json'], (string)json_encode($data));
    }
}
