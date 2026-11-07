<?php
declare(strict_types=1);

namespace TheMusicDev\CakeEventbrite\Controller;

use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Http\Client;
use Cake\Http\RequestFactory;
use Cake\Http\Response;
use Cake\Http\StreamFactory;
use Cake\Log\Log;
use RuntimeException;
use TheMusicDev\CakeEventbrite\EventbriteClientFactory;
use TheMusicDev\CakeEventbrite\EventbriteCrypto;
use TheMusicDev\CakeEventbrite\Model\Table\EventbriteConnectionsTable;
use TheMusicDev\CakeEventbrite\OwnerResolver;
use TheMusicDev\Eventbrite\EventbriteOAuth;

/**
 * The Eventbrite OAuth flow: GET /eventbrite/oauth/login redirects the
 * browser to Eventbrite's authorize page; GET /eventbrite/oauth/callback
 * exchanges the ?code=, proves the token works and stores the connection.
 *
 * The token never appears in any response body or log. The owner (from
 * CakeEventbrite.ownerResolver) is required on both requests, re-resolved on
 * the callback and never carried in a URL. State is bound to the session id
 * (after starting the session), blocking login CSRF. The callback redirects
 * to the configured connected/failed targets with an ?error= code on
 * failure; those targets come from config, never from request input, so
 * there is no open redirect. No templates.
 */
final class OAuthController extends Controller
{
    /**
     * Redirect the browser to the Eventbrite authorization page.
     *
     * @return \Cake\Http\Response
     */
    public function login(): Response
    {
        $owner = OwnerResolver::resolve($this->request);
        if ($owner === null) {
            Log::warning('Eventbrite OAuth refused: no owner resolved');

            return $this->failed('no_owner', 403);
        }

        try {
            $oauth = $this->oauth();
            $state = $oauth->newState($this->sessionId());
        } catch (RuntimeException $e) {
            Log::error('Eventbrite OAuth refused: {message}', ['message' => $e->getMessage()]);

            return $this->failed('oauth_not_configured', 500);
        }

        return $this->redirectResponse($oauth->authorizeUrl($state));
    }

    /**
     * Exchange the authorization code and store the connected account.
     *
     * @return \Cake\Http\Response
     */
    public function callback(): Response
    {
        $params = $this->request->getQueryParams();
        if (isset($params['error'])) {
            Log::warning('Eventbrite OAuth failed: {description}', [
                'description' => (string)($params['error_description'] ?? $params['error']),
            ]);

            return $this->failed('eventbrite_error');
        }

        $code = $params['code'] ?? null;
        $state = $params['state'] ?? null;
        if (!is_string($code) || $code === '' || !is_string($state) || $state === '') {
            return $this->failed('invalid_request');
        }

        $owner = OwnerResolver::resolve($this->request);
        if ($owner === null) {
            Log::warning('Eventbrite OAuth refused: no owner resolved on callback');

            return $this->failed('no_owner', 403);
        }

        $oauth = $this->oauth();
        if (!$oauth->verifyState($state, $this->sessionId())) {
            Log::info('Eventbrite OAuth failed: state verification');

            return $this->failed('invalid_state', 403);
        }

        try {
            $tokenResponse = $oauth->exchangeCode($code);
            $token = (string)($tokenResponse['access_token'] ?? '');
            if ($token === '') {
                throw new RuntimeException('The token response contained no access_token.');
            }
            // Connecting stores the token only after it is proven to work.
            $me = $this->factory()->forToken($token)->me();
            $this->connections()->connectFromResponse($owner, $tokenResponse, $me);
        } catch (RuntimeException $e) {
            // EventbriteApiException extends RuntimeException; the message
            // never contains the token, only the status and error body.
            Log::error('Eventbrite OAuth failed: {message}', ['message' => $e->getMessage()]);

            return $this->failed('connect_failed', 502);
        }

        $connected = (string)Configure::read('CakeEventbrite.redirects.connected', '/');

        return $this->redirectResponse($connected);
    }

    /**
     * The session-bound visitor value for OAuth state. The session is
     * started here, after which Session::id() identifies this browser
     * until the callback (docs/eventbrite-plugin-design.md).
     *
     * @return string
     */
    private function sessionId(): string
    {
        $session = $this->request->getSession();
        if (!$session->started()) {
            $session->start();
        }

        return $session->id();
    }

    /**
     * The OAuth service for this host's configuration.
     *
     * @return \TheMusicDev\Eventbrite\EventbriteOAuth
     * @throws \RuntimeException When the OAuth settings or the encryption key are missing.
     */
    private function oauth(): EventbriteOAuth
    {
        $oauth = new EventbriteOAuth(
            new Client(),
            new RequestFactory(),
            new StreamFactory(),
            (string)Configure::read('CakeEventbrite.oauth.clientId', ''),
            (string)Configure::read('CakeEventbrite.oauth.clientSecret', ''),
            (string)Configure::read('CakeEventbrite.oauth.redirectUri', ''),
            EventbriteCrypto::stateSalt(),
        );

        return $oauth;
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
     * Redirect to the configured failure target with an ?error= code. The
     * target comes from config, never from request input (no open redirect).
     *
     * @param string $code A stable error code for the host to display.
     * @param int $status The HTTP status for the redirect response.
     * @return \Cake\Http\Response
     */
    private function failed(string $code, int $status = 302): Response
    {
        $target = (string)Configure::read('CakeEventbrite.redirects.failed', '/');
        $url = $target . (str_contains($target, '?') ? '&' : '?') . http_build_query(['error' => $code]);

        return $this->redirectResponse($url, $status);
    }

    /**
     * A redirect response, without the controller's null edge (the controller
     * state is updated so a redirect is never missed).
     *
     * @param string $url The target to redirect to.
     * @param int $status The HTTP status for the redirect.
     * @return \Cake\Http\Response
     */
    private function redirectResponse(string $url, int $status = 302): Response
    {
        $response = $this->response
            ->withStatus($status)
            ->withLocation($url);
        $this->response = $response;

        return $response;
    }
}
