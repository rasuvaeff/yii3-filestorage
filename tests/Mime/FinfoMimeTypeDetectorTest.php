<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Mime;

use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\Mime\FinfoMimeTypeDetector;
use Rasuvaeff\Yii3Filestorage\Tests\Support\ShortReadingStream;
use Rasuvaeff\Yii3Filestorage\Upload;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(FinfoMimeTypeDetector::class)]
final class FinfoMimeTypeDetectorTest
{
    private Psr17Factory $factory;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    public function detectsAPngFromItsSignature(): void
    {
        Assert::same((new FinfoMimeTypeDetector())->detect($this->upload($this->png())), 'image/png');
    }

    public function detectsPlainText(): void
    {
        Assert::same(
            (new FinfoMimeTypeDetector())->detect($this->upload('just some words in a file')),
            'text/plain',
        );
    }

    /**
     * The exact message carries both the limit and the value that broke it: a
     * dropped half would name the rule but not the configuration to fix.
     */
    public function aTooSmallWindowIsNamedInTheMessage(): void
    {
        try {
            new FinfoMimeTypeDetector(5);
            Assert::true(actual: false, message: 'the window should have been rejected');
        } catch (InvalidArgumentException $e) {
            Assert::same($e->getMessage(), 'sniffBytes must be at least 512, got 5');
        }
    }

    public function anEmptyUploadHasNoType(): void
    {
        Assert::null((new FinfoMimeTypeDetector())->detect($this->upload('')));
    }

    /**
     * The whole pipeline depends on this: the detector reads a prefix and
     * leaves the stream where it found it, so the next stage sees every byte.
     */
    public function detectionRewindsTheStream(): void
    {
        $upload = $this->upload($this->png());

        (new FinfoMimeTypeDetector())->detect($upload);

        Assert::same($upload->stream()->getContents(), $this->png());
    }

    /**
     * A signature past the sniff window is not found. The window is bounded on
     * purpose — reading further would tax every upload — so the answer is
     * whatever the prefix looks like, never the real type. Any group that cares
     * pins an allow-list, which then refuses the mismatch.
     */
    public function aSignatureBeyondTheWindowIsNotFound(): void
    {
        $body = str_repeat("\x00", 4_096) . $this->png();

        Assert::same(
            (new FinfoMimeTypeDetector(sniffBytes: 512))->detect($this->upload($body)),
            'application/octet-stream',
        );
    }

    /**
     * The window is a real limit, not decoration: the same bytes classify
     * differently depending on how much of them was read. That is the reason
     * the default is 4 KiB rather than the 1 KiB a first sketch had — a
     * ZIP-based document (docx, odt) needs more than a kilobyte.
     */
    public function theWindowSizeChangesWhatIsSeen(): void
    {
        $body = str_repeat('a', 600) . "\x00" . str_repeat('b', 100);

        Assert::same((new FinfoMimeTypeDetector(sniffBytes: 512))->detect($this->upload($body)), 'text/plain');
        Assert::same(
            (new FinfoMimeTypeDetector(sniffBytes: 4_096))->detect($this->upload($body)),
            'application/octet-stream',
        );
    }

    /**
     * Review 1.0 B1: PSR-7 lets `read($n)` return less than `$n`. A sniff that
     * trusted one read classified whatever fragment arrived — here, text,
     * where the full window holds a NUL byte and is binary.
     */
    public function shortReadsStillFillTheWholeWindow(): void
    {
        $body = str_repeat('a', 600) . "\x00" . str_repeat('b', 100);
        $upload = Upload::fromStream(
            new ShortReadingStream($this->factory->createStream($body), 100),
            'thing.bin',
            $this->factory,
        );

        Assert::same((new FinfoMimeTypeDetector())->detect($upload), 'application/octet-stream');
    }

    public function tooSmallASniffWindowIsRejected(): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('at least 512');

        new FinfoMimeTypeDetector(sniffBytes: 511);
    }

    public function theClientHintIsNeverReturned(): void
    {
        $upload = Upload::fromStream(
            $this->factory->createStream('plain words here'),
            'a.png',
            $this->factory,
            clientMediaTypeHint: 'image/png',
        );

        Assert::same((new FinfoMimeTypeDetector())->detect($upload), 'text/plain');
        Assert::same($upload->clientMediaTypeHint, 'image/png', 'kept, but only for diagnostics');
    }

    private function upload(string $body): Upload
    {
        return Upload::fromStream($this->factory->createStream($body), 'thing.bin', $this->factory);
    }

    private function png(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            strict: true,
        );
    }
}
