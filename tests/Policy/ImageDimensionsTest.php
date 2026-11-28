<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Policy;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\Policy\GifReader;
use Rasuvaeff\Yii3Filestorage\Policy\ImageDimensions;
use Rasuvaeff\Yii3Filestorage\Stream\StreamReader;
use Rasuvaeff\Yii3Filestorage\Tests\Support\Gif;
use Rasuvaeff\Yii3Filestorage\Tests\Support\Jpeg;
use Rasuvaeff\Yii3Filestorage\Tests\Support\ShortReadingStream;
use Rasuvaeff\Yii3Filestorage\Tests\Support\Tiff;
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
#[Covers(GifReader::class)]
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
        Assert::same($this->jpeg("\xFF\xD8\xFF\xFF\xFF" . substr(Jpeg::sof(8, 4), 1) . "\xFF\xD9"), [8, 4]);
    }

    public function aLongRunOfFillBytesIsSkippedAsLibjpegSkipsIt(): void
    {
        $fill = str_repeat("\xFF", 5000);

        Assert::same($this->jpeg("\xFF\xD8" . $fill . substr(Jpeg::sof(8, 4), 1) . "\xFF\xD9"), [8, 4]);
    }

    /**
     * Review 1.0 pass 2, N2: libjpeg warns "Corrupt JPEG data: N extraneous
     * bytes before marker" and carries on, and so do PHP's getimagesize() and
     * Pillow. The walk refused such files as malformed. Pillow-made 64×48 with
     * two zero bytes after APP0 — probe q1's `extraneous.jpg`.
     */
    public function extraneousBytesBetweenSegmentsAreSkippedLikeLibjpegSkipsThem(): void
    {
        $bytes = (string) file_get_contents(__DIR__ . '/../Support/fixtures/extraneous.jpg');

        Assert::same($this->jpeg($bytes), [64, 48]);
    }

    /**
     * Two frame headers: libjpeg refuses the file (`JERR_SOF_DUPLICATE`), but
     * Pillow reports the *last* one — so a decoy 1×1 frame ahead of a
     * 60000×60000 one passed as 1×1 (found while fixing pass 2, N2). The walk
     * goes on to the start of scan, and a second frame header is undetermined.
     */
    public function aSecondFrameHeaderIsUndetermined(): void
    {
        Assert::null($this->jpeg(Jpeg::of([Jpeg::sof(1, 1), Jpeg::sof(60_000, 60_000)])));
        Assert::null($this->jpeg(Jpeg::of([Jpeg::sof(1, 1), Jpeg::app(1, 30), Jpeg::sof(60_000, 60_000, 0xC2)])));
    }

    public function theWalkStopsAtTheStartOfScan(): void
    {
        $scan = Jpeg::segment(0xDA, "\x01\x01\x00\x00\x3F\x00") . "\xFF\xC0 entropy-coded data, never walked";

        Assert::same($this->jpeg("\xFF\xD8" . Jpeg::sof(64, 48) . Jpeg::segment(0xC4, str_repeat("\0", 20)) . $scan), [64, 48]);
    }

    public function aWalkThatBreaksAfterTheFrameIsUndetermined(): void
    {
        Assert::null($this->jpeg(Jpeg::of([Jpeg::sof(8, 4)], withEoi: false)));
        Assert::null($this->jpeg("\xFF\xD8" . Jpeg::sof(8, 4) . "\xFF\xE0\xFF\xF0"));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function gapProvider(): iterable
    {
        yield 'zeros after SOI' => ["\xFF\xD8\x00\x00" . Jpeg::sof(8, 4) . "\xFF\xD9"];
        yield 'a stuffed zero is data, not a marker' => ["\xFF\xD8\xFF\x00" . Jpeg::sof(8, 4) . "\xFF\xD9"];
        yield 'a long gap after a segment' => ["\xFF\xD8" . Jpeg::app(0, 20) . str_repeat("\x11", 100_000) . Jpeg::sof(8, 4) . "\xFF\xD9"];
        yield 'a gap spanning the scan chunk with FF 00 inside' => [
            "\xFF\xD8" . Jpeg::app(0, 20) . str_repeat("\x11", 8190) . "\xFF\x00\x22" . Jpeg::sof(8, 4) . "\xFF\xD9",
        ];
    }

    #[DataProvider('gapProvider')]
    public function garbageBetweenSegmentsIsSkipped(string $bytes): void
    {
        Assert::same($this->jpeg($bytes), [8, 4]);
    }

    /**
     * What keeps A1 closed with the lenient gap: a segment is still stepped
     * over by its length, never scanned. A frame header inside a segment — the
     * EXIF thumbnail, or a decoy inside a comment — stays invisible, exactly
     * as it is to libjpeg.
     */
    public function aDecoyFrameInsideASegmentIsStillNotFoundByTheGapScan(): void
    {
        $decoy = Jpeg::segment(0xFE, "\x00" . Jpeg::sof(1, 1));

        Assert::same($this->jpeg("\xFF\xD8" . $decoy . "\x00\x00" . Jpeg::sof(60_000, 60_000) . "\xFF\xD9"), [60_000, 60_000]);
    }

    /**
     * A segment length is two bytes, high byte first. Misreading a length over
     * 255 lands inside the payload, where this decoy waits.
     */
    public function aSegmentLongerThan255BytesIsSteppedOverWhole(): void
    {
        $decoy = Jpeg::segment(0xE1, str_repeat("\x00", 250) . Jpeg::sof(1, 1) . str_repeat("\x00", 30));

        Assert::same($this->jpeg(Jpeg::of([$decoy, Jpeg::sof(8, 4)])), [8, 4]);
    }

    /**
     * TEM and RST0–7 carry no length field: reading two bytes after them as a
     * length would swallow the next marker.
     */
    public function standaloneMarkersAreSteppedOverWithoutALength(): void
    {
        $standalone = "\xFF\x01\xFF\xD0\xFF\xD7";

        Assert::same($this->jpeg("\xFF\xD8" . $standalone . Jpeg::sof(12, 9) . "\xFF\xD9"), [12, 9]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function undeterminedProvider(): iterable
    {
        yield 'not a JPEG at all' => ['raster bytes nothing can parse'];
        yield 'SOI only' => ["\xFF\xD8"];
        yield 'garbage and no marker after it' => ["\xFF\xD8\x00\x00\x11\xFF\x00"];
        yield 'scan before any frame' => [Jpeg::of([Jpeg::segment(0xDA, "\x01\x01\x00\x00\x3F\x00"), Jpeg::sof(8, 4)])];
        yield 'EOI before any frame' => ["\xFF\xD8\xFF\xD9" . Jpeg::sof(8, 4)];
        yield 'a second SOI' => ["\xFF\xD8\xFF\xD8" . Jpeg::sof(8, 4)];
        yield 'a second SOI after the frame, shaped like a segment' => [Jpeg::of([Jpeg::sof(8, 4), "\xFF\xD8\x00\x04\x00\x00"])];
        yield 'a valid walk behind the wrong signature' => ["\x00\x00" . substr(Jpeg::of([Jpeg::sof(8, 4)]), 2)];
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

    public function theWalkedFormatsAreParseable(): void
    {
        foreach (['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/tiff'] as $type) {
            Assert::true(ImageDimensions::isParseable($type), $type);
        }
        foreach (['image/avif', 'image/heic', 'image/bmp', 'image/svg+xml', 'text/plain'] as $type) {
            Assert::false(ImageDimensions::isParseable($type), $type);
        }
    }

    /**
     * Review 1.0 pass 2, N1: getimagesize() reports a GIF's logical screen,
     * and every frame carries its own size. A 1×1 screen around an
     * 8000×8000 frame passed as 1×1 — Pillow decodes it at 8000×8000. The
     * fixture is probe q1's `screen1x1-64mp.gif`, made by Pillow.
     */
    public function aGifFrameLargerThanItsScreenIsUndetermined(): void
    {
        $bytes = (string) file_get_contents(__DIR__ . '/../Support/fixtures/screen1x1-64mp.gif');
        Assert::same(getimagesizefromstring($bytes)[0] ?? null, 1, 'the fixture really lies about its screen');

        Assert::null($this->gif($bytes));
    }

    public function aGifIsMeasuredByItsScreenWhenEveryFrameFits(): void
    {
        $gif = Gif::of(300, 200, [
            Gif::extension(0xF9),
            Gif::frame(300, 200, subBlocks: [255, 255, 7]),
            Gif::extension(0xFE, [3, 9]),
            Gif::frame(100, 50, left: 200, top: 150, localColorTable: true),
        ]);

        Assert::same($this->gif($gif), [300, 200]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function gifOutsideTheScreenProvider(): iterable
    {
        yield 'wider than the screen' => [Gif::of(10, 10, [Gif::frame(11, 10)])];
        yield 'taller than the screen' => [Gif::of(10, 10, [Gif::frame(10, 11)])];
        yield 'offset past the right edge' => [Gif::of(10, 10, [Gif::frame(5, 5, left: 6)])];
        yield 'offset past the bottom edge' => [Gif::of(10, 10, [Gif::frame(5, 5, top: 6)])];
        yield 'a later frame only' => [Gif::of(10, 10, [Gif::frame(10, 10), Gif::frame(8000, 8000)])];
        yield 'behind a comment that hides it from a prefix' => [
            Gif::of(1, 1, [Gif::extension(0xFE, array_fill(0, 2000, 255)), Gif::frame(8000, 8000)]),
        ];
    }

    #[DataProvider('gifOutsideTheScreenProvider')]
    public function aGifFrameOutsideTheScreenIsUndetermined(string $bytes): void
    {
        Assert::null($this->gif($bytes));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function gifUndeterminedProvider(): iterable
    {
        yield 'not a GIF' => ['GIF00a' . str_repeat("\x00", 20)];
        yield 'header cut short' => ['GIF89a' . "\x0A\x00"];
        yield 'zero screen' => [Gif::of(0, 10, [Gif::frame(0, 10)])];
        yield 'no frame at all' => [Gif::of(10, 10, [])];
        yield 'unknown block' => [Gif::of(10, 10, ["\x99"])];
        yield 'descriptor cut short' => [substr(Gif::of(10, 10, [Gif::frame(10, 10)]), 0, 19 + 6)];
        yield 'zero-sized frame' => [Gif::of(10, 10, [Gif::frame(0, 10)])];
        yield 'zero-height frame' => [Gif::of(10, 10, [Gif::frame(10, 0)])];
        yield 'a valid body behind the wrong signature' => ['GIF00a' . substr(Gif::of(10, 10, [Gif::frame(10, 10)]), 6)];
        yield 'an unknown block after a frame that fits' => [Gif::of(10, 10, [Gif::frame(10, 10), "\x99"])];
    }

    /**
     * The colour tables are sized from the packed field's low three bits and
     * present only when its top bit says so. A misread size desynchronises the
     * walk: it either trips over a table byte or loses the frame that follows.
     */
    public function colourTablesAreSkippedByTheirDeclaredSize(): void
    {
        $frame = Gif::frame(10, 10);
        $noGlobalTable = Gif::of(10, 10, [$frame], globalColorTable: false);
        $fourEntryGlobalTable = 'GIF89a' . pack('vv', 10, 10) . "\x81\x00\x00" . str_repeat("\x00", 12) . $frame . ';';

        Assert::same($this->gif($noGlobalTable), [10, 10]);
        Assert::same($this->gif($fourEntryGlobalTable), [10, 10]);
    }

    /**
     * A local colour table is skipped by its size too, so the frame after it
     * is still read — and here that frame outgrows the screen.
     */
    #[DataProvider('frameBehindALocalTableProvider')]
    public function theFrameAfterALocalColourTableIsStillChecked(string $descriptorFlags, string $table): void
    {
        $first = ',' . pack('vvvv', 0, 0, 10, 10) . $descriptorFlags . $table . "\x02" . "\x0A" . str_repeat("\x2A", 10) . "\x00";

        Assert::null($this->gif(Gif::of(10, 10, [$first, Gif::frame(8000, 8000)])));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function frameBehindALocalTableProvider(): iterable
    {
        yield 'a four-entry local table' => ["\x81", str_repeat("\x00", 12)];
        yield 'size bits without the table flag mean no table' => ["\x07", ''];
    }

    #[DataProvider('gifUndeterminedProvider')]
    public function whatTheGifWalkCannotFollowIsUndetermined(string $bytes): void
    {
        Assert::null($this->gif($bytes));
    }

    /**
     * Truncated frame data or a missing trailer is what decoders tolerate; the
     * frames read so far all fit, so the screen is the answer.
     */
    public function aGifCutShortAfterAFrameIsMeasuredByWhatWasRead(): void
    {
        Assert::same($this->gif(Gif::of(10, 10, [Gif::frame(10, 10)], trailer: false)), [10, 10]);
        Assert::same($this->gif(substr(Gif::of(10, 10, [Gif::frame(10, 10, subBlocks: [200])]), 0, -50)), [10, 10]);
    }

    public function moreGifFramesThanTheCapAreUndetermined(): void
    {
        $frames = array_fill(0, ImageDimensions::MAX_GIF_FRAMES + 1, Gif::frame(2, 2, subBlocks: [1]));

        Assert::null($this->gif(Gif::of(2, 2, $frames)));
        Assert::same($this->gif(Gif::of(2, 2, \array_slice($frames, 1))), [2, 2]);
    }

    /**
     * One-byte sub-blocks are the costliest shape to walk; the cap bounds the
     * walk, not the file.
     */
    public function moreGifSubBlocksThanTheCapAreUndetermined(): void
    {
        $atCap = Gif::frame(2, 2, subBlocks: array_fill(0, ImageDimensions::MAX_GIF_SUB_BLOCKS, 1));
        $pastCap = Gif::frame(2, 2, subBlocks: array_fill(0, ImageDimensions::MAX_GIF_SUB_BLOCKS + 1, 1));

        Assert::same($this->gif(Gif::of(2, 2, [$atCap])), [2, 2]);
        Assert::null($this->gif(Gif::of(2, 2, [$pastCap])));
    }

    public function theGifWalkWorksThroughShortReads(): void
    {
        $gif = Gif::of(40, 30, [Gif::extension(0xFF, [11, 3]), Gif::frame(40, 30, subBlocks: [255, 255, 255])]);
        $stream = new ShortReadingStream($this->factory->createStream($gif), 7);

        Assert::same(ImageDimensions::measure($stream, 'image/gif'), [40, 30]);
    }

    /**
     * Review 1.0 pass 2, N3: a TIFF writer may put the IFD after the image
     * data, and the 256 KiB window then saw nothing — so a 60000×60000 TIFF
     * passed unmeasured.
     */
    public function aTiffIfdPastTheOldWindowIsMeasured(): void
    {
        Assert::same($this->tiff(Tiff::of([[60_000, 60_000]], padding: 300_000)), [60_000, 60_000]);
    }

    /**
     * @return iterable<string, array{string, array{int, int}}>
     */
    public static function tiffProvider(): iterable
    {
        yield 'big-endian LONG' => [Tiff::of([[640, 480]]), [640, 480]];
        yield 'little-endian LONG' => [Tiff::of([[640, 480]], bigEndian: false), [640, 480]];
        yield 'big-endian SHORT' => [Tiff::of([[640, 480]], type: 3), [640, 480]];
        yield 'little-endian SHORT' => [Tiff::of([[640, 480]], bigEndian: false, type: 3), [640, 480]];
        yield 'the largest page of several' => [Tiff::of([[10, 10], [5000, 4000], [20, 20]]), [5000, 4000]];
        // Largest by area, width times height, whatever the shapes.
        yield 'a wide page after a tall one' => [Tiff::of([[1, 100], [20, 6]]), [20, 6]];
        yield 'a tall page after a wide one' => [Tiff::of([[100, 1], [6, 20]]), [6, 20]];
        yield 'of two equal areas the first' => [Tiff::of([[4, 1], [2, 2]]), [4, 1]];
        yield 'a tag before the dimensions' => [
            'MM' . pack('nN', 42, 8) . pack('n', 3) . pack('nnNN', 254, 4, 1, 0)
                . pack('nnNN', 256, 4, 1, 640) . pack('nnNN', 257, 4, 1, 480) . pack('N', 0),
            [640, 480],
        ];
    }

    /**
     * @param array{int, int} $expected
     */
    #[DataProvider('tiffProvider')]
    public function aTiffIsMeasuredFromItsIfdChain(string $bytes, array $expected): void
    {
        Assert::same($this->tiff($bytes), $expected);
    }

    /**
     * A directory without dimensions in the chain — a thumbnail table, EXIF
     * or private data — is skipped, not taken for a malformed file.
     */
    public function aTiffDirectoryWithoutDimensionsIsSkipped(): void
    {
        $first = pack('n', 2) . pack('nnNN', 256, 4, 1, 640) . pack('nnNN', 257, 4, 1, 480) . pack('N', 8 + 2 + 24 + 4);
        $second = pack('n', 1) . pack('nnNN', 305, 2, 4, 0) . pack('N', 0);

        Assert::same($this->tiff('MM' . pack('nN', 42, 8) . $first . $second), [640, 480]);
    }

    public function aRealTiffIsMeasured(): void
    {
        $bytes = (string) file_get_contents(__DIR__ . '/../Support/fixtures/real.tif');
        $expected = getimagesizefromstring($bytes);
        Assert::true($expected !== false);

        Assert::same($this->tiff($bytes), [$expected[0], $expected[1]]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tiffUndeterminedProvider(): iterable
    {
        yield 'not a TIFF' => ['XX' . str_repeat("\x00", 20)];
        yield 'BigTIFF' => ['MM' . pack('n', 43) . str_repeat("\x00", 20)];
        yield 'IFD offset past the end' => ['MM' . pack('nN', 42, 1_000_000)];
        yield 'IFD offset inside the header' => ['MM' . pack('nN', 42, 4)];
        yield 'no width tag' => ['MM' . pack('nN', 42, 8) . pack('n', 1) . pack('nnNN', 257, 4, 1, 10) . pack('N', 0)];
        yield 'a width of another type' => [
            'MM' . pack('nN', 42, 8) . pack('n', 2) . pack('nnNN', 256, 5, 1, 10) . pack('nnNN', 257, 4, 1, 10) . pack('N', 0),
        ];
        yield 'zero width' => [Tiff::of([[0, 10]])];
        yield 'zero height' => [Tiff::of([[10, 0]])];
        yield 'no height tag' => ['MM' . pack('nN', 42, 8) . pack('n', 1) . pack('nnNN', 256, 4, 1, 10) . pack('N', 0)];
        yield 'a valid chain behind an unknown byte order' => ['XX' . substr(Tiff::of([[640, 480]]), 2)];
        yield 'a valid chain behind the BigTIFF magic' => ['MM' . pack('n', 43) . substr(Tiff::of([[640, 480]]), 4)];
        yield 'entries cut short' => [substr(Tiff::of([[640, 480]]), 0, 20)];
        yield 'an IFD chain that loops' => [
            'MM' . pack('nN', 42, 8) . pack('n', 2) . pack('nnNN', 256, 4, 1, 10) . pack('nnNN', 257, 4, 1, 10) . pack('N', 8),
        ];
    }

    #[DataProvider('tiffUndeterminedProvider')]
    public function whatTheTiffWalkCannotFollowIsUndetermined(string $bytes): void
    {
        Assert::null($this->tiff($bytes));
    }

    public function moreTiffPagesThanTheCapAreUndetermined(): void
    {
        $pages = array_fill(0, ImageDimensions::MAX_TIFF_IFDS + 1, [2, 2]);

        Assert::null($this->tiff(Tiff::of($pages)));
        Assert::same($this->tiff(Tiff::of(\array_slice($pages, 1))), [2, 2]);
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

    /**
     * @return array{int, int}|null
     */
    private function gif(string $bytes): ?array
    {
        return ImageDimensions::measure($this->factory->createStream($bytes), 'image/gif');
    }

    /**
     * @return array{int, int}|null
     */
    private function tiff(string $bytes): ?array
    {
        return ImageDimensions::measure($this->factory->createStream($bytes), 'image/tiff');
    }
}
