<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Exception;

use RuntimeException;

/**
 * Metadata could not be persisted after the object had been written.
 *
 * The store object has been removed on a best-effort basis; a failed removal
 * leaves a reclaimable orphan rather than a row pointing at missing bytes.
 *
 * @api
 */
final class AddException extends RuntimeException implements FilestorageException {}
