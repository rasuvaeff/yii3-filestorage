<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store;

/**
 * A per-process counter for {@see QuarantinedObject::newToken()}: the readonly
 * value object cannot hold it.
 *
 * @internal
 */
final class QuarantineSequence
{
    private static int $last = 0;

    private function __construct() {}

    /**
     * @return int<0, 16777215>
     */
    public static function next(): int
    {
        self::$last = (self::$last + 1) % 0x1000000;

        return max(0, min(0xFFFFFF, self::$last));
    }
}
