<?php
declare(strict_types=1);

namespace TheMusicDev\CakeEventbrite\Command;

/**
 * JSON output for the eventbrite commands.
 */
final class EventbriteJson
{
    /**
     * @param array<mixed> $data The data to print.
     * @return string
     */
    public static function encode(array $data): string
    {
        return (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
