<?php
declare(strict_types=1);

namespace TheMusicDev\CakeEventbrite\Test\TestCase\Model\Table;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Error\Debugger;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use RuntimeException;
use TheMusicDev\CakeEventbrite\EventbriteCrypto;
use TheMusicDev\CakeEventbrite\Model\Entity\EventbriteConnection;

/**
 * The connections table: one row per owner, encryption at rest, reconnect
 * isolation, secrets never serialized.
 */
final class EventbriteConnectionsTableTest extends TestCase
{
    private const TEST_KEY = 'table-test-key-0123456789abcdef0123456789abcdef';

    private $connections;

    public function setUp(): void
    {
        parent::setUp();
        Configure::write('CakeEventbrite.encryptionKey', self::TEST_KEY);
        $this->connections = TableRegistry::getTableLocator()
            ->get('TheMusicDev/CakeEventbrite.EventbriteConnections');
        $this->connections->deleteAll([]);
    }

    public function tearDown(): void
    {
        $this->connections->deleteAll([]);
        parent::tearDown();
    }

    public function testConnectStoresEncryptedTokenAndUserId(): void
    {
        $connection = $this->connect('user:1', 'first-oauth-token', '101');

        $this->assertSame('user:1', $connection->owner_key);
        $this->assertSame('101', $connection->eventbrite_user_id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $connection->webhook_secret);
        $this->assertNotSame('first-oauth-token', $connection->access_token, 'the column holds ciphertext');

        $raw = $this->rawAccessToken('user:1');
        $this->assertStringNotContainsString('first-oauth-token', $raw, 'the database column never holds the plain token');
        $this->assertSame('first-oauth-token', EventbriteCrypto::decryptToken($raw), 'the stored ciphertext decrypts back to the token');
    }

    public function testRoundTripDecryptsThroughTheFactoryPath(): void
    {
        $connection = $this->connect('user:2', 'second-token', '102');

        $this->assertSame('second-token', EventbriteCrypto::decryptToken($connection->access_token));
    }

    public function testReconnectReplacesOnlyThatOwner(): void
    {
        $one = $this->connect('user:a', 'token-a', '201');
        $two = $this->connect('user:b', 'token-b', '202');

        $this->connect('user:a', 'token-a2', '205');

        $this->assertSame(2, $this->connections->find()->count(), 'reconnect replaces, never appends');
        $twoAfter = $this->connections->forOwner('user:b');
        $this->assertInstanceOf(EventbriteConnection::class, $twoAfter);
        $this->assertSame($two->webhook_secret, $twoAfter->webhook_secret, 'other owners untouched');
        $this->assertSame('202', $twoAfter->eventbrite_user_id);
        $oneAfter = $this->connections->forOwner('user:a');
        $this->assertInstanceOf(EventbriteConnection::class, $oneAfter);
        $this->assertSame('205', $oneAfter->eventbrite_user_id);
        $this->assertSame('token-a2', EventbriteCrypto::decryptToken($oneAfter->access_token));
        $this->assertSame($one->id, $oneAfter->id, 'same row updated in place');
    }

    public function testConnectionEntityHidesSecretsFromSerialization(): void
    {
        $connection = $this->connect('user:hide', 'hide-this-token', '301');

        $this->assertArrayNotHasKey('access_token', $connection->toArray());
        $this->assertArrayNotHasKey('webhook_secret', $connection->toArray());
        $this->assertStringNotContainsString('hide-this-token', (string)json_encode($connection));
        // Debug output is the classic leak: Debugger::dump() renders fields.
        ob_start();
        Debugger::dump($connection->toArray());
        $dumped = (string)ob_get_clean();
        $this->assertStringNotContainsString('hide-this-token', $dumped);
    }

    public function testWebhookSecretLookup(): void
    {
        $connection = $this->connect('user:lookup', 'lookup-token', '401');

        $found = $this->connections->forWebhookSecret($connection->webhook_secret);
        $this->assertNotNull($found);
        $this->assertSame($connection->id, $found->id);
        $this->assertNull($this->connections->forWebhookSecret(''));
        $this->assertNull($this->connections->forWebhookSecret(str_repeat('f', 32)));
    }

    public function testConnectWithoutKeyThrows(): void
    {
        Configure::write('CakeEventbrite.encryptionKey', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('encryptionKey');
        $this->connections->connectFromResponse('user:nok', ['access_token' => 'tok'], ['id' => '1']);
    }

    public function testConnectRefusesBlankOwner(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('owner');
        $this->connections->connectFromResponse('  ', ['access_token' => 'tok'], ['id' => '1']);
    }

    /**
     * @return \TheMusicDev\CakeEventbrite\Model\Entity\EventbriteConnection
     */
    private function connect(string $ownerKey, string $token, string $userId): EventbriteConnection
    {
        return $this->connections->connectFromResponse($ownerKey, ['access_token' => $token], ['id' => $userId]);
    }

    /**
     * @return string
     */
    private function rawAccessToken(string $ownerKey): string
    {
        $row = ConnectionManager::get('test')->execute(
            'SELECT access_token FROM eventbrite_connections WHERE owner_key = ?',
            [$ownerKey],
        )->fetch('assoc');
        $this->assertIsArray($row);

        return (string)$row['access_token'];
    }
}
