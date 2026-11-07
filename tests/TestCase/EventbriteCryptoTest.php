<?php
declare(strict_types=1);

namespace TheMusicDev\CakeEventbrite\Test\TestCase;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use TheMusicDev\CakeEventbrite\EventbriteCrypto;

/**
 * Token encryption and state secret derivation.
 */
final class EventbriteCryptoTest extends TestCase
{
    private const TEST_KEY = 'crypto-test-key-0123456789abcdef0123456789abcdef';

    public function setUp(): void
    {
        parent::setUp();
        Configure::write('CakeEventbrite.encryptionKey', self::TEST_KEY);
    }

    public function tearDown(): void
    {
        parent::tearDown();
        Configure::delete('CakeEventbrite.encryptionKey');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function keyVariants(): array
    {
        return [
            'short' => ['abc'],
            'medium' => [bin2hex(random_bytes(16))],
            'with symbols' => ['ünïcødé-key/+=@#*'],
        ];
    }

    #[DataProvider('keyVariants')]
    public function testEncryptDecryptRoundTrip(string $key): void
    {
        Configure::write('CakeEventbrite.encryptionKey', $key);
        $token = 'evt-access-token-' . random_int(100000, 999999);

        $stored = EventbriteCrypto::encryptToken($token);

        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString($token, $stored);
        $this->assertSame($token, EventbriteCrypto::decryptToken($stored));
    }

    public function testEncryptIsNonDeterministic(): void
    {
        $one = EventbriteCrypto::encryptToken('same-token');
        $two = EventbriteCrypto::encryptToken('same-token');

        $this->assertNotSame($one, $two, 'every encryption uses a fresh IV');
    }

    public function testDecryptRejectsTamperedCiphertext(): void
    {
        $stored = EventbriteCrypto::encryptToken('private-value');
        $tampered = substr($stored, 0, -2) . 'xx';

        $this->expectException(RuntimeException::class);
        EventbriteCrypto::decryptToken($tampered);
    }

    public function testSavingWithoutKeyThrows(): void
    {
        Configure::write('CakeEventbrite.encryptionKey', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('encryptionKey');
        EventbriteCrypto::encryptToken('token');
    }

    public function testStateSaltFollowsTheKey(): void
    {
        $saltOne = EventbriteCrypto::stateSalt();
        Configure::write('CakeEventbrite.encryptionKey', self::TEST_KEY . '-rotated');

        $this->assertNotSame($saltOne, EventbriteCrypto::stateSalt());
        $this->assertSame(
            hash_hmac('sha256', 'TheMusicDev\CakeEventbrite|oauth-state', self::TEST_KEY),
            $saltOne,
        );
    }

    public function testWebhookSecretIsHex(): void
    {
        $one = EventbriteCrypto::webhookSecret();
        $two = EventbriteCrypto::webhookSecret();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $one);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $two);
        $this->assertNotSame($one, $two);
    }
}
