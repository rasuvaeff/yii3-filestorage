<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Support;

/**
 * TIFF byte strings: a header and a chain of IFDs carrying only the two
 * dimension tags, each IFD placed where the test wants it.
 *
 * @internal
 */
final class Tiff
{
    /**
     * @param list<array{int, int}> $pages Width and height per IFD.
     * @param int $padding Bytes of image data before the first IFD.
     */
    public static function of(array $pages, bool $bigEndian = true, int $padding = 0, int $type = 4): string
    {
        $short = $bigEndian ? 'n' : 'v';
        $long = $bigEndian ? 'N' : 'V';
        $bytes = ($bigEndian ? 'MM' : 'II') . pack($short, 42) . pack($long, 8 + $padding) . str_repeat("\x00", $padding);

        foreach ($pages as $index => [$width, $height]) {
            $next = $index === \count($pages) - 1 ? 0 : \strlen($bytes) + 2 + 2 * 12 + 4;
            $bytes .= pack($short, 2)
                . self::entry(256, $width, $type, $short, $long)
                . self::entry(257, $height, $type, $short, $long)
                . pack($long, $next);
        }

        return $bytes;
    }

    private static function entry(int $tag, int $value, int $type, string $short, string $long): string
    {
        $field = $type === 3 ? pack($short, $value) . "\x00\x00" : pack($long, $value);

        return pack($short, $tag) . pack($short, $type) . pack($long, 1) . $field;
    }
}
