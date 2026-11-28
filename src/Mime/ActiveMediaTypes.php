<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Mime;

use InvalidArgumentException;

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
 * The *instance* is configured once: core's DI builds it from
 * `params['rasuvaeff/yii3-filestorage']['activeMediaTypes']` through
 * {@see self::fromConfig()}, and the delivery registry, `check`, `-web` and a
 * presigning store take that one instance from the container (review 1.0
 * pass 2, N4). Any `+xml` type is scriptable whatever the list says.
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

    /** Everything {@see self::fromConfig()} understands. */
    private const array OPTIONS = ['extra', 'allowInline'];

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
     * The family's configured list, from core's `params['activeMediaTypes']`
     * (review 1.0 pass 2, N4): `extra` joins the defaults, `allowInline` lets
     * plugin-tier types out — a group with `forceDownload: false` may then
     * show a PDF in the browser's viewer. Only plugin types can be let out:
     * a scriptable one inline is stored XSS, and a request for it is refused
     * rather than honoured.
     *
     * @param array<array-key, mixed> $config
     *
     * @throws InvalidArgumentException
     */
    public static function fromConfig(array $config): self
    {
        foreach (array_keys($config) as $option) {
            if (!\in_array($option, self::OPTIONS, strict: true)) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown activeMediaTypes option "%s". Known options: %s',
                    (string) $option,
                    implode(', ', self::OPTIONS),
                ));
            }
        }

        $extra = self::listOption($config, 'extra');
        $inline = array_map(static fn(string $type): string => strtolower(trim($type)), self::listOption($config, 'allowInline'));
        foreach ($inline as $type) {
            if (!\in_array($type, self::PLUGIN, strict: true)) {
                throw new InvalidArgumentException(sprintf(
                    'Only plugin types can be served inline; "%s" is not one of %s',
                    $type,
                    implode(', ', self::PLUGIN),
                ));
            }
        }

        return new self(array_values(array_filter(
            [...self::DEFAULT, ...$extra],
            static fn(string $type): bool => !\in_array(strtolower(trim($type)), $inline, strict: true),
        )));
    }

    /**
     * This list without the plugin tier: what must never reach a URL that
     * cannot force a download. `filestorage:check` refuses these on public
     * delivery, an application's extras included.
     */
    public function withoutPlugins(): self
    {
        return new self(array_values(array_filter(
            $this->types,
            static fn(string $type): bool => !\in_array($type, self::PLUGIN, strict: true),
        )));
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
     * @param array<array-key, mixed> $config
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    private static function listOption(array $config, string $key): array
    {
        $value = $config[$key] ?? [];
        if (!\is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException("activeMediaTypes \"{$key}\" must be a list of non-empty strings");
        }

        $types = [];
        foreach ($value as $type) {
            if (!\is_string($type) || trim($type) === '') {
                throw new InvalidArgumentException("activeMediaTypes \"{$key}\" must be a list of non-empty strings");
            }

            $types[] = $type;
        }

        return $types;
    }

    /**
     * @return lowercase-string
     */
    private function normalise(string $mediaType): string
    {
        return strtolower(trim(explode(';', $mediaType, 2)[0]));
    }
}
