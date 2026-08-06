<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Filestorage\File;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(File::class)]
final class FileTest
{
    public function createExposesEveryFieldItWasGiven(): void
    {
        $created = new DateTimeImmutable('2026-08-06T12:00:00.123456+00:00');
        $updated = new DateTimeImmutable('2026-08-06T13:00:00.654321+00:00');

        $file = File::create(
            id: 'file-1',
            storeName: 'upload',
            groupName: 'avatars',
            relativePath: 'avatars/aa/bb/key/original.png',
            originalName: 'me.png',
            size: 1234,
            createdAt: $created,
            externalId: 'bucket/object',
            mimeType: 'image/png',
            description: 'A picture',
            contentHash: str_repeat('a', 64),
            metadata: ['width' => 100, 'alt' => null],
            updatedAt: $updated,
        );

        Assert::same($file->id, 'file-1');
        Assert::same($file->storeName, 'upload');
        Assert::same($file->groupName, 'avatars');
        Assert::same($file->relativePath, 'avatars/aa/bb/key/original.png');
        Assert::same($file->originalName, 'me.png');
        Assert::same($file->size, 1234);
        Assert::same($file->externalId, 'bucket/object');
        Assert::same($file->mimeType, 'image/png');
        Assert::same($file->description, 'A picture');
        Assert::same($file->contentHash, str_repeat('a', 64));
        Assert::same($file->metadata, ['width' => 100, 'alt' => null]);
        Assert::same($file->createdAt, $created);
        Assert::same($file->updatedAt, $updated);
    }

    public function updatedAtDefaultsToCreatedAt(): void
    {
        $file = self::file();

        Assert::same($file->updatedAt, $file->createdAt);
    }

    public function optionalFieldsDefaultToNull(): void
    {
        $file = self::file();

        Assert::null($file->externalId);
        Assert::null($file->mimeType);
        Assert::null($file->description);
        Assert::null($file->contentHash);
        Assert::same($file->metadata, []);
    }

    /**
     * The whole reason timestamps are not serialised as ATOM: PSR-20 clocks
     * report microseconds, and ATOM would silently drop them.
     */
    public function toArrayKeepsMicroseconds(): void
    {
        $file = self::file(createdAt: new DateTimeImmutable('2026-08-06T12:00:00.123456+00:00'));

        Assert::same($file->toArray()['createdAt'], '2026-08-06T12:00:00.123456+00:00');
    }

    public function fromArrayRestoresEveryField(): void
    {
        $file = File::create(
            id: 'file-1',
            storeName: 'upload',
            groupName: 'docs',
            relativePath: 'docs/aa/bb/key/original.pdf',
            originalName: 'contract.pdf',
            size: 99,
            createdAt: new DateTimeImmutable('2026-08-06T12:00:00.123456+03:00'),
            externalId: 'ext',
            mimeType: 'application/pdf',
            description: 'signed',
            contentHash: str_repeat('f', 64),
            metadata: ['pages' => 3, 'draft' => false, 'note' => 'x', 'ratio' => 1.5, 'none' => null],
            updatedAt: new DateTimeImmutable('2026-08-07T12:00:00.000001+03:00'),
        );

        Assert::same(File::fromArray($file->toArray())->toArray(), $file->toArray());
    }

    public function directoryIsTheParentOfTheStoredObject(): void
    {
        $file = self::file(relativePath: 'avatars/aa/bb/0123456789abcdef/original.png');

        Assert::same($file->directory(), 'avatars/aa/bb/0123456789abcdef');
    }

    public function directoryOfAPathWithoutSeparatorsIsThePathItself(): void
    {
        $file = self::file(relativePath: 'lonely.bin');

        Assert::same($file->directory(), 'lonely.bin');
    }

    public function withMetadataReplacesMetadataAndBumpsUpdatedAt(): void
    {
        $file = self::file();
        $later = $file->createdAt->modify('+1 hour');

        $updated = $file->withMetadata(['a' => 1], $later);

        Assert::same($updated->metadata, ['a' => 1]);
        Assert::same($updated->updatedAt, $later);
        Assert::same($updated->id, $file->id);
        Assert::same($file->metadata, [], 'the original is untouched');
    }

    #[DataProvider('invalidFieldProvider')]
    public function createRejectsInvalidInput(string $message, callable $build): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining($message);

        $build();
    }

    /**
     * @return iterable<string, array{string, callable}>
     */
    public static function invalidFieldProvider(): iterable
    {
        yield 'empty id' => ['id must not be empty', static fn (): File => self::file(id: '')];
        yield 'empty original name' => [
            'originalName must not be empty',
            static fn (): File => self::file(originalName: ''),
        ];
        yield 'empty store name' => ['Invalid store name', static fn (): File => self::file(storeName: '')];
        yield 'store name with a slash' => [
            'Invalid store name',
            static fn (): File => self::file(storeName: 'up/load'),
        ];
        yield 'store name starting with a dash' => [
            'Invalid store name',
            static fn (): File => self::file(storeName: '-upload'),
        ];
        yield 'store name over 64 characters' => [
            'Invalid store name',
            static fn (): File => self::file(storeName: str_repeat('a', 65)),
        ];
        yield 'empty group name' => ['Invalid group name', static fn (): File => self::file(groupName: '')];
        yield 'negative size' => ['Size must not be negative', static fn (): File => self::file(size: -1)];
        yield 'empty relative path' => [
            'Invalid relative path',
            static fn (): File => self::file(relativePath: ''),
        ];
        yield 'absolute relative path' => [
            'Invalid relative path',
            static fn (): File => self::file(relativePath: '/etc/passwd'),
        ];
        yield 'traversal segment' => [
            'Invalid relative path',
            static fn (): File => self::file(relativePath: 'a/../../etc/passwd'),
        ];
        yield 'leading traversal' => [
            'Invalid relative path',
            static fn (): File => self::file(relativePath: '../secret'),
        ];
        yield 'trailing traversal' => [
            'Invalid relative path',
            static fn (): File => self::file(relativePath: 'a/b/..'),
        ];
        yield 'NUL byte in path' => [
            'Invalid relative path',
            static fn (): File => self::file(relativePath: "a/b\0.png"),
        ];
        yield 'empty external id' => [
            'externalId must be null or non-empty',
            static fn (): File => self::file(externalId: ''),
        ];
        yield 'empty mime type' => [
            'mimeType must be null or non-empty',
            static fn (): File => self::file(mimeType: ''),
        ];
        yield 'short content hash' => [
            'Invalid SHA-256 content hash',
            static fn (): File => self::file(contentHash: 'abc'),
        ];
        yield 'uppercase content hash' => [
            'Invalid SHA-256 content hash',
            static fn (): File => self::file(contentHash: str_repeat('A', 64)),
        ];
        yield 'updatedAt before createdAt' => [
            'updatedAt must not precede createdAt',
            static fn (): File => self::file(
                createdAt: new DateTimeImmutable('2026-08-06T12:00:00+00:00'),
                updatedAt: new DateTimeImmutable('2026-08-06T11:00:00+00:00'),
            ),
        ];
        yield 'integer metadata key' => [
            'File metadata keys must be non-empty strings',
            static fn (): File => self::file(metadata: [0 => 'x']),
        ];
        yield 'empty metadata key' => [
            'File metadata keys must be non-empty strings',
            static fn (): File => self::file(metadata: ['' => 'x']),
        ];
        yield 'array metadata value' => [
            'must be scalar or null',
            static fn (): File => self::file(metadata: ['a' => ['nested']]),
        ];
    }

    /**
     * A path that ends in `..` as part of a longer segment is a normal name,
     * not traversal: rejecting `a/b..` would be wrong.
     */
    public function pathWithDotsInsideASegmentIsAccepted(): void
    {
        Assert::same(self::file(relativePath: 'a/b../c...d/original.bin')->relativePath, 'a/b../c...d/original.bin');
    }

    #[DataProvider('invalidTimestampProvider')]
    public function fromArrayRejectsAMalformedTimestamp(string $timestamp): void
    {
        $row = self::file()->toArray();
        $row['createdAt'] = $timestamp;

        Expect::exception(InvalidArgumentException::class)->withMessageContaining('Invalid timestamp');

        File::fromArray($row);
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function invalidTimestampProvider(): iterable
    {
        yield 'nonsense' => ['not a date'];
        yield 'ATOM without microseconds' => ['2026-08-06T12:00:00+00:00'];
        yield 'month 13' => ['2026-13-06T12:00:00.000000+00:00'];
        yield 'trailing garbage' => ['2026-08-06T12:00:00.000000+00:00 and more'];
    }

    /**
     * The contract the database backend is written against: a row read back
     * must reconstruct the same value object, microseconds and all.
     */
    #[Property(runs: 300)]
    public function arrayRoundTripIsLossless(
        string $id,
        string $originalName,
        int $size,
        int $createdOffset,
        int $updatedGap,
        ?string $description,
    ): void {
        $createdAt = (new DateTimeImmutable('2000-01-01T00:00:00.000000+00:00'))
            ->modify("+{$createdOffset} seconds")
            ->modify('+' . ($createdOffset % 999_999) . ' microseconds');

        $file = File::create(
            id: $id,
            storeName: 'upload',
            groupName: 'common',
            relativePath: "common/{$id}/original.bin",
            originalName: $originalName,
            size: $size,
            createdAt: $createdAt,
            description: $description,
            updatedAt: $createdAt->modify("+{$updatedGap} seconds"),
        );

        Assert::same(File::fromArray($file->toArray())->toArray(), $file->toArray());
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function arrayRoundTripIsLosslessGenerators(): array
    {
        return [
            'id' => Gen::stringFrom('0123456789abcdef-', 1, 40),
            'originalName' => Gen::stringFrom('abcXYZ 0123456789._-', 1, 60),
            'size' => Gen::intBetween(0, 1_000_000_000),
            'createdOffset' => Gen::intBetween(0, 1_600_000_000),
            'updatedGap' => Gen::intBetween(0, 100_000),
            'description' => Gen::nullable(Gen::stringOf(0, 40)),
        ];
    }

    /**
     * @param array<array-key, mixed> $metadata
     */
    private static function file(
        string $id = 'file-1',
        string $storeName = 'upload',
        string $groupName = 'common',
        string $relativePath = 'common/aa/bb/key/original.bin',
        string $originalName = 'thing.bin',
        int $size = 10,
        ?DateTimeImmutable $createdAt = null,
        ?string $externalId = null,
        ?string $mimeType = null,
        ?string $description = null,
        ?string $contentHash = null,
        array $metadata = [],
        ?DateTimeImmutable $updatedAt = null,
    ): File {
        return File::create(
            id: $id,
            storeName: $storeName,
            groupName: $groupName,
            relativePath: $relativePath,
            originalName: $originalName,
            size: $size,
            createdAt: $createdAt ?? new DateTimeImmutable('2026-08-06T12:00:00.000000+00:00'),
            externalId: $externalId,
            mimeType: $mimeType,
            description: $description,
            contentHash: $contentHash,
            metadata: $metadata,
            updatedAt: $updatedAt,
        );
    }
}
