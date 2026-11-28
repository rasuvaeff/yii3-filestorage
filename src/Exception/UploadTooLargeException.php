<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Exception;

use RuntimeException;

/**
 * An ingress limit was exceeded before the upload reached any group policy.
 *
 * A non-seekable upload over the spool cap, a data URL that decodes past it,
 * or PHP's own `UPLOAD_ERR_INI_SIZE` / `UPLOAD_ERR_FORM_SIZE`. A group's
 * `maxBytes` is {@see UploadSizeException}; catch
 * {@see SizeLimitExceededException} for both.
 *
 * @api
 */
final class UploadTooLargeException extends RuntimeException implements SizeLimitExceededException {}
