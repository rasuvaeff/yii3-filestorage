<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store;

use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\File;

/**
 * Resolves a named derivative preset to its rendition: generated on demand,
 * streamable, with its authoritative descriptor.
 *
 * The contract lives in core for the same reason
 * {@see \Rasuvaeff\Yii3Filestorage\Url\ProxyUrlGeneratorInterface} does: the
 * package that *serves* a rendition over HTTP (`rasuvaeff/yii3-filestorage-web`)
 * and the package that *produces* it (`rasuvaeff/yii3-filestorage-images`)
 * must not depend on each other. The images package's `Derivatives` facade is
 * the reference implementation; a consumer may provide their own — the
 * contract is the whole integration surface.
 *
 * @api
 */
interface DerivativeResolverInterface
{
    /**
     * The rendition's bytes, generating them first if they do not exist yet
     * (lazy generation — the first request pays, every later one is a read).
     *
     * @param non-empty-string $presetName
     *
     * @return StreamInterface|null Null only when the object vanished between
     *         generation and read — treat it like any missing file.
     *
     * @throws \Rasuvaeff\Yii3Filestorage\Exception\FilestorageException When
     *         the preset is not configured, or generation fails. A caller
     *         serving HTTP maps an unresolvable preset to a 404 and lets a
     *         generation failure surface: the first is "no such URL target",
     *         the second is breakage worth seeing.
     */
    public function stream(File $file, string $presetName): ?StreamInterface;

    /**
     * The physical identity the rendition is — or will be — stored under:
     * versioned name, extension and authoritative media type.
     *
     * @param non-empty-string $presetName
     *
     * @throws \Rasuvaeff\Yii3Filestorage\Exception\FilestorageException When
     *         the preset is not configured.
     */
    public function descriptor(string $presetName): DerivativeDescriptor;
}
