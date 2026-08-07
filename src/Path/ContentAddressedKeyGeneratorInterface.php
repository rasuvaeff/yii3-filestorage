<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Path;

/**
 * Produces the path of a *shared* object, addressed by its content hash.
 *
 * Deliberately separate from {@see PathGeneratorInterface}: a generator that
 * can return an existing path turns an ordinary write into sharing, and
 * sharing is only safe when a ledger tracks references (see the dedup design
 * in `rasuvaeff/yii3-filestorage-db`). Nothing on the base write path can
 * reach this contract.
 *
 * @api
 */
interface ContentAddressedKeyGeneratorInterface
{
    /**
     * @param non-empty-string $scope Isolation boundary of the shared object —
     *        tenant and group by default, so one tenant cannot learn that
     *        another already stored a given file.
     * @param non-empty-string $sha256 Lowercase hex digest of the complete content.
     *
     * @return non-empty-string
     */
    public function generate(string $scope, string $sha256): string;
}
