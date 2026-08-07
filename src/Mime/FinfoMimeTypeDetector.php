<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Mime;

use finfo;
use InvalidArgumentException;
use Override;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * Sniffs the media type from a bounded prefix of the upload with `ext-fileinfo`.
 *
 * `symfony/mime` is a dependency of this package, but its `MimeTypes::guessMimeType()`
 * is not usable here: it takes a filesystem *path* and hands it to `finfo::file()`,
 * which reads the whole object. Ingress is a PSR-7 stream that is frequently
 * `php://temp`, so using it would mean materialising a temporary file on every
 * upload to reach the same `ext-fileinfo` call this class makes directly — while
 * losing the bounded sniff window. Symfony's contribution is the media-type ⇒
 * extension table instead; see {@see ExtensionMap}.
 *
 * @api
 */
final readonly class FinfoMimeTypeDetector implements MimeTypeDetectorInterface
{
    /** Anything below this cannot identify a container format. */
    public const int MIN_SNIFF_BYTES = 512;

    /**
     * What `finfo` reports when it recognised nothing, which is not a media type.
     */
    private const array NON_TYPES = ['application/x-empty', 'inode/x-empty'];

    /**
     * @param int $sniffBytes 4 KiB by default. Signatures placed later
     *        in a file stay unknown on purpose: a bigger window is a bigger read
     *        on every upload, and an unknown type is already handled safely.
     *        ZIP-based formats (docx, odt) need more than 1 KiB, hence the default.
     */
    public function __construct(private int $sniffBytes = 4096)
    {
        if ($sniffBytes < self::MIN_SNIFF_BYTES) {
            throw new InvalidArgumentException(
                'sniffBytes must be at least ' . self::MIN_SNIFF_BYTES . ", got {$sniffBytes}",
            );
        }
    }

    #[Override]
    public function detect(Upload $upload): ?string
    {
        $stream = $upload->stream();
        $bytes = $stream->read($this->sniffBytes);
        $stream->rewind();

        if ($bytes === '') {
            return null;
        }

        $detected = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return $detected === false || $detected === '' || in_array($detected, self::NON_TYPES, true)
            ? null
            : $detected;
    }
}
