<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Filestorage\Command\BackfillHashCommand;
use Rasuvaeff\Yii3Filestorage\Command\CheckCommand;
use Rasuvaeff\Yii3Filestorage\Command\GcCommand;
use Rasuvaeff\Yii3Filestorage\Command\ImportCommand;
use Rasuvaeff\Yii3Filestorage\Command\StatCommand;
use Rasuvaeff\Yii3Filestorage\Command\VerifyCommand;
use Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface;
use Rasuvaeff\Yii3Filestorage\Repository\MaintenanceRepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Repository\ScopedFileResolverInterface;
use Rasuvaeff\Yii3Filestorage\Store\BlobLedgerInterface;
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
use Rasuvaeff\Yii3Filestorage\Url\ProxyUrlGeneratorInterface;

/** @var array $params */

// Core binds the facade and its own services. It deliberately does NOT bind
// StoreInterface or RepositoryInterface: `yiisoft/config` allows exactly one
// vendor package to define a key, so the swappable interfaces belong to
// whichever backend is installed (-flysystem, -db) or to the application.
// Two backends binding the same one is a `Duplicate key` error by design —
// it means "choose one", and it is better raised at build time than resolved
// arbitrarily at runtime.
return [
    ExtensionMap::class => static fn (): ExtensionMap => new ExtensionMap(
        $params['rasuvaeff/yii3-filestorage']['extensionOverrides'],
    ),

    MimeTypeDetectorInterface::class => FinfoMimeTypeDetector::class,

    IdGeneratorInterface::class => Uuid7IdGenerator::class,

    PathGeneratorInterface::class => RandomPathGenerator::class,

    PolicyRegistry::class => static fn (): PolicyRegistry => PolicyRegistry::fromArray(
        $params['rasuvaeff/yii3-filestorage']['policies'],
    ),

    DeliveryPolicyRegistry::class => static fn (): DeliveryPolicyRegistry => DeliveryPolicyRegistry::fromArray(
        $params['rasuvaeff/yii3-filestorage']['delivery'],
    ),

    // One store in, one registry out. An application that genuinely runs
    // several physical stores overrides this key in its own config, where the
    // app layer beats the vendor layer.
    StoreRegistry::class => static fn (StoreInterface $store): StoreRegistry => new StoreRegistry([$store]),

    // Two ids for one facade, and the second one is not decoration. A package
    // that decorates the facade — `-db`'s deduplicating storage is the first,
    // and it will not be the last — has to be handed the undecorated one. With
    // `StorageInterface` as the only id, an application that rebinds it to a
    // decorator makes the decorator's own dependency resolve to itself:
    // `CircularReferenceException`, and nothing about the recipe says why. The
    // concrete class is the id that always means "core's plain facade", whatever
    // the application has done to the interface.
    Storage::class => static fn (
        StoreRegistry $stores,
        RepositoryInterface $repository,
        PathGeneratorInterface $pathGenerator,
        MimeTypeDetectorInterface $mimeTypeDetector,
        IdGeneratorInterface $idGenerator,
        PolicyRegistry $policies,
        DeliveryPolicyRegistry $deliveryPolicies,
        ClockInterface $clock,
        ?ProxyUrlGeneratorInterface $proxyUrls = null,
    ): Storage => new Storage(
        stores: $stores,
        repository: $repository,
        pathGenerator: $pathGenerator,
        mimeTypeDetector: $mimeTypeDetector,
        idGenerator: $idGenerator,
        policies: $policies,
        deliveryPolicies: $deliveryPolicies,
        clock: $clock,
        defaultUrlTtl: new DateInterval($params['rasuvaeff/yii3-filestorage']['defaultUrlTtl']),
        defaultGroup: $params['rasuvaeff/yii3-filestorage']['defaultGroup'],
        maxInlineBytes: $params['rasuvaeff/yii3-filestorage']['maxInlineBytes'],
        integrityHashMaxBytes: $params['rasuvaeff/yii3-filestorage']['integrityHashMaxBytes'],
        proxyUrls: $proxyUrls,
    ),

    StorageInterface::class => Storage::class,

    CheckCommand::class => static fn (
        StoreRegistry $stores,
        RepositoryInterface $repository,
        PolicyRegistry $policies,
        DeliveryPolicyRegistry $deliveryPolicies,
        ?FileScopeProviderInterface $scopes = null,
        ?ScopedFileResolverInterface $scopedFiles = null,
    ): CheckCommand => new CheckCommand(
        stores: $stores,
        repository: $repository,
        policies: $policies,
        deliveryPolicies: $deliveryPolicies,
        // Both param arrays, not just `policies`. A group configured only
        // under `delivery` still inherits `policies['*']`, whose shipped
        // default accepts anything — so `delivery['x']['allowDirectPublicUrl']`
        // with no `policies['x']` is precisely the unsafe combination
        // CheckCommand exists to fail on, and it would never have been looked at.
        knownGroups: array_values(array_unique(array_filter(
            [
                ...array_keys($params['rasuvaeff/yii3-filestorage']['policies']),
                ...array_keys($params['rasuvaeff/yii3-filestorage']['delivery']),
            ],
            static fn (mixed $group): bool => \is_string($group) && $group !== PolicyRegistry::WILDCARD,
        ))),
        scopes: $scopes,
        scopedFiles: $scopedFiles,
    ),

    // The operations commands need a repository that can be walked. That is a
    // stronger contract than the hot path's, so an installation whose backend
    // implements only RepositoryInterface cannot build them — and because
    // params.php names all six commands unconditionally, running one there is
    // a container error naming MaintenanceRepositoryInterface. That is the
    // honest outcome for a command that genuinely cannot work; what it is not
    // is silent. The ledger is
    // optional in the same way: without one there are no shared blobs, and
    // `gc` still sweeps orphans.
    //
    // The scope provider is injected so that `gc --orphans` can refuse itself:
    // the referenced-set it builds is tenant-filtered while the object listing
    // it compares against is physical, so under tenancy the difference is other
    // tenants' live files.
    GcCommand::class => static fn (
        StoreRegistry $stores,
        MaintenanceRepositoryInterface $repository,
        ClockInterface $clock,
        ?BlobLedgerInterface $ledger = null,
        ?FileScopeProviderInterface $scopes = null,
    ): GcCommand => new GcCommand(
        stores: $stores,
        repository: $repository,
        clock: $clock,
        ledger: $ledger,
        scopes: $scopes,
    ),

    VerifyCommand::class => static fn (
        StoreRegistry $stores,
        MaintenanceRepositoryInterface $repository,
    ): VerifyCommand => new VerifyCommand(stores: $stores, repository: $repository),

    BackfillHashCommand::class => static fn (
        StoreRegistry $stores,
        MaintenanceRepositoryInterface $repository,
    ): BackfillHashCommand => new BackfillHashCommand(stores: $stores, repository: $repository),

    // Through StorageInterface, not through a store and a repository: an
    // application that enabled deduplication did it by overriding this key, and
    // importing a legacy tree is exactly when sharing pays. Reaching past the
    // facade would quietly import everything unique.
    ImportCommand::class => static fn (
        StorageInterface $storage,
        StreamFactoryInterface $streams,
    ): ImportCommand => new ImportCommand(storage: $storage, streams: $streams),

    StatCommand::class => static fn (
        MaintenanceRepositoryInterface $repository,
        ?FileScopeProviderInterface $scopes = null,
    ): StatCommand => new StatCommand(repository: $repository, scopes: $scopes),
];
