<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Policy;

use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Filestorage\Exception\PolicyViolationException;
use Rasuvaeff\Yii3Filestorage\Exception\SizeLimitExceededException;
use Rasuvaeff\Yii3Filestorage\Exception\UnsupportedMediaTypeException;
use Rasuvaeff\Yii3Filestorage\Exception\UploadSizeException;
use Rasuvaeff\Yii3Filestorage\Policy\ImageDimensions;
use Rasuvaeff\Yii3Filestorage\Policy\UploadPolicy;
use Rasuvaeff\Yii3Filestorage\Tests\Support\Jpeg;
use Rasuvaeff\Yii3Filestorage\Tests\Support\ShortReadingStream;
use Rasuvaeff\Yii3Filestorage\Upload;
use Testo\Assert;
use Testo\Assert\ExpectNoAssertions;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(UploadPolicy::class)]
#[Covers(ImageDimensions::class)]
final class UploadPolicyTest
{
    private Psr17Factory $factory;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    #[ExpectNoAssertions]
    public function theDefaultPolicyAcceptsAnyTypeAndAnySize(): void
    {
        (new UploadPolicy())->assertAcceptable($this->upload('anything at all'), 'application/x-whatever');
    }

    #[ExpectNoAssertions]
    public function anEmptyAllowListAcceptsAnUnrecognisedType(): void
    {
        (new UploadPolicy())->assertAcceptable($this->upload('x'), null);
    }

    #[ExpectNoAssertions]
    public function anAllowedTypePasses(): void
    {
        (new UploadPolicy(allowedMimeTypes: ['image/png', 'image/jpeg']))
            ->assertAcceptable($this->upload($this->png(1, 1)), 'image/png');
    }

    public function aTypeOutsideTheAllowListIsRefusedAndTheMessageListsWhatIsAllowed(): void
    {
        $policy = new UploadPolicy(allowedMimeTypes: ['image/png']);

        Expect::exception(UnsupportedMediaTypeException::class)->withMessageContaining('Allowed: image/png');

        $policy->assertAcceptable($this->upload('x'), 'text/plain');
    }

    /**
     * An unrecognised type fails a non-empty allow-list. Falling back to "well,
     * we could not tell, so let it through" is how a polyglot file gets in.
     */
    public function anUnrecognisedTypeFailsANonEmptyAllowList(): void
    {
        $policy = new UploadPolicy(allowedMimeTypes: ['image/png']);

        Expect::exception(UnsupportedMediaTypeException::class)->withMessageContaining('<unrecognised>');

        $policy->assertAcceptable($this->upload('x'), null);
    }

    public function aKnownSizeOverTheCapIsRefusedBeforeAnyIo(): void
    {
        $policy = new UploadPolicy(maxBytes: 4);

        Expect::exception(UploadSizeException::class)->withMessageContaining('over the 4 byte limit');

        $policy->assertAcceptable($this->upload('12345'), 'text/plain');
    }

    #[ExpectNoAssertions]
    public function aSizeExactlyAtTheCapPasses(): void
    {
        (new UploadPolicy(maxBytes: 5))->assertAcceptable($this->upload('12345'), 'text/plain');
    }

    #[ExpectNoAssertions]
    public function aZeroCapMeansNoSizeLimit(): void
    {
        (new UploadPolicy(maxBytes: 0))->assertAcceptable($this->upload(str_repeat('x', 10_000)), 'text/plain');
    }

    /**
     * A decompression bomb is a small file that declares enormous dimensions;
     * the guard reads the header and never decodes a pixel.
     */
    public function anImageOverThePixelCapIsRefused(): void
    {
        $policy = new UploadPolicy(maxPixels: 100);

        Expect::exception(PolicyViolationException::class)->withMessageContaining('over the 100 pixel limit');

        $policy->assertAcceptable($this->upload($this->png(width: 40, height: 40)), 'image/png');
    }

    #[ExpectNoAssertions]
    public function anImageUnderThePixelCapPasses(): void
    {
        (new UploadPolicy(maxPixels: 10_000))
            ->assertAcceptable($this->upload($this->png(width: 40, height: 40)), 'image/png');
    }

    /**
     * The cap is inclusive, and one pixel over is not: a limit whose boundary
     * is off by one rejects legitimate uploads at exactly the size somebody
     * configured as acceptable.
     */
    #[ExpectNoAssertions]
    public function anImageExactlyAtThePixelCapPasses(): void
    {
        (new UploadPolicy(maxPixels: 1_200))
            ->assertAcceptable($this->upload($this->png(width: 40, height: 30)), 'image/png');
    }

    public function oneMorePixelThanTheCapIsRefused(): void
    {
        $policy = new UploadPolicy(maxPixels: 1_199);

        Expect::exception(PolicyViolationException::class)->withMessageContaining('1200 pixels');

        $policy->assertAcceptable($this->upload($this->png(width: 40, height: 30)), 'image/png');
    }

    /**
     * Width and height are reported the right way round. A non-square image is
     * the only thing that can catch the two being swapped.
     */
    public function theRefusalNamesTheDimensionsInTheRightOrder(): void
    {
        $policy = new UploadPolicy(maxPixels: 10);

        Expect::exception(PolicyViolationException::class)->withMessageContaining('Image is 40x30 = 1200 pixels');

        $policy->assertAcceptable($this->upload($this->png(width: 40, height: 30)), 'image/png');
    }

    #[ExpectNoAssertions]
    public function aZeroPixelCapDisablesTheCheck(): void
    {
        (new UploadPolicy(maxPixels: 0))
            ->assertAcceptable($this->upload($this->png(width: 40, height: 40)), 'image/png');
    }

    /**
     * SVG has no raster dimensions, so the pixel guard does not apply to it —
     * SVG is dangerous for an entirely different reason, handled by delivery.
     */
    #[ExpectNoAssertions]
    public function svgIsNotSubjectToThePixelCap(): void
    {
        (new UploadPolicy(maxPixels: 1))->assertAcceptable(
            $this->upload('<svg xmlns="http://www.w3.org/2000/svg"><rect width="99999" height="99999"/></svg>'),
            'image/svg+xml',
        );
    }

    #[ExpectNoAssertions]
    public function anUnparseableImagePassesUnlessDimensionsAreRequired(): void
    {
        (new UploadPolicy(maxPixels: 10))->assertAcceptable($this->upload('not really an image'), 'image/x-exotic');
    }

    /**
     * Opt-in, because formats the runtime cannot parse (AVIF, HEIC on older
     * builds) would otherwise be rejected wholesale.
     */
    public function anUnparseableImageIsRefusedWhenDimensionsAreRequired(): void
    {
        $policy = new UploadPolicy(maxPixels: 10, requireImageDimensions: true);

        Expect::exception(PolicyViolationException::class)->withMessageContaining('could not be established');

        $policy->assertAcceptable($this->upload('not really an image'), 'image/x-exotic');
    }

    #[ExpectNoAssertions]
    public function nonImagesAreNeverSubjectToThePixelCheck(): void
    {
        (new UploadPolicy(maxPixels: 1, requireImageDimensions: true))
            ->assertAcceptable($this->upload('%PDF-1.4'), 'application/pdf');
    }

    public function theDimensionCheckRewindsTheStream(): void
    {
        $upload = $this->upload($this->png(width: 4, height: 4));

        (new UploadPolicy(maxPixels: 1_000))->assertAcceptable($upload, 'image/png');

        Assert::same($upload->stream()->getContents(), $this->png(width: 4, height: 4));
    }

    public function aNegativeMaxBytesIsRejected(): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('maxBytes must not be negative');

        new UploadPolicy(maxBytes: -1);
    }

    public function aNegativeMaxPixelsIsRejected(): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('maxPixels must not be negative');

        new UploadPolicy(maxPixels: -1);
    }

    public function anEmptyAllowedMediaTypeIsRejected(): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('must not be empty strings');

        new UploadPolicy(allowedMimeTypes: ['image/png', '']);
    }

    /**
     * The byte cap is a boundary, and boundaries are where off-by-one lives.
     */
    #[Property(runs: 200)]
    public function everySizeUpToTheCapPassesAndEverySizeOverItFails(int $cap, int $over): void
    {
        $policy = new UploadPolicy(maxBytes: $cap);
        $accepted = true;

        try {
            $policy->assertAcceptable($this->upload(str_repeat('x', $cap)), 'text/plain');
        } catch (PolicyViolationException) {
            $accepted = false;
        }
        Assert::true($accepted);

        $refused = false;

        try {
            $policy->assertAcceptable($this->upload(str_repeat('x', $cap + $over)), 'text/plain');
        } catch (PolicyViolationException) {
            $refused = true;
        }
        Assert::true($refused);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function everySizeUpToTheCapPassesAndEverySizeOverItFailsGenerators(): array
    {
        return [
            'cap' => Gen::intBetween(1, 2_000),
            'over' => Gen::intBetween(1, 500),
        ];
    }

    /**
     * The typed violations must stay substitutable for the parent: consumers
     * catch `PolicyViolationException` and have answered 400 with it all
     * along — narrowing the hierarchy is not allowed to break them.
     */
    public function typedViolationsRemainPolicyViolations(): void
    {
        $upload = $this->upload('xxxxx');

        try {
            (new UploadPolicy(maxBytes: 4))->assertAcceptable($upload, 'text/plain');
            Assert::true(actual: false, message: 'a policy violation was expected');
        } catch (PolicyViolationException $e) {
            Assert::instanceOf($e, UploadSizeException::class);
            // Review 1.0 B3: and a size refusal, catchable with the spool cap's.
            Assert::instanceOf($e, SizeLimitExceededException::class);
        }

        try {
            (new UploadPolicy(allowedMimeTypes: ['image/png']))->assertAcceptable($upload, 'text/plain');
            Assert::true(actual: false, message: 'a policy violation was expected');
        } catch (PolicyViolationException $e) {
            Assert::instanceOf($e, UnsupportedMediaTypeException::class);
        }
    }

    private function upload(string $body): Upload
    {
        return Upload::fromStream($this->factory->createStream($body), 'thing.bin', $this->factory);
    }

    /**
     * The rejection must name what was actually detected: a report of
     * "<unrecognised>" for a type the detector did recognise would send the
     * operator hunting for a detection bug that does not exist.
     */
    public function aMismatchNamesTheDetectedType(): void
    {
        $policy = new UploadPolicy(['image/png']);

        Expect::exception(UnsupportedMediaTypeException::class)->withMessageContaining(
            'Media type image/jpeg is not accepted here. Allowed: image/png',
        );
        $policy->assertAcceptable($this->upload('jpeg'), 'image/jpeg');
    }

    /**
     * "requires dimensions" is about images it can measure; a plain text
     * upload has no dimensions to require and must sail through.
     */
    #[ExpectNoAssertions]
    public function aNonRasterUploadNeedsNoDimensionsEvenWhenTheyAreRequired(): void
    {
        $policy = new UploadPolicy(maxPixels: 0, requireImageDimensions: true);
        $policy->assertAcceptable($this->upload('plain text'), 'text/plain');
    }

    /**
     * Review 1.0 A1 (summary A2): for a format the guard can parse, "no
     * dimensions found" is no longer "nothing to check". Failing open here is
     * the whole bypass — a crafted file only has to hide its header.
     */
    #[DataProvider('parseableRasterProvider')]
    public function aParseableRasterWithoutDimensionsIsRefusedEvenWhenTheyAreNotRequired(string $mediaType): void
    {
        $policy = new UploadPolicy(maxPixels: 1000, requireImageDimensions: false);

        Expect::exception(PolicyViolationException::class)->withMessageContaining(
            "Image dimensions could not be established for {$mediaType}",
        );
        $policy->assertAcceptable($this->upload('raster bytes nothing can parse'), $mediaType);
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function parseableRasterProvider(): iterable
    {
        yield 'jpeg' => ['image/jpeg'];
        yield 'png' => ['image/png'];
        yield 'gif' => ['image/gif'];
        yield 'webp' => ['image/webp'];
    }

    /**
     * The fail-closed rule is bounded by the pixel cap: a group that switched
     * the cap off asked for no measurement, so a corrupt PNG is its business.
     */
    #[ExpectNoAssertions]
    public function aCorruptParseableRasterPassesWhenThePixelCapIsOff(): void
    {
        (new UploadPolicy(maxPixels: 0))->assertAcceptable($this->upload('raster bytes nothing can parse'), 'image/png');
    }

    /**
     * Probe p3 of the 1.0 review: five 65 000-byte APP2 segments push a
     * 60000×60000 frame header past the old 256 KiB window. It used to be
     * accepted by both the wildcard policy and a JPEG-only one.
     */
    public function aJpegPaddedPastTheOldHeaderWindowIsStillMeasured(): void
    {
        $bomb = Jpeg::of([Jpeg::padding(), Jpeg::sof(60_000, 60_000)]);
        Assert::true(\strlen($bomb) > UploadPolicy::MAX_IMAGE_HEADER_BYTES, 'the fixture really is past the window');

        Expect::exception(PolicyViolationException::class)->withMessageContaining(
            'Image is 60000x60000 = 3600000000 pixels, over the 40000000 pixel limit',
        );
        (new UploadPolicy(allowedMimeTypes: ['image/jpeg']))->assertAcceptable($this->upload($bomb), 'image/jpeg');
    }

    /**
     * The other side of A1: a real JPEG with a fat ICC or Photoshop block before
     * its frame header must be measured and accepted, not refused because the
     * header sits late. Measured, because 101×100 against a 10 000 cap fails.
     */
    public function aLegitimateJpegWithLargeMetadataBeforeTheFrameIsMeasured(): void
    {
        $policy = new UploadPolicy(maxPixels: 10_000, requireImageDimensions: true);
        $policy->assertAcceptable(
            $this->upload(Jpeg::of([Jpeg::app(0, 14), Jpeg::padding(), Jpeg::sof(100, 100)])),
            'image/jpeg',
        );

        Expect::exception(PolicyViolationException::class)->withMessageContaining('Image is 101x100');
        $policy->assertAcceptable(
            $this->upload(Jpeg::of([Jpeg::app(0, 14), Jpeg::padding(), Jpeg::sof(101, 100)])),
            'image/jpeg',
        );
    }

    /**
     * Probe p1 of the 1.0 review (B1): a seekable stream that hands out at
     * most 100 bytes per read used to turn the pixel check off — a one-read
     * prefix had no frame header in it.
     */
    public function shortReadsDoNotSwitchThePixelCheckOff(): void
    {
        $bomb = Jpeg::of([Jpeg::app(0, 300), Jpeg::sof(60_000, 60_000)]);
        $upload = Upload::fromStream(
            new ShortReadingStream($this->factory->createStream($bomb), 100),
            'bomb.jpg',
            $this->factory,
        );

        Expect::exception(PolicyViolationException::class)->withMessageContaining('Image is 60000x60000');
        (new UploadPolicy())->assertAcceptable($upload, 'image/jpeg');
    }

    /**
     * The same for the formats whose header sits at the start: the prefix for
     * `getimagesizefromstring()` is read in full, not in one call.
     */
    public function shortReadsStillMeasureAPng(): void
    {
        $upload = Upload::fromStream(
            new ShortReadingStream($this->factory->createStream($this->png(9_000, 9_000)), 3),
            'big.png',
            $this->factory,
        );

        Expect::exception(PolicyViolationException::class)->withMessageContaining('Image is 9000x9000');
        (new UploadPolicy())->assertAcceptable($upload, 'image/png');
    }

    #[ExpectNoAssertions]
    public function anUnparseableExoticRasterStillPassesWhenDimensionsAreNotRequired(): void
    {
        (new UploadPolicy(maxPixels: 10))->assertAcceptable($this->upload('ftypavif...'), 'image/avif');
    }

    public function theJpegWalkRewindsTheStream(): void
    {
        $upload = $this->upload(Jpeg::of([Jpeg::sof(10, 10)]));
        (new UploadPolicy())->assertAcceptable($upload, 'image/jpeg');

        Assert::same($upload->stream()->tell(), 0);
    }

    /**
     * maxPixels 0 means "no pixel cap" — an image of any size is fine, even
     * when the group still insists the dimensions be measurable.
     */
    #[ExpectNoAssertions]
    public function aZeroPixelCapImposesNoLimitEvenWithDimensionsRequired(): void
    {
        $policy = new UploadPolicy(maxPixels: 0, requireImageDimensions: true);
        $policy->assertAcceptable($this->upload($this->png(500, 400)), 'image/png');
    }

    /** A picture of exactly the pixel cap fits; one pixel more does not. */
    public function thePixelCapIsInclusive(): void
    {
        $atCap = new UploadPolicy(maxPixels: 1, requireImageDimensions: true);
        $atCap->assertAcceptable($this->upload($this->png(1, 1)), 'image/png');

        Expect::exception(PolicyViolationException::class)->withMessageContaining('over the 1 pixel limit');
        (new UploadPolicy(maxPixels: 1, requireImageDimensions: true))->assertAcceptable($this->upload($this->png(2, 1)), 'image/png');
    }

    /**
     * A real PNG header, so `getimagesizefromstring()` has something to parse.
     */
    private function png(int $width, int $height): string
    {
        $ihdr = pack('NN', $width, $height) . "\x08\x02\x00\x00\x00";

        return "\x89PNG\r\n\x1a\n"
            . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr))
            . pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));
    }
}
