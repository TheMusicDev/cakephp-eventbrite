<?php
declare(strict_types=1);

namespace TheMusicDev\CakeEventbrite;

use Cake\Core\Configure;
use Cake\Utility\Security;
use RuntimeException;

/**
 * Token encryption and OAuth state secret, derived from one host key
 * (CakeEventbrite.encryptionKey) so hosts manage a single secret.
 *
 * Never log anything passed to or returned by this class.
 */
final class EventbriteCrypto
{
    /**
     * The configured key. No fallback exists by design: without a key,
     * saving a token must throw, never silently degrade to Security.salt
     * (rotating the salt would then destroy every stored token).
     *
     * @return string
     */
    public static function key(): string
    {
        $key = (string)Configure::read('CakeEventbrite.encryptionKey', '');
        if ($key === '') {
            throw new RuntimeException(
                'No Eventbrite encryption key configured. Set CakeEventbrite.encryptionKey '
                . '(EVENTBRITE_ENCRYPTION_KEY) to a random string, e.g. bin2hex(random_bytes(16)).',
            );
        }

        return $key;
    }

    /**
     * Encrypt a token for storage. The result is opaque base64 text; the plain
     * token never reappears from it without decryptToken() and the key.
     *
     * @param string $plain The secret to encrypt.
     * @return string Base64 of the encrypted form.
     */
    public static function encryptToken(string $plain): string
    {
        return base64_encode(Security::encrypt($plain, self::encryptionKey(), self::hmacSalt()));
    }

    /**
     * @param string $stored Base64 of the encrypted form.
     * @return string The plain secret.
     * @throws \RuntimeException When the ciphertext does not authenticate or the base64 is malformed.
     */
    public static function decryptToken(string $stored): string
    {
        $cipher = base64_decode($stored, true);
        if ($cipher === false) {
            throw new RuntimeException('Stored token is not valid base64.');
        }
        $plain = Security::decrypt($cipher, self::encryptionKey(), self::hmacSalt());
        if ($plain === null) {
            throw new RuntimeException('Stored token could not be decrypted (wrong key or corrupted value).');
        }

        return $plain;
    }

    /**
     * The random secret that identifies a connection on webhook deliveries;
     * Eventbrite's deliveries carry no signature, so the private path segment
     * is the authentication. 32 lowercase hex characters.
     *
     * @return string
     */
    public static function webhookSecret(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * The signing secret for OAuth state, derived from the same host key with
     * an HMAC, so hosts have one secret to manage.
     *
     * @return string
     */
    public static function stateSalt(): string
    {
        return hash_hmac('sha256', 'TheMusicDev\CakeEventbrite|oauth-state', self::key());
    }

    /**
     * Security::encrypt()'s key must be exactly 32 bytes; hosts may set any
     * length, so derive an internal 32-byte key from the configured one.
     *
     * @return string
     */
    private static function encryptionKey(): string
    {
        return hash_hmac('sha256', self::key(), 'TheMusicDev\CakeEventbrite|encryption', true);
    }

    /**
     * The HMAC salt for the ciphertext authentication, derived as well — token
     * encryption depends only on the host key, never on Security.salt (rotating
     * the salt must not scramble every stored token).
     *
     * @return string
     */
    private static function hmacSalt(): string
    {
        return hash_hmac('sha256', self::key(), 'TheMusicDev\CakeEventbrite|token-hmac', true);
    }
}
