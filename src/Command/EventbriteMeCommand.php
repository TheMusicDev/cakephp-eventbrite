<?php
declare(strict_types=1);

namespace TheMusicDev\CakeEventbrite\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use RuntimeException;
use TheMusicDev\Eventbrite\EventbriteApiException;

/**
 * Show the authenticated user: bin/cake eventbrite me [--owner=key]
 */
final class EventbriteMeCommand extends Command
{
    use EventbriteClientTrait;

    /**
     * @param \Cake\Console\ConsoleOptionParser $parser The parser to build.
     * @return \Cake\Console\ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription('Show the Eventbrite user the token belongs to (users/me).')
            ->addOption('owner', EventbriteOptionDocs::owner());

        return $parser;
    }

    /**
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io.
     * @return int The exit code.
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        try {
            $io->out(EventbriteJson::encode($this->client($args)->me()));
        } catch (EventbriteApiException | RuntimeException $e) {
            $io->err($e->getMessage());

            return static::CODE_ERROR;
        }

        return static::CODE_SUCCESS;
    }
}
