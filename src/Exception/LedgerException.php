<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Exception;

use RuntimeException;

/**
 * The blob ledger refused an operation that its state made impossible.
 *
 * A committed reservation that does not exist, a file that does not match the
 * blob it is being committed against, two different sizes for one content hash.
 * These are not retryable and not user errors: something upstream of the ledger
 * is inconsistent, and continuing would trade a loud failure for a silent one.
 *
 * @api
 */
final class LedgerException extends RuntimeException implements FilestorageException {}
