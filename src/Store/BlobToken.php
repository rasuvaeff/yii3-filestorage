<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store;

use InvalidArgumentException;

/**
 * An opaque, ledger-issued handle to one in-flight claim on a blob.
 *
 * Reservations and deletion leases are both "I am the one who did this",
 * asserted across a process boundary and possibly across a crash. A counter
 * cannot express that: two writers incrementing the same number are
 * indistinguishable, so recovering after one of them dies means guessing.
 * A token per claim makes each one individually revocable and individually
 * expirable.
 *
 * The value is validated here so it can be interpolated into diagnostics and
 * compared in SQL without any caller having to wonder where it came from.
 *
 * @api
 */
final readonly class BlobToken
{
    private const string PATTERN = '/^[A-Za-z0-9_-]{8,64}\z/';

    /** @var non-empty-string */
    public string $value;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(string $value)
    {
        if ($value === '' || preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException("Invalid blob token \"{$value}\"");
        }

        $this->value = $value;
    }

    /**
     * 128 bits of randomness: tokens are compared for equality across
     * processes, so a guessable one lets an unrelated worker release a claim
     * it does not hold.
     */
    public static function random(): self
    {
        return new self(bin2hex(random_bytes(16)));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
