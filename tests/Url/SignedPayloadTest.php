<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Url;

use InvalidArgumentException;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Filestorage\Url\SignedPayload;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(SignedPayload::class)]
final class SignedPayloadTest
{
    public function encodesWithAFixedKeyOrder(): void
    {
        $payload = new SignedPayload(fileId: 'f-1', variant: 'thumb', scopeId: 'tenant-9');

        Assert::same(
            $payload->toCanonicalJson(),
            '{"fileId":"f-1","variant":"thumb","scopeId":"tenant-9"}',
        );
    }

    public function absentFieldsAreEncodedAsNullRatherThanOmitted(): void
    {
        Assert::same(
            (new SignedPayload('f-1'))->toCanonicalJson(),
            '{"fileId":"f-1","variant":null,"scopeId":null}',
        );
    }

    public function roundTripsThroughCanonicalJson(): void
    {
        $payload = new SignedPayload(fileId: 'f-1', variant: 'thumb', scopeId: 'tenant-9');
        $restored = SignedPayload::fromCanonicalJson($payload->toCanonicalJson());

        Assert::same($restored?->fileId, 'f-1');
        Assert::same($restored?->variant, 'thumb');
        Assert::same($restored?->scopeId, 'tenant-9');
    }

    /**
     * Anything but the exact canonical encoding is refused, so one signature
     * can only ever authenticate one byte string.
     */
    #[DataProvider('nonCanonicalProvider')]
    public function anythingButTheCanonicalEncodingIsRefused(string $json): void
    {
        Assert::null(SignedPayload::fromCanonicalJson($json));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonCanonicalProvider(): iterable
    {
        yield 'reordered keys' => ['{"variant":null,"fileId":"f-1","scopeId":null}'];
        yield 'whitespace' => ['{"fileId": "f-1", "variant": null, "scopeId": null}'];
        yield 'missing key' => ['{"fileId":"f-1","variant":null}'];
        yield 'extra key' => ['{"fileId":"f-1","variant":null,"scopeId":null,"extra":1}'];
        yield 'escaped slash' => ['{"fileId":"a\/b","variant":null,"scopeId":null}'];
        yield 'not an object' => ['["f-1",null,null]'];
        yield 'not json' => ['nonsense'];
        yield 'empty' => [''];
        yield 'wrong type for fileId' => ['{"fileId":42,"variant":null,"scopeId":null}'];
        yield 'wrong type for variant' => ['{"fileId":"f-1","variant":7,"scopeId":null}'];
        yield 'wrong type for scopeId' => ['{"fileId":"f-1","variant":null,"scopeId":false}'];
        yield 'empty fileId' => ['{"fileId":"","variant":null,"scopeId":null}'];
        yield 'invalid variant' => ['{"fileId":"f-1","variant":"Thumb!","scopeId":null}'];
        yield 'empty scopeId' => ['{"fileId":"f-1","variant":null,"scopeId":""}'];
    }

    #[DataProvider('invalidConstructionProvider')]
    public function invalidFieldsAreRejectedAtConstruction(string $message, callable $build): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining($message);

        $build();
    }

    /**
     * @return iterable<string, array{string, callable}>
     */
    public static function invalidConstructionProvider(): iterable
    {
        yield 'empty file id' => ['Invalid signed file id', static fn(): SignedPayload => new SignedPayload('')];
        yield 'over-long file id' => [
            'Invalid signed file id',
            static fn(): SignedPayload => new SignedPayload(str_repeat('a', 256)),
        ];
        yield 'invalid utf-8 file id' => [
            'Invalid signed file id',
            static fn(): SignedPayload => new SignedPayload("\xC3\x28"),
        ];
        yield 'empty variant' => [
            'Invalid signed variant',
            static fn(): SignedPayload => new SignedPayload('f-1', variant: ''),
        ];
        yield 'uppercase variant' => [
            'Invalid signed variant',
            static fn(): SignedPayload => new SignedPayload('f-1', variant: 'Thumb'),
        ];
        yield 'variant with a slash' => [
            'Invalid signed variant',
            static fn(): SignedPayload => new SignedPayload('f-1', variant: '../original'),
        ];
        yield 'over-long variant' => [
            'Invalid signed variant',
            static fn(): SignedPayload => new SignedPayload('f-1', variant: str_repeat('a', 65)),
        ];
        yield 'empty scope' => [
            'Invalid signed scope',
            static fn(): SignedPayload => new SignedPayload('f-1', scopeId: ''),
        ];
        yield 'over-long scope' => [
            'Invalid signed scope',
            static fn(): SignedPayload => new SignedPayload('f-1', scopeId: str_repeat('a', 256)),
        ];
    }

    /**
     * The token format is frozen, so the payload has to survive a round trip
     * for every value it accepts — including non-ASCII file ids.
     */
    #[Property(runs: 300)]
    public function everyAcceptedPayloadRoundTrips(string $fileId, ?string $variant, ?string $scopeId): void
    {
        $payload = new SignedPayload(fileId: $fileId, variant: $variant, scopeId: $scopeId);
        $restored = SignedPayload::fromCanonicalJson($payload->toCanonicalJson());

        // The optional members are where a canonical encoder can quietly
        // differ: omitting a null key and writing it as null produce different
        // bytes, and only one of them re-encodes to the same token.
        Classify::cover($variant === null && $scopeId === null, 'both optionals absent', 15.0);
        Classify::cover($variant !== null && $scopeId !== null, 'both optionals present', 15.0);
        Classify::when($variant === null xor $scopeId === null, 'exactly one optional present');

        Assert::same($restored?->fileId, $fileId);
        Assert::same($restored?->variant, $variant);
        Assert::same($restored?->scopeId, $scopeId);
    }

    /**
     * @return iterable<string, array{string, ?string, ?string}>
     */
    public static function everyAcceptedPayloadRoundTripsExamples(): iterable
    {
        yield 'shortest id, no optionals' => ['a', null, null];
        yield 'both optionals present' => ['a', 'thumb', 'tenant-1'];
        yield 'multibyte id' => ['日本語', null, null];
        yield 'multibyte scope' => ['a', null, 'Ωπ→'];
        yield 'id that looks like JSON' => ['a.b-c_1', 'w200', '0123456789'];
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function everyAcceptedPayloadRoundTripsGenerators(): array
    {
        return [
            // Non-ASCII belongs here: the canonical JSON is written with
            // JSON_UNESCAPED_UNICODE, so a multibyte id is the case where an
            // encoder that escaped differently would break the byte-for-byte
            // re-encode check. `variant` stays ASCII because its own pattern
            // rejects anything else.
            'fileId' => Gen::stringFrom('abcdef0123456789-_.äöü日本語', 1, 60),
            'variant' => Gen::nullable(Gen::stringFrom('abcdefghijklmnopqrstuvwxyz0123456789', 1, 20)),
            'scopeId' => Gen::nullable(Gen::stringFrom('abcdef0123456789-Ωπ→', 1, 30)),
        ];
    }
}
