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
 * Print an organization's events: bin/cake eventbrite events <orgId> [--status=s] [--paginate]
 */
final class EventbriteEventsCommand extends Command
{
    use EventbriteClientTrait;

    /**
     * @param \Cake\Console\ConsoleOptionParser $parser The parser to build.
     * @return \Cake\Console\ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription("Print an Eventbrite organization's events as JSON.")
            ->addArgument('orgId', [
                'help' => 'The Eventbrite organization ID.',
                'required' => true,
            ])
            ->addOption('status', [
                'help' => 'Filter by status (draft, live, started, ended, canceled).',
                'choices' => ['draft', 'live', 'started', 'ended', 'canceled'],
                'default' => null,
            ])
            ->addOption('paginate', [
                'help' => 'Walk every page (continuation) instead of printing only the first one.',
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
        $params = [];
        $status = $args->getOption('status');
        if ($status !== null && $status !== '') {
            $params['status'] = $status;
        }
        $path = 'organizations/' . (string)$args->getArgument('orgId') . '/events/';

        try {
            if ($args->getOption('paginate')) {
                $events = [];
                foreach ($this->client($args)->paginate($path, 'events', $params) as $event) {
                    $events[] = $event;
                }
                $out = $events;
            } else {
                $out = $this->client($args)->get($path, $params);
            }
        } catch (EventbriteApiException | RuntimeException $e) {
            $io->err($e->getMessage());

            return static::CODE_ERROR;
        }
        $io->out(EventbriteJson::encode($out));

        return static::CODE_SUCCESS;
    }
}
