<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Id;

use Override;
use Psr\Clock\ClockInterface;

/**
 * Default generator: UUIDv7 per RFC 9562, with no dependency beyond the core,
 * so `composer require` alone yields a working package.
 *
 * Layout: 48-bit big-endian Unix timestamp in milliseconds, 4-bit version
 * `0111`, 12 bits of randomness, 2-bit variant `10`, 62 bits of randomness.
 * The leading timestamp makes ids sort by creation time, which keeps inserts
 * clustered at the right edge of a B-tree index instead of scattered across it.
 *
 * Ordering holds *between* milliseconds. Two ids minted within the same
 * millisecond have no defined order: RFC 9562's monotonic counter needs
 * per-process state that guarantees nothing across PHP-FPM workers anyway, so
 * it is deliberately not implemented. Nothing here depends on
 * intra-millisecond ordering.
 *
 * To use a library implementation instead, bind {@see SymfonyUidIdGenerator}
 * or {@see RamseyUuidIdGenerator}.
 *
 * @api
 */
final readonly class Uuid7IdGenerator implements IdGeneratorInterface
{
    public function __construct(private ClockInterface $clock) {}

    #[Override]
    public function generate(): string
    {
        // 'Uv' renders seconds followed by milliseconds, i.e. the millisecond
        // timestamp; the low 48 bits of its big-endian form are the prefix.
        $milliseconds = (int) $this->clock->now()->format('Uv');
        $bytes = substr(pack('J', $milliseconds), 2, 6) . random_bytes(10);

        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x70);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        $hex = bin2hex($bytes);

        /** @var non-empty-string */
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20),
        );
    }
}
