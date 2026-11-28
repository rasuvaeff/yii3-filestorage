<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Exception;

/**
 * The upload is too large — whichever limit said so.
 *
 * One violation used to arrive as two unrelated classes: a group's `maxBytes`
 * against a known size was an {@see UploadSizeException}, the same limit
 * crossed mid-copy by an unknown-length body was an
 * {@see UploadTooLargeException}, and PHP's own `upload_max_filesize` was a
 * bare {@see UploadFailedException} (review 1.0 B3). An HTTP layer answers 413
 * to all of them, so catch this.
 *
 * Implemented by {@see UploadSizeException} (the group's limit, from the policy
 * or from a store during the copy) and {@see UploadTooLargeException} (an
 * ingress limit: the spool cap, a data URL over its cap, PHP's
 * `UPLOAD_ERR_INI_SIZE` / `UPLOAD_ERR_FORM_SIZE`).
 *
 * @api
 */
interface SizeLimitExceededException extends FilestorageException {}
