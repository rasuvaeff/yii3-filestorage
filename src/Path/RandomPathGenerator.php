<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Path;

use InvalidArgumentException;
use Override;
use Rasuvaeff\Yii3Filestorage\Mime\ExtensionMap;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * `<group>/<2 hex>/<2 hex>/<32 hex>/original.<ext>`, or `<prefix>/<group>/…`
 * with a prefix.
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
    private const string PREFIX_PATTERN = '~^[A-Za-z0-9][A-Za-z0-9_-]{0,63}(?:/[A-Za-z0-9][A-Za-z0-9_-]{0,63})*\z~';

    /** @var non-empty-string|null */
    private ?string $prefix;

    /**
     * @param string|null $prefix Put in front of the group: `files` gives
     *        `files/<group>/…`. For a store whose root it shares with the
     *        application — one entry in `ownedPrefixes` instead of one per
     *        group, and no group name can collide with a directory the
     *        application keeps there.
     *
     * @throws InvalidArgumentException
     */
    public function __construct(private ExtensionMap $extensions = new ExtensionMap(), ?string $prefix = null)
    {
        if ($prefix !== null && preg_match(self::PREFIX_PATTERN, $prefix) !== 1) {
            throw new InvalidArgumentException("Invalid path prefix \"{$prefix}\"");
        }

        $this->prefix = $prefix === '' ? null : $prefix;
    }

    #[Override]
    public function generate(string $groupName, Upload $upload, ?string $mediaType): string
    {
        $key = bin2hex(random_bytes(16));
        $fanOut = bin2hex(random_bytes(2));

        return ($this->prefix === null ? '' : $this->prefix . '/')
            . $groupName
            . '/' . substr($fanOut, 0, 2)
            . '/' . substr($fanOut, 2, 2)
            . '/' . $key
            . '/original.' . $this->extensions->extensionFor($mediaType);
    }
}
