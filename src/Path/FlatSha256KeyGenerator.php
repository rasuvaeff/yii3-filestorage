<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Path;

use InvalidArgumentException;
use Override;

/**
 * `<scope>/<hash[0:2]>/<hash[2:4]>/<hash>.<ext>` — a flat content-addressed
 * key with no directory per file, for a store that uses
 * {@see \Rasuvaeff\Yii3Filestorage\Store\Layout\FlatSiblingLayout}.
 *
 * Unlike {@see Sha256KeyGenerator} the key carries an extension, so the same
 * bytes detected as two different types get two keys. Without an extension the
 * key is `<hash>` alone. Derivatives sit beside it as `<hash>-<name>.<ext>`.
 *
 * @api
 */
final readonly class FlatSha256KeyGenerator implements ContentAddressedKeyGeneratorInterface
{
    private const string SCOPE_PATTERN = '~^[A-Za-z0-9][A-Za-z0-9_-]{0,63}(?:/[A-Za-z0-9][A-Za-z0-9_-]{0,63})*\z~';
    private const string SHA256_PATTERN = '/^[a-f0-9]{64}\z/';
    private const string EXTENSION_PATTERN = '/^[a-z0-9]{1,16}\z/';

    #[Override]
    public function generate(string $scope, string $sha256, ?string $extension = null): string
    {
        if (preg_match(self::SCOPE_PATTERN, $scope) !== 1) {
            throw new InvalidArgumentException("Invalid content-addressed scope \"{$scope}\"");
        }
        if (preg_match(self::SHA256_PATTERN, $sha256) !== 1) {
            throw new InvalidArgumentException("Invalid SHA-256 digest \"{$sha256}\"");
        }
        if ($extension !== null && preg_match(self::EXTENSION_PATTERN, $extension) !== 1) {
            throw new InvalidArgumentException("Invalid extension \"{$extension}\"");
        }

        return $scope
            . '/' . substr($sha256, 0, 2)
            . '/' . substr($sha256, 2, 2)
            . '/' . $sha256
            . ($extension === null ? '' : '.' . $extension);
    }
}
