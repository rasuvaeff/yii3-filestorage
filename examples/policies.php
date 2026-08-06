<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\Exception\PolicyViolationException;
use Rasuvaeff\Yii3Filestorage\Exception\UploadTooLargeException;
use Rasuvaeff\Yii3Filestorage\Id\Uuid7IdGenerator;
use Rasuvaeff\Yii3Filestorage\Mime\FinfoMimeTypeDetector;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Storage;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Test\MemoryRepository;
use Rasuvaeff\Yii3Filestorage\Upload;
use Yiisoft\Test\Support\Clock\StaticClock;

require __DIR__ . '/../vendor/autoload.php';

$factory = new Psr17Factory();
$clock = new StaticClock(new DateTimeImmutable('2026-01-01T00:00:00.000000+00:00'));
$store = new InMemoryStore('memory', $factory);

// The same shape you would write in config/common/params.php.
$policies = PolicyRegistry::fromArray([
    'avatars' => [
        'allowedMimeTypes' => ['image/jpeg', 'image/png', 'image/webp'],
        'maxBytes' => 512,
        'maxPixels' => 1_000,
        'requireImageDimensions' => true,
    ],
    'documents' => ['allowedMimeTypes' => ['application/pdf']],
    '*' => ['maxBytes' => 1_048_576],
]);

$storage = new Storage(
    stores: new StoreRegistry([$store]),
    repository: new MemoryRepository(),
    pathGenerator: new RandomPathGenerator(),
    mimeTypeDetector: new FinfoMimeTypeDetector(),
    idGenerator: new Uuid7IdGenerator($clock),
    policies: $policies,
    deliveryPolicies: new DeliveryPolicyRegistry(),
    clock: $clock,
    defaultUrlTtl: new DateInterval('PT1H'),
);

/**
 * @param non-empty-string $group
 */
function attempt(Storage $storage, InMemoryStore $store, string $label, string $group, Upload $upload): void
{
    $before = $store->writeCount();

    try {
        $file = $storage->add($upload, groupName: $group);
        echo "  OK      {$label} -> {$file->relativePath}\n";
    } catch (PolicyViolationException|UploadTooLargeException $e) {
        $written = $store->writeCount() - $before;
        echo '  REJECT  ' . $label . ' -> ' . $e->getMessage() . "\n";
        echo "          objects written: {$written}\n";
    }
}

$png1x1 = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    true,
);

echo "Group \"avatars\": jpeg/png/webp only, 512 bytes, 1000 pixels\n";
attempt(
    $storage,
    $store,
    'a real 1x1 PNG',
    'avatars',
    Upload::fromStream($factory->createStream($png1x1), 'ok.png', $factory),
);
attempt(
    $storage,
    $store,
    'text renamed to .png',
    'avatars',
    Upload::fromStream($factory->createStream('not an image at all'), 'evil.png', $factory),
);
attempt(
    $storage,
    $store,
    'a 700-byte PNG',
    'avatars',
    Upload::fromStream($factory->createStream($png1x1 . str_repeat("\0", 700)), 'big.png', $factory),
);

echo "\nGroup \"documents\": application/pdf only\n";
attempt(
    $storage,
    $store,
    'a PDF',
    'documents',
    Upload::fromStream($factory->createStream("%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"), 'invoice.pdf', $factory),
);
attempt(
    $storage,
    $store,
    'a PNG in the documents group',
    'documents',
    Upload::fromStream($factory->createStream($png1x1), 'invoice.png', $factory),
);

echo "\nGroup \"reports\": no entry of its own, so the '*' fallback applies\n";
attempt(
    $storage,
    $store,
    'anything, under 1 MiB',
    'reports',
    Upload::fromStream($factory->createStream('id,total' . PHP_EOL . '1,99'), 'q3.csv', $factory),
);

echo "\nEvery rejection wrote zero objects: policy is checked before the store is\n";
echo "touched, so a rejected upload leaves nothing behind to clean up.\n";
echo "Objects in the store: " . count($store->paths()) . "\n";
