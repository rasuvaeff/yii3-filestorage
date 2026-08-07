<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store;

use InvalidArgumentException;

/**
 * What a store reports after publishing an object.
 *
 * @api
 */
final readonly class StoreResult
{
    /** @var non-empty-string */
    public string $relativePath;

    /** @var non-empty-string|null */
    public ?string $externalId;

    /** @var int<0, max> */
    public int $size;

    /**
     * @param bool $created False when the target object already existed
     *        byte-identically and the write was a no-op. Only a
     *        content-addressed put can report this; an ordinary write always
     *        creates, which is why compensation on the base path may delete
     *        the object it just wrote without endangering anyone else's.
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        string $relativePath,
        int $size,
        ?string $externalId = null,
        public bool $created = true,
    ) {
        if ($relativePath === '') {
            throw new InvalidArgumentException('relativePath must not be empty');
        }
        if ($externalId === '') {
            throw new InvalidArgumentException('externalId must be null or non-empty');
        }
        if ($size < 0) {
            throw new InvalidArgumentException('size must not be negative');
        }

        $this->relativePath = $relativePath;
        $this->externalId = $externalId;
        $this->size = $size;
    }
}
