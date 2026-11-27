<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store\Layout;

use InvalidArgumentException;
use Override;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeDescriptor;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;

/**
 * `<dir>/<base>.<ext>` with derivatives beside it as `<dir>/<base>-<name>.<ext>`
 * — no directory per file. Opt-in, chosen once per store; pairs with
 * {@see \Rasuvaeff\Yii3Filestorage\Path\FlatSha256KeyGenerator}
 * (`blobs/ab/cd/<sha>.<ext>`).
 *
 * The owner of any object is `<dir>/` plus its name up to the first `-` or
 * `.`. That is only unambiguous when a file's own base contains neither, so
 * every operation that acts *on a file* refuses one whose name is not
 * `<base>` or `<base>.<ext>` with a base of letters, digits and underscores:
 * otherwise deleting `foo-bar.jpg` would take `foo.jpg` and its previews with
 * it.
 *
 * @api
 */
final readonly class FlatSiblingLayout implements ObjectLayoutInterface
{
    private const string NAME_PATTERN = '/^([A-Za-z0-9_]+)(?:\.[A-Za-z0-9]+)?\z/';

    #[Override]
    public function derivativePath(File $file, DerivativeDescriptor $derivative): string
    {
        [$directory, $base] = $this->split($file->relativePath);

        return ($directory === null ? '' : $directory . '/') . $base . '-' . $derivative->fileName();
    }

    #[Override]
    public function ownerOf(StoredObjectId $object): string
    {
        $path = $object->relativePath;
        $slash = strrpos($path, '/');
        $name = $slash === false ? $path : substr($path, $slash + 1);
        $cut = strcspn($name, '-.');
        if ($cut === 0) {
            return $path;
        }

        $owner = substr($path, 0, ($slash === false ? 0 : $slash + 1) + $cut);
        \assert($owner !== '');

        return $owner;
    }

    #[Override]
    public function ownerOfFile(File $file): string
    {
        return $this->ownerOf(new StoredObjectId($file->relativePath));
    }

    #[Override]
    public function deletion(StoredObjectId $object): ObjectSet
    {
        [$directory, $base] = $this->split($object->relativePath);

        return ObjectSet::siblings($directory, $base);
    }

    /**
     * @return array{non-empty-string|null, non-empty-string}
     *
     * @throws InvalidArgumentException
     */
    private function split(string $relativePath): array
    {
        $slash = strrpos($relativePath, '/');
        $name = $slash === false ? $relativePath : substr($relativePath, $slash + 1);
        if (preg_match(self::NAME_PATTERN, $name, $matches) !== 1) {
            throw new InvalidArgumentException(
                "\"{$relativePath}\" does not fit the flat sibling layout: the name must be <base> or <base>.<ext>, "
                . 'with a base of letters, digits and underscores',
            );
        }

        $directory = $slash === false || $slash === 0 ? null : substr($relativePath, 0, $slash);
        $base = $matches[1];
        \assert($base !== '');

        return [$directory === '' ? null : $directory, $base];
    }
}
