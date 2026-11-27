<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Reference;

/**
 * Who decides that a group's file is still in use.
 *
 * @api
 */
enum ReferenceAuthority: string
{
    /**
     * A `File` row is the reference: it lives until the application removes
     * it. The default, and the behaviour before 0.9.
     */
    case Rows = 'rows';

    /**
     * The application keeps references of its own — URLs inside text, JSON —
     * and a {@see ReferenceCheckerInterface} answers for them. `filestorage:gc`
     * releases rows of such a group that are older than its minimum age and
     * that the checker no longer names.
     */
    case Application = 'application';
}
