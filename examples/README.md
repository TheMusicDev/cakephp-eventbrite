# Examples

Copyable wiring for a host app. The plugin ships no screens — these are the smallest complete versions of the two
pieces every host adds. Copy them into your app's namespace and adjust to your tables.

## Webhook listener

Apps store locally what Eventbrite tells them. A listener under your app's `src/` (registered once, e.g. in
`Application::bootstrap()` or a service):

```php
// src/Event/EventbriteWebhookListener.php
namespace App\Event;

use Cake\Event\Event;
use Cake\Event\EventListenerInterface;

final class EventbriteWebhookListener implements EventListenerInterface
{
    /**
     * @return array<string, callable|array{callable, string}>
     */
    public function implementedEvents(): array
    {
        return ['CakeEventbrite.Webhook.received' => 'handle'];
    }

    /**
     * $event->getData():
     *  - connection: TheMusicDev\CakeEventbrite\Model\Entity\EventbriteConnection
     *  - kind: 'event' | 'order' | 'attendee' | ...
     *  - apiUrl: the URL the object was fetched from
     *  - object: the fresh API object (do not trust the webhook payload instead)
     *
     * Must be idempotent: Eventbrite retries until this answers 2xx.
     *
     * @param \Cake\Event\Event $event The delivery event.
     * @return void
     */
    public function handle(Event $event): void
    {
        $kind = $event->getData('kind');
        $object = $event->getData('object');
        // match ($kind) { 'order' => $this->orders->upsertFromWebhook($object), ... }
        // Refuse (throw) to make Eventbrite retry this delivery.
    }
}
```

## Connecting an account (host screen, host auth)

Your admin controller provides the button; the plugin's routes do the rest:

```php
// In your (authenticated) admin controller:
public function connect(): \Cake\Http\Response
{
    // ownerResolver reads this from the request context.
    Configure::write('CakeEventbrite.ownerResolver', fn (): string => 'user:' . $this->Auth->user('id'));

    return $this->redirect($this->Url->build('/eventbrite/oauth/login'));
}
```

After the callback the connection row exists for that owner; list them with the
`eventbrite_connections` table and show `eventbrite_user_id` (the account that connected).

## Registering webhooks per connection

```php
use TheMusicDev\CakeEventbrite\EventbriteClientFactory;
use TheMusicDev\CakeEventbrite\Service\WebhookRegistrar;

$registrar = new WebhookRegistrar(new EventbriteClientFactory());
// $orgId from your app (Eventbrite organization id, not the user id):
$counts = $registrar->ensure($connection, $orgId);   // ['existing' => n, 'created' => n]
```

`ensure()` reads the live listing first, so it is safe to run repeatedly (also from
`bin/cake eventbrite webhooks <orgId> --owner=<key> --ensure`).
