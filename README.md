# TheMusicDev/CakeEventbrite

Eventbrite for CakePHP 5 apps: connect Eventbrite accounts through OAuth, keep their tokens encrypted, receive webhooks
as CakePHP events, and get an API client for any connection. The plugin owns connections, routes and webhook intake;
**your app owns the screens and decides what to do with the data**.

It is built on [`themusicdev/eventbrite`](https://github.com/TheMusicDev/eventbrite), the framework-agnostic API client.

Requires PHP 8.2+ and CakePHP 5.2+.

## Why this plugin

The API client knows nothing about CakePHP, so every app would otherwise rebuild the same parts: the OAuth routes, token
storage, the webhook receiver, and the wiring to CakePHP's HTTP client, cache and logger. This plugin is that shared
layer, and it gets the security-sensitive parts right once. Design and decisions:
[`docs/eventbrite-plugin-design.md`](docs/eventbrite-plugin-design.md).

## Install

```bash
composer require themusicdev/cakephp-eventbrite
bin/cake plugin load TheMusicDev/CakeEventbrite
bin/cake migrations migrate -p TheMusicDev/CakeEventbrite
```

This creates the `eventbrite_connections` table in the app's default connection (the plugin owns the schema, the app
owns the storage engine).

## Use

### Connecting accounts

The plugin ships the routes `/eventbrite/oauth/login` and `/eventbrite/oauth/callback` — loading the plugin mounts
them. Two things must be configured or the routes refuse every request (connecting fails closed):

- `CakeEventbrite.ownerResolver` — a `fn (ServerRequest $request): ?string` returning the owner key to connect (for
  example `'user:' . $user->id`). Set it from your authentication context; when it returns null, no connection happens.
  Plugin controllers don't inherit your `AppController`, so its authentication does not protect these routes.
- `CakeEventbrite.encryptionKey` — the key tokens are encrypted with.

The callback redirects to `CakeEventbrite.redirects.connected` (`?error=` + a code on failure), and stores the row only
after the new token has been proven against `/users/me/`.

### Using a connection's API access

```php
use TheMusicDev\CakeEventbrite\EventbriteClientFactory;

// Inject the factory; there is one client per token, not one per app.
$client = $factory->forConnection($connection);

foreach ($client->organizationEvents($orgId) as $event) {
    // ...
}
```

`forToken($token)` builds a client for an ad-hoc token; `forPrivateToken()` for apps that skip OAuth entirely
(`CakeEventbrite.privateToken`, i.e. `EVENTBRITE_TOKEN`). It wires `Cake\Http\Client` (PSR-18), Cake's PSR-17 factories,
an optional PSR-16 cache and PSR-3 logger. **No 429 retries outside the CLI**, because the client's backoff sleeps — a
webhook failure answers 502 instead, and Eventbrite retries.

### Receiving webhooks (no registration work)

Deliveries arrive at `POST /eventbrite/webhook/{secret}`, where `{secret}` is the connection's private path secret —
Eventbrite's deliveries carry no signature, so the unpublished per-connection URL is the authentication. The controller
fetches the pointed object through `fetchUrl()` (never trusting the payload, always bypassing the cache) and fires:

```php
EventManager::instance()->on('CakeEventbrite.Webhook.received', function (EventInterface $event): void {
    // $event->getData(): connection, kind ('event', 'order', 'attendee', ...), apiUrl, object
    // Store the object here. Idempotent: non-2xx answers make Eventbrite retry.
});
```

Non-2xx makes Eventbrite retry every ten minutes (ten attempts); an exception in a listener counts as failure (500).
The plugin never returns 410, which would unsubscribe the endpoint.

### Registering webhooks on an organization

```php
use TheMusicDev\CakeEventbrite\Service\WebhookRegistrar;

$registrar = new WebhookRegistrar($factory);
$registrar->ensure($connection, $orgId);      // idempotent; one webhook per action
$registrar->endpointUrl($connection);         // the URL Eventbrite should POST to
$registrar->disable($connection, $orgId);     // remove this connection's webhooks
```

The endpoint URL comes from `CakeEventbrite.webhook.baseUrl` plus the connection's secret. Default actions are
`attendee.updated`, `barcode.*` (check-in), `order.*` and `event.*` — overridable in config.

### Commands

```bash
bin/cake eventbrite me                       # who the token belongs to
bin/cake eventbrite events <orgId> [--status=live] [--paginate]
bin/cake eventbrite event <id> [--attendees]
bin/cake eventbrite webhooks <orgId>         # list what is registered
bin/cake eventbrite webhooks <orgId> --owner=<key> [--ensure] [--disable]
```

`--owner=key` operates on a stored connection; without it the commands use the private token. Commands never print
tokens. See `examples/` for copyable wiring.

## Configure (host `config/app.php`)

Host values always win over the plugin's defaults. Every knob below can also come from the environment; `.env.example`
in this repo lists the variable names.

| Key | Purpose |
|---|---|
| `CakeEventbrite.oauth.clientId` / `.clientSecret` / `.redirectUri` | From the [API key page](https://www.eventbrite.com/account-settings/apps). The redirect URI must match exactly. |
| `CakeEventbrite.encryptionKey` | Any non-empty string; required, with no fallback (`EVENTBRITE_ENCRYPTION_KEY`). Rotate it and stored tokens become unreadable — those accounts reconnect. |
| `CakeEventbrite.ownerResolver` | `fn (ServerRequest): ?string`. Says who may connect, and as which owner. Unset or null refuses every connection. |
| `CakeEventbrite.redirects.connected` / `.failed` | Where the browser goes after the OAuth callback. |
| `CakeEventbrite.webhook.baseUrl` / `.actions` | Public base URL for webhook endpoints (tunnels and proxies), and which actions to register (`EVENTBRITE_WEBHOOK_BASE_URL`). |
| `CakeEventbrite.privateToken` | Optional, for single-account apps that skip OAuth. |
| `CakeEventbrite.cache` | Optional PSR-16 cache config **name** for GET responses. Off by default: responses contain attendee personal data, so hosts choose where those are stored. |
| `CakeEventbrite.logger` | Optional PSR-3 channel name (`Cake\Log\Log::engine()`) for 429 backoff warnings. |

## Gotchas

- **Exempt the webhook route from CSRF.** Your app owns the CSRF middleware, and Eventbrite's POSTs carry no token. Skip
  the check for paths starting with `/eventbrite/webhook/`. Apply the same exemption to any global authentication
  middleware.
- **The redirect back from Eventbrite needs the session cookie.** Use `SameSite=Lax`, which is CakePHP's default, not
  `Strict`. A `Session::renew()` between the redirect and the callback, such as logging in mid-flow, makes the OAuth
  state fail (state is bound to the session id and expires after 10 minutes).
- **Webhooks are delivered more than once.** A non-2xx response makes Eventbrite retry, so listeners must be idempotent.
  An exception in a listener counts as a failed delivery.
- **Organization id ≠ user id.** `eventbrite_user_id` on a connection is the user from `/users/me/`; webhook
  registration needs an organization id. An account without an organization returns 404s using the user id instead —
  create one in the Eventbrite dashboard.
- **Plugin controllers don't inherit your `AppController`.** Authentication set up there doesn't protect plugin routes,
  which is why `ownerResolver` exists.

## Development

```bash
docker compose up -d dbtest
composer install
composer check                     # tests, then coding standard, then static analysis
vendor/bin/phpunit --filter=SomeTest
```

## License

[MIT](LICENSE)
