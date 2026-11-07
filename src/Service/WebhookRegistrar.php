<?php
declare(strict_types=1);

namespace TheMusicDev\CakeEventbrite\Service;

use Cake\Core\Configure;
use RuntimeException;
use TheMusicDev\CakeEventbrite\EventbriteClientFactory;
use TheMusicDev\CakeEventbrite\Model\Entity\EventbriteConnection;
use TheMusicDev\Eventbrite\EventbriteClient;

/**
 * Registers and removes webhooks on an organization, pointing at the
 * plugin's intake endpoint: the public base URL from config plus the
 * connection's secret. Confirmed against the live API: ensureWebhooks() is
 * idempotent and organization-wide webhooks report event_id: null (docs/
 * eventbrite-plugin-design.md). Registration reads its listing uncached —
 * the endpoint's enabled state is the source of truth.
 */
final class WebhookRegistrar
{
    /**
     * @param \TheMusicDev\CakeEventbrite\EventbriteClientFactory $factory Builds a client for the connection.
     */
    public function __construct(
        private EventbriteClientFactory $factory,
    ) {
    }

    /**
     * The endpoint URL Eventbrite should POST to.
     *
     * @param \TheMusicDev\CakeEventbrite\Model\Entity\EventbriteConnection $connection The connection to receive for.
     * @return string
     * @throws \RuntimeException When CakeEventbrite.webhook.baseUrl is not configured.
     */
    public function endpointUrl(EventbriteConnection $connection): string
    {
        $baseUrl = rtrim((string)Configure::read('CakeEventbrite.webhook.baseUrl', ''), '/');
        if ($baseUrl === '') {
            throw new RuntimeException(
                'No webhook base URL configured. Set CakeEventbrite.webhook.baseUrl '
                . '(EVENTBRITE_WEBHOOK_BASE_URL) to the public base URL of the host app.',
            );
        }

        return $baseUrl . '/eventbrite/webhook/' . $connection->webhook_secret;
    }

    /**
     * Make sure Eventbrite delivers each enrolled action to this
     * connection's endpoint (one webhook per action, idempotent).
     *
     * @param \TheMusicDev\CakeEventbrite\Model\Entity\EventbriteConnection $connection The connection to receive for.
     * @param string $orgId Eventbrite organization id (not the user id).
     * @param array<string>|null $actions Action codes; defaults to config.
     * @return array{existing: int, created: int} Counts from the client.
     */
    public function ensure(EventbriteConnection $connection, string $orgId, ?array $actions = null): array
    {
        return $this->client($connection)->ensureWebhooks(
            $orgId,
            $this->endpointUrl($connection),
            $actions ?? (array)Configure::read('CakeEventbrite.webhook.actions', []),
        );
    }

    /**
     * Delete every webhook pointing at this connection's endpoint.
     *
     * @param \TheMusicDev\CakeEventbrite\Model\Entity\EventbriteConnection $connection The connection to stop receiving for.
     * @param string $orgId Eventbrite organization id.
     * @return int Number of webhooks deleted.
     */
    public function disable(EventbriteConnection $connection, string $orgId): int
    {
        return $this->client($connection)->disableWebhooks($orgId, $this->endpointUrl($connection));
    }

    /**
     * @param \TheMusicDev\CakeEventbrite\Model\Entity\EventbriteConnection $connection The connection.
     * @return \TheMusicDev\Eventbrite\EventbriteClient
     */
    private function client(EventbriteConnection $connection): EventbriteClient
    {
        return $this->factory->forConnection($connection);
    }
}
