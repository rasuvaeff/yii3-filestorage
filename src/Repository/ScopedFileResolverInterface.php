<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Repository;

use Rasuvaeff\Yii3Filestorage\File;

/**
 * Resolves a file by id *and* the scope a signed URL was minted for.
 *
 * A download served from a signed token has no ambient tenant: the request may
 * arrive with no session at all, which is the point of signing it. So the
 * ordinary scoped repository — which reads the current scope from the request —
 * cannot answer, and the tempting fix is to turn the tenant filter off for
 * downloads. That fix is a cross-tenant read of every file whose id leaks.
 *
 * This contract is the alternative: the scope travels inside the signed token
 * and is matched here as a second predicate. The implementation queries both
 * values together and never disables its mandatory filter.
 *
 * @api
 */
interface ScopedFileResolverInterface
{
    /**
     * @param non-empty-string $id
     * @param non-empty-string|null $scopeId The scope authenticated by the token; null for an unscoped application.
     *
     * @return File|null Null when there is no such file *in that scope*, which
     *         a caller must not distinguish from "no such file".
     */
    public function findInScope(string $id, ?string $scopeId): ?File;
}
