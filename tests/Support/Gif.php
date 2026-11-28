<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Support;

/**
 * GIF byte strings built block by block. The image data is a structurally
 * valid sub-block chain, not decodable LZW: the walk under test never decodes.
 *
 * @internal
 */
final class Gif
{
    /**
     * @param list<string> $blocks
     */
    public static function of(int $width, int $height, array $blocks, bool $globalColorTable = true, bool $trailer = true): string
    {
        $packed = $globalColorTable ? 0x80 : 0x00;

        return 'GIF89a' . pack('vv', $width, $height) . \chr($packed) . "\x00\x00"
            . ($globalColorTable ? str_repeat("\x00", 6) : '')
            . implode('', $blocks)
            . ($trailer ? ';' : '');
    }

    /**
     * An image descriptor followed by its data sub-blocks.
     *
     * @param list<int> $subBlocks Sizes of the data sub-blocks.
     */
    public static function frame(
        int $width,
        int $height,
        int $left = 0,
        int $top = 0,
        array $subBlocks = [10],
        bool $localColorTable = false,
    ): string {
        return ',' . pack('vvvv', $left, $top, $width, $height) . \chr($localColorTable ? 0x81 : 0x00)
            . ($localColorTable ? str_repeat("\x00", 12) : '')
            . "\x02" . self::subBlocks($subBlocks);
    }

    /**
     * An extension block: graphic control, comment, application.
     *
     * @param list<int> $subBlocks
     */
    public static function extension(int $label, array $subBlocks = [4]): string
    {
        return '!' . \chr($label) . self::subBlocks($subBlocks);
    }

    /**
     * @param list<int> $sizes
     */
    private static function subBlocks(array $sizes): string
    {
        $bytes = '';
        foreach ($sizes as $size) {
            $bytes .= \chr($size) . str_repeat("\x2A", $size);
        }

        return $bytes . "\x00";
    }
}
