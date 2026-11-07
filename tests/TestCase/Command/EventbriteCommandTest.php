<?php
declare(strict_types=1);

namespace TheMusicDev\CakeEventbrite\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\Http\Client\Response as ClientResponse;
use Cake\Http\TestSuite\HttpClientTrait;
use Cake\TestSuite\TestCase;
use TestApp\Application;

/**
 * The plugin commands: discovery under the `eventbrite` prefix, output and
 * error paths. No token may appear in any output.
 */
final class EventbriteCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;
    use HttpClientTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->configApplication(Application::class, [CONFIG]);
        Configure::write('CakeEventbrite.privateToken', 'private-token-value');
        Configure::delete('CakeEventbrite.cache');
        Configure::delete('CakeEventbrite.logger');
    }

    public function testMeIsDiscoveredAndPrintsTheUser(): void
    {
        $this->mockClientGet(
            'https://www.eventbriteapi.com/v3/users/me/',
            $this->json(200, ['id' => '77', 'name' => 'Commander']),
        );

        $this->exec('eventbrite me');

        $this->assertExitSuccess();
        $this->assertOutputContains('"name": "Commander"');
        $this->assertOutputNotContains('private-token-value');
    }

    public function testMeWithoutTokenAnswersError(): void
    {
        Configure::write('CakeEventbrite.privateToken', '');

        $this->exec('eventbrite me');

        $this->assertExitError();
        $this->assertErrorContains('EVENTBRITE_TOKEN');
        $this->assertStringNotContainsString('private-token-value', implode('', $this->_err->messages()));
    }

    public function testMeErrorPathSurfacesApiFailure(): void
    {
        $this->mockClientGet(
            'https://www.eventbriteapi.com/v3/users/me/',
            $this->json(401, ['error_description' => 'The OAuth token you provided was invalid']),
        );

        $this->exec('eventbrite me');

        $this->assertExitError();
        $this->assertErrorContains('invalid');
    }

    public function testEventsWithoutOrgIdRefuses(): void
    {
        $this->exec('eventbrite events');

        $this->assertExitError();
    }

    public function testUnknownSubcommandRefused(): void
    {
        // An unrecognized word under the prefix prints the command list.
        $this->exec('eventbrite sync');

        $this->assertOutputContains('eventbrite me', 'the help lists what does exist');
        $this->assertOutputNotContains('sync');
    }

    /**
     * A mocked Cake HTTP JSON response with the given status.
     *
     * @param int $code The HTTP status.
     * @param array<mixed> $data The JSON body.
     * @return \Cake\Http\Client\Response
     */
    private function json(int $code, array $data): ClientResponse
    {
        return new ClientResponse(["HTTP/1.1 $code", 'content-type: application/json'], (string)json_encode($data));
    }
}
