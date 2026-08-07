<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Exception;

use RuntimeException;

/**
 * A physical store operation failed.
 *
 * @api
 */
final class StoreException extends RuntimeException implements FilestorageException {}
