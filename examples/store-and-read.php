<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\Id\Uuid7IdGenerator;
use Rasuvaeff\Yii3Filestorage\Mime\FinfoMimeTypeDetector;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Storage;
use Rasuvaeff\Yii3Filestorage\Store\FileSystem\FileSystemStore;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\MemoryRepository;
use Rasuvaeff\Yii3Filestorage\Upload;
use Yiisoft\Test\Support\Clock\StaticClock;

require __DIR__ . '/../vendor/autoload.php';

$root = sys_get_temp_dir() . '/filestorage-example-' . bin2hex(random_bytes(6));
$factory = new Psr17Factory();
$clock = new StaticClock(new DateTimeImmutable('2026-01-01T00:00:00.000000+00:00'));

$store = new FileSystemStore(name: 'upload', rootPath: $root, streamFactory: $factory);

$storage = new Storage(
    stores: new StoreRegistry([$store]),
    repository: $repository = new MemoryRepository(),
    pathGenerator: new RandomPathGenerator(),
    mimeTypeDetector: new FinfoMimeTypeDetector(),
    idGenerator: new Uuid7IdGenerator($clock),
    policies: new PolicyRegistry(),
    deliveryPolicies: new DeliveryPolicyRegistry(),
    clock: $clock,
    defaultUrlTtl: new DateInterval('PT1H'),
);

echo "Store root: {$root}\n\n";

// --- Storing ------------------------------------------------------------

$readme = $storage->add(
    Upload::fromStream($factory->createStream("# Hello\n\nA markdown file.\n"), 'readme.md', $factory),
    groupName: 'documents',
    description: 'Example document',
    metadata: ['authorId' => 42],
);

// A one-pixel PNG, so finfo has a real signature to recognise.
$png = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    true,
);
$image = $storage->add(
    Upload::fromStream($factory->createStream($png), 'pixel.png', $factory),
    groupName: 'images',
);

// The client filename claims .png, but the bytes are plain text. The stored
// extension follows the detected type, not the claim.
$spoofed = $storage->add(
    Upload::fromStream($factory->createStream('this is not a png'), 'trustme.png', $factory),
    groupName: 'images',
);

printf("%-12s %-24s %-26s %s\n", 'GROUP', 'ORIGINAL NAME', 'DETECTED TYPE', 'STORED PATH');
foreach ([$readme, $image, $spoofed] as $file) {
    printf(
        "%-12s %-24s %-26s %s\n",
        $file->groupName,
        $file->originalName,
        $file->mimeType ?? '<unrecognised>',
        $file->relativePath,
    );
}

echo "\nNote the last row: the file was uploaded as \"trustme.png\" and stored as\n";
echo "text/plain with a .txt extension. The client-supplied name never decides.\n\n";

// --- Reading ------------------------------------------------------------

$found = $storage->find($readme->id);
echo "find({$readme->id}) returned: {$found?->originalName}\n";
echo "content(): " . json_encode($storage->content($found)) . "\n";
echo 'stream() size: ' . $storage->stream($found)?->getSize() . " bytes\n";
echo 'exists(): ' . var_export($storage->exists($found), true) . "\n\n";

// --- Round-trip ---------------------------------------------------------

$restored = Rasuvaeff\Yii3Filestorage\File::fromArray($found->toArray());
echo 'fromArray(toArray($f)) == $f: ' . var_export($restored == $found, true) . "\n";
echo "createdAt keeps microseconds: {$restored->toArray()['createdAt']}\n\n";

// --- Removing -----------------------------------------------------------

$directory = $root . '/' . $spoofed->directory();
echo "Before remove(), the file's directory exists: " . var_export(is_dir($directory), true) . "\n";

$storage->remove($spoofed->id);

echo 'After remove(), the whole directory is gone: ' . var_export(!is_dir($directory), true) . "\n";
echo "That is deliberate — thumbnails live beside the original, so deleting only\n";
echo "the object would leak every derivative of it forever.\n\n";

echo "Files still in the repository: {$repository->count()}\n";

// --- Clean up -----------------------------------------------------------

Yiisoft\Files\FileHelper::removeDirectory($root);
echo "Removed {$root}\n";
