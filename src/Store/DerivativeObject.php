<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store;

use InvalidArgumentException;

/**
 * What a store reports after writing a derivative.
 *
 * A derivative is a sibling object, not a row: `<key>/original.jpg` and
 * `<key>/thumb.webp` share a directory. No `parent_id`, no variant rows
 * polluting listings and counts, no migration when a preset is added. The
 * trade-off accepted is that a file's variants cannot be listed cheaply —
 * `exists()` per preset covers both serving and warming.
 *
 * @api
 */
final readonly class DerivativeObject
{
    /** @var non-empty-string */
    public string $relativePath;

    /** @var int<0, max> */
    public int $size;

    /** @var non-empty-string */
    public string $mediaType;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(string $relativePath, int $size, string $mediaType)
    {
        if ($relativePath === '') {
            throw new InvalidArgumentException('relativePath must not be empty');
        }
        if ($size < 0) {
            throw new InvalidArgumentException('size must not be negative');
        }
        if ($mediaType === '') {
            throw new InvalidArgumentException('mediaType must not be empty');
        }

        $this->relativePath = $relativePath;
        $this->size = $size;
        $this->mediaType = $mediaType;
    }
}
