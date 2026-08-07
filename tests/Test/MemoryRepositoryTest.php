<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Test;

use DateTimeImmutable;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Test\MemoryRepository;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(MemoryRepository::class)]
final class MemoryRepositoryTest
{
    private MemoryRepository $repository;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->repository = new MemoryRepository();
    }

    public function savesAndFindsByIdentifier(): void
    {
        $file = $this->file('a');

        $this->repository->save($file);

        Assert::same($this->repository->find('a')?->id, 'a');
        Assert::same($this->repository->count(), 1);
    }

    /**
     * `null`, not an exception: "not found" is an ordinary answer, consistently
     * with `yiisoft/*` repositories.
     */
    public function findReturnsNullForAnUnknownIdentifier(): void
    {
        Assert::null($this->repository->find('nope'));
    }

    public function savingTheSameIdentifierReplacesTheRow(): void
    {
        $this->repository->save($this->file('a', originalName: 'first.txt'));
        $this->repository->save($this->file('a', originalName: 'second.txt'));

        Assert::same($this->repository->find('a')?->originalName, 'second.txt');
        Assert::same($this->repository->count(), 1);
    }

    public function deleteReportsWhetherThereWasAnything(): void
    {
        $this->repository->save($this->file('a'));

        Assert::true($this->repository->delete('a'));
        Assert::false($this->repository->delete('a'));
        Assert::null($this->repository->find('a'));
    }

    public function allReturnsEveryStoredFile(): void
    {
        $this->repository->save($this->file('a'));
        $this->repository->save($this->file('b'));

        Assert::same(count($this->repository->all()), 2);
    }

    public function clearEmptiesTheRepository(): void
    {
        $this->repository->save($this->file('a'));

        $this->repository->clear();

        Assert::same($this->repository->count(), 0);
        Assert::same($this->repository->all(), []);
    }

    public function filesPagesInIdentifierOrder(): void
    {
        foreach (['c', 'a', 'b', 'd'] as $id) {
            $this->repository->save($this->file($id));
        }

        $ids = array_map(
            static fn(File $file): string => $file->id,
            iterator_to_array($this->repository->files(limit: 2), false),
        );

        Assert::same($ids, ['a', 'b']);
    }

    public function filesResumesAfterACursor(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $id) {
            $this->repository->save($this->file($id));
        }

        $ids = array_map(
            static fn(File $file): string => $file->id,
            iterator_to_array($this->repository->files(afterId: 'b', limit: 10), false),
        );

        Assert::same($ids, ['c', 'd']);
    }

    public function updateContentHashRewritesOnlyTheHash(): void
    {
        $this->repository->save($this->file('a', originalName: 'keep.txt'));
        $hash = hash('sha256', 'content');

        Assert::true($this->repository->updateContentHash('a', $hash));

        $file = $this->repository->find('a');
        Assert::same($file?->contentHash, $hash);
        Assert::same($file?->originalName, 'keep.txt');
    }

    public function updateContentHashReportsAMissingRow(): void
    {
        Assert::false($this->repository->updateContentHash('nope', hash('sha256', 'x')));
    }

    private function file(string $id, string $originalName = 'thing.txt'): File
    {
        return File::create(
            id: $id,
            storeName: 'memory',
            groupName: 'common',
            relativePath: "common/{$id}/original.txt",
            originalName: $originalName,
            size: 1,
            createdAt: new DateTimeImmutable('2026-08-06T12:00:00.000000+00:00'),
        );
    }
}
