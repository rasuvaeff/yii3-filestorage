<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Reference;

use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;

/**
 * The application's answer to "does anything still point at this path?".
 *
 * For applications whose references are not `File` rows — a URL inside a
 * text column, a path inside JSON. By path, not by row: the same question is
 * asked about a row's object before the row is released, about a shared blob
 * right before its deletion (when the row is already gone), and about
 * objects in the orphan sweep.
 *
 * Batched per page on purpose: a typical implementation scans text columns,
 * builds an index once per run and answers from it. It is called from
 * `filestorage:gc` only; the blob ledger never calls it, because the ledger is
 * one SQL transaction and this is application code.
 *
 * @api
 */
interface ReferenceCheckerInterface
{
    /**
     * @param non-empty-string $storeName
     * @param non-empty-list<StoredObjectId> $objects Candidates from one page.
     *
     * @return list<StoredObjectId> Those the application still references.
     */
    public function referenced(string $storeName, array $objects): array;
}
