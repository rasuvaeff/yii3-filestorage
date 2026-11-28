<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store;

/**
 * An optional capability of a {@see BlobLedgerInterface}: many lookups in one
 * round trip.
 *
 * `filestorage:gc` asks the ledger about every object of every quarantine
 * entry on every run — an orphan sweep leaves an entry per object, and they
 * stay for the whole retention. With this capability that is one call per
 * page of the quarantine instead of one per object (review 1.0 pass 2,
 * maintenance N7). A ledger without it is asked object by object, as before.
 *
 * @api
 */
interface BlobLedgerBatchLookupInterface extends BlobLedgerInterface
{
    /**
     * What {@see BlobLedgerInterface::find()} would answer for each blob,
     * leaving out the ones it would answer null for.
     *
     * @param list<BlobId> $blobs
     *
     * @return array<non-empty-string, BlobRecord> Keyed by {@see BlobId::key()}.
     */
    public function findMany(array $blobs): array;
}
