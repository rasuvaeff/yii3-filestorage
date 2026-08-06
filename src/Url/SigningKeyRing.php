<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Url;

use Rasuvaeff\Yii3Filestorage\Exception\InvalidConfigException;

/**
 * The active signing key plus the keys still accepted for verification.
 *
 * Rotation without breaking live URLs is the whole reason this is a ring and
 * not a single secret: put the new key in as active, keep the previous one
 * listed for at least the maximum URL TTL, then drop it. A key that is no
 * longer listed stops verifying immediately, which is what you want when a key
 * leaks.
 *
 * Key ids are validated on construction against an allow-list without `.`,
 * because the id travels inside a dot-separated token envelope: an id
 * containing a separator would let one token be re-split into a different one.
 *
 * @api
 */
final readonly class SigningKeyRing
{
    /**
     * 256 bits. HMAC-SHA256 gains nothing from a longer key and loses real
     * security below its block size, so a short secret is a configuration
     * error rather than a weaker-but-working setup.
     */
    public const int MIN_KEY_LENGTH = 32;

    private const string KEY_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,31}\z/';

    /** @var non-empty-array<non-empty-string, non-empty-string> */
    private array $keys;

    /** @var non-empty-string */
    private string $activeKeyId;

    /**
     * @param non-empty-string $activeKeyId
     * @param array<non-empty-string, non-empty-string> $keys Key id => secret. Must
     *        contain `$activeKeyId`, and may contain previous keys kept for
     *        verification only.
     *
     * @throws InvalidConfigException
     */
    public function __construct(string $activeKeyId, array $keys)
    {
        foreach ($keys as $keyId => $secret) {
            if (preg_match(self::KEY_ID_PATTERN, $keyId) !== 1) {
                throw new InvalidConfigException(
                    "Invalid signing key id \"{$keyId}\". Key ids match "
                    . self::KEY_ID_PATTERN . ' — letters, digits, "_" and "-" only',
                );
            }
            if (strlen($secret) < self::MIN_KEY_LENGTH) {
                throw new InvalidConfigException(
                    "Signing key \"{$keyId}\" is shorter than " . self::MIN_KEY_LENGTH
                    . ' bytes. Generate one with: php -r "echo bin2hex(random_bytes(32));"',
                );
            }
        }

        if (!isset($keys[$activeKeyId])) {
            $known = $keys === [] ? 'none' : implode(', ', array_keys($keys));

            throw new InvalidConfigException(
                "Active signing key \"{$activeKeyId}\" is not in the key ring. Configured keys: {$known}",
            );
        }

        $this->keys = $keys;
        $this->activeKeyId = $activeKeyId;
    }

    /**
     * @return non-empty-string
     */
    public function activeKeyId(): string
    {
        return $this->activeKeyId;
    }

    /**
     * @param non-empty-string $keyId
     *
     * @return non-empty-string|null Null for an unknown or retired key.
     */
    public function secretFor(string $keyId): ?string
    {
        return $this->keys[$keyId] ?? null;
    }
}
