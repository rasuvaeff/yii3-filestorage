<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Store;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\Exception\InvalidConfigException;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(StoreRegistry::class)]
final class StoreRegistryTest
{
    private Psr17Factory $factory;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    /**
     * The single-store case is what core's `di.php` builds, because
     * `yiisoft/config` lets exactly one vendor package bind `StoreInterface`.
     */
    public function oneStoreBecomesTheDefault(): void
    {
        $registry = new StoreRegistry([$this->store('only')]);

        Assert::same($registry->defaultName(), 'only');
        Assert::same($registry->get()->name(), 'only');
        Assert::same($registry->get('only')->name(), 'only');
        Assert::same($registry->names(), ['only']);
    }

    public function severalStoresAreIndexedByTheirOwnName(): void
    {
        $registry = new StoreRegistry([$this->store('a'), $this->store('b')]);

        Assert::same($registry->get('b')->name(), 'b');
        Assert::same($registry->defaultName(), 'a', 'the first store is the default');
        Assert::same($registry->names(), ['a', 'b']);
    }

    public function anExplicitDefaultWins(): void
    {
        $registry = new StoreRegistry([$this->store('a'), $this->store('b')], 'b');

        Assert::same($registry->get()->name(), 'b');
    }

    public function hasReportsMembership(): void
    {
        $registry = new StoreRegistry([$this->store('a')]);

        Assert::true($registry->has('a'));
        Assert::false($registry->has('b'));
    }

    /**
     * The message names what is actually registered, because the alternative is
     * a bare failure against a name the user thought they had configured.
     */
    public function anUnknownStoreNamesTheRegisteredOnes(): void
    {
        $registry = new StoreRegistry([$this->store('a'), $this->store('b')]);

        Expect::exception(InvalidConfigException::class)->withMessageContaining('Registered stores: a, b');

        $registry->get('missing');
    }

    /**
     * Collapsing them silently would make one unreachable, and the symptom
     * would surface much later as files written to the wrong place.
     */
    public function duplicateStoreNamesAreRefused(): void
    {
        Expect::exception(InvalidConfigException::class)->withMessageContaining('Duplicate store name "a"');

        new StoreRegistry([$this->store('a'), $this->store('a')]);
    }

    public function anUnregisteredDefaultIsRefused(): void
    {
        Expect::exception(InvalidConfigException::class)->withMessageContaining('Default store "c" is not registered');

        new StoreRegistry([$this->store('a')], 'c');
    }

    private function store(string $name): InMemoryStore
    {
        return new InMemoryStore($name, $this->factory);
    }
}
