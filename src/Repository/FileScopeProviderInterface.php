<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Repository;

/**
 * Which tenant, account or workspace the current request belongs to.
 *
 * The application binds this, not `rasuvaeff/yii3-filestorage-db`. The database
 * package applies the scope to every query it makes, but it has no way to know
 * what the scope *is*: that comes from `rasuvaeff/yii3-tenancy`, from a session,
 * from a subdomain, or from a fixed string in a single-tenant deployment. A
 * backend package that guessed would be wrong in every installation that does
 * it differently, and a backend package that depended on one tenancy library
 * would force it on everybody.
 *
 * Leaving it unbound is the supported single-tenant case: the repository then
 * applies no predicate at all, rather than a predicate on a null scope.
 *
 * @api
 */
interface FileScopeProviderInterface
{
    /**
     * @return non-empty-string|null Null only where the application genuinely has no scope.
     */
    public function currentScopeId(): ?string;
}
