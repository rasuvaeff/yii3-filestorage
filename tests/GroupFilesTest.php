<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests;

use DateTimeImmutable;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\GroupFiles;
use Rasuvaeff\Yii3Filestorage\Test\MemoryRepository;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(GroupFiles::class)]
final class GroupFilesTest
{
    public function yieldsOnlyTheGroupsFilesInWalkOrder(): void
    {
        $repository = new MemoryRepository();
        $repository->save($this->file('a', 'avatars'));
        $repository->save($this->file('b', 'docs'));
        $repository->save($this->file('c', 'avatars'));

        $ids = [];
        foreach ((new GroupFiles($repository, 'avatars'))() as $file) {
            $ids[] = $file->id;
        }

        Assert::same($ids, ['a', 'c']);
    }

    /** The walk pages until exhausted — a group spread over pages arrives whole. */
    public function theWalkContinuesPastPagesWithNoMatches(): void
    {
        $repository = new MemoryRepository();
        for ($i = 0; $i < 3; $i++) {
            $repository->save($this->file("noise-{$i}", 'docs'));
        }
        $repository->save($this->file('wanted', 'avatars'));

        $ids = [];
        foreach ((new GroupFiles($repository, 'avatars'))() as $file) {
            $ids[] = $file->id;
        }

        Assert::same($ids, ['wanted']);
    }

    /** The metadata filter is presence, not value: "everything we know the size of". */
    public function theMetadataFilterChecksPresence(): void
    {
        $repository = new MemoryRepository();
        $repository->save($this->file('sized', 'avatars', ['width' => 320, 'height' => 200]));
        $repository->save($this->file('unsized', 'avatars'));

        $ids = [];
        foreach ((new GroupFiles($repository, 'avatars', metadataKey: 'width'))() as $file) {
            $ids[] = $file->id;
        }

        Assert::same($ids, ['sized']);
    }

    public function anEmptyGroupYieldsNothing(): void
    {
        $repository = new MemoryRepository();
        $repository->save($this->file('a', 'docs'));

        Assert::same(iterator_count((new GroupFiles($repository, 'avatars'))()), 0);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function file(string $id, string $group, array $metadata = []): File
    {
        return File::create(
            id: $id,
            storeName: 'memory',
            groupName: $group,
            relativePath: "{$group}/ab/cd/{$id}/original.bin",
            originalName: "{$id}.bin",
            size: 1,
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            metadata: $metadata,
        );
    }
}
