<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Url;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
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
use Testo\Expect;
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

    /**
     * Review 1.0 C2: `sign()` used to mint tokens `verify()` can never accept —
     * a negative timestamp or one past eleven digits fails the expiry shape.
     * A link that is dead on arrival is a bug to report at the call site.
     */
    #[DataProvider('unrepresentableExpiryProvider')]
    public function anExpiryTheTokenCannotCarryIsRefusedAtSigning(string $expiresAt, string $timestamp): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessage(
            "Cannot sign a token expiring at {$timestamp}: the expiry must be between 0 and 99999999999 seconds since the epoch",
        );

        $this->signer->sign(new SignedPayload(fileId: 'f-1'), new DateTimeImmutable($expiresAt));
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string}>
     */
    public static function unrepresentableExpiryProvider(): iterable
    {
        yield 'before 1970' => ['1969-12-31T23:59:59+00:00', '-1'];
        yield 'twelve digits' => ['@100000000000', '100000000000'];
    }

    public function theLargestRepresentableExpirySignsAndVerifiesItsShape(): void
    {
        $token = $this->signer->sign(new SignedPayload(fileId: 'f-1'), new DateTimeImmutable('@99999999999'));

        Assert::same(explode('.', $token)[2], '99999999999');
        Assert::same($this->signer->verify($token)?->fileId, 'f-1');
    }

    public function theEpochItselfSigns(): void
    {
        $token = $this->signer->sign(new SignedPayload(fileId: 'f-1'), new DateTimeImmutable('@0'));

        Assert::same(explode('.', $token)[2], '0');
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
        // two-byte tail encoded as three base64 characters, the last of which
        // carries two unused bits. Those bits are what lets a second string
        // decode to the same bytes.
        $token = $this->signer->sign(new SignedPayload('f-12'), $this->expiry());
        $parts = explode('.', $token);

        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        $index = strpos($alphabet, $parts[3][strlen($parts[3]) - 1]);
        \assert($index !== false);
        // Only the two low bits are spare, so the substitute has to come from
        // the same group of four. Wrapping over the whole alphabet would change
        // significant bits and produce a *different* payload — which the
        // signature would reject for the ordinary reason, proving nothing about
        // canonical encoding.
        $variant = $alphabet[($index & ~3) | (($index + 1) & 3)];
        $forged = substr($parts[3], 0, -1) . $variant;

        // 63 characters is 63 % 4 == 3, so exactly one '=' completes the group.
        // Padding it with two makes base64_decode() return false on both sides,
        // and comparing false with false is how this assertion used to pass
        // while proving nothing.
        Assert::same(
            base64_decode(strtr($forged, '-_', '+/') . '=', strict: true),
            base64_decode(strtr($parts[3], '-_', '+/') . '=', strict: true),
            'the two encodings really do decode to the same bytes',
        );

        // Re-signed with the same key. Without this the token fails at
        // hash_equals() — long before the canonicalisation check — and the test
        // would stay green with that check deleted, which is the one thing it
        // exists to protect.
        $signedPart = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.' . $forged;
        $signature = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $signedPart, self::ACTIVE, binary: true),
        ), '+/', '-_'), '=');

        Assert::null($this->signer->verify($signedPart . '.' . $signature));

        // And the same token with the canonical payload still verifies, so the
        // rejection above is about the encoding and not about the re-signing.
        $canonical = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.' . $parts[3];
        $canonicalSignature = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $canonical, self::ACTIVE, binary: true),
        ), '+/', '-_'), '=');

        Assert::instanceOf(
            $this->signer->verify($canonical . '.' . $canonicalSignature),
            SignedPayload::class,
        );
    }

    public function anOverlongTokenIsRefusedBeforeAnyWork(): void
    {
        Assert::null($this->signer->verify(str_repeat('a', 5_000)));
    }

    /**
     * A signature segment whose length makes strict base64 fail outright —
     * one character is length 1, which base64 rejects as impossible — is
     * refused, not "verified against a garbage signature". Both sides of the
     * decode guard have to answer null.
     */
    public function aSignatureThatDoesNotDecodeIsRejected(): void
    {
        foreach (['A', 'AAAAA'] as $signature) {
            $parts = explode('.', $this->sign());
            $parts[4] = $signature;

            Assert::null($this->signer->verify(implode('.', $parts)), "signature \"{$signature}\" decodes to nothing");
        }
    }

    /**
     * A payload segment that fails to decode is rejected on the same guard —
     * and, being past the signature check, only the decode itself can refuse
     * it: the forged payload is re-signed so hash_equals passes and the
     * canonical-decode guard is what answers.
     */
    public function aPayloadThatDoesNotDecodeIsRejected(): void
    {
        $parts = explode('.', $this->sign());
        $parts[3] = 'AAAAA';
        $signedPart = implode('.', [$parts[0], $parts[1], $parts[2], $parts[3]]);
        $signature = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $signedPart, self::ACTIVE, binary: true),
        ), '+/', '-_'), '=');

        Assert::null($this->signer->verify($signedPart . '.' . $signature));
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
     * The expiry field allows at most eleven digits: a twelve- or twenty-digit
     * year is refused on shape *before* the clock is consulted. Both tokens are
     * re-signed with the real key, so the shape guard — and not the signature —
     * is what rejects them.
     */
    #[DataProvider('overlongExpiryProvider')]
    public function anExpiryBeyondElevenDigitsIsRefusedOnShape(string $expires): void
    {
        $parts = explode('.', $this->sign());
        $parts[2] = $expires;
        $signedPart = implode('.', [$parts[0], $parts[1], $parts[2], $parts[3]]);
        $signature = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $signedPart, self::ACTIVE, binary: true),
        ), '+/', '-_'), '=');

        Assert::null($this->signer->verify($signedPart . '.' . $signature));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function overlongExpiryProvider(): iterable
    {
        yield 'twelve digits' => [str_repeat('9', 12)];
        yield 'twenty digits' => [str_repeat('9', 20)];
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
