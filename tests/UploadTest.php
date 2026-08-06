<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests;

use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\UploadedFile;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Filestorage\Exception\UploadFailedException;
use Rasuvaeff\Yii3Filestorage\Exception\UploadTooLargeException;
use Rasuvaeff\Yii3Filestorage\Tests\Support\ForwardOnlyStream;
use Rasuvaeff\Yii3Filestorage\Upload;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(Upload::class)]
final class UploadTest
{
    private Psr17Factory $factory;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    public function fromStreamKeepsASeekableStreamAsItIs(): void
    {
        $stream = $this->factory->createStream('hello');

        $upload = Upload::fromStream($stream, 'a.txt', $this->factory);

        Assert::same($upload->stream(), $stream, 'a seekable stream is not copied');
        Assert::same($upload->originalName, 'a.txt');
        Assert::same($upload->size(), 5);
    }

    /**
     * The single guarantee everything downstream relies on: sniff, policy,
     * hash and write each read the same bytes from the start.
     */
    public function streamIsRewoundOnEveryCall(): void
    {
        $upload = Upload::fromStream($this->factory->createStream('hello'), 'a.txt', $this->factory);

        Assert::same($upload->stream()->getContents(), 'hello');
        Assert::same($upload->stream()->getContents(), 'hello');
        Assert::same($upload->stream()->read(2), 'he');
        Assert::same($upload->stream()->getContents(), 'hello');
    }

    public function aNonSeekableStreamIsSpooledIntoASeekableOne(): void
    {
        $upload = Upload::fromStream(
            new ForwardOnlyStream('streamed bytes'),
            'a.txt',
            $this->factory,
        );

        Assert::true($upload->stream()->isSeekable());
        Assert::same($upload->stream()->getContents(), 'streamed bytes');
        Assert::same($upload->stream()->getContents(), 'streamed bytes', 'the copy is re-readable');
    }

    public function spoolingStopsAtTheCap(): void
    {
        Expect::exception(UploadTooLargeException::class)->withMessageContaining('spool cap of 8 bytes');

        Upload::fromStream(
            new ForwardOnlyStream('123456789'),
            'a.txt',
            $this->factory,
            maxSpoolBytes: 8,
        );
    }

    public function spoolingAcceptsExactlyTheCap(): void
    {
        $upload = Upload::fromStream(
            new ForwardOnlyStream('12345678'),
            'a.txt',
            $this->factory,
            maxSpoolBytes: 8,
        );

        Assert::same($upload->stream()->getContents(), '12345678');
    }

    /**
     * Zero means "no cap", which is only defensible for a trusted offline
     * import — an HTTP-facing configuration must keep a finite value.
     */
    public function aZeroCapDisablesSpoolLimiting(): void
    {
        $upload = Upload::fromStream(
            new ForwardOnlyStream(str_repeat('x', 5_000)),
            'a.txt',
            $this->factory,
            maxSpoolBytes: 0,
        );

        Assert::same($upload->size(), 5_000);
    }

    public function aNegativeCapIsRejected(): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('must not be negative');

        Upload::fromStream($this->factory->createStream('x'), 'a.txt', $this->factory, maxSpoolBytes: -1);
    }

    public function anEmptyOriginalNameFallsBackToAPlaceholder(): void
    {
        $upload = Upload::fromStream($this->factory->createStream('x'), '', $this->factory);

        Assert::same($upload->originalName, 'file');
    }

    public function anEmptyClientMediaTypeBecomesNull(): void
    {
        $upload = Upload::fromStream(
            $this->factory->createStream('x'),
            'a.txt',
            $this->factory,
            clientMediaTypeHint: '',
        );

        Assert::null($upload->clientMediaTypeHint);
    }

    public function fromPathReadsTheFileAndTakesItsBasename(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fs-upload-');
        file_put_contents($path, 'from disk');

        try {
            $upload = Upload::fromPath($path, $this->factory);

            Assert::same($upload->stream()->getContents(), 'from disk');
            Assert::same($upload->originalName, basename($path));
        } finally {
            unlink($path);
        }
    }

    public function fromPathAcceptsAnExplicitOriginalName(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fs-upload-');
        file_put_contents($path, 'x');

        try {
            Assert::same(Upload::fromPath($path, $this->factory, 'invoice.pdf')->originalName, 'invoice.pdf');
        } finally {
            unlink($path);
        }
    }

    public function fromUploadedFileCarriesNameAndClientHint(): void
    {
        $file = new UploadedFile(
            $this->factory->createStream('body'),
            4,
            \UPLOAD_ERR_OK,
            'photo.jpg',
            'image/jpeg',
        );

        $upload = Upload::fromUploadedFile($file, $this->factory);

        Assert::same($upload->originalName, 'photo.jpg');
        Assert::same($upload->clientMediaTypeHint, 'image/jpeg');
        Assert::same($upload->stream()->getContents(), 'body');
    }

    public function fromUploadedFileWithoutAClientNameFallsBack(): void
    {
        $file = new UploadedFile($this->factory->createStream('body'), 4, \UPLOAD_ERR_OK);

        Assert::same(Upload::fromUploadedFile($file, $this->factory)->originalName, 'file');
    }

    /**
     * A partial upload must not enter the pipeline: its stream holds whatever
     * arrived before the failure, and storing that as a complete file is worse
     * than refusing it.
     */
    #[DataProvider('uploadErrorProvider')]
    public function fromUploadedFileRejectsAFailedUpload(int $error): void
    {
        $file = new UploadedFile($this->factory->createStream('partial'), 7, $error);

        Expect::exception(UploadFailedException::class)->withMessageContaining("PHP error code {$error}");

        Upload::fromUploadedFile($file, $this->factory);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function uploadErrorProvider(): iterable
    {
        yield 'ini size' => [\UPLOAD_ERR_INI_SIZE];
        yield 'form size' => [\UPLOAD_ERR_FORM_SIZE];
        yield 'partial' => [\UPLOAD_ERR_PARTIAL];
        yield 'no file' => [\UPLOAD_ERR_NO_FILE];
        yield 'no tmp dir' => [\UPLOAD_ERR_NO_TMP_DIR];
        yield 'cant write' => [\UPLOAD_ERR_CANT_WRITE];
        yield 'extension' => [\UPLOAD_ERR_EXTENSION];
    }

    public function sizeIsNullWhenTheStreamCannotReportIt(): void
    {
        $stream = new class extends ForwardOnlyStream {
            public function __construct()
            {
                parent::__construct('x');
            }

            public function isSeekable(): bool
            {
                return true;
            }

            public function getSize(): ?int
            {
                return null;
            }

            public function rewind(): void {}
        };

        Assert::null(Upload::fromStream($stream, 'a.txt', $this->factory)->size());
    }

    /**
     * The cap is a boundary, and boundaries are where off-by-one lives: any
     * body up to the cap survives, anything past it is refused, for every size.
     */
    #[Property(runs: 200)]
    public function spoolCapAcceptsUpToTheLimitAndRefusesBeyondIt(int $cap, int $under, int $over): void
    {
        $accepted = Upload::fromStream(
            new ForwardOnlyStream(str_repeat('x', $cap - $under)),
            'a.txt',
            $this->factory,
            maxSpoolBytes: $cap,
        );
        Assert::same($accepted->size(), $cap - $under);

        // Expect::exception() registers an expectation on the whole test, which
        // a property body runs hundreds of times — so the refusal is asserted
        // by catching it here instead.
        $refused = false;

        try {
            Upload::fromStream(
                new ForwardOnlyStream(str_repeat('x', $cap + $over)),
                'a.txt',
                $this->factory,
                maxSpoolBytes: $cap,
            );
        } catch (UploadTooLargeException) {
            $refused = true;
        }

        Assert::true($refused);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function spoolCapAcceptsUpToTheLimitAndRefusesBeyondItGenerators(): array
    {
        return [
            'cap' => Gen::intBetween(1, 4_000),
            'under' => Gen::intBetween(0, 1),
            'over' => Gen::intBetween(1, 500),
        ];
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function aSeekableStreamIsNeverCopiedGenerators(): array
    {
        return ['body' => Gen::stringOf(0, 200)];
    }

    /**
     * Copying a seekable stream would double memory for no reason, and would
     * silently detach the caller's stream from the one that gets stored.
     */
    #[Property(runs: 200)]
    public function aSeekableStreamIsNeverCopied(string $body): void
    {
        $stream = $this->factory->createStream($body);

        Assert::same(Upload::fromStream($stream, 'a.txt', $this->factory)->stream(), $stream);
    }
}
