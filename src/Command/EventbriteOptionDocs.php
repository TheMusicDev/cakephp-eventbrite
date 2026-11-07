<?php
declare(strict_types=1);

namespace TheMusicDev\CakeEventbrite\Command;

/**
 * Shared option parser pieces for the eventbrite commands.
 */
final class EventbriteOptionDocs
{
    /**
     * The option selecting a stored connection instead of the private token.
     *
     * @return array<string, mixed>
     */
    public static function owner(): array
    {
        return [
            'help' => 'Operate on the connection stored for this owner key instead of the configured private token.',
            'default' => '',
        ];
    }
}
