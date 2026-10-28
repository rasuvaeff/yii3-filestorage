<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Test;

use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryOptions;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicy;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Test\UrlCapableInMemoryStore;
use Rasuvaeff\Yii3Filestorage\Upload;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Test\Support\Clock\StaticClock;

#[Test]
#[Covers(UrlCapableInMemoryStore::class)]
final class UrlCapableInMemoryStoreTest
{
    public function mintsBothKindsOfUrls(): void
    {
        $store = $this->store();

        Assert::same(
            $store->publicUrl($this->file()),
            'https://public.example.test/memory/docs/ab/thing.txt',
        );
        Assert::same(
            $store->temporaryUrl($this->file(), new DateTimeImmutable('2026-01-01T01:00:00+00:00'), $this->options()),
            'https://signed.example.test/docs/ab/thing.txt?expires=1767229200',
        );
    }

    /**
     * The toggles exist to exercise the fallback ladder of `Storage::urlFor()`:
     * a store whose presign declines must be representable, and so must one
     * with no public prefix.
     */
    public function eachUrlKindCanBeTurnedOff(): void
    {
        $neither = $this->store(publicUrls: false, temporaryUrls: false);

        Assert::null($neither->publicUrl($this->file()));
        Assert::null($neither->temporaryUrl($this->file(), new DateTimeImmutable(), $this->options()));

        $noPublic = $this->store(publicUrls: false);
        Assert::null($noPublic->publicUrl($this->file()));
        Assert::string((string) $noPublic->temporaryUrl($this->file(), new DateTimeImmutable(), $this->options()));

        $noTemporary = $this->store(temporaryUrls: false);
        Assert::string((string) $noTemporary->publicUrl($this->file()));
        Assert::null($noTemporary->temporaryUrl($this->file(), new DateTimeImmutable(), $this->options()));
    }

    /** It is a store, not just a provider — a registry takes it as one. */
    public function storesAndServesBytesLikeTheBaseDouble(): void
    {
        $store = $this->store();
        $upload = Upload::fromStream(
            (new Psr17Factory())->createStream('hello'),
            'thing.txt',
            new Psr17Factory(),
        );

        $result = $store->write($upload, 'docs', new RandomPathGenerator());

        $file = $this->file($result->relativePath);
        Assert::true($store->exists($file));
        Assert::same($store->size($file), 5);
        Assert::same((string) ($store->stream($file) ?? ''), 'hello');
        Assert::same($store->name(), 'memory');

        $store->delete($file);
        Assert::false($store->exists($file));
    }

    /**
     * A body larger than the double's read chunk must survive the copy loop
     * whole — a per-chunk overwrite would keep only the last piece.
     */
    public function aMultiChunkBodySurvivesTheCopyLoop(): void
    {
        $store = $this->store();
        $body = str_repeat('a', 262_144) . str_repeat('b', 262_144) . 'tail';
        $result = $store->write(
            Upload::fromStream((new Psr17Factory())->createStream($body), 'big.bin', new Psr17Factory()),
            'docs',
            new RandomPathGenerator(),
            maxBytes: strlen($body),
        );

        $file = $this->file($result->relativePath);
        Assert::same($store->size($file), strlen($body));
        Assert::same((string) ($store->stream($file) ?? ''), $body);
    }

    /** The byte cap is inclusive: a body exactly at it is stored. */
    public function theByteCapIsInclusive(): void
    {
        $store = $this->store();
        $result = $store->write(
            Upload::fromStream((new Psr17Factory())->createStream('12345'), 'x.bin', new Psr17Factory()),
            'docs',
            new RandomPathGenerator(),
            maxBytes: 5,
        );

        $file = $this->file($result->relativePath);
        Assert::same((string) ($store->stream($file) ?? ''), '12345');
    }

    private function store(bool $publicUrls = true, bool $temporaryUrls = true): UrlCapableInMemoryStore
    {
        return new UrlCapableInMemoryStore(
            new InMemoryStore('memory', new Psr17Factory(), new StaticClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'))),
            publicUrls: $publicUrls,
            temporaryUrls: $temporaryUrls,
        );
    }

    private function options(): DeliveryOptions
    {
        return DeliveryOptions::fromFile($this->file(), new DeliveryPolicy());
    }

    private function file(string $relativePath = 'docs/ab/thing.txt'): File
    {
        return File::create(
            id: 'f-1',
            storeName: 'memory',
            groupName: 'docs',
            relativePath: $relativePath,
            originalName: 'thing.txt',
            size: 0,
            createdAt: new DateTimeImmutable('2026-08-06T12:00:00.000000+00:00'),
        );
    }
}
