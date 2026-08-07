<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Mime;

use InvalidArgumentException;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Mime\MimeTypesInterface;

/**
 * Maps a detected media type to the extension of the stored object.
 *
 * The table comes from `symfony/mime` — the Apache/IANA data set, thousands of
 * entries, maintained upstream. Only two things are added on top of it:
 *
 * 1. `$overrides`, for the handful of cases where an application wants a
 *    different preferred extension than Symfony's first candidate;
 * 2. a `/^[a-z0-9]{1,10}\z/` filter over whatever comes back, which is the
 *    anchor guaranteeing that no extension can ever introduce a path
 *    separator, a second dot or a NUL — not because Symfony's table contains
 *    such an entry, but because this value is concatenated into a path and the
 *    guarantee should not depend on a third-party data file.
 *
 * The extension is derived from the *detected* type alone. A client-supplied
 * filename never contributes it, so `invoice.pdf.php` cannot make the stored
 * object executable on a misconfigured origin, and an unknown type lands on
 * the inert {@see self::FALLBACK_EXTENSION}.
 *
 * @api
 */
final readonly class ExtensionMap
{
    /** @var non-empty-string */
    public const string FALLBACK_EXTENSION = 'bin';

    private const string EXTENSION_PATTERN = '/^[a-z0-9]{1,10}\z/';

    private MimeTypesInterface $mimeTypes;

    /** @var array<non-empty-string, non-empty-string> */
    private array $overrides;

    /**
     * @param array<non-empty-string, non-empty-string> $overrides Media type => extension.
     */
    public function __construct(array $overrides = [], ?MimeTypesInterface $mimeTypes = null)
    {
        foreach ($overrides as $mediaType => $extension) {
            if (preg_match(self::EXTENSION_PATTERN, $extension) !== 1) {
                throw new InvalidArgumentException(
                    "Invalid extension \"{$extension}\" for media type \"{$mediaType}\"",
                );
            }
        }

        $this->overrides = $overrides;
        $this->mimeTypes = $mimeTypes ?? MimeTypes::getDefault();
    }

    /**
     * @param non-empty-string|null $mediaType
     *
     * @return non-empty-string The fallback extension for an unknown type.
     */
    public function extensionFor(?string $mediaType): string
    {
        if ($mediaType === null) {
            return self::FALLBACK_EXTENSION;
        }

        $normalised = strtolower($mediaType);
        if (isset($this->overrides[$normalised])) {
            return $this->overrides[$normalised];
        }

        foreach ($this->mimeTypes->getExtensions($normalised) as $candidate) {
            if ($candidate !== '' && preg_match(self::EXTENSION_PATTERN, $candidate) === 1) {
                return $candidate;
            }
        }

        return self::FALLBACK_EXTENSION;
    }
}
