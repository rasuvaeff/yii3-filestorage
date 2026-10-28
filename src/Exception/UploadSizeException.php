<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Exception;

/**
 * The upload is over the group's size limit.
 *
 * A policy violation, but a specifically-typed one: an HTTP layer answers 413
 * for this and 415 for {@see UnsupportedMediaTypeException}, and it can only
 * tell them apart when the two are different classes rather than different
 * messages.
 *
 * @api
 */
final class UploadSizeException extends PolicyViolationException {}
