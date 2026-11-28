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
 * filename never contributes it, and an unknown type lands on the inert
 * {@see self::FALLBACK_EXTENSION}.
 *
 * Content that executes lands there too (review 1.0 B5). A public store serves
 * objects by path whatever the delivery policy says, so on such a store the
 * extension *is* the response type: `original.html` or `original.svg` is
 * stored XSS on a computable path, and `original.pl` a CGI script on a
 * misconfigured server. Every scriptable type ({@see ActiveMediaTypes::scriptable()},
 * which includes any `+xml` type) maps to `bin`, and so does every type a table — whichever table — lists with an
 * extension from {@see self::INERT_EXTENSIONS}. Only new files are affected: an existing object keeps
 * the path stored in `File::$relativePath`. An override is the explicit way
 * back (`'image/svg+xml' => 'svg'` for SVG an application really serves
 * publicly).
 *
 * @api
 */
final readonly class ExtensionMap
{
    /** @var non-empty-string */
    public const string FALLBACK_EXTENSION = 'bin';

    private const string EXTENSION_PATTERN = '/^[a-z0-9]{1,10}\z/';

    /**
     * Extensions a browser renders as active content or a web server hands to
     * an interpreter. Never produced from the table; only an override can.
     */
    public const array INERT_EXTENSIONS = [
        'htm', 'html', 'shtml', 'xht', 'xhtml', 'mht', 'mhtml', 'hta',
        'svg', 'svgz', 'xml', 'xsl', 'xslt', 'mml', 'rss', 'atom',
        'js', 'mjs', 'cjs', 'swf',
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar',
        'pl', 'pm', 'cgi', 'py', 'rb', 'sh', 'bash', 'asp', 'aspx', 'jsp',
        'exe', 'com', 'bat', 'cmd', 'dll', 'msi', 'vbs', 'wsf', 'ps1', 'jar',
    ];

    private MimeTypesInterface $mimeTypes;

    /** @var array<non-empty-string, non-empty-string> */
    private array $overrides;

    /**
     * @param array<non-empty-string, non-empty-string> $overrides Media type => extension.
     */
    public function __construct(array $overrides = [], ?MimeTypesInterface $mimeTypes = null)
    {
        $normalised = [];
        foreach ($overrides as $mediaType => $extension) {
            if (preg_match(self::EXTENSION_PATTERN, $extension) !== 1) {
                throw new InvalidArgumentException(
                    "Invalid extension \"{$extension}\" for media type \"{$mediaType}\"",
                );
            }

            // Looked up by the lower-cased type, so a key in any other case
            // would never match — silently (review 1.0 pass 2, N7).
            $key = strtolower($mediaType);
            if (isset($normalised[$key])) {
                throw new InvalidArgumentException("Media type \"{$key}\" is overridden twice, in different case");
            }

            $normalised[$key] = $extension;
        }

        $this->overrides = $normalised;
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

        if (ActiveMediaTypes::scriptable()->contains($normalised)) {
            return self::FALLBACK_EXTENSION;
        }

        $candidates = $this->mimeTypes->getExtensions($normalised);
        // A type the table knows by *any* executable extension is executable:
        // `text/x-perl` is `pl` first and `al` later, and taking the later one
        // would still store a Perl script under a guessable name.
        if (array_intersect($candidates, self::INERT_EXTENSIONS) !== []) {
            return self::FALLBACK_EXTENSION;
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && preg_match(self::EXTENSION_PATTERN, $candidate) === 1) {
                return $candidate;
            }
        }

        return self::FALLBACK_EXTENSION;
    }
}
