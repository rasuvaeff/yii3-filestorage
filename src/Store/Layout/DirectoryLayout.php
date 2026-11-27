<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store\Layout;

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
 * @api
 */
final readonly class DirectoryLayout implements ObjectLayoutInterface
{
    #[Override]
    public function derivativePath(File $file, DerivativeDescriptor $derivative): string
    {
        return $file->directory() . '/' . $derivative->fileName();
    }

    #[Override]
    public function ownerOf(StoredObjectId $object): string
    {
        return $object->directory();
    }

    #[Override]
    public function ownerOfFile(File $file): string
    {
        return $file->directory();
    }

    #[Override]
    public function deletion(StoredObjectId $object): ObjectSet
    {
        return ObjectSet::tree(new StoredObjectId($object->directory()));
    }
}
