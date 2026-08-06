<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
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

    StorageInterface::class => static fn (
        StoreRegistry $stores,
        RepositoryInterface $repository,
        PathGeneratorInterface $pathGenerator,
        MimeTypeDetectorInterface $mimeTypeDetector,
        IdGeneratorInterface $idGenerator,
        PolicyRegistry $policies,
        DeliveryPolicyRegistry $deliveryPolicies,
        ClockInterface $clock,
        ?ProxyUrlGeneratorInterface $proxyUrls = null,
    ): StorageInterface => new Storage(
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

    CheckCommand::class => static fn (
        StoreRegistry $stores,
        RepositoryInterface $repository,
        PolicyRegistry $policies,
        DeliveryPolicyRegistry $deliveryPolicies,
    ): CheckCommand => new CheckCommand(
        stores: $stores,
        repository: $repository,
        policies: $policies,
        deliveryPolicies: $deliveryPolicies,
        knownGroups: array_values(array_filter(
            array_keys($params['rasuvaeff/yii3-filestorage']['policies']),
            static fn (mixed $group): bool => \is_string($group) && $group !== PolicyRegistry::WILDCARD,
        )),
    ),
];
