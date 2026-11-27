<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Mime;

use Rasuvaeff\Yii3Filestorage\Mime\ActiveMediaTypes;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
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
}
