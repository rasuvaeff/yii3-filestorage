<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Url;

use DateTimeImmutable;
use Rasuvaeff\Yii3Filestorage\File;

/**
 * Builds the application URL that serves a file through a signed token.
 *
 * Independent of any physical store on purpose: a private filesystem store has
 * no idea what an HTTP route is, and an S3 store that can presign does not need
 * one. `rasuvaeff/yii3-filestorage-web` is the only vendor package that binds
 * this; without it {@see \Rasuvaeff\Yii3Filestorage\Storage::urlFor()} simply
 * has one fewer option to fall back to.
 *
 * @api
 */
interface ProxyUrlGeneratorInterface
{
    /**
     * @return non-empty-string|null
     */
    public function url(File $file, DateTimeImmutable $expiresAt): ?string;

    /**
     * The proxy URL for a named derivative rendition rather than the original.
     *
     * The variant travels inside the signed payload
     * ({@see \Rasuvaeff\Yii3Filestorage\Url\SignedPayload}), so a URL minted
     * for a thumbnail cannot be replayed for the full-resolution file — the
     * same guarantee presigned store URLs carry.
     *
     * @param non-empty-string $variant
     *
     * @return non-empty-string|null
     */
    public function variantUrl(File $file, string $variant, DateTimeImmutable $expiresAt): ?string;
}
