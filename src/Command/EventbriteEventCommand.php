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
 * Print one event: bin/cake eventbrite event <id> [--attendees]
 */
final class EventbriteEventCommand extends Command
{
    use EventbriteClientTrait;

    /**
     * @param \Cake\Console\ConsoleOptionParser $parser The parser to build.
     * @return \Cake\Console\ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription('Print an Eventbrite event as JSON, optionally with its attendees.')
            ->addArgument('id', [
                'help' => 'The Eventbrite event ID.',
                'required' => true,
            ])
            ->addOption('attendees', [
                'help' => 'Also fetch every attendee, following pagination. Output contains personal data.',
                'boolean' => true,
                'default' => false,
            ])
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
        $id = (string)$args->getArgument('id');

        try {
            $event = $this->client($args)->event($id);
            if ($args->getOption('attendees')) {
                $attendees = [];
                foreach ($this->client($args)->eventAttendees($id) as $attendee) {
                    $attendees[] = $attendee;
                }
                $event['attendees'] = $attendees;
            }
        } catch (EventbriteApiException | RuntimeException $e) {
            $io->err($e->getMessage());

            return static::CODE_ERROR;
        }
        $io->out(EventbriteJson::encode($event));

        return static::CODE_SUCCESS;
    }
}
