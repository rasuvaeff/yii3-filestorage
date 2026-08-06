<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Url;

use DateTimeImmutable;

/**
 * Signs and verifies opaque tokens for downloads proxied through the application.
 *
 * This contract is LOCAL: the same application both signs and verifies. It is
 * NOT how S3 pre-signing works — there the object store validates the
 * signature and the application has nothing to verify. Store-native expiring
 * URLs are {@see \Rasuvaeff\Yii3Filestorage\Store\StoreUrlProviderInterface}
 * instead, and conflating the two produces an interface no S3 adapter can
 * implement.
 *
 * @api
 */
interface UrlSignerInterface
{
    /**
     * @return non-empty-string
     */
    public function sign(SignedPayload $payload, DateTimeImmutable $expiresAt): string;

    /**
     * @return SignedPayload|null The validated payload, or null when the token is
     *         malformed, tampered with, signed by an unknown or retired key, or expired.
     */
    public function verify(string $token): ?SignedPayload;
}
