<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store\Layout;

use InvalidArgumentException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeDescriptor;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;

/**
 * Where a file's derivatives live and what deleting the file removes.
 *
 * Chosen once per store and never changed afterwards: `File::$relativePath` is
 * persisted and derivative URLs are published, so switching the layout of a
 * store that holds data orphans every derivative. {@see DirectoryLayout} is the
 * default and is frozen; {@see FlatSiblingLayout} is an opt-in alternative for
 * flat content-addressed trees.
 *
 * @api
 */
interface ObjectLayoutInterface
{
    /**
     * @return non-empty-string
     *
     * @throws InvalidArgumentException When the file's path does not fit this layout.
     */
    public function derivativePath(File $file, DerivativeDescriptor $derivative): string;

    /**
     * The owner key of any object in the store: an object and a file row with
     * the same owner belong together — the orphan sweep keeps an object whose
     * owner some row has.
     *
     * @return non-empty-string
     */
    public function ownerOf(StoredObjectId $object): string;


    /**
     * Everything deleting the object at `$object` removes: the object and its
     * derivatives.
     *
     * @throws InvalidArgumentException When the path does not fit this layout.
     */
    public function deletion(StoredObjectId $object): ObjectSet;
}
