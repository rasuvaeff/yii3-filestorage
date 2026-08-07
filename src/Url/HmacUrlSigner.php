<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Url;

use DateTimeImmutable;
use Override;
use Psr\Clock\ClockInterface;

/**
 * Token format `v1.<key-id>.<expires>.<base64url payload>.<base64url hmac>`.
 *
 * Everything before the signature is covered by it — version, key id, expiry
 * and payload — so none of them can be edited in transit. The format is frozen:
 * tokens are handed out to browsers and live until they expire, so a change
 * here invalidates URLs already in the wild. A future format uses `v2.`, and
 * both may be accepted during a bounded migration window.
 *
 * Verification order matters and is deliberate: shape, then signature, then
 * expiry, then payload. The JSON payload is parsed only after the HMAC proves
 * this application produced it — an unauthenticated parser is an attack
 * surface, however small.
 *
 * @api
 */
final readonly class HmacUrlSigner implements UrlSignerInterface
{
    private const string VERSION = 'v1';
    private const string ALGORITHM = 'sha256';
    private const string BASE64URL_PATTERN = '/^[A-Za-z0-9_-]+\z/';
    private const string KEY_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,31}\z/';

    /** Whole tokens above this are rejected before any work is done on them. */
    private const int MAX_TOKEN_LENGTH = 4096;

    /** Decoded payload cap; the canonical JSON of a valid payload is far below it. */
    private const int MAX_PAYLOAD_LENGTH = 2048;

    /** 10 digits reaches the year 2286, 11 leaves room without allowing an overflow probe. */
    private const int MAX_EXPIRES_DIGITS = 11;

    public function __construct(
        private ClockInterface $clock,
        private SigningKeyRing $keys,
    ) {}

    #[Override]
    public function sign(SignedPayload $payload, DateTimeImmutable $expiresAt): string
    {
        $keyId = $this->keys->activeKeyId();
        $secret = $this->keys->secretFor($keyId);
        \assert($secret !== null);

        $signedPart = self::VERSION
            . '.' . $keyId
            . '.' . $expiresAt->getTimestamp()
            . '.' . $this->base64UrlEncode($payload->toCanonicalJson());

        return $signedPart . '.' . $this->base64UrlEncode(hash_hmac(self::ALGORITHM, $signedPart, $secret, binary: true));
    }

    #[Override]
    public function verify(string $token): ?SignedPayload
    {
        if ($token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 5) {
            return null;
        }
        [$version, $keyId, $expires, $encodedPayload, $encodedSignature] = $parts;

        if (
            $version !== self::VERSION
            || $keyId === ''
            || preg_match(self::KEY_ID_PATTERN, $keyId) !== 1
            || preg_match('/^[0-9]{1,' . self::MAX_EXPIRES_DIGITS . '}\z/', $expires) !== 1
            || preg_match(self::BASE64URL_PATTERN, $encodedPayload) !== 1
            || preg_match(self::BASE64URL_PATTERN, $encodedSignature) !== 1
        ) {
            return null;
        }

        $secret = $this->keys->secretFor($keyId);
        $signature = $this->base64UrlDecode($encodedSignature);
        if ($secret === null || $signature === null) {
            return null;
        }

        $expected = hash_hmac(
            self::ALGORITHM,
            $version . '.' . $keyId . '.' . $expires . '.' . $encodedPayload,
            $secret,
            binary: true,
        );
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        if ((int) $expires < $this->clock->now()->getTimestamp()) {
            return null;
        }

        $payload = $this->base64UrlDecode($encodedPayload);
        if ($payload === null || strlen($payload) > self::MAX_PAYLOAD_LENGTH) {
            return null;
        }

        return SignedPayload::fromCanonicalJson($payload);
    }

    /**
     * @return non-empty-string
     */
    private function base64UrlEncode(string $value): string
    {
        $encoded = rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        \assert($encoded !== '');

        return $encoded;
    }

    /**
     * @return string|null Null unless the input is the one canonical encoding of its bytes.
     */
    private function base64UrlDecode(string $value): ?string
    {
        // No padding is re-added: base64_decode() accepts an unpadded input
        // even in strict mode, and the canonical-encoding check below is what
        // actually rejects a non-canonical variant.
        $decoded = base64_decode(strtr($value, '-_', '+/'), strict: true);
        if ($decoded === false || $decoded === '') {
            return null;
        }

        // A second encoding of the same bytes must be byte-identical, otherwise
        // two different tokens would carry the same payload past the signature.
        return $this->base64UrlEncode($decoded) === $value ? $decoded : null;
    }
}
