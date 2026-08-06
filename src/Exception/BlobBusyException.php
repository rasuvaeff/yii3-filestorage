<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Exception;

use RuntimeException;

/**
 * The blob is being deleted; the same call will succeed once the lease ends.
 *
 * Separate from {@see LedgerException} because the correct response is
 * different: this one is transient by construction — a deletion lease has a
 * deadline — so callers back off and retry, and only give up after a bounded
 * number of attempts.
 *
 * @api
 */
final class BlobBusyException extends RuntimeException implements FilestorageException {}
