<?php
declare(strict_types=1);

namespace TheMusicDev\CakeEventbrite\Model\Entity;

use Cake\ORM\Entity;

/**
 * One connected Eventbrite account, keyed by a host-chosen owner.
 *
 * Sensitive: access_token and webhook_secret are hidden from serialization
 * and debug output, and access_token is stored encrypted. Never log or
 * render either value.
 *
 * @property string $owner_key Opaque key chosen by the host, e.g. 'user:42'.
 * @property string $access_token Encrypted at rest (EventbriteCrypto).
 * @property string $webhook_secret Random path secret identifying this connection.
 * @property string $eventbrite_user_id Eventbrite user id from me() at connect time (not the org id).
 * @property \Cake\I18n\DateTime|null $created
 * @property \Cake\I18n\DateTime|null $modified
 */
class EventbriteConnection extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = ['*' => true];

    /**
     * @var array<string>
     */
    protected array $_hidden = ['access_token', 'webhook_secret'];
}
