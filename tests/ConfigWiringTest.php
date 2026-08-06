<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests;

use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Filestorage\Command\CheckCommand;
use Rasuvaeff\Yii3Filestorage\Id\IdGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Id\Uuid7IdGenerator;
use Rasuvaeff\Yii3Filestorage\Mime\ExtensionMap;
use Rasuvaeff\Yii3Filestorage\Mime\FinfoMimeTypeDetector;
use Rasuvaeff\Yii3Filestorage\Mime\MimeTypeDetectorInterface;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Repository\RepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Storage;
use Rasuvaeff\Yii3Filestorage\StorageInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Test\MemoryRepository;
use Rasuvaeff\Yii3Filestorage\Upload;
use Rasuvaeff\Yii3Filestorage\Url\ProxyUrlGeneratorInterface;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;
use Yiisoft\Test\Support\Clock\StaticClock;

/**
 * `config/di.php` is covered by neither cs, nor psalm, nor the unit suite — it
 * is not in `src`. Without this test a mistake there surfaces at deploy time,
 * so it is exercised through a real container rather than by reading the array.
 *
 * @internal
 */
#[Test]
#[CoversNothing]
final class ConfigWiringTest
{
    /**
     * Install the package, bind the two swappable interfaces, and everything
     * else has to resolve. If it does not, the first-run experience is a
     * container error naming an interface the user has never heard of.
     */
    public function theFacadeResolvesOnceTheApplicationBindsAStoreAndARepository(): void
    {
        $storage = $this->container()->get(StorageInterface::class);

        Assert::instanceOf($storage, Storage::class);

        $factory = new Psr17Factory();
        $file = $storage->add(Upload::fromStream($factory->createStream('hello'), 'a.txt', $factory));

        Assert::same($storage->content($file), 'hello');
    }

    public function coreServicesResolveToTheirDefaultImplementations(): void
    {
        $container = $this->container();

        Assert::instanceOf($container->get(MimeTypeDetectorInterface::class), FinfoMimeTypeDetector::class);
        Assert::instanceOf($container->get(PathGeneratorInterface::class), RandomPathGenerator::class);
        Assert::instanceOf($container->get(IdGeneratorInterface::class), Uuid7IdGenerator::class);
        Assert::instanceOf($container->get(ExtensionMap::class), ExtensionMap::class);
        Assert::instanceOf($container->get(PolicyRegistry::class), PolicyRegistry::class);
        Assert::instanceOf($container->get(DeliveryPolicyRegistry::class), DeliveryPolicyRegistry::class);
        Assert::instanceOf($container->get(CheckCommand::class), CheckCommand::class);
    }

    /**
     * The registry is built from the single `StoreInterface` a backend binds,
     * which is the whole reason it exists: `yiisoft/config` lets exactly one
     * vendor package define a key.
     */
    public function theRegistryIsBuiltFromTheOneBoundStore(): void
    {
        $registry = $this->container()->get(StoreRegistry::class);

        Assert::same($registry->names(), ['memory']);
        Assert::same($registry->defaultName(), 'memory');
    }

    /**
     * `-web` is the only vendor package that binds the proxy generator, so
     * without it the optional dependency has to resolve as absent rather than
     * blowing up — this is exactly the shape that has bitten a nullable
     * constructor default in this monorepo before.
     */
    public function theOptionalProxyGeneratorResolvesAsAbsentWhenNothingBindsIt(): void
    {
        $factory = new Psr17Factory();
        $storage = $this->container()->get(StorageInterface::class);
        $file = $storage->add(Upload::fromStream($factory->createStream('x'), 'a.txt', $factory));

        Assert::null($storage->urlFor($file));
    }

    public function bindingTheProxyGeneratorGivesAPrivateStoreAUrl(): void
    {
        $container = $this->container([
            ProxyUrlGeneratorInterface::class => static fn(): ProxyUrlGeneratorInterface
                => new class implements ProxyUrlGeneratorInterface {
                    #[\Override]
                    public function url(
                        \Rasuvaeff\Yii3Filestorage\File $file,
                        \DateTimeImmutable $expiresAt,
                    ): string {
                        return "https://app.example.com/files/{$file->id}";
                    }
                },
        ]);

        $factory = new Psr17Factory();
        $storage = $container->get(StorageInterface::class);
        $file = $storage->add(Upload::fromStream($factory->createStream('x'), 'a.txt', $factory));

        Assert::same($storage->urlFor($file), "https://app.example.com/files/{$file->id}");
    }

    /**
     * The one-source rule. Core binding either of these would make a backend
     * package binding it too a `yiisoft/config` `Duplicate key` error — which
     * is by design, and only works if core stays out of the way.
     */
    public function coreBindsNeitherTheStoreNorTheRepository(): void
    {
        $definitions = $this->definitions();

        Assert::false(\array_key_exists(StoreInterface::class, $definitions));
        Assert::false(\array_key_exists(RepositoryInterface::class, $definitions));
    }

    /**
     * `params.php` has to carry every key `di.php` reads, or the package fails
     * to boot against its own defaults.
     */
    public function everyParameterTheWiringReadsIsShipped(): void
    {
        $params = $this->params();
        $own = $params['rasuvaeff/yii3-filestorage'];

        foreach ([
            'defaultGroup',
            'maxInlineBytes',
            'maxSpoolBytes',
            'integrityHashMaxBytes',
            'defaultUrlTtl',
            'extensionOverrides',
            'policies',
            'delivery',
        ] as $key) {
            Assert::true(\array_key_exists($key, $own), "params is missing \"{$key}\"");
        }

        Assert::same($params['yiisoft/yii-console']['commands']['filestorage:check'], CheckCommand::class);
    }

    /**
     * The shipped defaults have to be the safe ones: a permissive delivery
     * default would make every fresh installation hand out permanent public
     * URLs.
     */
    public function theShippedDeliveryDefaultIsTheSafeOne(): void
    {
        $policy = $this->container()->get(DeliveryPolicyRegistry::class)->for('anything');

        Assert::false($policy->allowDirectPublicUrl);
        Assert::true($policy->forceDownload);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function container(array $extra = []): Container
    {
        $definitions = $this->definitions();

        $definitions[StreamFactoryInterface::class] = Psr17Factory::class;
        $definitions[ClockInterface::class] = static fn(): ClockInterface => new StaticClock(new DateTimeImmutable('2026-01-01T00:00:00.000000+00:00'));
        $definitions[StoreInterface::class] = static fn(
            StreamFactoryInterface $streams,
        ): StoreInterface => new InMemoryStore('memory', $streams);
        $definitions[RepositoryInterface::class] = MemoryRepository::class;

        return new Container(ContainerConfig::create()->withDefinitions($definitions + $extra));
    }

    /**
     * @return array<string, mixed>
     */
    private function definitions(): array
    {
        $params = $this->params();

        return require __DIR__ . '/../config/di.php';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function params(): array
    {
        return require __DIR__ . '/../config/params.php';
    }
}
