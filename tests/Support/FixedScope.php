<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Support;

use Override;
use Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface;

/**
 * A tenant that never changes. Binding one at all is what makes an installation
 * multi-tenant as far as this package is concerned — which is the condition
 * `gc --orphans` refuses on.
 *
 * @internal
 */
final readonly class FixedScope implements FileScopeProviderInterface
{
    public function __construct(private ?string $scopeId) {}

    #[Override]
    public function currentScopeId(): ?string
    {
        return $this->scopeId;
    }
}
