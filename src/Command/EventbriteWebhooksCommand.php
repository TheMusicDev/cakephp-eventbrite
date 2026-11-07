<?php
declare(strict_types=1);

namespace TheMusicDev\CakeEventbrite\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\ORM\TableRegistry;
use RuntimeException;
use TheMusicDev\CakeEventbrite\EventbriteClientFactory;
use TheMusicDev\CakeEventbrite\Model\Entity\EventbriteConnection;
use TheMusicDev\CakeEventbrite\Service\WebhookRegistrar;
use TheMusicDev\Eventbrite\EventbriteApiException;

/**
 * List (and optionally reconcile or remove) an organization's webhooks:
 * bin/cake eventbrite webhooks <orgId> [--owner=key] [--ensure] [--disable] [--actions=codes]
 */
final class EventbriteWebhooksCommand extends Command
{
    use EventbriteClientTrait;

    /**
     * @param \Cake\Console\ConsoleOptionParser $parser The parser to build.
     * @return \Cake\Console\ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription('List the webhooks registered on an Eventbrite organization.')
            ->addArgument('orgId', [
                'help' => 'The Eventbrite organization ID.',
                'required' => true,
            ])
            ->addOption('ensure', [
                'help' => 'Register any missing webhook actions pointing at this connection\'s endpoint '
                    . '(requires --owner; idempotent).',
                'boolean' => true,
                'default' => false,
            ])
            ->addOption('disable', [
                'help' => 'Delete every webhook pointing at this connection\'s endpoint (requires --owner).',
                'boolean' => true,
                'default' => false,
            ])
            ->addOption('actions', [
                'help' => 'Comma-separated action codes for --ensure; defaults to config.',
                'default' => null,
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
        $orgId = (string)$args->getArgument('orgId');
        $owner = (string)($args->getOption('owner') ?? '');

        try {
            $result = [];
            if ($args->getOption('ensure') || $args->getOption('disable')) {
                $registrar = $this->registrar($owner);
                if ($args->getOption('ensure')) {
                    $result['ensure'] = $registrar->ensure(
                        $this->connection($owner),
                        $orgId,
                        $this->actions((string)($args->getOption('actions') ?? '')),
                    );
                }
                if ($args->getOption('disable')) {
                    $result['deleted'] = $registrar->disable($this->connection($owner), $orgId);
                }
            }
            $result['webhooks'] = $this->client($args)->webhooks($orgId)['webhooks'] ?? [];
        } catch (EventbriteApiException | RuntimeException $e) {
            $io->err($e->getMessage());

            return static::CODE_ERROR;
        }
        $io->out(EventbriteJson::encode($result));

        return static::CODE_SUCCESS;
    }

    /**
     * @param string $owner The owner key a stored connection is required for (--ensure/--disable).
     * @return \TheMusicDev\CakeEventbrite\Service\WebhookRegistrar
     * @throws \RuntimeException When --ensure/--disable was given without --owner.
     */
    private function registrar(string $owner): WebhookRegistrar
    {
        if ($owner === '') {
            throw new RuntimeException(
                '--ensure and --disable need --owner (the endpoint URL is built from the stored connection).',
            );
        }

        return new WebhookRegistrar(new EventbriteClientFactory());
    }

    /**
     * @param string $owner The owner key (required by --ensure/--disable).
     * @return \TheMusicDev\CakeEventbrite\Model\Entity\EventbriteConnection
     */
    private function connection(string $owner): EventbriteConnection
    {
        /** @var \TheMusicDev\CakeEventbrite\Model\Table\EventbriteConnectionsTable $connections */
        $connections = TableRegistry::getTableLocator()
            ->get('TheMusicDev/CakeEventbrite.EventbriteConnections');
        $connection = $connections->forOwner($owner);
        if ($connection === null) {
            throw new RuntimeException('No Eventbrite connection is stored for this owner.');
        }

        return $connection;
    }

    /**
     * @param string $raw Comma-separated codes, or empty for the default.
     * @return array<string>|null
     */
    private function actions(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
