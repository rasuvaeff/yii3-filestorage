<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Exception;

use Throwable;

/**
 * Marker for every exception thrown by this package, so a consumer can catch
 * the whole family without naming each class.
 *
 * @api
 */
interface FilestorageException extends Throwable {}
