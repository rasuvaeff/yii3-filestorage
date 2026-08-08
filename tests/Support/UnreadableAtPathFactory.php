<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Support;

use Override;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A stream factory that refuses one path the way PSR-17 says to.
 *
 * `createStreamFromFile()` is specified to throw `\RuntimeException` when the
 * file cannot be opened, and that is *not* a `FilestorageException` — so a
 * command catching only the latter dies on the first unreadable file in a
 * legacy tree. Reproducing that with real permissions does not work here: the
 * suite runs as root inside the container, and root reads a `chmod 000` file.
 *
 * @internal
 */
final readonly class UnreadableAtPathFactory implements StreamFactoryInterface
{
    public function __construct(
        private StreamFactoryInterface $inner,
        private string $refusedSuffix,
    ) {}

    #[Override]
    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        if (str_ends_with($filename, $this->refusedSuffix)) {
            throw new RuntimeException("The file {$filename} cannot be opened");
        }

        return $this->inner->createStreamFromFile($filename, $mode);
    }

    #[Override]
    public function createStream(string $content = ''): StreamInterface
    {
        return $this->inner->createStream($content);
    }

    /**
     * @param resource $resource
     */
    #[Override]
    public function createStreamFromResource($resource): StreamInterface
    {
        return $this->inner->createStreamFromResource($resource);
    }
}
