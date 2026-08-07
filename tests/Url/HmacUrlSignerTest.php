<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Url;

use DateInterval;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Filestorage\Tests\Support\MovableClock;
use Rasuvaeff\Yii3Filestorage\Url\HmacUrlSigner;
use Rasuvaeff\Yii3Filestorage\Url\SignedPayload;
use Rasuvaeff\Yii3Filestorage\Url\SigningKeyRing;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(HmacUrlSigner::class)]
final class HmacUrlSignerTest
{
    private const string ACTIVE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string PREVIOUS = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private MovableClock $clock;
    private HmacUrlSigner $signer;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->clock = new MovableClock('2026-08-06T12:00:00.000000+00:00');
        $this->signer = new HmacUrlSigner($this->clock, new SigningKeyRing('active', [
            'active' => self::ACTIVE,
            'previous' => self::PREVIOUS,
        ]));
    }

    /**
     * The format is frozen: tokens live in browsers until they expire, so a
     * change here invalidates URLs already handed out.
     */
    public function tokensHaveFiveDotSeparatedSegmentsStartingWithTheVersion(): void
    {
        $token = $this->sign();
        $parts = explode('.', $token);

        Assert::same(count($parts), 5);
        Assert::same($parts[0], 'v1');
        Assert::same($parts[1], 'active');
        Assert::same($parts[2], (string) $this->expiry()->getTimestamp());
        Assert::same(preg_match('~^[A-Za-z0-9_-]+\z~', $parts[3]), 1, 'payload is base64url');
        Assert::same(preg_match('~^[A-Za-z0-9_-]+\z~', $parts[4]), 1, 'signature is base64url');
    }

    public function aFreshTokenVerifiesBackToItsPayload(): void
    {
        $payload = new SignedPayload(fileId: 'f-1', variant: 'thumb', scopeId: 'tenant-9');
        $verified = $this->signer->verify($this->signer->sign($payload, $this->expiry()));

        Assert::same($verified?->fileId, 'f-1');
        Assert::same($verified?->variant, 'thumb');
        Assert::same($verified?->scopeId, 'tenant-9');
    }

    public function signingIsDeterministicForTheSameInputs(): void
    {
        Assert::same($this->sign(), $this->sign());
    }

    public function aTokenExpiresExactlyWhenItSaidItWould(): void
    {
        $token = $this->sign();

        $this->clock->advanceSeconds(3_600);
        Assert::true($this->signer->verify($token) instanceof \Rasuvaeff\Yii3Filestorage\Url\SignedPayload, 'still valid at the expiry second');

        $this->clock->advanceSeconds(1);
        Assert::null($this->signer->verify($token));
    }

    #[DataProvider('tamperProvider')]
    public function anyTamperingIsRejected(string $description, callable $tamper): void
    {
        Assert::null($this->signer->verify($tamper($this->sign())), $description);
    }

    /**
     * @return iterable<string, array{string, callable}>
     */
    public static function tamperProvider(): iterable
    {
        yield 'version bumped' => ['version', static fn(string $t): string => 'v2' . substr($t, 2)];
        yield 'expiry extended' => ['expiry', static function (string $t): string {
            $p = explode('.', $t);
            $p[2] = (string) ((int) $p[2] + 86_400);

            return implode('.', $p);
        }];
        yield 'payload swapped' => ['payload', static function (string $t): string {
            $p = explode('.', $t);
            $p[3] = rtrim(strtr(base64_encode(
                (new SignedPayload('other'))->toCanonicalJson(),
            ), '+/', '-_'), '=');

            return implode('.', $p);
        }];
        yield 'signature reversed' => ['signature', static function (string $t): string {
            $p = explode('.', $t);
            $p[4] = strrev($p[4]);

            return implode('.', $p);
        }];
        yield 'unknown key id' => ['key id', static function (string $t): string {
            $p = explode('.', $t);
            $p[1] = 'nobody';

            return implode('.', $p);
        }];
        yield 'segment dropped' => ['shape', static fn(string $t): string => substr($t, strpos($t, '.') + 1)];
        yield 'segment added' => ['shape', static fn(string $t): string => $t . '.extra'];
        yield 'empty token' => ['empty', static fn(string $t): string => ''];
        yield 'padded base64' => ['non-canonical encoding', static function (string $t): string {
            $p = explode('.', $t);
            $p[3] .= '=';

            return implode('.', $p);
        }];
        yield 'non-alphabet character in the payload' => ['alphabet', static function (string $t): string {
            $p = explode('.', $t);
            $p[3] .= '+';

            return implode('.', $p);
        }];
        yield 'negative expiry' => ['expiry shape', static function (string $t): string {
            $p = explode('.', $t);
            $p[2] = '-1';

            return implode('.', $p);
        }];
        yield 'absurdly long expiry' => ['expiry shape', static function (string $t): string {
            $p = explode('.', $t);
            $p[2] = str_repeat('9', 20);

            return implode('.', $p);
        }];
    }

    /**
     * base64 leaves spare bits in the final character when the input length is
     * not a multiple of three, so two different strings can decode to the same
     * bytes. Without the re-encode check both would verify, and one signature
     * would authenticate more than one token.
     */
    public function anAlternativeEncodingOfTheSamePayloadIsRejected(): void
    {
        // 'f-12' makes the canonical JSON 47 bytes long, and 47 % 3 == 2 — a
        // two-byte tail, encoded as three base64 characters, the last of which
        // carries two unused bits. Those bits are what makes a second encoding
        // of the same bytes possible.
        $token = $this->signer->sign(new SignedPayload('f-12'), $this->expiry());
        $parts = explode('.', $token);

        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        $last = $parts[3][strlen($parts[3]) - 1];
        $index = strpos($alphabet, $last);
        \assert($index !== false);
        // Wrapped: the last alphabet character would otherwise index past the
        // end and hand the rest of the test an empty string, which asserts
        // nothing while looking like it does.
        $variant = $alphabet[($index + 1) % strlen($alphabet)];

        Assert::same(
            base64_decode(strtr(substr($parts[3], 0, -1) . $variant, '-_', '+/') . '==', true),
            base64_decode(strtr($parts[3], '-_', '+/') . '==', true),
            'the two encodings really do decode to the same bytes',
        );

        $parts[3] = substr($parts[3], 0, -1) . $variant;

        Assert::null($this->signer->verify(implode('.', $parts)));
    }

    public function anOverlongTokenIsRefusedBeforeAnyWork(): void
    {
        Assert::null($this->signer->verify(str_repeat('a', 5_000)));
    }

    /**
     * Rotation is the whole reason the ring exists: new URLs use the new key
     * while URLs already in the wild keep working until they expire.
     */
    public function rotationKeepsUnexpiredUrlsValid(): void
    {
        $oldSigner = new HmacUrlSigner($this->clock, new SigningKeyRing('previous', ['previous' => self::PREVIOUS]));
        $oldToken = $oldSigner->sign(new SignedPayload('f-1'), $this->expiry());

        Assert::same($this->signer->verify($oldToken)?->fileId, 'f-1');
        Assert::same(explode('.', $this->sign())[1], 'active', 'new tokens use the active key');
    }

    public function aRetiredKeyStopsVerifyingImmediately(): void
    {
        $oldSigner = new HmacUrlSigner($this->clock, new SigningKeyRing('previous', ['previous' => self::PREVIOUS]));
        $oldToken = $oldSigner->sign(new SignedPayload('f-1'), $this->expiry());

        $retired = new HmacUrlSigner($this->clock, new SigningKeyRing('active', ['active' => self::ACTIVE]));

        Assert::null($retired->verify($oldToken));
    }

    /**
     * A different secret must not verify, or the signature would be decoration.
     */
    public function aTokenFromADifferentSecretIsRejected(): void
    {
        $other = new HmacUrlSigner($this->clock, new SigningKeyRing('active', ['active' => str_repeat('z', 32)]));

        Assert::null($this->signer->verify($other->sign(new SignedPayload('f-1'), $this->expiry())));
    }

    /**
     * Round trip for every payload the format accepts, since the format cannot
     * change once tokens are in the wild.
     */
    #[Property(runs: 300)]
    public function everyPayloadSurvivesSignThenVerify(string $fileId, ?string $variant, ?string $scopeId): void
    {
        $payload = new SignedPayload(fileId: $fileId, variant: $variant, scopeId: $scopeId);
        $verified = $this->signer->verify($this->signer->sign($payload, $this->expiry()));

        Assert::same($verified?->fileId, $fileId);
        Assert::same($verified?->variant, $variant);
        Assert::same($verified?->scopeId, $scopeId);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function everyPayloadSurvivesSignThenVerifyGenerators(): array
    {
        return [
            'fileId' => Gen::stringFrom('abcdef0123456789-_.', 1, 60),
            'variant' => Gen::nullable(Gen::stringFrom('abcdefghijklmnopqrstuvwxyz0123456789', 1, 20)),
            'scopeId' => Gen::nullable(Gen::stringFrom('abcdef0123456789-', 1, 30)),
        ];
    }

    /**
     * Flipping any single character of the signature must break it: a signer
     * that compared only a prefix would pass every hand-written test above.
     */
    #[Property(runs: 200)]
    public function flippingAnyCharacterOfTheSignatureBreaksIt(int $index): void
    {
        $token = $this->sign();
        $parts = explode('.', $token);
        $signature = $parts[4];
        $position = $index % strlen($signature);

        $signature[$position] = $signature[$position] === 'A' ? 'B' : 'A';
        $parts[4] = $signature;

        Assert::null($this->signer->verify(implode('.', $parts)));
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function flippingAnyCharacterOfTheSignatureBreaksItGenerators(): array
    {
        return ['index' => Gen::intBetween(0, 1_000)];
    }

    private function sign(): string
    {
        return $this->signer->sign(new SignedPayload('f-1'), $this->expiry());
    }

    private function expiry(): \DateTimeImmutable
    {
        return (new MovableClock('2026-08-06T12:00:00.000000+00:00'))->now()->add(new DateInterval('PT1H'));
    }
}
