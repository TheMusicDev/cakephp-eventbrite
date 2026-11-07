<?php
declare(strict_types=1);

namespace TheMusicDev\CakeEventbrite;

use Cake\Console\CommandCollection;
use Cake\Core\BasePlugin;

/**
 * TheMusicDev/CakeEventbrite: OAuth connections with encrypted token storage,
 * webhook intake and per-token API clients on top of themusicdev/eventbrite.
 *
 * The plugin ships routes (/eventbrite/oauth/*, /eventbrite/webhook/{secret})
 * and commands (bin/cake eventbrite me|events|event|webhooks). No screens:
 * visible UI belongs to the host (docs/eventbrite-plugin-design.md).
 */
final class CakeEventbritePlugin extends BasePlugin
{
    /**
     * Register the commands under the shared `eventbrite` prefix so that
     * `bin/cake eventbrite me` and friends work (auto-discovery would name
     * them with the full plugin prefix instead).
     *
     * @param \Cake\Console\CommandCollection $commands The command collection to add commands into.
     * @return \Cake\Console\CommandCollection
     */
    public function console(CommandCollection $commands): CommandCollection
    {
        return $commands->addMany([
            'eventbrite me' => Command\EventbriteMeCommand::class,
            'eventbrite events' => Command\EventbriteEventsCommand::class,
            'eventbrite event' => Command\EventbriteEventCommand::class,
            'eventbrite webhooks' => Command\EventbriteWebhooksCommand::class,
        ]);
    }
}
