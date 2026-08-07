<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Support;

use Override;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Repository\ScopedFileResolverInterface;

/**
 * A resolver that resolves nothing. `filestorage:check` only asks whether one is
 * *bound* — a scope provider without a resolver leaves a signed download with no
 * scoped way in — so a double that answers null is the whole contract needed
 * here.
 *
 * @internal
 */
final readonly class NullScopedFileResolver implements ScopedFileResolverInterface
{
    #[Override]
    public function findInScope(string $id, ?string $scopeId): ?File
    {
        return null;
    }
}
