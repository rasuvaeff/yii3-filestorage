<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Exception;

use RuntimeException;

/**
 * The PHP upload itself failed, so its stream must not be consumed.
 *
 * @api
 */
final class UploadFailedException extends RuntimeException implements FilestorageException {}
