<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Support;

use Override;
use Rasuvaeff\Yii3Filestorage\Reference\ReferenceCheckerInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;

/**
 * An application whose references are a fixed list of `store:path` strings,
 * recording every question it was asked.
 *
 * @internal
 */
final class ListReferenceChecker implements ReferenceCheckerInterface
{
    /** @var list<list<string>> */
    public array $asked = [];

    /**
     * @param list<string> $referenced `store:path`.
     */
    public function __construct(private array $referenced = []) {}

    public function reference(string $storeName, string $relativePath): void
    {
        $this->referenced[] = $storeName . ':' . $relativePath;
    }

    #[Override]
    public function referenced(string $storeName, array $objects): array
    {
        $this->asked[] = array_map(static fn(StoredObjectId $o): string => $o->relativePath, $objects);

        return array_values(array_filter(
            $objects,
            fn(StoredObjectId $o): bool => \in_array($storeName . ':' . $o->relativePath, $this->referenced, strict: true),
        ));
    }
}
