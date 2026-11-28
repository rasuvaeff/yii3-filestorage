<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests;

use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Filestorage\Exception\SizeLimitExceededException;
use Rasuvaeff\Yii3Filestorage\Exception\UploadFailedException;
use Rasuvaeff\Yii3Filestorage\Exception\UploadTooLargeException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Tests\Support\ForwardOnlyStream;
use Rasuvaeff\Yii3Filestorage\Tests\Support\ShortWritingStream;
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

    public function fromDataUrlDecodesBase64WithAMediaTypeHint(): void
    {
        $upload = Upload::fromDataUrl(
            'data:image/png;base64,' . base64_encode('png bytes'),
            'logo.png',
            $this->factory,
            $hint,
        );

        Assert::same($upload->stream()->getContents(), 'png bytes');
        Assert::same($upload->originalName, 'logo.png');
        Assert::same($hint, 'image/png');
        Assert::same($upload->clientMediaTypeHint, 'image/png');

        // The marker without a media type: still base64, still no hint.
        $markerOnly = Upload::fromDataUrl('data:;base64,' . base64_encode('x'), 'y.bin', $this->factory, $noHint);
        Assert::same($markerOnly->stream()->getContents(), 'x');
        Assert::null($noHint);
    }

    /** RFC 2397 default: percent-encoded text, no base64 marker. */
    public function fromDataUrlDecodesThePercentEncodedForm(): void
    {
        $upload = Upload::fromDataUrl('data:,a%20b%2Fc', 'note.txt', $this->factory, $hint);

        Assert::same($upload->stream()->getContents(), 'a b/c');
        Assert::null($hint);

        // Percent-decoded, not left literal and not form-decoded: '+' stays
        // the plus it is in RFC 2397.
        Assert::same(Upload::fromDataUrl('data:,a%41+b', 'n.txt', $this->factory)->stream()->getContents(), 'aA+b');
    }

    /** No media type at all: the hint stays null, detection belongs to storage. */
    public function fromDataUrlWithoutABase64MarkerIsPlainText(): void
    {
        $upload = Upload::fromDataUrl('data:text/plain,aGVsbG8=', 'x.txt', $this->factory, $hint);

        // Not base64-decoded: no marker means percent-encoded text, and the
        // literal '=' survives.
        Assert::same($upload->stream()->getContents(), 'aGVsbG8=');
        Assert::same($hint, 'text/plain');
    }

    #[DataProvider('badDataUrlProvider')]
    public function fromDataUrlRefusesWhatItCannotParse(string $dataUrl, string $messagePart): void
    {
        Expect::exception(UploadFailedException::class)->withMessageContaining($messagePart);

        Upload::fromDataUrl($dataUrl, 'x.bin', $this->factory);
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string}>
     */
    public static function badDataUrlProvider(): iterable
    {
        yield 'not a data url' => ['https://example.test/x', 'must start with "data:"'];
        yield 'no comma' => ['data:image/png', 'contain ","'];
        yield 'base64 payload does not decode' => ['data:image/png;base64,***not base64***', 'does not decode'];
        yield 'malformed media type' => ['data:not a type,x', 'Malformed media type'];
        yield 'media type with a second slash' => ['data:image/png/extra,x', 'Malformed media type "image/png/extra"'];
        yield 'media type with a leading stray' => ['data: image/png,x', 'Malformed media type " image/png"'];
    }

    /**
     * An empty header segment (the `data:;base64,` form) is skipped and the
     * loop must keep going: bailing there would drop the base64 marker that
     * follows and store the encoded text as if it were the payload.
     */
    public function anEmptyHeaderPartDoesNotAbortParameterParsing(): void
    {
        $upload = Upload::fromDataUrl('data:;base64,SGVsbG8=', 'x.bin', $this->factory);

        Assert::same($upload->stream()->getContents(), 'Hello');
        Assert::null($upload->clientMediaTypeHint);
    }

    /**
     * The spool cap counts the whole stream, not the last chunk: a slow
     * reader delivering 1000 bytes at a time crosses a 2500-byte cap on the
     * third chunk, even though no single chunk ever exceeds it.
     */
    public function theSpoolCapCountsTheWholeBodyNotTheLastChunk(): void
    {
        $slow = new class (str_repeat('x', 5000)) extends ForwardOnlyStream {
            #[\Override]
            public function read(int $length): string
            {
                return parent::read(min($length, 1000));
            }
        };

        Expect::exception(UploadTooLargeException::class)->withMessageContaining('spool cap of 2500 bytes');
        Upload::fromStream($slow, 'a.txt', $this->factory, maxSpoolBytes: 2500);
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

    /**
     * PSR-7 allows an empty read while EOF is still false. Treating that as a
     * completed upload would persist a valid-looking prefix as the whole file.
     */
    public function anEmptyReadBeforeEofIsRefusedInsteadOfSilentlyTruncated(): void
    {
        $stream = new class ('remaining bytes') extends ForwardOnlyStream {
            private bool $stalled = true;

            #[\Override]
            public function read(int $length): string
            {
                if ($this->stalled) {
                    $this->stalled = false;

                    return '';
                }

                return parent::read($length);
            }
        };

        Expect::exception(UploadFailedException::class)->withMessageContaining('no bytes before EOF');

        Upload::fromStream($stream, 'a.txt', $this->factory);
    }

    /**
     * A PSR-7 write may accept fewer bytes than requested. Spooling must keep
     * writing the remainder instead of losing the tail of every chunk.
     */
    public function spoolingCompletesShortWrites(): void
    {
        $handle = fopen('php://temp', 'w+b');
        \assert($handle !== false);

        // A decorator rather than a subclass: `Nyholm\Psr7\Stream` is `@final`,
        // and extending it is a static-analysis failure this package will not
        // suppress. Delegating also keeps `write()`'s signature honest.
        $buffer = new ShortWritingStream(Stream::create($handle), 2);

        $factory = new readonly class (
            $this->factory,
            $buffer,
        ) implements StreamFactoryInterface {
            public function __construct(
                private StreamFactoryInterface $delegate,
                private StreamInterface $shortWrites,
            ) {}

            #[\Override]
            public function createStream(string $content = ''): StreamInterface
            {
                return $this->delegate->createStream($content);
            }

            #[\Override]
            public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
            {
                return $this->shortWrites;
            }

            #[\Override]
            public function createStreamFromResource(mixed $resource): StreamInterface
            {
                return $this->delegate->createStreamFromResource($resource);
            }
        };

        $upload = Upload::fromStream(
            new ForwardOnlyStream('short writes preserve every byte'),
            'a.txt',
            $factory,
        );

        Assert::same($upload->stream()->getContents(), 'short writes preserve every byte');
        $upload->stream()->close();
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

    /**
     * Review 1.0 B3: PHP's own size refusals are size refusals. They used to be
     * a bare `UploadFailedException`, indistinguishable from a broken tmp dir,
     * so a caller following the "catch the size marker" recipe answered 500.
     */
    #[DataProvider('phpSizeErrorProvider')]
    public function phpSizeErrorsAreSizeLimitRefusals(int $error): void
    {
        $file = new UploadedFile($this->factory->createStream('partial'), 7, $error);

        try {
            Upload::fromUploadedFile($file, $this->factory);
            Assert::true(actual: false, message: 'the upload should have been refused');
        } catch (UploadTooLargeException $e) {
            Assert::instanceOf($e, SizeLimitExceededException::class);
            Assert::string($e->getMessage())->contains("PHP error code {$error}");
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function phpSizeErrorProvider(): iterable
    {
        yield 'ini size' => [\UPLOAD_ERR_INI_SIZE];
        yield 'form size' => [\UPLOAD_ERR_FORM_SIZE];
    }

    /**
     * Review 1.0 B2, probe p1: ordinary RFC 2397 forms — parameters after the
     * type, an upper-case type, an upper-case `;BASE64` — were refused as
     * "malformed".
     */
    #[DataProvider('rfc2397Provider')]
    public function fromDataUrlAcceptsTheOrdinaryRfc2397Forms(string $dataUrl, string $body, ?string $hint): void
    {
        $upload = Upload::fromDataUrl($dataUrl, 'x.bin', $this->factory, $actualHint);

        Assert::same($upload->stream()->getContents(), $body);
        Assert::same($actualHint, $hint);
        Assert::same($upload->clientMediaTypeHint, $hint);
    }

    /**
     * @return iterable<string, array{non-empty-string, string, non-empty-string|null}>
     */
    public static function rfc2397Provider(): iterable
    {
        yield 'charset parameter' => ['data:text/plain;charset=utf-8,Hello', 'Hello', 'text/plain'];
        yield 'svg with charset' => ['data:image/svg+xml;charset=utf-8,%3Csvg%3E', '<svg>', 'image/svg+xml'];
        yield 'upper-case type' => ['data:image/PNG;base64,iVBORw0KGgo=', "\x89PNG\r\n\x1a\n", 'image/png'];
        yield 'upper-case base64' => ['data:text/plain;Base64,SGVsbG8=', 'Hello', 'text/plain'];
        yield 'parameter and base64' => ['data:text/plain;charset=US-ASCII;base64,SGVsbG8=', 'Hello', 'text/plain'];
        yield 'parameters without a type' => ['data:;charset=utf-8,Hello', 'Hello', null];
    }

    #[DataProvider('malformedParameterProvider')]
    public function fromDataUrlStillRefusesAMalformedParameter(string $dataUrl, string $message): void
    {
        Expect::exception(UploadFailedException::class)->withMessage($message);

        Upload::fromDataUrl($dataUrl, 'x.bin', $this->factory);
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string}>
     */
    public static function malformedParameterProvider(): iterable
    {
        yield 'parameter without a value separator' => [
            'data:text/plain;garbage,x',
            'Malformed parameter "garbage" in the data URL',
        ];
        yield 'parameter with an empty name' => ['data:text/plain;=v,x', 'Malformed parameter "=v" in the data URL'];
        yield 'a type in the parameter position' => [
            'data:text/plain;image/png,x',
            'Malformed parameter "image/png" in the data URL',
        ];
        yield 'type with a trailing newline' => ["data:text/plain\n,x", "Malformed media type \"text/plain\n\" in the data URL"];
    }

    /**
     * Review 1.0 C1: the cap was accepted and ignored — a factory stream over
     * the decoded bytes is already seekable, so the spool never ran.
     */
    public function fromDataUrlEnforcesTheSpoolCapOnTheDecodedBytes(): void
    {
        $dataUrl = 'data:;base64,' . base64_encode(str_repeat('A', 2000));

        Assert::same(Upload::fromDataUrl($dataUrl, 'x', $this->factory, maxSpoolBytes: 2000)->size(), 2000);

        Expect::exception(UploadTooLargeException::class)->withMessage(
            'The data URL decodes to 2000 bytes, over the cap of 1999 bytes',
        );
        Upload::fromDataUrl($dataUrl, 'x', $this->factory, maxSpoolBytes: 1999);
    }

    public function fromDataUrlWithAZeroCapIsUnlimited(): void
    {
        $dataUrl = 'data:;base64,' . base64_encode(str_repeat('A', 2000));

        Assert::same(Upload::fromDataUrl($dataUrl, 'x', $this->factory, maxSpoolBytes: 0)->size(), 2000);
    }

    /**
     * Review 1.0 B4, probe p2: the client's name is metadata, but it reaches a
     * database column of 255 characters and a `Content-Disposition` in a save
     * dialog. Control characters and bidi overrides — U+202E turns
     * `gpj.exe` into a visible `exe.jpg` — are removed.
     */
    #[DataProvider('originalNameProvider')]
    public function theOriginalNameIsNormalised(string $given, string $expected): void
    {
        Assert::same(Upload::fromStream($this->factory->createStream('x'), $given, $this->factory)->originalName, $expected);
    }

    /**
     * @return iterable<string, array{string, non-empty-string}>
     */
    public static function originalNameProvider(): iterable
    {
        yield 'plain name' => ['report.pdf', 'report.pdf'];
        yield 'traversal kept verbatim, it never reaches a path' => ['../../etc/passwd', '../../etc/passwd'];
        yield 'control characters' => ["a\x07b\x00c\nd\x7Fe\u{0085}.txt", 'abcde.txt'];
        yield 'right-to-left override' => ["invoice\u{202E}gpj.exe", 'invoicegpj.exe'];
        yield 'every bidi control' => ["a\u{200E}\u{200F}\u{202A}\u{202B}\u{202C}\u{202D}\u{2066}\u{2067}\u{2068}\u{2069}\u{061C}b", 'ab'];
        yield 'nothing left' => ["\u{202E}\x01", 'file'];
        yield 'surrounding whitespace' => ["  name.txt \t", 'name.txt'];
        yield 'invalid UTF-8 is replaced, not dropped' => ["caf\xE9.txt", "caf\u{FFFD}.txt"];
        yield 'multi-byte text survives' => ['отчёт.pdf', 'отчёт.pdf'];
        yield 'emoji with a joiner survive' => ["\u{1F469}\u{200D}\u{1F4BB}.png", "\u{1F469}\u{200D}\u{1F4BB}.png"];
        // Review 1.0 pass 2, N9: invisible format characters and line or
        // paragraph separators stayed, so two names that look the same
        // differed, and a name broke a log line.
        yield 'zero-width space, BOM, word joiner' => ["a\u{200B}b\u{FEFF}c\u{2060}.txt", 'abc.txt'];
        yield 'soft hyphen and other format characters' => ["re\u{00AD}port\u{180E}\u{206F}.txt", 'report.txt'];
        yield 'line and paragraph separators' => ["a\u{2028}b\u{2029}c.txt", 'abc.txt'];
        yield 'a non-joiner stays, Persian and Indic need it' => ["\u{0645}\u{06CC}\u{200C}\u{062E}\u{0648}\u{0627}\u{0647}\u{0645}.txt", "\u{0645}\u{06CC}\u{200C}\u{062E}\u{0648}\u{0627}\u{0647}\u{0645}.txt"];
    }

    /**
     * Review 1.0 pass 2, N6: the per-character work ran over the whole input
     * before the cut — an 8 MiB name took 278 MB and over a second, and
     * `fromStream()` takes names from JSON bodies and form fields. The input
     * is cut to a bounded window first.
     */
    public function aHugeNameCostsNoMoreThanASmallOne(): void
    {
        $given = str_repeat('я', 4 * 1_048_576) . '.pdf';
        memory_reset_peak_usage();
        $before = memory_get_usage();

        $name = Upload::fromStream($this->factory->createStream('x'), $given, $this->factory)->originalName;

        Assert::true(memory_get_peak_usage() - $before < 2 * 1_048_576, 'peak stays near the input already held');
        Assert::same($name, str_repeat('я', File::MAX_ORIGINAL_NAME_LENGTH - 4) . '.pdf');
    }

    /**
     * The cut lands on a character boundary and keeps the extension, even
     * when the window ends inside a multi-byte character.
     */
    public function theInputWindowCutsOnACharacterBoundary(): void
    {
        foreach (['', 'a', 'ab', 'abc'] as $shift) {
            $name = Upload::fromStream(
                $this->factory->createStream('x'),
                $shift . str_repeat('€', 20_000) . '.docx',
                $this->factory,
            )->originalName;

            Assert::false(str_contains($name, "\u{FFFD}"), $shift);
            Assert::true(str_ends_with($name, '.docx'), $shift);
        }
    }

    /**
     * The window keeps 4096 bytes minus the extension. Control-character
     * padding, which normalisation removes afterwards, puts the window's last
     * bytes inside the name that survives, so the cut itself is visible: a
     * character the window splits is dropped whole, one it ends on is kept.
     *
     * @param int<1, 16> $inWindow bytes of `$tail` that fall inside the window
     */
    #[DataProvider('windowEndProvider')]
    public function theInputWindowDropsOnlyTheCharacterItSplits(string $tail, int $inWindow, string $expected): void
    {
        $given = str_repeat("\x01", 4096 - \strlen('.txt') - $inWindow) . $tail . '.txt';

        Assert::same(Upload::fromStream($this->factory->createStream('x'), $given, $this->factory)->originalName, $expected);
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function windowEndProvider(): iterable
    {
        yield 'ends on ASCII' => ['abcdef', 3, 'abc.txt'];
        yield 'two-byte character split' => ['abéz', 3, 'ab.txt'];
        yield 'two-byte character complete' => ['abéz', 4, 'abé.txt'];
        yield 'lowest three-byte lead, two bytes in' => ["ab\u{0800}z", 4, 'ab.txt'];
        yield 'three-byte character, one byte in' => ['ab€z', 3, 'ab.txt'];
        yield 'three-byte character, two bytes in' => ['ab€z', 4, 'ab.txt'];
        yield 'three-byte character complete' => ['ab€z', 5, 'ab€.txt'];
        yield 'four-byte character, one byte in' => ["ab\u{1F600}z", 3, 'ab.txt'];
        yield 'four-byte character, two bytes in' => ["ab\u{1F600}z", 4, 'ab.txt'];
        yield 'four-byte character, three bytes in' => ["ab\u{1F600}z", 5, 'ab.txt'];
        yield 'four-byte character complete' => ["ab\u{1F600}z", 6, "ab\u{1F600}.txt"];
        yield 'stray continuation bytes are not a character to drop' => ["ab\x80\x80z", 4, "ab\u{FFFD}\u{FFFD}.txt"];
    }

    /**
     * Padding past the window costs the padder their own base name — never
     * memory — and the extension still survives.
     */
    public function aNameBehindPaddingLosesItsBaseButKeepsItsExtension(): void
    {
        $name = Upload::fromStream(
            $this->factory->createStream('x'),
            str_repeat("\u{200B}", 100_000) . 'photo.jpg',
            $this->factory,
        )->originalName;

        Assert::same($name, '.jpg');
    }

    /**
     * Truncated to {@see File::MAX_ORIGINAL_NAME_LENGTH} characters — not
     * bytes — keeping the extension, so the name still opens with the right
     * program and fits a `VARCHAR(255)` on every database.
     */
    public function aLongOriginalNameIsTruncatedKeepingItsExtension(): void
    {
        $name = Upload::fromStream($this->factory->createStream('x'), str_repeat('я', 5000) . '.pdf', $this->factory)->originalName;

        Assert::same(preg_match_all('/./su', $name), File::MAX_ORIGINAL_NAME_LENGTH);
        Assert::same($name, str_repeat('я', File::MAX_ORIGINAL_NAME_LENGTH - 4) . '.pdf');
    }

    public function aNameOfExactlyTheLimitIsKept(): void
    {
        $given = str_repeat('a', File::MAX_ORIGINAL_NAME_LENGTH);

        Assert::same(Upload::fromStream($this->factory->createStream('x'), $given, $this->factory)->originalName, $given);
    }

    /**
     * An "extension" too long to be one is not preserved at the cost of the
     * whole name: the name is cut like any other string.
     */
    public function aLongNameWithoutAPlausibleExtensionIsCutPlainly(): void
    {
        $given = 'a.' . str_repeat('b', 400);
        $name = Upload::fromStream($this->factory->createStream('x'), $given, $this->factory)->originalName;

        Assert::same($name, substr($given, 0, File::MAX_ORIGINAL_NAME_LENGTH));
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
        \assert($path !== false);
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
        \assert($path !== false);
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
