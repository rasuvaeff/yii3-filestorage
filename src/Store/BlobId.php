<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store;

use InvalidArgumentException;

/**
 * Physical identity of stored bytes: which store, which path.
 *
 * Deliberately not a content hash. A hash is content *identity*; it says
 * nothing about who owns the bytes, and it is identical across stores, groups
 * and tenant scopes. Reference counting keyed by hash is how a delete in one
 * tenant removes bytes another tenant is still pointing at. Ownership follows
 * this type instead.
 *
 * @api
 */
final readonly class BlobId
{
    private const string STORE_NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/';

    /** @var non-empty-string */
    public string $storeName;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(string $storeName, public StoredObjectId $object)
    {
        if ($storeName === '' || preg_match(self::STORE_NAME_PATTERN, $storeName) !== 1) {
            throw new InvalidArgumentException("Invalid store name \"{$storeName}\"");
        }

        $this->storeName = $storeName;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function create(string $storeName, string $relativePath): self
    {
        return new self($storeName, new StoredObjectId($relativePath));
    }

    /**
     * @return non-empty-string
     */
    public function relativePath(): string
    {
        return $this->object->relativePath;
    }

    public function equals(self $other): bool
    {
        return $this->storeName === $other->storeName && $this->object->equals($other->object);
    }

    /**
     * @return non-empty-string Stable key for maps and ledger lookups.
     */
    public function key(): string
    {
        return $this->storeName . ':' . $this->object->relativePath;
    }
}
