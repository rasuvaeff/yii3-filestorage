<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Objects a store has taken off their keys, kept until a collector purges or
 * restores them.
 *
 * Deleting shared bytes is two-phase: the collector first quarantines them,
 * then commits the deletion in the ledger, and only then purges — or, when the
 * ledger refuses because a writer re-published the content meanwhile,
 * restores them. The token is `<unix time, zero-padded to 10 digits>-<16 hex>`,
 * so tokens sort in time order: the moment of quarantine
 * travels in the name because a move keeps the object's own modification time,
 * which says nothing about how long it has been in quarantine.
 *
 * @api
 */
final readonly class QuarantinedObject
{
    private const string TOKEN_PATTERN = '/^(\d{10,19})-[a-f0-9]{16}\z/';

    /** @var non-empty-string */
    public string $token;

    public DateTimeImmutable $quarantinedAt;

    /** @var non-empty-list<StoredObjectId> Original locations, ordered. */
    public array $objects;

    /**
     * @param list<StoredObjectId> $objects
     *
     * @throws InvalidArgumentException
     */
    public function __construct(string $token, array $objects)
    {
        if (preg_match(self::TOKEN_PATTERN, $token, $matches) !== 1) {
            throw new InvalidArgumentException("Invalid quarantine token \"{$token}\"");
        }
        if ($objects === []) {
            throw new InvalidArgumentException('A quarantine entry holds at least one object');
        }

        usort($objects, static fn(StoredObjectId $a, StoredObjectId $b): int => strcmp($a->relativePath, $b->relativePath));

        \assert($token !== '');
        $this->token = $token;
        $this->quarantinedAt = new DateTimeImmutable('@' . $matches[1]);
        $this->objects = $objects;
    }

    /**
     * A fresh token for an entry quarantined at `$now`.
     *
     * @return non-empty-string
     */
    public static function newToken(DateTimeImmutable $now): string
    {
        // The first six hex digits are a per-process sequence, the other ten
        // random: two entries one process quarantines in the same second sort
        // in the order they were made, so a page of the quarantine — and the
        // batch gc hands the reference checker — is the same on every run
        // (review 1.0 pass 2, N5). Across processes the random part keeps
        // tokens apart; their order within one second is then arbitrary.
        return sprintf('%010d', max(0, $now->getTimestamp()))
            . '-' . sprintf('%06x', QuarantineSequence::next())
            . bin2hex(random_bytes(5));
    }

    public static function isToken(string $candidate): bool
    {
        return preg_match(self::TOKEN_PATTERN, $candidate) === 1;
    }
}
