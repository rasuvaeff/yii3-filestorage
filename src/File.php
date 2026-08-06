<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Metadata of one stored object.
 *
 * The id is assigned before the write, so a File never exists in an id-less
 * state, and no mutator reads an ambient clock: every one takes the new
 * `updatedAt` as a parameter.
 *
 * @psalm-type FileMetadata = array<non-empty-string, scalar|null>
 * @psalm-type FileArrayShape = array{
 *     id: non-empty-string,
 *     storeName: non-empty-string,
 *     externalId: non-empty-string|null,
 *     groupName: non-empty-string,
 *     relativePath: non-empty-string,
 *     originalName: non-empty-string,
 *     mimeType: non-empty-string|null,
 *     size: int<0, max>,
 *     description: string|null,
 *     contentHash: non-empty-string|null,
 *     metadata: FileMetadata,
 *     createdAt: non-empty-string,
 *     updatedAt: non-empty-string
 * }
 *
 * @api
 */
final readonly class File
{
    /**
     * RFC 3339 with microseconds.
     *
     * `DateTimeInterface::ATOM` truncates to whole seconds, and PSR-20 clocks
     * report microseconds, so ATOM would break `fromArray(toArray($f)) == $f`.
     */
    public const string TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.uP';

    private const string NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/';
    private const string SHA256_PATTERN = '/^[a-f0-9]{64}\z/';
    private const string TRAVERSAL_PATTERN = '~(?:^|/)\.\.(?:/|\z)~';

    /**
     * @param non-empty-string $id
     * @param non-empty-string $storeName
     * @param non-empty-string|null $externalId
     * @param non-empty-string $groupName
     * @param non-empty-string $relativePath
     * @param non-empty-string $originalName
     * @param non-empty-string|null $mimeType
     * @param int<0, max> $size
     * @param non-empty-string|null $contentHash
     * @param FileMetadata $metadata
     */
    private function __construct(
        public string $id,
        public string $storeName,
        public ?string $externalId,
        public string $groupName,
        public string $relativePath,
        public string $originalName,
        public ?string $mimeType,
        public int $size,
        public ?string $description,
        public ?string $contentHash,
        public array $metadata,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /**
     * @param array<array-key, mixed> $metadata Validated and narrowed here.
     *
     * @throws InvalidArgumentException
     */
    public static function create(
        string $id,
        string $storeName,
        string $groupName,
        string $relativePath,
        string $originalName,
        int $size,
        DateTimeImmutable $createdAt,
        ?string $externalId = null,
        ?string $mimeType = null,
        ?string $description = null,
        ?string $contentHash = null,
        array $metadata = [],
        ?DateTimeImmutable $updatedAt = null,
    ): self {
        $updatedAt ??= $createdAt;

        if ($size < 0) {
            throw new InvalidArgumentException('Size must not be negative');
        }
        if ($updatedAt < $createdAt) {
            throw new InvalidArgumentException('updatedAt must not precede createdAt');
        }

        return new self(
            id: self::requireNonEmpty($id, 'id'),
            storeName: self::requireName($storeName, 'store name'),
            externalId: self::requireNullOrNonEmpty($externalId, 'externalId'),
            groupName: self::requireName($groupName, 'group name'),
            relativePath: self::requireRelativePath($relativePath),
            originalName: self::requireNonEmpty($originalName, 'originalName'),
            mimeType: self::requireNullOrNonEmpty($mimeType, 'mimeType'),
            size: $size,
            description: $description,
            contentHash: self::requireContentHash($contentHash),
            metadata: self::requireMetadata($metadata),
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }

    /**
     * @param FileArrayShape $row
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $row): self
    {
        return self::create(
            id: $row['id'],
            storeName: $row['storeName'],
            groupName: $row['groupName'],
            relativePath: $row['relativePath'],
            originalName: $row['originalName'],
            size: $row['size'],
            createdAt: self::parseTimestamp($row['createdAt']),
            externalId: $row['externalId'],
            mimeType: $row['mimeType'],
            description: $row['description'],
            contentHash: $row['contentHash'],
            metadata: $row['metadata'],
            updatedAt: self::parseTimestamp($row['updatedAt']),
        );
    }

    /**
     * @return FileArrayShape
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'storeName' => $this->storeName,
            'externalId' => $this->externalId,
            'groupName' => $this->groupName,
            'relativePath' => $this->relativePath,
            'originalName' => $this->originalName,
            'mimeType' => $this->mimeType,
            'size' => $this->size,
            'description' => $this->description,
            'contentHash' => $this->contentHash,
            'metadata' => $this->metadata,
            'createdAt' => self::formatTimestamp($this->createdAt),
            'updatedAt' => self::formatTimestamp($this->updatedAt),
        ];
    }

    /**
     * @param array<array-key, mixed> $metadata
     */
    public function withMetadata(array $metadata, DateTimeImmutable $updatedAt): self
    {
        return self::create(
            id: $this->id,
            storeName: $this->storeName,
            groupName: $this->groupName,
            relativePath: $this->relativePath,
            originalName: $this->originalName,
            size: $this->size,
            createdAt: $this->createdAt,
            externalId: $this->externalId,
            mimeType: $this->mimeType,
            description: $this->description,
            contentHash: $this->contentHash,
            metadata: $metadata,
            updatedAt: $updatedAt,
        );
    }

    /**
     * The directory holding this file's object and every derivative of it.
     *
     * Every path generator emits `<…>/<key>/original.<ext>`, so removing the
     * parent directory is what makes derivative cleanup automatic.
     *
     * @return non-empty-string
     */
    public function directory(): string
    {
        $slash = strrpos($this->relativePath, '/');
        if ($slash === false || $slash === 0) {
            return $this->relativePath;
        }

        $directory = substr($this->relativePath, 0, $slash);
        \assert($directory !== '');

        return $directory;
    }

    /**
     * @return non-empty-string
     */
    private static function requireNonEmpty(string $value, string $field): string
    {
        if ($value === '') {
            throw new InvalidArgumentException("{$field} must not be empty");
        }

        return $value;
    }

    /**
     * @return non-empty-string|null
     */
    private static function requireNullOrNonEmpty(?string $value, string $field): ?string
    {
        if ($value === '') {
            throw new InvalidArgumentException("{$field} must be null or non-empty");
        }

        return $value;
    }

    /**
     * @return non-empty-string
     */
    private static function requireName(string $value, string $field): string
    {
        if ($value === '' || preg_match(self::NAME_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException("Invalid {$field} \"{$value}\"");
        }

        return $value;
    }

    /**
     * @return non-empty-string
     */
    private static function requireRelativePath(string $value): string
    {
        if (
            $value === ''
            || str_starts_with($value, '/')
            || str_contains($value, "\0")
            || preg_match(self::TRAVERSAL_PATTERN, $value) === 1
        ) {
            throw new InvalidArgumentException("Invalid relative path \"{$value}\"");
        }

        return $value;
    }

    /**
     * @return non-empty-string|null
     */
    private static function requireContentHash(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value === '' || preg_match(self::SHA256_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException("Invalid SHA-256 content hash \"{$value}\"");
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $metadata
     *
     * @return FileMetadata
     */
    private static function requireMetadata(array $metadata): array
    {
        $validated = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || $key === '') {
                throw new InvalidArgumentException('File metadata keys must be non-empty strings');
            }
            if ($value !== null && !is_scalar($value)) {
                throw new InvalidArgumentException("File metadata value \"{$key}\" must be scalar or null");
            }
            $validated[$key] = $value;
        }

        return $validated;
    }

    /**
     * @return non-empty-string
     */
    private static function formatTimestamp(DateTimeImmutable $timestamp): string
    {
        return $timestamp->format(self::TIMESTAMP_FORMAT);
    }

    /**
     * @param non-empty-string $value
     */
    private static function parseTimestamp(string $value): DateTimeImmutable
    {
        $timestamp = DateTimeImmutable::createFromFormat(self::TIMESTAMP_FORMAT, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $timestamp === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $timestamp->format(self::TIMESTAMP_FORMAT) !== $value
        ) {
            throw new InvalidArgumentException("Invalid timestamp \"{$value}\"");
        }

        return $timestamp;
    }
}
