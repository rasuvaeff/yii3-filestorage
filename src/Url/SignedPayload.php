<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Url;

use InvalidArgumentException;
use JsonException;

/**
 * What a download token actually authorises.
 *
 * Typed rather than a bare id or a delimiter-joined string, because every
 * field here is a security boundary:
 *
 * - `variant` is inside the signature, so a token minted for a redacted or
 *   watermarked rendition cannot be replayed for the original;
 * - `scopeId` is the authenticated tenant, so a token cannot be carried across
 *   tenants;
 * - the encoding is canonical, so two different byte strings can never both
 *   verify as the same payload.
 *
 * @api
 */
final readonly class SignedPayload
{
    /** Keys in this exact order — any other order is not canonical and is rejected. */
    private const array KEYS = ['fileId', 'variant', 'scopeId'];

    private const int MAX_ID_LENGTH = 255;
    private const string VARIANT_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}\z/';

    /** @var non-empty-string */
    public string $fileId;

    /** @var non-empty-string|null Named preset; null means the original. */
    public ?string $variant;

    /** @var non-empty-string|null Authenticated tenant/application scope. */
    public ?string $scopeId;

    /**
     * Every argument may come from a decoded token, so all three are validated
     * here rather than trusted from a docblock.
     *
     * @throws InvalidArgumentException
     */
    public function __construct(string $fileId, ?string $variant = null, ?string $scopeId = null)
    {
        if ($fileId === '' || strlen($fileId) > self::MAX_ID_LENGTH || !$this->isUtf8($fileId)) {
            throw new InvalidArgumentException('Invalid signed file id');
        }
        if ($variant !== null && ($variant === '' || preg_match(self::VARIANT_PATTERN, $variant) !== 1)) {
            throw new InvalidArgumentException('Invalid signed variant');
        }
        if (
            $scopeId !== null
            && ($scopeId === '' || strlen($scopeId) > self::MAX_ID_LENGTH || !$this->isUtf8($scopeId))
        ) {
            throw new InvalidArgumentException('Invalid signed scope');
        }

        $this->fileId = $fileId;
        $this->variant = $variant;
        $this->scopeId = $scopeId;
    }

    /**
     * @return non-empty-string Canonical JSON with a fixed key order.
     */
    public function toCanonicalJson(): string
    {
        return json_encode(
            [
                'fileId' => $this->fileId,
                'variant' => $this->variant,
                'scopeId' => $this->scopeId,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * @return self|null Null for anything that is not exactly the canonical encoding.
     */
    public static function fromCanonicalJson(string $payload): ?self
    {
        try {
            /** @var mixed $data */
            $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($data) || array_keys($data) !== self::KEYS) {
            return null;
        }

        $fileId = $data['fileId'] ?? null;
        $variant = $data['variant'] ?? null;
        $scopeId = $data['scopeId'] ?? null;

        if (
            !is_string($fileId)
            || ($variant !== null && !is_string($variant))
            || ($scopeId !== null && !is_string($scopeId))
        ) {
            return null;
        }

        try {
            $result = new self(fileId: $fileId, variant: $variant, scopeId: $scopeId);
        } catch (InvalidArgumentException) {
            return null;
        }

        // Re-encoding must reproduce the input byte for byte: otherwise a
        // second encoding of the same payload would also verify, and the
        // signature would no longer pin one exact string.
        return $result->toCanonicalJson() === $payload ? $result : null;
    }

    private function isUtf8(string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }
}
