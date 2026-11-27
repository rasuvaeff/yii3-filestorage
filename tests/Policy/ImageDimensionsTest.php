<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Policy;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\Policy\ImageDimensions;
use Rasuvaeff\Yii3Filestorage\Stream\StreamReader;
use Rasuvaeff\Yii3Filestorage\Tests\Support\Jpeg;
use Rasuvaeff\Yii3Filestorage\Tests\Support\ShortReadingStream;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * The JPEG marker walk, branch by branch. Review 1.0 A1 (summary A2): the
 * frame header is found by following segment lengths, never by scanning, and
 * every shape the walk cannot follow is "undetermined", which the policy
 * refuses.
 */
#[Test]
#[Covers(ImageDimensions::class)]
#[Covers(StreamReader::class)]
final class ImageDimensionsTest
{
    private Psr17Factory $factory;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    public function aBaselineFrameIsMeasuredAsWidthThenHeight(): void
    {
        Assert::same($this->jpeg(Jpeg::of([Jpeg::sof(640, 480)])), [640, 480]);
    }

    /**
     * Every SOF variant carries the same header; the three non-frame markers
     * inside the C0–CF range (DHT, JPG, DAC) are skipped as ordinary segments.
     */
    #[DataProvider('frameMarkerProvider')]
    public function everyFrameMarkerIsRecognised(int $marker): void
    {
        Assert::same($this->jpeg(Jpeg::of([Jpeg::sof(30, 20, $marker)])), [30, 20]);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function frameMarkerProvider(): iterable
    {
        foreach ([0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF] as $marker) {
            yield sprintf('SOF %02X', $marker) => [$marker];
        }
    }

    /**
     * DHT/JPG/DAC look like frame markers by range. Read as one, their payload
     * would be "dimensions"; skipped, the real frame after them is found.
     */
    #[DataProvider('nonFrameMarkerProvider')]
    public function theNonFrameMarkersInTheFrameRangeAreSkipped(int $marker): void
    {
        $decoy = Jpeg::segment($marker, "\x08" . pack('n', 1) . pack('n', 1) . "\x03");

        Assert::same($this->jpeg(Jpeg::of([$decoy, Jpeg::sof(70, 50)])), [70, 50]);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function nonFrameMarkerProvider(): iterable
    {
        yield 'DHT' => [0xC4];
        yield 'JPG' => [0xC8];
        yield 'DAC' => [0xCC];
    }

    /**
     * The EXIF thumbnail is a whole JPEG inside APP1, frame header included. A
     * scanner for `FFC0` finds the thumbnail's 160×120 and passes the bomb.
     */
    public function aThumbnailFrameInsideExifIsNotMistakenForTheImage(): void
    {
        $thumbnail = Jpeg::of([Jpeg::sof(160, 120)]);
        $exif = Jpeg::segment(0xE1, "Exif\0\0" . $thumbnail);

        Assert::same($this->jpeg(Jpeg::of([$exif, Jpeg::sof(60_000, 60_000)])), [60_000, 60_000]);
    }

    public function fillBytesBeforeAMarkerAreSkipped(): void
    {
        Assert::same($this->jpeg("\xFF\xD8\xFF\xFF\xFF" . substr(Jpeg::sof(8, 4), 1)), [8, 4]);
    }

    public function aRunOfFillBytesPastTheCapIsUndetermined(): void
    {
        $fill = str_repeat("\xFF", ImageDimensions::MAX_FILL_BYTES + 1);

        Assert::null($this->jpeg("\xFF\xD8" . $fill . substr(Jpeg::sof(8, 4), 1)));
    }

    public function fillBytesExactlyAtTheCapAreAccepted(): void
    {
        $fill = str_repeat("\xFF", ImageDimensions::MAX_FILL_BYTES);

        Assert::same($this->jpeg("\xFF\xD8" . $fill . substr(Jpeg::sof(8, 4), 1)), [8, 4]);
    }

    /**
     * TEM and RST0–7 carry no length field: reading two bytes after them as a
     * length would swallow the next marker.
     */
    public function standaloneMarkersAreSteppedOverWithoutALength(): void
    {
        $standalone = "\xFF\x01\xFF\xD0\xFF\xD7";

        Assert::same($this->jpeg("\xFF\xD8" . $standalone . Jpeg::sof(12, 9)), [12, 9]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function undeterminedProvider(): iterable
    {
        yield 'not a JPEG at all' => ['raster bytes nothing can parse'];
        yield 'SOI only' => ["\xFF\xD8"];
        yield 'garbage where a marker belongs' => ["\xFF\xD8\x00\x00" . Jpeg::sof(8, 4)];
        yield 'stuffed zero as a marker' => ["\xFF\xD8\xFF\x00" . Jpeg::sof(8, 4)];
        yield 'scan before any frame' => [Jpeg::of([Jpeg::segment(0xDA, "\x01\x01\x00\x00\x3F\x00"), Jpeg::sof(8, 4)])];
        yield 'EOI before any frame' => ["\xFF\xD8\xFF\xD9" . Jpeg::sof(8, 4)];
        yield 'a second SOI' => ["\xFF\xD8\xFF\xD8" . Jpeg::sof(8, 4)];
        yield 'length below two' => ["\xFF\xD8\xFF\xE0\x00\x01" . Jpeg::sof(8, 4)];
        yield 'length field cut short' => ["\xFF\xD8\xFF\xE0\x00"];
        yield 'segment running past the end' => ["\xFF\xD8\xFF\xE0\xFF\xF0abc" . Jpeg::sof(8, 4)];
        yield 'frame header cut short' => ["\xFF\xD8\xFF\xC0\x00\x11\x08\x00"];
        yield 'zero height (DNL-defined)' => [Jpeg::of([Jpeg::sof(8, 0)])];
        yield 'zero width' => [Jpeg::of([Jpeg::sof(0, 8)])];
        yield 'truncated after padding' => [substr(Jpeg::of([Jpeg::padding(1), Jpeg::sof(8, 4)]), 0, 30_000)];
        yield 'nothing after the last segment' => [Jpeg::of([Jpeg::app(0, 10)], withEoi: false)];
    }

    #[DataProvider('undeterminedProvider')]
    public function whatTheWalkCannotFollowIsUndetermined(string $bytes): void
    {
        Assert::null($this->jpeg($bytes));
    }

    /**
     * A bounded walk: a file of nothing but tiny segments cannot make the guard
     * itself the expensive part. Real encoders stay far below the cap — even an
     * ICC profile split into 64 KiB chunks.
     */
    public function moreSegmentsThanTheCapAreUndetermined(): void
    {
        $segments = array_fill(0, ImageDimensions::MAX_JPEG_SEGMENTS, Jpeg::segment(0xFE, ''));

        Assert::null($this->jpeg(Jpeg::of([...$segments, Jpeg::sof(8, 4)])));
    }

    public function aFrameAfterOneSegmentFewerThanTheCapIsFound(): void
    {
        $segments = array_fill(0, ImageDimensions::MAX_JPEG_SEGMENTS - 1, Jpeg::segment(0xFE, ''));

        Assert::same($this->jpeg(Jpeg::of([...$segments, Jpeg::sof(8, 4)])), [8, 4]);
    }

    public function theWalkWorksThroughShortReads(): void
    {
        $bytes = Jpeg::of([Jpeg::app(0, 500), Jpeg::sof(800, 600)]);
        $stream = new ShortReadingStream($this->factory->createStream($bytes), 1);

        Assert::same(ImageDimensions::measure($stream, 'image/jpeg'), [800, 600]);
    }

    public function aPngIsMeasuredFromItsHeader(): void
    {
        $ihdr = pack('NN', 33, 44) . "\x08\x02\x00\x00\x00";
        $png = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));

        Assert::same(ImageDimensions::measure($this->factory->createStream($png), 'image/png'), [33, 44]);
    }

    public function anUnparseablePngIsUndetermined(): void
    {
        Assert::null(ImageDimensions::measure($this->factory->createStream('not a png'), 'image/png'));
    }

    public function onlyTheFourCommonRasterFormatsAreParseable(): void
    {
        foreach (['image/jpeg', 'image/png', 'image/gif', 'image/webp'] as $type) {
            Assert::true(ImageDimensions::isParseable($type), $type);
        }
        foreach (['image/avif', 'image/heic', 'image/tiff', 'image/svg+xml', 'text/plain'] as $type) {
            Assert::false(ImageDimensions::isParseable($type), $type);
        }
    }

    public function readUpToReadsAcrossShortReadsAndStopsAtEof(): void
    {
        $stream = new ShortReadingStream($this->factory->createStream('abcdefghij'), 3);

        Assert::same(StreamReader::readUpTo($stream, 7), 'abcdefg');
        Assert::same(StreamReader::readUpTo($stream, 7), 'hij');
        Assert::same(StreamReader::readUpTo($stream, 7), '');
    }

    /**
     * @return array{int, int}|null
     */
    private function jpeg(string $bytes): ?array
    {
        return ImageDimensions::measure($this->factory->createStream($bytes), 'image/jpeg');
    }
}
