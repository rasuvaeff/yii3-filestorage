<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Mime;

/**
 * Media types a browser or a plugin will execute if you let it render them —
 * the one list for the whole family.
 *
 * An uploaded HTML or SVG file served inline from your own origin is stored
 * XSS: it runs with your cookies, your CSP and your session. `filestorage:check`
 * refuses public delivery of these, the `-web` proxy forces them to
 * `attachment`, and a presigned URL must not serve them inline. Those used to
 * be separate lists that had already drifted apart (review 1.0 A5, C5).
 *
 * Two tiers:
 *
 * - {@see self::SCRIPTABLE} executes *as the origin* — markup, script, XML a
 *   browser renders (and can style with XSLT). Never acceptable on a URL that
 *   cannot force a download.
 * - {@see self::PLUGIN} executes in a plugin or a separate viewer. Forced to
 *   download where a response can say so, but a permanent public PDF is a
 *   legitimate publication, so `check` does not refuse it.
 *
 * {@see self::DEFAULT} is both. SVG is the one people forget: an image
 * everywhere else in a codebase, a scriptable document here.
 *
 * @api
 */
final readonly class ActiveMediaTypes
{
    public const array SCRIPTABLE = [
        'text/html',
        'application/xhtml+xml',
        'image/svg+xml',
        'application/xml',
        'text/xml',
        'application/xslt+xml',
        'text/xsl',
        'application/mathml+xml',
        'application/javascript',
        'text/javascript',
        'application/ecmascript',
        'text/ecmascript',
        'application/x-javascript',
        // Feeds are XML: a browser that renders one applies its stylesheet.
        'application/rss+xml',
        'application/atom+xml',
    ];

    public const array PLUGIN = [
        'application/pdf',
        'application/x-shockwave-flash',
    ];

    public const array DEFAULT = [...self::SCRIPTABLE, ...self::PLUGIN];

    /** @var list<lowercase-string> */
    private array $types;

    /**
     * @param list<string> $types Replaces the defaults; see {@see self::withExtra()}.
     */
    public function __construct(array $types = self::DEFAULT)
    {
        $this->types = array_map($this->normalise(...), $types);
    }

    /**
     * @param list<string> $types Added to the defaults rather than replacing them.
     */
    public static function withExtra(array $types): self
    {
        return new self([...self::DEFAULT, ...$types]);
    }

    /**
     * The tier that executes as the origin, without the plugin types.
     */
    public static function scriptable(): self
    {
        return new self(self::SCRIPTABLE);
    }

    /**
     * Parameters and case are not part of the identity: `Text/HTML;
     * charset=utf-8` is every bit as executable as `text/html`.
     */
    public function contains(string $mediaType): bool
    {
        $normalised = $this->normalise($mediaType);

        return \in_array($normalised, $this->types, strict: true) || $this->isXml($normalised);
    }

    /**
     * Any `+xml` structured-syntax suffix: by the WHATWG MIME sniffing
     * standard every such type is an XML MIME type, which a browser renders
     * as XML — and XHTML-namespaced `<script>` in it runs (review 1.0 pass 2,
     * N8). A property of the type rather than an entry, so it holds for an
     * explicit list too.
     */
    private function isXml(string $mediaType): bool
    {
        return preg_match('~^[a-z0-9][a-z0-9!#$&^_.-]*/[a-z0-9][a-z0-9!#$&^_.+-]*\+xml\z~', $mediaType) === 1;
    }

    /**
     * @return list<lowercase-string>
     */
    public function types(): array
    {
        return $this->types;
    }

    /**
     * @return lowercase-string
     */
    private function normalise(string $mediaType): string
    {
        return strtolower(trim(explode(';', $mediaType, 2)[0]));
    }
}
