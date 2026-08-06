<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Path;

use InvalidArgumentException;
use Override;

/**
 * `<scope>/<hash[0:2]>/<hash[2:4]>/<hash>/original`.
 *
 * The object has no extension on purpose: content identity must not move when
 * a MIME database is updated and the same bytes start being called something
 * else. It has no fallback either — the dedup coordinator calls it only once a
 * complete hash exists, and a file too large to hash takes the ordinary unique
 * path instead.
 *
 * @api
 */
final readonly class Sha256KeyGenerator implements ContentAddressedKeyGeneratorInterface
{
    private const string SCOPE_PATTERN = '~^[A-Za-z0-9][A-Za-z0-9_-]{0,63}(?:/[A-Za-z0-9][A-Za-z0-9_-]{0,63})*\z~';
    private const string SHA256_PATTERN = '/^[a-f0-9]{64}\z/';

    #[Override]
    public function generate(string $scope, string $sha256): string
    {
        if (preg_match(self::SCOPE_PATTERN, $scope) !== 1) {
            throw new InvalidArgumentException("Invalid content-addressed scope \"{$scope}\"");
        }
        if (preg_match(self::SHA256_PATTERN, $sha256) !== 1) {
            throw new InvalidArgumentException("Invalid SHA-256 digest \"{$sha256}\"");
        }

        return $scope
            . '/' . substr($sha256, 0, 2)
            . '/' . substr($sha256, 2, 2)
            . '/' . $sha256
            . '/original';
    }
}
