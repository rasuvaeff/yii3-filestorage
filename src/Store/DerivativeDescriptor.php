<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store;

use InvalidArgumentException;

/**
 * An immutable, named rendition of a file: `thumb`, `preview`, `poster`.
 *
 * Named presets, never free-form dimensions. `w=200&h=200&fit=crop` — even
 * HMAC-signed — turns one uploaded image into an unbounded set of addressable
 * derivatives, so anyone who can obtain signed URLs can force unbounded CPU and
 * storage. A whitelist of presets makes the derivative set finite by
 * construction.
 *
 * The name is validated here rather than where it is used: it becomes a
 * filename inside the file's own directory, and a preset called `../../etc`
 * must be impossible to construct, not merely rejected somewhere downstream.
 *
 * @api
 */
final readonly class DerivativeDescriptor
{
    private const string NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}\z/';
    private const string EXTENSION_PATTERN = '/^[a-z0-9]{1,10}\z/';
    private const string MEDIA_TYPE_PATTERN = '~^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*\z~';

    /** @var non-empty-string */
    public string $name;

    /** @var non-empty-string Without a leading dot. */
    public string $extension;

    /** @var non-empty-string */
    public string $mediaType;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(string $name, string $extension, string $mediaType)
    {
        if ($name === '' || preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException("Invalid derivative name \"{$name}\"");
        }
        if ($extension === '' || preg_match(self::EXTENSION_PATTERN, $extension) !== 1) {
            throw new InvalidArgumentException("Invalid derivative extension \"{$extension}\"");
        }
        if ($mediaType === '' || preg_match(self::MEDIA_TYPE_PATTERN, $mediaType) !== 1) {
            throw new InvalidArgumentException("Invalid derivative media type \"{$mediaType}\"");
        }

        $this->name = $name;
        $this->extension = $extension;
        $this->mediaType = $mediaType;
    }

    /**
     * @return non-empty-string Filename inside the file's directory.
     */
    public function fileName(): string
    {
        return $this->name . '.' . $this->extension;
    }
}
