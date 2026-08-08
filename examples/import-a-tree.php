<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\Command\ImportCommand;
use Rasuvaeff\Yii3Filestorage\Id\Uuid7IdGenerator;
use Rasuvaeff\Yii3Filestorage\Mime\FinfoMimeTypeDetector;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\UploadPolicy;
use Rasuvaeff\Yii3Filestorage\Storage;
use Rasuvaeff\Yii3Filestorage\Store\FileSystem\FileSystemStore;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\MemoryRepository;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Yiisoft\Test\Support\Clock\StaticClock;

require __DIR__ . '/../vendor/autoload.php';

$factory = new Psr17Factory();
$clock = new StaticClock(new DateTimeImmutable('2026-01-01T00:00:00.000000+00:00'));

$work = sys_get_temp_dir() . '/filestorage-import-example-' . bin2hex(random_bytes(6));
$legacy = $work . '/legacy';
$manifest = $work . '/import.jsonl';

// --- A legacy tree to import -------------------------------------------

mkdir($legacy . '/2025/invoices', 0o775, true);
mkdir($legacy . '/.git', 0o775, true);
file_put_contents($legacy . '/notes.txt', "Some notes.\n");
file_put_contents($legacy . '/2025/invoices/march.txt', "Invoice, March.\n");
file_put_contents($legacy . '/2025/invoices/april.txt', "Invoice, April.\n");
file_put_contents($legacy . '/.git/config', "[core]\n");
file_put_contents($legacy . '/huge.txt', str_repeat('x', 4096));

$repository = new MemoryRepository();
$storage = new Storage(
    stores: new StoreRegistry([
        new FileSystemStore(name: 'upload', rootPath: $work . '/store', streamFactory: $factory),
    ]),
    repository: $repository,
    pathGenerator: new RandomPathGenerator(),
    mimeTypeDetector: new FinfoMimeTypeDetector(),
    idGenerator: new Uuid7IdGenerator($clock),
    // A cap small enough that one of the five files is rejected, which is the
    // interesting case: the import must not record what it did not store.
    policies: new PolicyRegistry(['*' => new UploadPolicy(maxBytes: 1024)]),
    deliveryPolicies: new DeliveryPolicyRegistry(),
    clock: $clock,
    defaultUrlTtl: new DateInterval('PT1H'),
);

$command = new ImportCommand($storage, $factory);
$output = new ConsoleOutput();

$run = static function (array $options) use ($command, $legacy, $manifest, $output): int {
    return $command->run(
        new ArrayInput(['directory' => $legacy, '--manifest' => $manifest, ...$options]),
        $output,
    );
};

// --- 1. Dry run: what would happen -------------------------------------

echo "== Dry run ==\n";
$run([]);
echo sprintf("Rows stored: %d\n", $repository->count());
echo sprintf("Manifest written: %s\n\n", is_file($manifest) ? 'yes' : 'no');

// --- 2. The real thing --------------------------------------------------

echo "== Import ==\n";
$status = $run(['--apply' => true]);
echo sprintf("Exit status: %d (non-zero because one file was rejected)\n", $status);
echo sprintf("Rows stored: %d\n\n", $repository->count());

// `.git/config` was never a candidate — dot entries are skipped at every
// depth — and `huge.txt` was rejected by the byte cap, so three of five landed.

// --- 3. Run it again ----------------------------------------------------

echo "== Same tree, again ==\n";
$run(['--apply' => true]);
echo sprintf("Rows stored: %d (unchanged — the manifest skipped them)\n\n", $repository->count());

// --- 4. A new file appears in the tree ---------------------------------

file_put_contents($legacy . '/2025/invoices/may.txt', "Invoice, May.\n");

echo "== One new file ==\n";
$run(['--apply' => true]);
echo sprintf("Rows stored: %d\n\n", $repository->count());

echo "== Where each row came from ==\n";
foreach ($repository->files(null, 100) as $file) {
    echo sprintf("  %-32s %s\n", $file->metadata['importSource'], $file->id);
}

// --- Clean up -----------------------------------------------------------

$remove = static function (string $path) use (&$remove): void {
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $child = $path . '/' . $entry;
        is_dir($child) ? $remove($child) : unlink($child);
    }

    rmdir($path);
};
$remove($work);

echo "\nDone.\n";
