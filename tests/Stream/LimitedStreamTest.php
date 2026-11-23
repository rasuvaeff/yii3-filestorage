<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Stream;

use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Stream;
use Override;
use Rasuvaeff\Yii3Filestorage\Stream\LimitedStream;
use Rasuvaeff\Yii3Filestorage\Tests\Support\ForwardOnlyStream;
use Rasuvaeff\Yii3Filestorage\Tests\Support\StallingStream;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(LimitedStream::class)]
final class LimitedStreamTest
{
    private Psr17Factory $factory;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    public function readsOnlyTheWindow(): void
    {
        Assert::same($this->window('0123456789', 2, 4)->getContents(), '2345');
    }

    public function reportsTheWindowSizeNotTheWholeStream(): void
    {
        Assert::same($this->window('0123456789', 2, 4)->getSize(), 4);
    }

    public function aWindowLongerThanTheStreamIsClampedToWhatIsThere(): void
    {
        Assert::same($this->window('0123456789', 8, 100)->getSize(), 2);
        Assert::same($this->window('0123456789', 8, 100)->getContents(), '89');
    }

    public function positionsAreRelativeToTheWindow(): void
    {
        $stream = $this->window('0123456789', 3, 4);

        Assert::same($stream->tell(), 0);
        Assert::same($stream->read(2), '34');
        Assert::same($stream->tell(), 2);
    }

    public function eofIsReachedAtTheEndOfTheWindowNotTheStream(): void
    {
        $stream = $this->window('0123456789', 0, 3);

        Assert::same($stream->read(3), '012');
        Assert::true($stream->eof());
    }

    public function readsPastTheWindowReturnNothing(): void
    {
        $stream = $this->window('0123456789', 0, 3);
        $stream->read(3);

        Assert::same($stream->read(10), '');
    }

    public function readingMoreThanRemainsIsTruncatedToTheWindow(): void
    {
        Assert::same($this->window('0123456789', 5, 3)->read(100), '567');
    }

    public function aNonPositiveReadLengthReturnsNothing(): void
    {
        Assert::same($this->window('0123456789', 0, 5)->read(0), '');
        Assert::same($this->window('0123456789', 0, 5)->read(-1), '');
    }

    public function seekingIsRelativeToTheWindow(): void
    {
        $stream = $this->window('0123456789', 2, 5);

        $stream->seek(1);
        Assert::same($stream->read(2), '34');

        $stream->seek(-1, \SEEK_END);
        Assert::same($stream->read(2), '6');

        $stream->rewind();
        Assert::same($stream->read(1), '2');

        $stream->seek(2, \SEEK_CUR);
        Assert::same($stream->read(1), '5');
    }

    /**
     * Seeking exactly to the end of the window is legal — it is where a
     * reader that consumed the whole range lands.
     */
    public function seekingToTheEndOfTheWindowIsAllowed(): void
    {
        $stream = $this->window('0123456789', 0, 4);
        $stream->seek(4);

        Assert::true($stream->eof());
    }

    /**
     * The position advances by what each read returned, so two reads land the
     * window at byte 4 — a position reset per read would serve the same bytes
     * twice to a streaming consumer.
     */
    public function thePositionAdvancesAcrossConsecutiveReads(): void
    {
        $stream = $this->window('0123456789', 0, 6);

        Assert::same($stream->read(2), '01');
        Assert::same($stream->read(2), '23');
        Assert::same($stream->tell(), 4);
    }

    /**
     * getContents() materialises the whole window: a window longer than one
     * read chunk must come back whole, not as its final chunk.
     */
    public function aWindowLongerThanOneChunkIsReadWhole(): void
    {
        $body = str_repeat('a', 262_144) . 'tail';
        $stream = new LimitedStream($this->factory->createStream($body), 0, 262_148);

        Assert::same($stream->getContents(), $body);
    }

    public function seekingOutsideTheWindowIsRefused(): void
    {
        $stream = $this->window('0123456789', 2, 4);

        Expect::exception(RuntimeException::class)->withMessageContaining('outside the range');

        $stream->seek(5);
    }

    public function seekingBeforeTheWindowIsRefused(): void
    {
        $stream = $this->window('0123456789', 2, 4);

        Expect::exception(RuntimeException::class)->withMessageContaining('outside the range');

        $stream->seek(-1);
    }

    public function anUnknownSeekModeIsRefused(): void
    {
        $stream = $this->window('0123456789', 0, 4);

        Expect::exception(RuntimeException::class)->withMessageContaining('Unsupported seek whence');

        $stream->seek(0, 99);
    }

    public function theWindowIsReadOnly(): void
    {
        $stream = $this->window('0123456789', 0, 4);

        Assert::false($stream->isWritable());

        Expect::exception(RuntimeException::class)->withMessageContaining('read-only');

        $stream->write('x');
    }

    public function stringConversionRewindsAndReturnsTheWindow(): void
    {
        $stream = $this->window('0123456789', 2, 3);
        $stream->read(2);

        Assert::same((string) $stream, '234');
    }

    public function delegatesReadabilityAndMetadataToTheInnerStream(): void
    {
        $stream = $this->window('0123456789', 0, 4);

        Assert::true($stream->isReadable());
        Assert::true($stream->isSeekable());
        Assert::same($stream->getMetadata('seekable'), expected: true);
    }

    public function detachAndCloseReachTheInnerStream(): void
    {
        $inner = $this->factory->createStream('0123456789');
        $stream = new LimitedStream($inner, 0, 4);

        Assert::true($stream->detach() !== null);

        $closable = new LimitedStream($this->factory->createStream('0123456789'), 0, 4);
        $closable->close();

        Assert::false($closable->isWritable(), 'close reaches the inner stream');
        $closable->close();
    }

    /**
     * A window whose offset sits past the end of the stream is empty, and
     * reports its size as zero — never a negative number.
     */
    public function aWindowStartingPastTheEndReportsZeroSize(): void
    {
        $limited = $this->window('0123456789', 12, 5);

        Assert::same($limited->getSize(), 0);
        Assert::same($limited->getContents(), '');
    }

    /**
     * A forward-only stream cannot serve a window without buffering, and
     * pretending otherwise would silently return the wrong bytes.
     */
    public function aNonSeekableStreamIsRefused(): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('requires a seekable stream');

        new LimitedStream(new ForwardOnlyStream('0123456789'), 0, 4);
    }

    public function negativeArgumentsAreRefused(): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('must not be negative');

        new LimitedStream($this->factory->createStream('x'), -1, 1);
    }

    public function aZeroLengthWindowIsEmptyButValid(): void
    {
        $stream = $this->window('0123456789', 3, 0);

        Assert::same($stream->getSize(), 0);
        Assert::same($stream->getContents(), '');
        Assert::true($stream->eof());
    }

    /**
     * PSR-7 lets a stream decline to report its size — a PHP input stream, a
     * network body. The window's length is the only answer left, and a null
     * inner size must not crash the lookup or leak the whole stream's length.
     */
    public function aWindowOverASizelessStreamReportsTheWindowLength(): void
    {
        $inner = new class ($this->factory->createStream('0123456789')->detach()) extends Stream {
            #[Override]
            public function getSize(): ?int
            {
                return null;
            }
        };

        $stream = new LimitedStream($inner, 2, 4);

        Assert::same($stream->getSize(), 4);
    }

    /**
     * An empty chunk before EOF is a stalled read, not the end of the window:
     * returning what was gathered so far would serve a truncated range with a
     * 200-style success. The window fails loudly instead.
     */
    public function aWindowRefusesAStreamThatStallsMidRead(): void
    {
        $stream = new LimitedStream(StallingStream::midStream('0123456789'), 0, 10);

        Expect::exception(RuntimeException::class)->withMessageContaining('returned no bytes before EOF');

        $stream->getContents();
    }

    private function window(string $body, int $offset, int $length): LimitedStream
    {
        return new LimitedStream($this->factory->createStream($body), $offset, $length);
    }
}
