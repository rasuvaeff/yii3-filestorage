<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Exception;

use RuntimeException;

/**
 * The object is larger than the configured inline-read cap.
 *
 * Read it with {@see \Rasuvaeff\Yii3Filestorage\StorageInterface::stream()} instead.
 *
 * @api
 */
final class ContentTooLargeException extends RuntimeException implements FilestorageException {}
