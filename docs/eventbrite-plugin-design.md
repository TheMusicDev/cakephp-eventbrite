# TheMusicDev/CakeEventbrite: plugin design

Design of record: why the plugin is shaped this way. Usage belongs in the [README](../README.md). This was written before
the build, so update it when reality disagrees.

## Why a plugin

`themusicdev/eventbrite` is deliberately framework-agnostic. Each CakePHP app would otherwise rebuild OAuth routes, token
storage, a webhook receiver and PSR wiring, and those are the parts where a small mistake has security consequences.

## Not in scope

- **API client code.** It stays in `themusicdev/eventbrite`. Gaps found here get fixed there first.
- **Local copies of Eventbrite data.** Tables for events, orders and attendees vary by app, so apps build them from webhook
  events. Revisit this if a second app needs the same sync code.
- **Screens and templates.** Host apps own these, with optional copies in `examples/`.
- **Token refresh.** Eventbrite documents re-running the flow when a token stops working. Add columns for refresh tokens or
  expiry only if a live token response shows they are needed.

## Names

The plugin naming pattern would give `themusicdev/eventbrite` and `TheMusicDev\Eventbrite`, which the API client already
uses. So the package keeps the `cakephp-` prefix, matching the repo, and the namespace is `TheMusicDev\CakeEventbrite`. The
package name must be final before the first Packagist submission, because renaming later means abandoning the old package.

## Pieces

### `Model/Table/EventbriteConnectionsTable`

One row per connected Eventbrite account:

| Column | Notes |
|---|---|
| `owner_key` | Opaque string chosen by the host, such as `user:42` or `site`. Not a foreign key, so any app schema fits. Unique. |
| `access_token` | Encrypted text. |
| `webhook_secret` | Random, unique. The path segment that identifies this connection on webhook deliveries. |
| `eventbrite_user_id` | From `me()` at connect time. Note that this is not the organization id. |
| `created`, `modified` | |

Reconnecting replaces only that owner's row. A single-row "latest token" design would let one account's login destroy
everyone else's connection, which is why multi-connection storage is the base case even for one-account apps.

### `EventbriteClientFactory`: the main seam

- `forConnection(EventbriteConnection)`, `forToken(string)`, and `forPrivateToken()` for apps that skip OAuth.
- The factory is stateless and small, so the plugin does not force container wiring; controllers and commands build one
  where needed, and hosts are free to register it in their container.
- Wires CakePHP's PSR implementations: `Cake\Http\Client` (PSR-18), `Cake\Http\RequestFactory` and `Cake\Http\StreamFactory`
  (PSR-17), `Cache::pool()` (PSR-16) and `Log::engine()` (PSR-3). Config keys: `CakeEventbrite.cache` holds a PSR-16 cache
  config *name* (null for no cache) and `CakeEventbrite.logger` a PSR-3 channel *name*.
- **No retries outside the CLI.** The client's 429 backoff calls `sleep()`, up to 7 seconds in total, which would hold a web
  worker. In a webhook request a failure returns 502, and Eventbrite retries instead.
- **No cache by default.** Responses contain attendee names and emails, and hosts should choose where those are stored.

### OAuth routes

`GET /eventbrite/oauth/login` and `GET /eventbrite/oauth/callback`, shipped by the plugin so that hosts mount them by loading
it.

- **`ownerResolver` is required.** The controllers can't inherit the host's `AppController`, so its authentication doesn't
  apply. Without a resolver that returns an owner, the routes refuse the request. Otherwise any visitor could connect an
  account, or replace one.
- **State is bound to the session.** Use `newState(Session::id())` from the client, after starting the session. This blocks
  login CSRF, where a victim is tricked into opening an attacker's callback URL.
- **The owner is re-resolved on the callback**, never carried in a URL. Connecting saves the token only after `me()` succeeds
  with it.
- **No templates.** The callback redirects to `redirects.connected` or `redirects.failed`, adding an error code on failure.
  Those targets come from config, never from request input, so there is no open redirect.

### Webhook intake

`POST /eventbrite/webhook/{secret}`.

Eventbrite's deliveries carry no signature, and the payload is only a pointer, `{"api_url": "..."}`. So:

- **A per-connection secret in the path** finds the connection, and therefore the token to fetch with. It also follows
  Eventbrite's advice to use a private URL, since an unpublished endpoint keeps out bogus requests.
- **The payload is never trusted.** The plugin fetches `api_url` through the client's `fetchUrl()`, which refuses anything
  outside the API and skips the cache, so it never sees a pre-change snapshot.
- **The plugin dispatches, and the app stores.** After fetching, it fires `CakeEventbrite.Webhook.received` with the connection,
  kind (`event`, `order`, `attendee` and so on, parsed from the URL), `apiUrl` and `object`.

| Situation | Response | Effect |
|---|---|---|
| Handled | 200 | Done |
| Unknown secret | 404 | Refused |
| Missing or foreign `api_url` | 400 | Refused |
| Fetch failed | 502 | Eventbrite retries |
| Listener threw | 500 | Eventbrite retries |

Eventbrite retries non-2xx every ten minutes, up to ten more attempts, so listeners must be idempotent. **Never return 410**: it
automatically unsubscribes the endpoint.

### Registering webhooks

A small service builds the endpoint URL from `webhook.baseUrl` and the connection's secret, then calls the client's
`ensureWebhooks()` and `disableWebhooks()` for a given organization. Confirmed against the live API: `ensureWebhooks()` is
idempotent, and organization-wide webhooks report `event_id: null`. Check-in actions are named `barcode.*`, not `attendee.*`.

### Commands

`bin/cake eventbrite me|events|event|webhooks`, for operating and debugging a connection. They never print tokens. There is no
sync command, because storage is app-side. All take `--owner=<key>` to operate on a stored connection instead of the
configured private token.

The spaces in the names are real: Cake's CommandRunner resolves multi-token names (`longestCommandName()`), so the plugin's
`console()` hook registers four commands under one prefix without a `MultiCommand` dispatcher — Cake 5 has none.

### Config

The standard plugin pattern: `config/app_default.php` holds defaults, and `config/bootstrap.php` merges them under host values, so
hosts always win. Credentials come from `env()`. `encryptionKey` has no fallback to `Security.salt`, because rotating the salt
would then silently destroy every stored token.

## Token encryption

`Security::encrypt()` with `CakeEventbrite.encryptionKey`, stored as base64 in a text column. Saving without a key throws. The
entity hides `access_token` and `webhook_secret` from serialization and debug output. The OAuth state signing secret is derived
from the same key with an HMAC, so hosts have one secret to manage. Internally both the encryption key and the HMAC salt are
32-byte derivations *of the host key* — token encryption depends only on the host key, never on `Security.salt`, and hosts
may set a key of any length (Security::encrypt would demand exactly 32 bytes otherwise).

## Testing

PHPUnit against MariaDB, in the plugin, with no dependency on a host app. HTTP uses `Cake\Http\Client::addMockResponse()`, so tests
also exercise the real CakePHP PSR implementations.

Tests must cover:

- The API client working through CakePHP's real PSR classes: bearer header, JSON POST body, and reading the full response body.
- A state from one session failing in another, and a missing `ownerResolver` refusing the request.
- Reconnecting one owner leaving other owners' rows alone.
- The raw database column not containing the token, and serialized entities not exposing it.
- Every row of the webhook response table above, and no response ever being 410.
- No token appearing in responses, logs or command output.

## Open questions

- Whether Eventbrite's token response includes anything worth storing beyond `access_token`. Check during the first live OAuth run.
  (The full flow is still unexercised against a live app — no Eventbrite app registration with a public redirect URI yet; the
  exchange path is integration-tested with mocked HTTP only. `/users/me/` was confirmed live through the plugin.)
- Whether a webhook created with `event_id` really delivers only for that event. The API stores the scope, but delivery hasn't been
  observed. It needs real orders on two events.

## Reality notes (build of 2026-11-06/07)

Things the design text above glosses over, found during the build:

- **`Migrations\AbstractMigration` no longer exists** in cakephp/migrations 5 — plugin migrations extend
  `Migrations\BaseMigration`, and the migrator refuses the legacy class by scanning the file text.
- **`ServerRequest::input()` is gone in Cake 5.4**; the webhook controller decodes the request body itself with
  `json_decode((string)$request->getBody(), true)`.
- **`Controller::redirect()` returns `?Response`**; the OAuth controller builds its redirect responses directly
  (`Response::withLocation()`) so action signatures stay non-nullable.
- **Token encryption derives its HMAC salt** (and the 32-byte key) from the host key rather than falling back to
  `Security::getSalt()` — in a bare plugin test environment `Security.salt` is not even set, and this also makes
  encryption independent of salt rotation (see Token encryption above).
- **Integration-test sessions**: `session_id('cli')` from the test bootstrap gives every request the same PHP session id, so
  the session-bound OAuth state is deterministic in tests (mint a state for `'cli'`, or a literal other visitor to prove
  cross-session rejection).
- **`bin/cake eventbrite <subcommand>` works through plain space-named registration** (see Commands).
- PHPUnit 13 supports only attribute data providers (`#[DataProvider]`), no docblock annotations.
