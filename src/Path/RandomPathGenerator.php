<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Path;

use Override;
use Rasuvaeff\Yii3Filestorage\Mime\ExtensionMap;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * `<group>/<2 hex>/<2 hex>/<32 hex>/original.<ext>`.
 *
 * The two fan-out segments keep any single directory small enough for a
 * filesystem to stay fast; the 128-bit key makes a collision unreachable.
 * Bytes come from `random_bytes()`, never from `rand()`/`uniqid()`: a
 * predictable key is a way to guess the path of somebody else's file on a
 * public store.
 *
 * @api
 */
final readonly class RandomPathGenerator implements PathGeneratorInterface
{
    public function __construct(private ExtensionMap $extensions = new ExtensionMap()) {}

    #[Override]
    public function generate(string $groupName, Upload $upload, ?string $mediaType): string
    {
        $key = bin2hex(random_bytes(16));
        $fanOut = bin2hex(random_bytes(2));

        return $groupName
            . '/' . substr($fanOut, 0, 2)
            . '/' . substr($fanOut, 2, 2)
            . '/' . $key
            . '/original.' . $this->extensions->extensionFor($mediaType);
    }
}
