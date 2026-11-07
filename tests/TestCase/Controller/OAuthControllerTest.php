<?php
declare(strict_types=1);

namespace TheMusicDev\CakeEventbrite\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\Http\Client;
use Cake\Http\Client\Response as ClientResponse;
use Cake\Http\RequestFactory;
use Cake\Http\StreamFactory;
use Cake\Http\TestSuite\HttpClientTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use TestApp\Application;
use TheMusicDev\CakeEventbrite\EventbriteCrypto;
use TheMusicDev\Eventbrite\EventbriteOAuth;

/**
 * GET /eventbrite/oauth/login and /eventbrite/oauth/callback — OAuth flow,
 * owner resolution, session-bound state, no templates.
 */
final class OAuthControllerTest extends TestCase
{
    use IntegrationTestTrait;
    use HttpClientTrait;

    private const TEST_KEY = 'oauth-test-key-0123456789abcdef0123456789abcdef';

    private $connections;

    public function setUp(): void
    {
        parent::setUp();
        $this->configApplication(Application::class, [CONFIG]);
        $this->connections = $this->getTableLocator()->get('TheMusicDev/CakeEventbrite.EventbriteConnections');
        $this->connections->deleteAll([]);
        Configure::write('CakeEventbrite.encryptionKey', self::TEST_KEY);
        Configure::write('CakeEventbrite.ownerResolver', static fn(): string => 'user:test');
    }

    public function tearDown(): void
    {
        $this->connections->deleteAll([]);
        parent::tearDown();
    }

    /**
     * @return void
     */
    private function configureOAuth(): void
    {
        Configure::write('CakeEventbrite.oauth', [
            'clientId' => 'client-id-1',
            'clientSecret' => 'client-secret-1',
            'redirectUri' => 'http://localhost/eventbrite/oauth/callback',
        ]);
        Configure::write('CakeEventbrite.redirects', ['connected' => '/account', 'failed' => '/connect']);
    }

    private function oauth(): EventbriteOAuth
    {
        return new EventbriteOAuth(
            new Client(),
            new RequestFactory(),
            new StreamFactory(),
            'client-id-1',
            'client-secret-1',
            'http://localhost/eventbrite/oauth/callback',
            EventbriteCrypto::stateSalt(),
        );
    }

    public function testLoginRedirectsToEventbriteWithState(): void
    {
        $this->configureOAuth();

        $this->get('/eventbrite/oauth/login');

        $location = (string)$this->_response->getHeaderLine('Location');
        $this->assertStringStartsWith('https://www.eventbrite.com/oauth/authorize?', $location);
        parse_str((string)parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('client-id-1', $query['client_id']);
        $this->assertSame('http://localhost/eventbrite/oauth/callback', $query['redirect_uri']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}\.\d+-[0-9a-f]{64}$/', $query['state']);
    }

    public function testLoginRefusesWithoutAnOwner(): void
    {
        $this->configureOAuth();
        Configure::write('CakeEventbrite.ownerResolver', null);

        $this->get('/eventbrite/oauth/login');

        $this->assertSame(403, $this->_response->getStatusCode());
        $this->assertStringContainsString('error=no_owner', (string)$this->_response->getHeaderLine('Location'));
        $this->assertStringContainsString('/connect', (string)$this->_response->getHeaderLine('Location'));
    }

    public function testLoginRefusesWithUnreadableKey(): void
    {
        $this->configureOAuth();
        Configure::write('CakeEventbrite.encryptionKey', '');

        $this->get('/eventbrite/oauth/login');

        $this->assertSame(500, $this->_response->getStatusCode());
        $this->assertStringContainsString('error=oauth_not_configured', (string)$this->_response->getHeaderLine('Location'));
    }

    public function testCallbackRefusesWithoutCodeOrState(): void
    {
        $this->configureOAuth();

        $this->get('/eventbrite/oauth/callback?incomplete=1');

        $this->assertStringContainsString('error=invalid_request', (string)$this->_response->getHeaderLine('Location'));
    }

    public function testCallbackRefusesAStateFromAnotherVisitor(): void
    {
        $this->configureOAuth();

        // A state minted for a different browser: same key, wrong visitor.
        $state = $this->oauth()->newState('attacker-browser-id');
        $this->get('/eventbrite/oauth/callback?' . http_build_query(['code' => 'c1', 'state' => $state]));

        $this->assertSame(403, $this->_response->getStatusCode());
        $this->assertStringContainsString('error=invalid_state', (string)$this->_response->getHeaderLine('Location'));
        $this->assertSame(
            0,
            $this->getTableLocator()->get('TheMusicDev/CakeEventbrite.EventbriteConnections')->find()->count(),
        );
    }

    public function testCallbackExchangesSavesAndRedirects(): void
    {
        $this->configureOAuth();

        // Mint the state for THIS process's session id (integration requests
        // share the PHP session), like the login page just did.
        // session_id('cli') is pre-set by the test bootstrap, so requests' PHP
        // session (started by the controller) uses the id 'cli' deterministically.
        $state = $this->oauth()->newState('cli');
        $this->mockClientPost(
            'https://www.eventbrite.com/oauth/token',
            $this->json(200, ['access_token' => 'oauth-tok-777', 'token_type' => 'bearer']),
        );
        $bearer = null;
        $this->mockClientGet(
            'https://www.eventbriteapi.com/v3/users/me/',
            $this->json(200, ['id' => '55', 'name' => 'OAuth Flow']),
            [
                'match' => function ($request) use (&$bearer) {
                    $bearer = $request->getHeaderLine('Authorization');

                    return true;
                },
            ],
        );

        $this->get('/eventbrite/oauth/callback?' . http_build_query(['code' => 'auth-code', 'state' => $state]));

        $this->assertSame(302, $this->_response->getStatusCode());
        $this->assertSame('/account', (string)$this->_response->getHeaderLine('Location'));
        $this->assertSame('Bearer oauth-tok-777', $bearer, 'the stored token must be proven against me()');
        $saved = $this->getTableLocator()->get('TheMusicDev/CakeEventbrite.EventbriteConnections')
            ->find()->firstOrFail();
        $this->assertSame('user:test', $saved->owner_key);
        $this->assertSame('55', $saved->eventbrite_user_id);
        $this->assertStringNotContainsString('oauth-tok-777', (string)$this->_response, 'the token never appears in the response');
    }

    public function testCallbackFailureAtExchangeRedirects(): void
    {
        $this->configureOAuth();
        // session_id('cli') is pre-set by the test bootstrap, so requests' PHP
        // session (started by the controller) uses the id 'cli' deterministically.
        $state = $this->oauth()->newState('cli');
        $this->mockClientPost(
            'https://www.eventbrite.com/oauth/token',
            $this->json(400, ['error_description' => 'Expired authorization code']),
        );

        $this->get('/eventbrite/oauth/callback?' . http_build_query(['code' => 'expired', 'state' => $state]));

        $this->assertSame(502, $this->_response->getStatusCode());
        $this->assertStringContainsString('error=connect_failed', (string)$this->_response->getHeaderLine('Location'));
        $this->assertSame(
            0,
            $this->getTableLocator()->get('TheMusicDev/CakeEventbrite.EventbriteConnections')->find()->count(),
        );
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
