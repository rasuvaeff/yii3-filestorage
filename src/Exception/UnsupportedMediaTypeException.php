<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Exception;

/**
 * The upload's media type is not one the group accepts.
 *
 * A policy violation, but a specifically-typed one: an HTTP layer answers 415
 * for this and 413 for {@see UploadSizeException}, and it can only tell them
 * apart when the two are different classes rather than different messages.
 *
 * @api
 */
final class UnsupportedMediaTypeException extends PolicyViolationException {}
