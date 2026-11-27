<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store;

use InvalidArgumentException;

/**
 * A relative path inside one store, validated once so nothing downstream has to.
 *
 * Paths in this package are always generated, never accepted from a request.
 * This type is what makes that checkable: an inventory walk, a maintenance
 * delete and a blob ledger all speak in validated ids rather than raw strings,
 * so a traversal sequence has no way in even if a row in the database is wrong.
 *
 * @api
 */
final readonly class StoredObjectId
{
    private const string TRAVERSAL_PATTERN = '~(?:^|/)\.\.(?:/|\z)~';

    /** @var non-empty-string */
    public string $relativePath;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(string $relativePath)
    {
        if (
            $relativePath === ''
            || str_starts_with($relativePath, '/')
            || str_contains($relativePath, "\0")
            || str_contains($relativePath, '\\')
            || preg_match(self::TRAVERSAL_PATTERN, $relativePath) === 1
        ) {
            throw new InvalidArgumentException("Invalid stored object path \"{$relativePath}\"");
        }

        $this->relativePath = $relativePath;
    }

    /**
     * The parent directory, the same way {@see \Rasuvaeff\Yii3Filestorage\File::directory()}
     * derives it: an object at the store root answers itself.
     *
     * @return non-empty-string
     */
    public function directory(): string
    {
        $slash = strrpos($this->relativePath, '/');
        if ($slash === false || $slash === 0) {
            return $this->relativePath;
        }

        $directory = substr($this->relativePath, 0, $slash);
        \assert($directory !== '');

        return $directory;
    }

    public function equals(self $other): bool
    {
        return $this->relativePath === $other->relativePath;
    }
}
