<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Path;

use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * Produces the relative path of a newly stored object.
 *
 * Implementations are collision-resistant and always produce a *new* logical
 * object: they never receive a content hash, so a plain write can never be
 * turned into silent sharing. Content-addressed keys are a separate contract,
 * {@see ContentAddressedKeyGeneratorInterface}, owned by the dedup path.
 *
 * Every implementation emits `<…segments…>/<key>/original.<ext>` — a directory
 * per stored file, never a bare filename. That layout is what lets a derivative
 * live next to its original (`<key>/thumb.webp`) with no schema change, and it
 * is why deleting a file is one directory operation. It is persisted in
 * `File::$relativePath`, so it cannot change after the first release.
 *
 * @api
 */
interface PathGeneratorInterface
{
    /**
     * @param non-empty-string $groupName
     * @param non-empty-string|null $mediaType The *detected* type. The extension is
     *        derived from it alone; the client filename never contributes.
     *
     * @return non-empty-string Relative path under the store root.
     */
    public function generate(string $groupName, Upload $upload, ?string $mediaType): string;
}
