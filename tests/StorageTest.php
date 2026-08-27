<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Filestorage\Exception\AddException;
use Rasuvaeff\Yii3Filestorage\Exception\ContentTooLargeException;
use Rasuvaeff\Yii3Filestorage\Exception\InvalidConfigException;
use Rasuvaeff\Yii3Filestorage\Exception\PolicyViolationException;
use Rasuvaeff\Yii3Filestorage\Exception\RemoveException;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Id\Uuid7IdGenerator;
use Rasuvaeff\Yii3Filestorage\Mime\FinfoMimeTypeDetector;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryOptions;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicy;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\UploadPolicy;
use Rasuvaeff\Yii3Filestorage\Repository\RepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Storage;
use Rasuvaeff\Yii3Filestorage\Store\StoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Store\StoreUrlProviderInterface;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Test\MemoryRepository;
use Rasuvaeff\Yii3Filestorage\Upload;
use Rasuvaeff\Yii3Filestorage\Url\ProxyUrlGeneratorInterface;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Test\Support\Clock\StaticClock;

use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(Storage::class)]
final class StorageTest
{
    private Psr17Factory $factory;
    private StaticClock $clock;
    private InMemoryStore $store;
    private MemoryRepository $repository;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->clock = new StaticClock(new DateTimeImmutable('2026-08-06T12:00:00.123456+00:00'));
        $this->store = new InMemoryStore('memory', $this->factory, $this->clock);
        $this->repository = new MemoryRepository();
    }

    public function addStoresBytesAndMetadataTogether(): void
    {
        $storage = $this->storage();

        $file = $storage->add(
            $this->upload('hello world', 'greeting.txt'),
            groupName: 'documents',
            description: 'a greeting',
            metadata: ['authorId' => 7],
        );

        Assert::same($file->storeName, 'memory');
        Assert::same($file->groupName, 'documents');
        Assert::same($file->originalName, 'greeting.txt');
        Assert::same($file->size, 11);
        Assert::same($file->mimeType, 'text/plain');
        Assert::same($file->description, 'a greeting');
        Assert::same($file->metadata, ['authorId' => 7]);
        Assert::same($file->createdAt->format(File::TIMESTAMP_FORMAT), '2026-08-06T12:00:00.123456+00:00');
        Assert::same($this->store->bytesAt($file->relativePath), 'hello world');
        Assert::same($this->repository->count(), 1);
    }

    public function addFallsBackToTheDefaultGroup(): void
    {
        Assert::same($this->storage()->add($this->upload('x'))->groupName, 'common');
    }

    /**
     * The claim made in the README and in AGENTS.md: the client-supplied name
     * is metadata and cannot influence where anything is written.
     */
    public function aTraversalFilenameNeverReachesThePath(): void
    {
        $file = $this->storage()->add($this->upload('x', '../../etc/passwd'));

        Assert::same($file->originalName, '../../etc/passwd', 'kept verbatim as metadata');
        Assert::false(str_contains($file->relativePath, '..'));
        Assert::false(str_contains($file->relativePath, 'passwd'));
        Assert::same($this->store->paths(), [$file->relativePath]);
    }

    /**
     * Same claim for the extension: a `.png` name over text bytes stores a
     * `.txt` object, because the extension follows the detected type.
     */
    public function theStoredExtensionFollowsDetectionNotTheFilename(): void
    {
        $file = $this->storage()->add($this->upload('definitely not a png', 'trustme.png'));

        Assert::same($file->mimeType, 'text/plain');
        Assert::true(str_ends_with($file->relativePath, '/original.txt'));
    }

    /**
     * The group name is checked before anything is written. `File::create()`
     * would reject it too, but only *after* the object is on disk — so this
     * asserts the store was never touched, not merely that it threw.
     */
    public function addRejectsAnInvalidGroupNameBeforeWritingAnything(): void
    {
        try {
            $this->storage()->add($this->upload('x'), groupName: 'not/a/group');
            Assert::true(actual: false, message: 'the group name should have been rejected');
        } catch (InvalidArgumentException $e) {
            Assert::true(str_contains($e->getMessage(), 'Invalid group name'));
            Assert::same($this->store->writeCount(), 0);
            Assert::same($this->repository->count(), 0);
        }
    }

    public function addRejectsAnUnknownStoreAndNamesTheRegisteredOnes(): void
    {
        Expect::exception(InvalidConfigException::class)->withMessageContaining('Registered stores: memory');

        $this->storage()->add($this->upload('x'), storeName: 'elsewhere');
    }

    /**
     * The ordering that matters: nothing may be written before the policy has
     * had its say, or a rejected upload leaves an object to clean up.
     */
    public function aPolicyRejectionWritesNothing(): void
    {
        $storage = $this->storage(policies: new PolicyRegistry([
            'avatars' => new UploadPolicy(allowedMimeTypes: ['image/png']),
        ]));

        try {
            $storage->add($this->upload('plain text', 'a.png'), groupName: 'avatars');
            Assert::true(actual: false, message: 'the upload should have been rejected');
        } catch (PolicyViolationException) {
            Assert::same($this->store->writeCount(), 0);
            Assert::same($this->repository->count(), 0);
        }
    }

    public function findReturnsTheStoredFile(): void
    {
        $storage = $this->storage();
        $file = $storage->add($this->upload('x'));

        Assert::same($storage->find($file->id)?->id, $file->id);
    }

    public function findReturnsNullForAnUnknownId(): void
    {
        Assert::null($this->storage()->find('nope'));
    }

    public function removeDeletesBothTheRowAndTheObject(): void
    {
        $storage = $this->storage();
        $file = $storage->add($this->upload('x'));

        Assert::true($storage->remove($file->id));
        Assert::null($storage->find($file->id));
        Assert::null($this->store->bytesAt($file->relativePath));
    }

    public function removeReturnsFalseForAnUnknownId(): void
    {
        Assert::false($this->storage()->remove('nope'));
    }

    /**
     * A row that vanishes between the lookup and the delete — another request
     * removed it — must not go on to delete the object, or the two callers
     * together destroy a file the survivor still has a row for.
     */
    public function aRowThatDisappearsMidRemoveLeavesTheObjectAlone(): void
    {
        $storage = $this->storage();
        $file = $storage->add($this->upload('x'));

        $racing = Understudy::for(RepositoryInterface::class);
        Understudy::forwarding($racing, $this->repository);
        when(static fn(): bool => $racing->delete(Arg::any()))->returns(false);

        Assert::false($this->storage(repository: $racing)->remove($file->id));
        Assert::same($this->store->bytesAt($file->relativePath), 'x', 'the object survives');
    }

    /**
     * Metadata is removed first on purpose: the recoverable failure is an
     * object nobody references, not a row promising bytes that are gone.
     */
    public function aFailedObjectDeleteLeavesAnOrphanAndSaysSo(): void
    {
        $store = Understudy::for(StoreInterface::class);
        Understudy::forwarding($store, $this->store);
        when(static fn() => $store->delete(Arg::any()))->throws(new StoreException('the object store is unreachable'));
        $storage = $this->storage(store: $store);
        $file = $storage->add($this->upload('x'));

        try {
            $storage->remove($file->id);
            Assert::true(actual: false, message: 'remove() should have reported the failure');
        } catch (RemoveException $e) {
            Assert::true(
                str_contains($e->getMessage(), 'from store "memory"; it is now an orphan'),
                'the message spans both halves of its concatenation',
            );
            Assert::null($this->repository->find($file->id), 'the row is gone');
            Assert::same($this->store->bytesAt($file->relativePath), 'x', 'the object is not');
        }
    }

    /**
     * Compensation is only safe because base storage never shares an object
     * between two files — so deleting it cannot break anybody else.
     */
    public function aFailedMetadataSaveRemovesTheObjectItJustWrote(): void
    {
        $repository = Understudy::for(RepositoryInterface::class);
        when(static fn() => $repository->save(Arg::any()))->throws(new RuntimeException('database is down'));
        $storage = $this->storage(repository: $repository);

        try {
            $storage->add($this->upload('doomed'));
            Assert::true(actual: false, message: 'add() should have failed');
        } catch (AddException $e) {
            Assert::same($e->getPrevious()?->getMessage(), 'database is down');
            Assert::same($this->store->paths(), [], 'the object was compensated away');
        }
    }

    public function existsReportsWhetherTheObjectIsStillThere(): void
    {
        $storage = $this->storage();
        $file = $storage->add($this->upload('x'));

        Assert::true($storage->exists($file));

        $this->store->clear();

        Assert::false($storage->exists($file));
    }

    public function streamReturnsTheStoredBytes(): void
    {
        $storage = $this->storage();
        $file = $storage->add($this->upload('body'));

        Assert::same($storage->stream($file)?->getContents(), 'body');
    }

    public function streamReturnsNullForAMissingObject(): void
    {
        $storage = $this->storage();
        $file = $storage->add($this->upload('x'));
        $this->store->clear();

        Assert::null($storage->stream($file));
    }

    public function contentReturnsSmallObjectsInline(): void
    {
        $storage = $this->storage();
        $file = $storage->add($this->upload('small'));

        Assert::same($storage->content($file), 'small');
    }

    public function contentReturnsNullForAMissingObject(): void
    {
        $storage = $this->storage();
        $file = $storage->add($this->upload('x'));
        $this->store->clear();

        Assert::null($storage->content($file));
    }

    public function contentRefusesAnObjectOverTheCap(): void
    {
        $storage = $this->storage(maxInlineBytes: 4);
        $file = $storage->add($this->upload('12345'));

        Expect::exception(ContentTooLargeException::class)->withMessageContaining('use stream() instead');

        $storage->content($file);
    }

    public function contentAcceptsAnObjectExactlyAtTheCap(): void
    {
        $storage = $this->storage(maxInlineBytes: 5);
        $file = $storage->add($this->upload('12345'));

        Assert::same($storage->content($file), '12345');
    }

    /**
     * The persisted size is a hint, not the boundary: an object can be replaced
     * behind the package's back, and the cap has to hold against what the
     * stream actually produces.
     */
    public function contentEnforcesTheCapAgainstTheStreamNotTheRecordedSize(): void
    {
        $storage = $this->storage(maxInlineBytes: 8);
        $file = $storage->add($this->upload('tiny'));

        $this->store->corrupt($file->relativePath, str_repeat('x', 100));

        Expect::exception(ContentTooLargeException::class);

        $storage->content($file);
    }

    public function contentHashIsNullUntilHashingIsEnabled(): void
    {
        Assert::null($this->storage()->add($this->upload('hello'))->contentHash);
    }

    public function contentHashIsComputedWhenEnabled(): void
    {
        $storage = $this->storage(integrityHashMaxBytes: 1_000);

        Assert::same($storage->add($this->upload('hello'))->contentHash, hash('sha256', 'hello'));
    }

    /**
     * The limit is inclusive: a file exactly at it is still hashed. Otherwise
     * `integrityHashMaxBytes` would silently mean "one byte less than this".
     */
    public function contentHashIsComputedForAFileExactlyAtTheLimit(): void
    {
        $storage = $this->storage(integrityHashMaxBytes: 5);

        Assert::same($storage->add($this->upload('12345'))->contentHash, hash('sha256', '12345'));
    }

    /**
     * Zero means off, including for an empty upload — where hashing nothing
     * would otherwise produce the digest of the empty string and look like a
     * real answer.
     */
    public function anEmptyUploadIsNotHashedWhenHashingIsDisabled(): void
    {
        Assert::null($this->storage()->add($this->upload(''))->contentHash);
    }

    /**
     * Crossing the limit falls back to storing the file unhashed rather than
     * reading an unbounded stream to the end.
     */
    public function contentHashIsSkippedAboveTheLimit(): void
    {
        $storage = $this->storage(integrityHashMaxBytes: 4);
        $file = $storage->add($this->upload('12345'));

        Assert::null($file->contentHash);
        Assert::same($this->store->bytesAt($file->relativePath), '12345', 'the file is still stored in full');
    }

    public function urlIsNullWhenTheStoreCannotProduceOne(): void
    {
        $storage = $this->storage();
        $file = $storage->add($this->upload('x'));

        Assert::null($storage->url($file));
        Assert::null($storage->temporaryUrl($file, $this->clock->now()));
        Assert::null($storage->urlFor($file));
    }

    public function urlForPrefersAPublicUrlOnlyWhenThePolicyAllowsIt(): void
    {
        $store = $this->urlAwareStore();

        $blocked = $this->storage(store: $store);
        $file = $blocked->add($this->upload('x'));

        Assert::same($blocked->url($file), 'https://cdn.example.com/' . $file->relativePath);
        Assert::same(
            $blocked->urlFor($file),
            'https://cdn.example.com/temp/' . $file->relativePath,
            'the policy forbids a permanent URL, so the presigned one wins',
        );

        $allowed = $this->storage(
            store: $store,
            deliveryPolicies: new DeliveryPolicyRegistry([
                '*' => new DeliveryPolicy(allowDirectPublicUrl: true),
            ]),
        );

        Assert::same($allowed->urlFor($file), 'https://cdn.example.com/' . $file->relativePath);
    }

    public function temporaryUrlPassesTheGroupsDeliveryOptionsToTheStore(): void
    {
        $store = $this->urlAwareStore();
        $storage = $this->storage(store: $store);
        $file = $storage->add($this->upload('a plain text body', 'my report.pdf'));

        $storage->temporaryUrl($file, $this->clock->now());

        $options = Understudy::lastCall(
            static fn(): string => $store->temporaryUrl(Arg::any(), Arg::any(), Arg::any()),
        )?->args[2];

        Assert::instanceOf($options, DeliveryOptions::class);
        Assert::same($options->downloadName, 'my report.pdf');
        Assert::same($options->responseMediaType, 'text/plain');
        Assert::true($options->forceDownload);
    }

    /**
     * The fallback that makes `urlFor()` worth calling: install `-web` and a
     * private store suddenly has a URL, with no template change.
     */
    public function urlForFallsBackToTheProxyGenerator(): void
    {
        $proxy = Understudy::for(ProxyUrlGeneratorInterface::class);
        when(static fn(): string => $proxy->url(Arg::any(), Arg::any()))->answers(
            static fn(Invocation $call): string => "https://app.example.com/files/{$call->args[0]->id}",
        );

        $storage = $this->storage(proxyUrls: $proxy);
        $file = $storage->add($this->upload('x'));

        Assert::same($storage->urlFor($file), "https://app.example.com/files/{$file->id}");

        $expiresAt = Understudy::lastCall(static fn(): string => $proxy->url(Arg::any(), Arg::any()))?->args[1];

        Assert::instanceOf($expiresAt, DateTimeImmutable::class);
        Assert::same(
            $expiresAt->format(File::TIMESTAMP_FORMAT),
            '2026-08-06T13:00:00.123456+00:00',
            'the default TTL is applied to the injected clock',
        );
    }

    public function urlForHonoursAnExplicitExpiry(): void
    {
        $proxy = Understudy::for(ProxyUrlGeneratorInterface::class);
        when(static fn(): string => $proxy->url(Arg::any(), Arg::any()))->returns('https://app.example.com/x');

        $storage = $this->storage(proxyUrls: $proxy);
        $file = $storage->add($this->upload('x'));
        $explicit = new DateTimeImmutable('2030-01-01T00:00:00.000000+00:00');

        $storage->urlFor($file, $explicit);

        Assert::same(
            Understudy::lastCall(static fn(): string => $proxy->url(Arg::any(), Arg::any()))?->args[1],
            $explicit,
        );
    }

    public function anInvalidDefaultGroupIsRejectedAtConstruction(): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('Invalid default group');

        $this->storage(defaultGroup: 'has spaces');
    }

    /**
     * A store that can produce both kinds of URL, so `urlFor()`'s ordering and
     * the delivery options it passes down can be observed. Writes are answered
     * by the real in-memory store; `InMemoryStore` deliberately implements no
     * URL capability, which is what makes it useful for the opposite case.
     */
    private function urlAwareStore(string $baseUrl = 'https://cdn.example.com'): StoreUrlProviderInterface
    {
        $store = Understudy::for(StoreUrlProviderInterface::class);
        when(static fn(): string => $store->name())->returns('memory');
        when(fn() => $store->write(Arg::any(), Arg::any(), Arg::any(), Arg::any(), Arg::any()))
            ->answers(fn(Invocation $call) => $this->store->write(...$call->args));
        when(static fn(): ?string => $store->publicUrl(Arg::any()))->answers(
            static fn(Invocation $call): string => $baseUrl . '/' . $call->args[0]->relativePath,
        );
        when(static fn(): ?string => $store->temporaryUrl(Arg::any(), Arg::any(), Arg::any()))->answers(
            static fn(Invocation $call): string => $baseUrl . '/temp/' . $call->args[0]->relativePath,
        );

        return $store;
    }

    private function upload(string $body, string $name = 'thing.txt'): Upload
    {
        return Upload::fromStream($this->factory->createStream($body), $name, $this->factory);
    }

    private function storage(
        ?StoreInterface $store = null,
        ?RepositoryInterface $repository = null,
        ?PolicyRegistry $policies = null,
        ?DeliveryPolicyRegistry $deliveryPolicies = null,
        string $defaultGroup = 'common',
        int $maxInlineBytes = 8_388_608,
        int $integrityHashMaxBytes = 0,
        ?ProxyUrlGeneratorInterface $proxyUrls = null,
    ): Storage {
        return new Storage(
            stores: new StoreRegistry([$store ?? $this->store]),
            repository: $repository ?? $this->repository,
            pathGenerator: new RandomPathGenerator(),
            mimeTypeDetector: new FinfoMimeTypeDetector(),
            idGenerator: new Uuid7IdGenerator($this->clock),
            policies: $policies ?? new PolicyRegistry(),
            deliveryPolicies: $deliveryPolicies ?? new DeliveryPolicyRegistry(),
            clock: $this->clock,
            defaultUrlTtl: new DateInterval('PT1H'),
            defaultGroup: $defaultGroup,
            maxInlineBytes: $maxInlineBytes,
            integrityHashMaxBytes: $integrityHashMaxBytes,
            proxyUrls: $proxyUrls,
        );
    }
}
