<?php
declare(strict_types=1);

namespace TheMusicDev\CakeEventbrite\Test\TestCase;

use Cake\Core\Configure;
use Cake\Http\Client\Response;
use Cake\Http\TestSuite\HttpClientTrait;
use Cake\TestSuite\TestCase;
use RuntimeException;
use TheMusicDev\CakeEventbrite\EventbriteClientFactory;
use TheMusicDev\CakeEventbrite\EventbriteCrypto;
use TheMusicDev\CakeEventbrite\Model\Entity\EventbriteConnection;

/**
 * EventbriteClientFactory: proves the API client works through CakePHP's real
 * PSR implementations (Cake\Http\Client as PSR-18 + its PSR-17 factories),
 * which is the whole point of this seam. HTTP is mocked at the transport.
 */
final class EventbriteClientFactoryTest extends TestCase
{
    use HttpClientTrait;

    private const TEST_KEY = 'factory-test-key-0123456789abcdef0123456789abcdef';

    public function setUp(): void
    {
        parent::setUp();
        Configure::write('CakeEventbrite.encryptionKey', self::TEST_KEY);
        Configure::write('CakeEventbrite.privateToken', 'private-token-value');
        Configure::delete('CakeEventbrite.cache');
        Configure::delete('CakeEventbrite.logger');
    }

    public function tearDown(): void
    {
        parent::tearDown();
        Configure::delete('CakeEventbrite.encryptionKey');
        Configure::delete('CakeEventbrite.privateToken');
    }

    public function testGetSendsBearerHeaderAndReadsFullBody(): void
    {
        $bearer = null;
        $this->mockClientGet(
            'https://www.eventbriteapi.com/v3/users/me/',
            $this->json200(['id' => '77', 'name' => 'Factory Test']),
            [
                'match' => function ($request) use (&$bearer) {
                    $bearer = $request->getHeaderLine('Authorization');

                    return true;
                },
            ],
        );

        $me = (new EventbriteClientFactory())->forToken('any-token')->me();

        $this->assertSame(['id' => '77', 'name' => 'Factory Test'], $me);
        $this->assertSame('Bearer any-token', $bearer, 'Cake\Http\Client sends the bearer header');
    }

    public function testPostSendsJsonBodyThroughCakeHttp(): void
    {
        $captured = null;
        $this->mockClientPost(
            'https://www.eventbriteapi.com/v3/organizations/12/webhooks/',
            $this->json200(['id' => 'n9', 'endpoint_url' => 'https://example.com/eventbrite/webhook/abc']),
            [
                'match' => function ($request) use (&$captured) {
                    $captured = $request;

                    return true;
                },
            ],
        );

        $created = (new EventbriteClientFactory())
            ->forToken('any-token')
            ->createWebhook('12', 'https://example.com/eventbrite/webhook/abc', ['order.placed']);

        $this->assertSame('n9', $created['id']);
        $this->assertSame('application/json', $captured->getHeaderLine('Content-Type'));
        $this->assertSame(
            ['endpoint_url' => 'https://example.com/eventbrite/webhook/abc', 'actions' => 'order.placed'],
            json_decode((string)$captured->getBody(), true),
        );
    }

    public function testForConnectionDecryptsTheStoredToken(): void
    {
        // EventbriteCrypto round-trips through Cake\Http\Client mocked at the transport.
        $connection = new EventbriteConnection([
            'access_token' => EventbriteCrypto::encryptToken('stored-oauth-token'),
        ]);
        $bearer = null;
        $this->mockClientGet(
            'https://www.eventbriteapi.com/v3/users/me/',
            $this->json200(['id' => '7']),
            [
                'match' => function ($request) use (&$bearer) {
                    $bearer = $request->getHeaderLine('Authorization');

                    return true;
                },
            ],
        );

        $me = (new EventbriteClientFactory())->forConnection($connection)->me();

        $this->assertSame(['id' => '7'], $me);
        $this->assertSame('Bearer stored-oauth-token', $bearer);
    }

    public function testForPrivateTokenUsesConfiguredToken(): void
    {
        $bearer = null;
        $this->mockClientGet(
            'https://www.eventbriteapi.com/v3/users/me/',
            $this->json200(['id' => '3']),
            [
                'match' => function ($request) use (&$bearer) {
                    $bearer = $request->getHeaderLine('Authorization');

                    return true;
                },
            ],
        );

        $me = (new EventbriteClientFactory())->forPrivateToken()->me();

        $this->assertSame(['id' => '3'], $me);
        $this->assertSame('Bearer private-token-value', $bearer);
    }

    public function testForPrivateTokenRefusesToBuildWithoutAToken(): void
    {
        Configure::write('CakeEventbrite.privateToken', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EVENTBRITE_TOKEN');
        (new EventbriteClientFactory())->forPrivateToken();
    }

    public function testForConnectionRefusesUnreadableCiphertext(): void
    {
        $connection = new EventbriteConnection(['access_token' => 'not-base64!!']);

        $this->expectException(RuntimeException::class);
        (new EventbriteClientFactory())->forConnection($connection);
    }

    /**
     * A mocked Cake HTTP JSON response.
     *
     * @param array<mixed> $data The JSON body.
     * @return \Cake\Http\Client\Response
     */
    private function json200(array $data): Response
    {
        return new Response(['HTTP/1.1 200', 'content-type: application/json'], (string)json_encode($data));
    }
}
