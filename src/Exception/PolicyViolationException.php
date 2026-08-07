<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Exception;

use RuntimeException;

/**
 * The upload does not satisfy the accept rules of its group.
 *
 * @api
 */
final class PolicyViolationException extends RuntimeException implements FilestorageException {}
