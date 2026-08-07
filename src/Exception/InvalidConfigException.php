<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Exception;

use RuntimeException;

/**
 * The package is wired in a way that cannot work.
 *
 * The message names the missing binding and the way to supply it.
 *
 * @api
 */
final class InvalidConfigException extends RuntimeException implements FilestorageException {}
