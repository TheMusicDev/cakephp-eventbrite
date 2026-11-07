<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * The plugin's single table: one row per connected Eventbrite account.
 * owner_key is the app-chosen key (unique), access_token is encrypted text,
 * webhook_secret is the unique path secret identifying the connection on
 * webhook deliveries (Eventbrite deliveries carry no signature).
 */
final class CreateEventbriteConnections extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('eventbrite_connections')
            ->addColumn('owner_key', 'string', ['limit' => 191, 'null' => false])
            ->addColumn('access_token', 'text', ['null' => false])
            ->addColumn('webhook_secret', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('eventbrite_user_id', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['owner_key'], ['unique' => true])
            ->addIndex(['webhook_secret'], ['unique' => true])
            ->create();
    }
}
