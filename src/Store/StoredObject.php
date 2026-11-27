<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One entry of a store's inventory: where it is, when it was last written and
 * how large it is.
 *
 * The timestamp is what makes an orphan sweep safe against uploads in flight:
 * an object is only a candidate once it is older than the moment its
 * references were checked minus a grace period. A store that cannot tell
 * reports null, and such an object is never deleted by a sweep.
 *
 * @api
 */
final readonly class StoredObject
{
    /** @var int<0, max>|null */
    public ?int $size;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(
        public StoredObjectId $id,
        public ?DateTimeImmutable $lastModified = null,
        ?int $size = null,
    ) {
        if ($size !== null && $size < 0) {
            throw new InvalidArgumentException('Object size must not be negative');
        }

        $this->size = $size;
    }
}
