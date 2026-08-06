<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\Exception\AddException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Id\Uuid7IdGenerator;
use Rasuvaeff\Yii3Filestorage\Mime\FinfoMimeTypeDetector;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Repository\RepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Storage;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Test\MemoryRepository;
use Rasuvaeff\Yii3Filestorage\Upload;
use Yiisoft\Test\Support\Clock\StaticClock;

require __DIR__ . '/../vendor/autoload.php';

$factory = new Psr17Factory();
$clock = new StaticClock(new DateTimeImmutable('2026-08-06T12:00:00.000000+00:00'));

$store = new InMemoryStore('memory', $factory, $clock);
$repository = new MemoryRepository();

$storage = new Storage(
    stores: new StoreRegistry([$store]),
    repository: $repository,
    pathGenerator: new RandomPathGenerator(),
    mimeTypeDetector: new FinfoMimeTypeDetector(),
    idGenerator: new Uuid7IdGenerator($clock),
    policies: new PolicyRegistry(),
    deliveryPolicies: new DeliveryPolicyRegistry(),
    clock: $clock,
    defaultUrlTtl: new DateInterval('PT1H'),
);

$file = $storage->add(Upload::fromStream($factory->createStream('hello'), 'greeting.txt', $factory));

echo "No disk, no real clock, no database:\n";
echo "  writeCount()      = {$store->writeCount()}\n";
echo '  bytesAt(path)     = ' . var_export($store->bytesAt($file->relativePath), true) . "\n";
echo "  repository count  = {$repository->count()}\n";
echo "  createdAt         = {$file->createdAt->format(File::TIMESTAMP_FORMAT)}\n\n";

// --- The branch a fake with every capability would hide -----------------

echo "InMemoryStore implements neither URLs nor ranges, on purpose:\n";
echo '  url($file)      = ' . var_export($storage->url($file), true) . "\n";
echo '  urlFor($file)   = ' . var_export($storage->urlFor($file), true) . "\n\n";
echo "That null is the answer a private store gives until a delivery route is\n";
echo "installed. A double implementing every capability would make this branch\n";
echo "untestable, and it is the branch most likely to be wrong in your code.\n\n";

// --- Compensation -------------------------------------------------------

$failing = new class implements RepositoryInterface {
    public function find(string $id): ?File
    {
        return null;
    }

    public function save(File $file): void
    {
        throw new RuntimeException('database is down');
    }

    public function delete(string $id): bool
    {
        return false;
    }
};

$fragile = new Storage(
    stores: new StoreRegistry([$store]),
    repository: $failing,
    pathGenerator: new RandomPathGenerator(),
    mimeTypeDetector: new FinfoMimeTypeDetector(),
    idGenerator: new Uuid7IdGenerator($clock),
    policies: new PolicyRegistry(),
    deliveryPolicies: new DeliveryPolicyRegistry(),
    clock: $clock,
    defaultUrlTtl: new DateInterval('PT1H'),
);

$before = count($store->paths());

try {
    $fragile->add(Upload::fromStream($factory->createStream('doomed'), 'doomed.txt', $factory));
} catch (AddException $e) {
    echo "When the metadata save fails after the object was written:\n";
    echo "  {$e->getMessage()}\n";
    echo '  caused by: ' . $e->getPrevious()?->getMessage() . "\n";
    echo '  objects left behind: ' . (count($store->paths()) - $before) . "\n\n";
}

echo "The object is deleted on a best-effort basis, so a failed add() leaves\n";
echo "neither a row without bytes nor bytes without a row. Compensation is safe\n";
echo "here precisely because base Storage never shares an object between files.\n";
