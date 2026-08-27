<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests;

use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Filestorage\Command\BackfillHashCommand;
use Rasuvaeff\Yii3Filestorage\Command\CheckCommand;
use Rasuvaeff\Yii3Filestorage\Command\GcCommand;
use Rasuvaeff\Yii3Filestorage\Command\ImportCommand;
use Rasuvaeff\Yii3Filestorage\Command\StatCommand;
use Rasuvaeff\Yii3Filestorage\Command\VerifyCommand;
use Rasuvaeff\Yii3Filestorage\Id\IdGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Id\Uuid7IdGenerator;
use Rasuvaeff\Yii3Filestorage\Mime\ExtensionMap;
use Rasuvaeff\Yii3Filestorage\Mime\FinfoMimeTypeDetector;
use Rasuvaeff\Yii3Filestorage\Mime\MimeTypeDetectorInterface;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface;
use Rasuvaeff\Yii3Filestorage\Repository\MaintenanceRepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Repository\RepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Storage;
use Rasuvaeff\Yii3Filestorage\StorageInterface;
use Rasuvaeff\Yii3Filestorage\Store\BlobLedgerInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Test\MemoryBlobLedger;
use Rasuvaeff\Yii3Filestorage\Test\MemoryRepository;
use Rasuvaeff\Yii3Filestorage\Tests\Support\CountingStorage;
use Rasuvaeff\Yii3Filestorage\Upload;
use Rasuvaeff\Yii3Filestorage\Url\ProxyUrlGeneratorInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;
use Yiisoft\Files\FileHelper;
use Yiisoft\Test\Support\Clock\StaticClock;

use function Rasuvaeff\Understudy\when;

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

    /**
     * The reason `Storage::class` is bound separately. An application that
     * rebinds `StorageInterface` to something wrapping the plain facade — which
     * is how `-db` offers deduplication — needs an id for the thing being
     * wrapped. If the wrapper's own dependency resolved through
     * `StorageInterface` it would resolve to the wrapper, and the container
     * would answer `CircularReferenceException` for a recipe that reads
     * perfectly.
     */
    public function anApplicationCanRebindTheInterfaceToSomethingThatWrapsTheBase(): void
    {
        $container = $this->container([
            StorageInterface::class => static fn(Storage $inner): StorageInterface => new CountingStorage($inner),
        ]);

        $decorated = $container->get(StorageInterface::class);
        $base = $container->get(Storage::class);

        Assert::instanceOf($decorated, CountingStorage::class);
        Assert::notSame($decorated, $base, 'the two ids resolve to different objects once one is decorated');
        Assert::same($decorated->inner, $base, 'and the wrapper was handed the base, not itself');

        // Delegation, not just construction: a wrapper that resolved without
        // reaching the real facade would satisfy the assertions above.
        $factory = new Psr17Factory();
        $file = $decorated->add(Upload::fromStream($factory->createStream('hello'), 'a.txt', $factory));

        Assert::same($decorated->added, 1);
        Assert::same($base->content($file), 'hello');
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
            ProxyUrlGeneratorInterface::class => static function (): ProxyUrlGeneratorInterface {
                $proxy = Understudy::for(ProxyUrlGeneratorInterface::class);
                when(static fn(): string => $proxy->url(Arg::any(), Arg::any()))->answers(
                    static fn(Invocation $call): string => "https://app.example.com/files/{$call->args[0]->id}",
                );

                return $proxy;
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
            'integrityHashMaxBytes',
            'defaultUrlTtl',
            'extensionOverrides',
            'policies',
            'delivery',
        ] as $key) {
            Assert::true(\array_key_exists($key, $own), "params is missing \"{$key}\"");
        }

        // A command class the container can build but `params` never names is
        // a command nobody can run.
        Assert::same($params['yiisoft/yii-console']['commands'], [
            'filestorage:check' => CheckCommand::class,
            'filestorage:gc' => GcCommand::class,
            'filestorage:verify' => VerifyCommand::class,
            'filestorage:backfill-hash' => BackfillHashCommand::class,
            'filestorage:stat' => StatCommand::class,
            'filestorage:import' => ImportCommand::class,
        ]);
    }

    /**
     * The maintenance commands ask for a repository that can be *walked*, a
     * stronger contract than the hot path's. An installation whose backend
     * implements only `RepositoryInterface` cannot build them at all, and
     * because `params.php` registers every command unconditionally, running
     * one there fails in the container naming the interface it needs.
     */
    public function theMaintenanceCommandsResolveOnceTheBackendCanBeWalked(): void
    {
        $container = $this->maintenanceContainer();

        Assert::instanceOf($container->get(GcCommand::class), GcCommand::class);
        Assert::instanceOf($container->get(VerifyCommand::class), VerifyCommand::class);
        Assert::instanceOf($container->get(BackfillHashCommand::class), BackfillHashCommand::class);
        Assert::instanceOf($container->get(StatCommand::class), StatCommand::class);
    }

    /**
     * The nullable-default shape again, and the one that matters most here:
     * deduplication lives in `-db`, so most installations have no ledger, and
     * `gc` still has orphans to sweep. A definitions release that passes `null`
     * over a constructor default would turn "no ledger" into a boot failure.
     */
    public function gcResolvesWithNoLedgerBound(): void
    {
        $tester = new CommandTester($this->maintenanceContainer()->get(GcCommand::class));
        $tester->execute([]);

        Assert::string($tester->getDisplay())->contains('No blob ledger is bound');
    }

    public function gcPicksUpALedgerWhenTheBackendBindsOne(): void
    {
        $container = $this->maintenanceContainer([
            BlobLedgerInterface::class => static fn(
                MaintenanceRepositoryInterface $repository,
            ): BlobLedgerInterface => new MemoryBlobLedger($repository),
        ]);

        $tester = new CommandTester($container->get(GcCommand::class));
        $tester->execute([]);

        Assert::string($tester->getDisplay())->notContains('No blob ledger is bound');
    }


    /**
     * The scope provider reaches `gc`, which is how it knows to refuse
     * `--orphans`. If the wiring drops it, a multi-tenant installation gets a
     * command that deletes other tenants' files and reports success.
     */
    public function gcSeesTheScopeProviderWhenTheApplicationBindsOne(): void
    {
        $container = $this->maintenanceContainer([
            FileScopeProviderInterface::class => static function (): FileScopeProviderInterface {
                $scopes = Understudy::for(FileScopeProviderInterface::class);
                when(static fn(): ?string => $scopes->currentScopeId())->returns('tenant-a');

                return $scopes;
            },
        ]);

        $tester = new CommandTester($container->get(GcCommand::class));
        $tester->execute(['--orphans' => true, '--apply' => true]);

        Assert::same($tester->getStatusCode(), Command::FAILURE);
        Assert::string($tester->getDisplay())->contains('Refusing --orphans');
    }

    /**
     * `import` resolves from the facade, not from a store and a repository —
     * which is what makes it inherit whatever the application bound to
     * `StorageInterface`. An application that enabled deduplication did it by
     * overriding exactly that key, and importing a legacy tree is when sharing
     * pays most; wiring past the facade would quietly import everything unique.
     */
    public function importResolvesThroughWhateverIsBoundAsTheFacade(): void
    {
        $container = $this->container();
        $source = sys_get_temp_dir() . '/fs-wiring-' . bin2hex(random_bytes(8));
        mkdir($source . '/tree', 0o775, recursive: true);
        file_put_contents($source . '/tree/a.txt', 'hello');

        $tester = new CommandTester($container->get(ImportCommand::class));
        $tester->execute([
            'directory' => $source,
            '--apply' => true,
            '--manifest' => $source . '/manifest.jsonl',
        ]);

        // Landed in the container's own repository, through the container's own
        // facade — the wiring, not a hand-built command.
        $files = iterator_to_array($container->get(RepositoryInterface::class)->files(null, 10), preserve_keys: false);

        Assert::same($tester->getStatusCode(), Command::SUCCESS);
        Assert::same(\count($files), 1);
        Assert::same($files[0]->metadata['importSource'], 'tree/a.txt');

        FileHelper::removeDirectory($source);
    }

    /**
     * `stat` gets the same optional dependency, for the reporting half of the
     * same asymmetry: with a scope provider bound it withholds the two physical
     * figures a tenant-filtered walk cannot produce. Wiring that drops it prints
     * an object count and a savings number computed from one tenant's rows.
     */
    public function statSeesTheScopeProviderWhenTheApplicationBindsOne(): void
    {
        $container = $this->maintenanceContainer([
            FileScopeProviderInterface::class => static function (): FileScopeProviderInterface {
                $scopes = Understudy::for(FileScopeProviderInterface::class);
                when(static fn(): ?string => $scopes->currentScopeId())->returns('tenant-a');

                return $scopes;
            },
        ]);

        $tester = new CommandTester($container->get(StatCommand::class));
        $tester->execute([]);

        // Whitespace squeezed out: SymfonyStyle wraps to the console width,
        // and that width is not the same on every CI runner — the phrase fits
        // one line on Linux and straddles two on Windows.
        Assert::string((string) preg_replace('/[\s!\[\]]+/u', ' ', $tester->getDisplay()))
            ->contains('current scope only');
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

        // Spread, not `+`: with the union operator the left operand wins, so a
        // test passing its own StoreInterface or RepositoryInterface silently
        // got the default one and asserted against the wrong object.
        return new Container(ContainerConfig::create()->withDefinitions([...$definitions, ...$extra]));
    }

    /**
     * A backend that implements both repository contracts, as `-db` does.
     *
     * @param array<string, mixed> $extra
     */
    private function maintenanceContainer(array $extra = []): Container
    {
        return $this->container([
            MaintenanceRepositoryInterface::class => static fn(
                RepositoryInterface $repository,
            ): MaintenanceRepositoryInterface => $repository,
            ...$extra,
        ]);
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
