<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Mime;

use InvalidArgumentException;
use Rasuvaeff\Yii3Filestorage\Mime\ActiveMediaTypes;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

/**
 * Review 1.0 A5 (core part) and C5: one list for the whole family. `check`
 * knew five types and `-web` ten, and they had already drifted apart.
 */
#[Test]
#[Covers(ActiveMediaTypes::class)]
final class ActiveMediaTypesTest
{
    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function scriptableProvider(): iterable
    {
        foreach ([
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
            'application/rss+xml',
            'application/atom+xml',
        ] as $type) {
            yield $type => [$type];
        }
    }

    #[DataProvider('scriptableProvider')]
    public function everyScriptableTypeIsActive(string $type): void
    {
        Assert::true((new ActiveMediaTypes())->contains($type));
        Assert::true(ActiveMediaTypes::scriptable()->contains($type));
    }

    /**
     * PDF and Flash execute in a plugin, not as the origin. A proxy forces them
     * to download; a permanent public URL for a PDF is a legitimate thing to
     * publish, so `check` does not treat them as stored XSS.
     */
    public function pluginTypesAreActiveButNotScriptable(): void
    {
        foreach (['application/pdf', 'application/x-shockwave-flash'] as $type) {
            Assert::true((new ActiveMediaTypes())->contains($type), $type);
            Assert::false(ActiveMediaTypes::scriptable()->contains($type), $type);
        }
    }

    public function theDefaultIsTheScriptableTierPlusThePluginTier(): void
    {
        Assert::same(ActiveMediaTypes::DEFAULT, [...ActiveMediaTypes::SCRIPTABLE, ...ActiveMediaTypes::PLUGIN]);
    }

    public function inertTypesAreNotActive(): void
    {
        foreach (['image/png', 'image/jpeg', 'text/plain', 'application/octet-stream', ''] as $type) {
            Assert::false((new ActiveMediaTypes())->contains($type), $type);
        }
    }

    /**
     * Parameters are not part of the identity, and neither is case:
     * `Text/HTML; charset=utf-8` executes exactly like `text/html`.
     */
    public function caseAndParametersDoNotHideAType(): void
    {
        $types = new ActiveMediaTypes();

        Assert::true($types->contains('Text/HTML; charset=utf-8'));
        Assert::true($types->contains(' image/svg+xml '));
        Assert::true((new ActiveMediaTypes(['Application/X-Custom']))->contains('application/x-custom'));
    }

    public function withExtraAddsToTheDefaultsRatherThanReplacingThem(): void
    {
        $types = ActiveMediaTypes::withExtra(['application/x-custom']);

        Assert::true($types->contains('application/x-custom'));
        Assert::true($types->contains('text/html'));
        Assert::true($types->contains('application/pdf'));
    }

    public function anExplicitListReplacesTheDefaults(): void
    {
        $types = new ActiveMediaTypes(['application/x-custom']);

        Assert::true($types->contains('application/x-custom'));
        Assert::false($types->contains('text/html'));
    }

    public function theListIsExposedNormalised(): void
    {
        Assert::same((new ActiveMediaTypes(['Text/HTML', 'image/SVG+xml']))->types(), ['text/html', 'image/svg+xml']);
    }

    /**
     * Review 1.0 pass 2, N8: by the WHATWG MIME sniffing standard every type
     * with the `+xml` suffix is an XML MIME type, rendered as XML with its
     * XHTML-namespaced scripts. The rule holds for any list, the explicit one
     * included: it is a property of the type, not a list entry.
     */
    public function anyXmlSuffixTypeIsScriptable(): void
    {
        foreach (['application/rdf+xml', 'application/vnd.mozilla.xul+xml', 'Application/Vnd.Adobe.XDP+XML; charset=utf-8'] as $type) {
            Assert::true((new ActiveMediaTypes())->contains($type), $type);
            Assert::true(ActiveMediaTypes::scriptable()->contains($type), $type);
            Assert::true((new ActiveMediaTypes([]))->contains($type), $type);
        }
    }

    public function otherSuffixesAndLookalikesAreNotActive(): void
    {
        foreach (['application/ld+json', 'application/epub+zip', 'application/xml-dtd', 'text/xmlish', 'application/+xml'] as $type) {
            Assert::false((new ActiveMediaTypes())->contains($type), $type);
        }
    }

    /**
     * Review 1.0 pass 2, N4: one configured list for the whole family. The
     * application's extras join the defaults; the plugin tier is the only one
     * that can be let out.
     */
    public function fromConfigAddsExtrasAndLetsPluginTypesOut(): void
    {
        $types = ActiveMediaTypes::fromConfig(['extra' => ['application/x-custom'], 'allowInline' => ['Application/PDF']]);

        Assert::true($types->contains('application/x-custom'));
        Assert::true($types->contains('text/html'));
        Assert::true($types->contains('application/x-shockwave-flash'));
        Assert::false($types->contains('application/pdf'));
    }

    public function anEmptyConfigIsTheDefaultList(): void
    {
        Assert::same(ActiveMediaTypes::fromConfig([])->types(), (new ActiveMediaTypes())->types());
        Assert::same(ActiveMediaTypes::fromConfig(['extra' => [], 'allowInline' => []])->types(), (new ActiveMediaTypes())->types());
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, string}>
     */
    public static function invalidConfigProvider(): iterable
    {
        yield 'a scriptable type let out' => [['allowInline' => ['image/svg+xml']], 'Only plugin types can be served inline; "image/svg+xml" is not one of application/pdf, application/x-shockwave-flash'];
        yield 'an unknown key' => [['extras' => []], 'Unknown activeMediaTypes option "extras". Known options: extra, allowInline'];
        yield 'extra not a list of strings' => [['extra' => 'text/x-foo'], 'activeMediaTypes "extra" must be a list of non-empty strings'];
        yield 'an empty extra' => [['extra' => ['']], 'activeMediaTypes "extra" must be a list of non-empty strings'];
        yield 'allowInline not a list' => [['allowInline' => [1]], 'activeMediaTypes "allowInline" must be a list of non-empty strings'];
    }

    /**
     * @param array<array-key, mixed> $config
     */
    #[DataProvider('invalidConfigProvider')]
    public function fromConfigRejectsWhatItCannotHonour(array $config, string $message): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining($message);

        ActiveMediaTypes::fromConfig($config);
    }

    /**
     * What `check` refuses on public URLs: everything active except the
     * plugin tier — an application's extras included.
     */
    public function withoutPluginsKeepsTheExtras(): void
    {
        $types = ActiveMediaTypes::withExtra(['application/x-custom'])->withoutPlugins();

        Assert::true($types->contains('application/x-custom'));
        Assert::true($types->contains('text/html'));
        Assert::false($types->contains('application/pdf'));
        Assert::false($types->contains('application/x-shockwave-flash'));
    }
}
