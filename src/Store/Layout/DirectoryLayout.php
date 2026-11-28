<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store\Layout;

use InvalidArgumentException;
use Override;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeDescriptor;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;

/**
 * `<…>/<key>/original.<ext>` with derivatives beside it as `<key>/<name>.<ext>`
 * — a directory per file. The default layout, and frozen: it is the one every
 * existing row and URL was written in.
 *
 * Deleting a file removes its whole key directory, which is what stops a
 * deleted file's previews from leaking. An object at the store root has no key
 * directory and owns only itself.
 *
 * Every operation that acts *on a file* — {@see self::deletion()},
 * {@see self::derivativePath()} — refuses a file not named `original` or
 * `original.<ext>`. A flat key such as `blobs/ab/cd/<sha>.jpg` has a directory
 * shared with every other blob of its fan-out bucket, so deleting "its key
 * directory" would delete the bucket and one derivative path would serve all
 * of them. Such keys need {@see FlatSiblingLayout}; check a generator against a
 * layout with {@see LayoutCompatibility} before the first write.
 *
 * @api
 */
final readonly class DirectoryLayout implements ObjectLayoutInterface
{
    private const string NAME_PATTERN = '~^original(?:\.[^/]+)?\z~';

    #[Override]
    public function derivativePath(File $file, DerivativeDescriptor $derivative): string
    {
        $this->requireKeyDirectory($file->relativePath);

        return $file->directory() . '/' . $derivative->fileName();
    }

    #[Override]
    public function ownerOf(StoredObjectId $object): string
    {
        return $object->directory();
    }


    #[Override]
    public function deletion(StoredObjectId $object): ObjectSet
    {
        $this->requireKeyDirectory($object->relativePath);

        return ObjectSet::tree(new StoredObjectId($object->directory()));
    }

    /**
     * @throws InvalidArgumentException
     */
    private function requireKeyDirectory(string $relativePath): void
    {
        $slash = strrpos($relativePath, '/');
        if ($slash === false) {
            return;
        }

        if (preg_match(self::NAME_PATTERN, substr($relativePath, $slash + 1)) !== 1) {
            throw new InvalidArgumentException(
                "\"{$relativePath}\" does not fit the directory layout: a file must be <key>/original or "
                . '<key>/original.<ext>, the only shape whose directory belongs to one file. A flat key such as '
                . '<dir>/<sha>.<ext> needs FlatSiblingLayout',
            );
        }
    }
}
