<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Policy;

use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Filestorage\Exception\PolicyViolationException;
use Rasuvaeff\Yii3Filestorage\Policy\UploadPolicy;
use Rasuvaeff\Yii3Filestorage\Upload;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Assert\ExpectNoAssertions;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(UploadPolicy::class)]
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
            ->assertAcceptable($this->upload('x'), 'image/png');
    }

    public function aTypeOutsideTheAllowListIsRefusedAndTheMessageListsWhatIsAllowed(): void
    {
        $policy = new UploadPolicy(allowedMimeTypes: ['image/png']);

        Expect::exception(PolicyViolationException::class)->withMessageContaining('Allowed: image/png');

        $policy->assertAcceptable($this->upload('x'), 'text/plain');
    }

    /**
     * An unrecognised type fails a non-empty allow-list. Falling back to "well,
     * we could not tell, so let it through" is how a polyglot file gets in.
     */
    public function anUnrecognisedTypeFailsANonEmptyAllowList(): void
    {
        $policy = new UploadPolicy(allowedMimeTypes: ['image/png']);

        Expect::exception(PolicyViolationException::class)->withMessageContaining('<unrecognised>');

        $policy->assertAcceptable($this->upload('x'), null);
    }

    public function aKnownSizeOverTheCapIsRefusedBeforeAnyIo(): void
    {
        $policy = new UploadPolicy(maxBytes: 4);

        Expect::exception(PolicyViolationException::class)->withMessageContaining('over the 4 byte limit');

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

        $policy->assertAcceptable($this->upload(self::png(width: 40, height: 40)), 'image/png');
    }

    #[ExpectNoAssertions]
    public function anImageUnderThePixelCapPasses(): void
    {
        (new UploadPolicy(maxPixels: 10_000))
            ->assertAcceptable($this->upload(self::png(width: 40, height: 40)), 'image/png');
    }

    #[ExpectNoAssertions]
    public function aZeroPixelCapDisablesTheCheck(): void
    {
        (new UploadPolicy(maxPixels: 0))
            ->assertAcceptable($this->upload(self::png(width: 40, height: 40)), 'image/png');
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
        $upload = $this->upload(self::png(width: 4, height: 4));

        (new UploadPolicy(maxPixels: 1_000))->assertAcceptable($upload, 'image/png');

        Assert::same($upload->stream()->getContents(), self::png(width: 4, height: 4));
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

    private function upload(string $body): Upload
    {
        return Upload::fromStream($this->factory->createStream($body), 'thing.bin', $this->factory);
    }

    /**
     * A real PNG header, so `getimagesizefromstring()` has something to parse.
     */
    private static function png(int $width, int $height): string
    {
        $ihdr = pack('NN', $width, $height) . "\x08\x02\x00\x00\x00";

        return "\x89PNG\r\n\x1a\n"
            . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr))
            . pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));
    }
}
