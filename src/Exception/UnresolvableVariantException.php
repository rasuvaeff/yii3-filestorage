<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Exception;

use InvalidArgumentException;

/**
 * The named derivative preset is not configured in this installation.
 *
 * Distinct from a generation failure on purpose: "no such preset" is the same
 * class of fact as "no such file" — an HTTP caller maps it to a 404 — while a
 * preset that exists but cannot render is breakage the caller should surface.
 * {@see \Rasuvaeff\Yii3Filestorage\Store\DerivativeResolverInterface} throws
 * this from both methods for the first and other
 * {@see FilestorageException} subtypes for the second.
 *
 * @api
 */
class UnresolvableVariantException extends InvalidArgumentException implements FilestorageException {}
