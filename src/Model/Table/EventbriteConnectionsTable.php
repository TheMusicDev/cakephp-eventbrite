<?php
declare(strict_types=1);

namespace TheMusicDev\CakeEventbrite\Model\Table;

use Cake\Event\EventInterface;
use Cake\ORM\Table;
use RuntimeException;
use TheMusicDev\CakeEventbrite\EventbriteCrypto;
use TheMusicDev\CakeEventbrite\Model\Entity\EventbriteConnection;

/**
 * One row per connected Eventbrite account. Reconnecting an owner replaces
 * only that owner's row; other owners are never touched — a single-row
 * "latest token" design would let one account's login destroy everyone
 * else's connection (docs/eventbrite-plugin-design.md).
 *
 * @method \TheMusicDev\CakeEventbrite\Model\Entity\EventbriteConnection get(mixed $primaryKey, array<string, mixed>|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \TheMusicDev\CakeEventbrite\Model\Entity\EventbriteConnection newEntity(array<string, mixed> $data, array<string, mixed> $options = [])
 */
final class EventbriteConnectionsTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('eventbrite_connections');
        $this->setPrimaryKey('id');
        $this->setEntityClass(EventbriteConnection::class);
        $this->addBehavior('Timestamp');

        $this->getValidator('default')
            ->requirePresence('owner_key')
            ->notBlank('owner_key', 'The owner key is required.')
            ->maxLength('owner_key', 191)
            ->requirePresence('access_token', 'create')
            ->notBlank('access_token', 'The access token cannot be blank.')
            ->requirePresence('eventbrite_user_id', 'create')
            ->notBlank('eventbrite_user_id', 'The Eventbrite user id cannot be blank.');
    }

    /**
     * The connected row for an owner, decryptable only on demand.
     *
     * @param string $ownerKey The host-chosen owner key.
     * @return \TheMusicDev\CakeEventbrite\Model\Entity\EventbriteConnection|null
     */
    public function forOwner(string $ownerKey): ?EventbriteConnection
    {
        /** @var \TheMusicDev\CakeEventbrite\Model\Entity\EventbriteConnection|null */
        return $this->find()->where(['owner_key' => $ownerKey])->first();
    }

    /**
     * The connection whose webhook secret identifies an incoming delivery.
     *
     * @param string $secret The {secret} path segment from the webhook URL.
     * @return \TheMusicDev\CakeEventbrite\Model\Entity\EventbriteConnection|null
     */
    public function forWebhookSecret(string $secret): ?EventbriteConnection
    {
        if ($secret === '') {
            return null;
        }

        /** @var \TheMusicDev\CakeEventbrite\Model\Entity\EventbriteConnection|null */
        return $this->find()->where(['webhook_secret' => $secret])->first();
    }

    /**
     * Store an OAuth exchange result for an owner, replacing only that
     * owner's row. The connection is saved only after the caller has proven
     * the token works (me() through it) — no dead tokens get stored.
     *
     * @param string $ownerKey The host-chosen owner key.
     * @param array<string, mixed> $tokenResponse The decoded oauth/token response.
     * @param array<string, mixed> $me The decoded /users/me/ from the new token.
     * @return \TheMusicDev\CakeEventbrite\Model\Entity\EventbriteConnection
     * @throws \RuntimeException When no key is configured.
     */
    public function connectFromResponse(
        string $ownerKey,
        array $tokenResponse,
        array $me,
    ): EventbriteConnection {
        $ownerKey = trim($ownerKey);
        if ($ownerKey === '') {
            throw new RuntimeException('The owner key cannot be blank.');
        }

        $connection = $this->forOwner($ownerKey) ?? $this->newEntity([
            'owner_key' => $ownerKey,
            'webhook_secret' => EventbriteCrypto::webhookSecret(),
        ]);
        $connection->access_token = EventbriteCrypto::encryptToken(
            (string)($tokenResponse['access_token'] ?? ''),
        );
        $connection->eventbrite_user_id = (string)($me['id'] ?? '');

        return $this->saveOrFail($connection);
    }

    /**
     * A new connection gets its webhook secret here, so rows created outside
     * connectFromResponse() are still webhook-addressable. Random, never
     * derived from a token or owner key.
     *
     * @param \Cake\Event\EventInterface<\TheMusicDev\CakeEventbrite\Model\Table\EventbriteConnectionsTable> $event The beforeSave event.
     * @param \TheMusicDev\CakeEventbrite\Model\Entity\EventbriteConnection $entity The entity about to be saved.
     * @return void
     */
    public function beforeSave(EventInterface $event, EventbriteConnection $entity): void
    {
        if ($entity->isNew() && $entity->webhook_secret === null) {
            $entity->webhook_secret = EventbriteCrypto::webhookSecret();
        }
    }
}
