<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store\Layout;

use InvalidArgumentException;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;

/**
 * The objects one deletion removes, described rather than listed.
 *
 * A store resolves the description with whatever is cheap for it — a
 * recursive directory removal, one `scandir()`, a prefix listing — instead of
 * walking its whole inventory.
 *
 * - **tree:** the object at `path`, or everything under `path/` when it is a
 *   directory. What {@see DirectoryLayout} removes for a file.
 * - **siblings:** the objects directly inside `directory` named `base`,
 *   `base.<anything>` or `base-<anything>`. What {@see FlatSiblingLayout}
 *   removes for a file.
 *
 * @api
 */
final readonly class ObjectSet
{
    private const string BASE_PATTERN = '/^[A-Za-z0-9_]+\z/';

    /**
     * @param non-empty-string $path For a tree, the root; for siblings, `directory/base`.
     * @param string|null $base Null for a tree; validated by {@see self::siblings()}.
     */
    private function __construct(
        public string $path,
        public ?string $base,
    ) {}

    public static function tree(StoredObjectId $root): self
    {
        return new self($root->relativePath, null);
    }

    /**
     * @throws InvalidArgumentException When the base could be confused with a sibling's suffix.
     */
    public static function siblings(?string $directory, string $base): self
    {
        if (preg_match(self::BASE_PATTERN, $base) !== 1) {
            throw new InvalidArgumentException(
                "Invalid sibling base \"{$base}\": only letters, digits and underscores keep it unambiguous",
            );
        }

        $path = $directory === null || $directory === '' ? $base : $directory . '/' . $base;

        return new self((new StoredObjectId($path))->relativePath, $base);
    }

    public function isTree(): bool
    {
        return $this->base === null;
    }

    /**
     * The directory the members live in; null for the store root.
     *
     * @return non-empty-string|null
     */
    public function directory(): ?string
    {
        $slash = strrpos($this->path, '/');
        if ($this->isTree() || $slash === false) {
            return $this->isTree() ? $this->path : null;
        }

        $directory = substr($this->path, 0, $slash);

        return $directory === '' ? null : $directory;
    }

    public function contains(string $relativePath): bool
    {
        if ($this->base === null) {
            return $relativePath === $this->path || str_starts_with($relativePath, $this->path . '/');
        }

        if ($relativePath === $this->path) {
            return true;
        }
        if (!str_starts_with($relativePath, $this->path)) {
            return false;
        }

        $rest = substr($relativePath, \strlen($this->path));

        return ($rest[0] === '.' || $rest[0] === '-') && !str_contains($rest, '/');
    }
}
