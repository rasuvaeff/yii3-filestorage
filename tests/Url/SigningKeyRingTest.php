<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Url;

use Rasuvaeff\Yii3Filestorage\Exception\InvalidConfigException;
use Rasuvaeff\Yii3Filestorage\Url\SigningKeyRing;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(SigningKeyRing::class)]
final class SigningKeyRingTest
{
    public function exposesTheActiveKeyAndEveryVerificationKey(): void
    {
        $ring = new SigningKeyRing('2026-09', [
            '2026-09' => str_repeat('b', 32),
            '2026-08' => str_repeat('a', 32),
        ]);

        Assert::same($ring->activeKeyId(), '2026-09');
        Assert::same($ring->secretFor('2026-09'), str_repeat('b', 32));
        Assert::same($ring->secretFor('2026-08'), str_repeat('a', 32), 'the previous key still verifies');
    }

    /**
     * A key that is no longer listed stops verifying immediately, which is what
     * you want the moment a key leaks.
     */
    public function anUnknownKeyHasNoSecret(): void
    {
        Assert::null((new SigningKeyRing('k', ['k' => str_repeat('a', 32)]))->secretFor('retired'));
    }

    public function theActiveKeyMustBeInTheRing(): void
    {
        Expect::exception(InvalidConfigException::class)->withMessageContaining('Configured keys: other');

        new SigningKeyRing('missing', ['other' => str_repeat('a', 32)]);
    }

    public function anEmptyRingIsRejectedWithAReadableMessage(): void
    {
        Expect::exception(InvalidConfigException::class)->withMessageContaining('Configured keys: none');

        new SigningKeyRing('k', []);
    }

    /**
     * Below the HMAC block size a short secret is a configuration error rather
     * than a weaker-but-working setup, and the message says how to make one.
     */
    public function aShortSecretIsRejectedAndTheMessageSaysHowToGenerateOne(): void
    {
        Expect::exception(InvalidConfigException::class)
            ->withMessageContaining('is shorter than 32 bytes. Generate one with: php -r "echo bin2hex(random_bytes(32));"');

        new SigningKeyRing('k', ['k' => str_repeat('a', 31)]);
    }

    public function aSecretExactlyAtTheMinimumIsAccepted(): void
    {
        Assert::same(
            (new SigningKeyRing('k', ['k' => str_repeat('a', 32)]))->secretFor('k'),
            str_repeat('a', 32),
        );
    }

    /**
     * The key id travels inside a dot-separated envelope, so an id containing a
     * separator would let one token be re-split into a different one.
     */
    #[DataProvider('invalidKeyIdProvider')]
    public function aKeyIdThatCouldBreakTheEnvelopeIsRejected(string $keyId): void
    {
        Expect::exception(InvalidConfigException::class)
            ->withMessageContaining('letters, digits, "_" and "-" only');

        new SigningKeyRing($keyId, [$keyId => str_repeat('a', 32)]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeyIdProvider(): iterable
    {
        yield 'contains the separator' => ['2026.08'];
        yield 'empty' => [''];
        yield 'leading dash' => ['-key'];
        yield 'a space' => ['my key'];
        yield 'a slash' => ['a/b'];
        yield 'over 32 characters' => [str_repeat('a', 33)];
    }
}
