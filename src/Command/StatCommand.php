<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Command;

use Override;
use Rasuvaeff\Yii3Filestorage\Repository\MaintenanceRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What is actually stored, by group.
 *
 * Answers the questions that come up before a capacity decision or a
 * deduplication one: how much is here, where is it, and how much of it is the
 * same bytes twice. That last column is why this walks rows rather than asking
 * the store: only the metadata knows which objects are shared.
 *
 * A full scan, so it is a command rather than a dashboard endpoint.
 *
 * @api
 */
#[AsCommand(name: 'filestorage:stat', description: 'Report stored file counts and sizes by group')]
final class StatCommand extends Command
{
    public function __construct(private readonly MaintenanceRepositoryInterface $repository)
    {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var array<string, array{files: int, bytes: int}> $groups */
        $groups = [];
        $files = 0;
        $bytes = 0;
        $paths = [];
        $sharedBytes = 0;
        $after = null;

        while (true) {
            $page = iterator_to_array($this->repository->files($after, 500), false);
            if ($page === []) {
                break;
            }

            foreach ($page as $file) {
                $after = $file->id;
                $files++;
                $bytes += $file->size;

                $key = $file->storeName . ':' . $file->relativePath;
                if (isset($paths[$key])) {
                    // A second row on one object: bytes counted once physically
                    // and twice logically. The difference is what dedup saved.
                    $sharedBytes += $file->size;
                } else {
                    $paths[$key] = true;
                }

                $group = $groups[$file->groupName] ?? ['files' => 0, 'bytes' => 0];
                $groups[$file->groupName] = [
                    'files' => $group['files'] + 1,
                    'bytes' => $group['bytes'] + $file->size,
                ];
            }
        }

        if ($files === 0) {
            $io->success('No files stored.');

            return Command::SUCCESS;
        }

        ksort($groups);
        $io->table(
            ['Group', 'Files', 'Size'],
            [
                ...array_map(
                    static fn(string $name, array $g): array => [$name, $g['files'], self::human($g['bytes'])],
                    array_keys($groups),
                    array_values($groups),
                ),
                ['', '', ''],
                ['<info>total</info>', $files, self::human($bytes)],
            ],
        );

        $io->text(sprintf('Distinct objects: %d', \count($paths)));
        $sharedBytes === 0
            ? $io->text('No rows share an object.')
            : $io->text(sprintf(
                'Shared: %d row%s reuse an existing object, saving %s.',
                $files - \count($paths),
                $files - \count($paths) === 1 ? '' : 's',
                self::human($sharedBytes),
            ));

        return Command::SUCCESS;
    }

    private static function human(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $unit = 0;
        $value = (float) $bytes;

        while ($value >= 1024.0 && $unit < 4) {
            $value /= 1024.0;
            $unit++;
        }

        return $unit === 0
            ? sprintf('%d B', $bytes)
            : sprintf('%.1f %s', $value, $units[$unit]);
    }
}
