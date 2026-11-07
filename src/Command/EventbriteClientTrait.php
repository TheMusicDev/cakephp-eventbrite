<?php
declare(strict_types=1);

namespace TheMusicDev\CakeEventbrite\Command;

use Cake\Console\Arguments;
use Cake\ORM\TableRegistry;
use RuntimeException;
use TheMusicDev\CakeEventbrite\EventbriteClientFactory;
use TheMusicDev\Eventbrite\EventbriteClient;

/**
 * Shared token resolution for the eventbrite commands: a stored connection
 * (--owner=key) or, by default, the configured private token. Neither
 * message contains a token.
 */
trait EventbriteClientTrait
{
    /**
     * @param \Cake\Console\Arguments $args The command arguments (--owner optional).
     * @return \TheMusicDev\Eventbrite\EventbriteClient
     * @throws \RuntimeException When no owner matches or no private token is configured.
     */
    private function client(Arguments $args): EventbriteClient
    {
        $owner = (string)($args->getOption('owner') ?? '');
        if ($owner !== '') {
            /** @var \TheMusicDev\CakeEventbrite\Model\Table\EventbriteConnectionsTable $connections */
            $connections = TableRegistry::getTableLocator()
                ->get('TheMusicDev/CakeEventbrite.EventbriteConnections');
            $connection = $connections->forOwner($owner);
            if ($connection === null) {
                throw new RuntimeException('No Eventbrite connection is stored for this owner.');
            }

            return (new EventbriteClientFactory())->forConnection($connection);
        }

        return (new EventbriteClientFactory())->forPrivateToken();
    }
}
