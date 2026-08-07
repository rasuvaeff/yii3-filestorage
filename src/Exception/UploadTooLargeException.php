<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Exception;

use RuntimeException;

/**
 * A non-seekable upload exceeded the spool cap before it could be buffered.
 *
 * @api
 */
final class UploadTooLargeException extends RuntimeException implements FilestorageException {}
