<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Exception;

use RuntimeException;

/**
 * The upload does not satisfy the accept rules of its group.
 *
 * Not final: {@see UploadSizeException} and {@see UnsupportedMediaTypeException}
 * narrow the two branches an HTTP caller must tell apart (413 vs 415).
 *
 * @api
 */
class PolicyViolationException extends RuntimeException implements FilestorageException {}
