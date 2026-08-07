<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Id;

/**
 * Mints the identifier of a file before it is written.
 *
 * Assigning the id up front is what keeps {@see \Rasuvaeff\Yii3Filestorage\File}
 * free of a nullable id and of a `withId()` mutator.
 *
 * @api
 */
interface IdGeneratorInterface
{
    /**
     * @return non-empty-string
     */
    public function generate(): string;
}
