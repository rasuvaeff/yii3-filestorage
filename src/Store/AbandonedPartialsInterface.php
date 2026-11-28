<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store;

use DateTimeImmutable;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;

/**
 * An optional capability of a store that stages writes next to their target:
 * removing what a crashed write left behind.
 *
 * A local store writes `<target>.part` (or a randomised `.part` for a
 * content-addressed write) and renames it into place. A crash in between
 * leaves the `.part` for good: it is no object, so neither the inventory nor
 * the quarantine lists it and no row or reference can ever point at it —
 * and under a public root it is served (review 1.0 pass 2, maintenance N4).
 * `filestorage:gc --orphans` asks every store with this capability to remove
 * the ones not written to since `--min-age`.
 *
 * @api
 */
interface AbandonedPartialsInterface extends StoreInterface
{
    /**
     * Partial files not modified since `$modifiedBefore`, inside what the
     * store owns, oldest path first — removed when `$apply` is true.
     *
     * @param int<1, max> $limit
     *
     * @return list<non-empty-string> Relative paths removed, or that would be.
     *
     * @throws StoreException
     */
    public function sweepAbandonedPartials(DateTimeImmutable $modifiedBefore, bool $apply, int $limit): array;
}
