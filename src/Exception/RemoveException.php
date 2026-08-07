<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Exception;

use RuntimeException;

/**
 * The metadata row was removed but its physical object was not.
 *
 * The remaining object is a reclaimable orphan: no row references it.
 *
 * @api
 */
final class RemoveException extends RuntimeException implements FilestorageException {}
