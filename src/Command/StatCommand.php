<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Command;

use Override;
use Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface;
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
 * Two kinds of number come out of that walk and they do not survive tenancy
 * equally. Counts and byte totals are *logical* — they describe rows, and rows
 * scoped to one tenant are a correct answer to "how much does this tenant
 * have". Distinct objects and sharing savings are *physical*: they claim to
 * know how many objects exist and how many rows point at each, and a
 * tenant-filtered read cannot see the rows in other scopes that point at the
 * same object. So under tenancy the physical half is withheld rather than
 * estimated — see {@see self::scopedPhysicalNote()}.
 *
 * A full scan, so it is a command rather than a dashboard endpoint.
 *
 * @api
 */
#[AsCommand(name: 'filestorage:stat', description: 'Report stored file counts and sizes by group')]
final class StatCommand extends Command
{
    /**
     * @param FileScopeProviderInterface|null $scopes Present when the
     *        installation is multi-tenant, which makes the physical half of the
     *        report unknowable from here.
     */
    public function __construct(
        private readonly MaintenanceRepositoryInterface $repository,
        private readonly ?FileScopeProviderInterface $scopes = null,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $scoped = $this->scopes !== null;

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

                // Skipped under a scope: the report withholds both physical
                // numbers there, and holding one entry per row of a
                // tenant-filtered walk would buy nothing.
                if (!$scoped) {
                    $key = $file->storeName . ':' . $file->relativePath;
                    if (isset($paths[$key])) {
                        // A second row on one object: bytes counted once
                        // physically and twice logically. The difference is
                        // what dedup saved.
                        $sharedBytes += $file->size;
                    } else {
                        $paths[$key] = true;
                    }
                }

                $group = $groups[$file->groupName] ?? ['files' => 0, 'bytes' => 0];
                $groups[$file->groupName] = [
                    'files' => $group['files'] + 1,
                    'bytes' => $group['bytes'] + $file->size,
                ];
            }
        }

        if ($scoped) {
            $io->note($this->scopedPhysicalNote());
        }

        if ($files === 0) {
            $io->success($scoped ? 'No files stored in the current scope.' : 'No files stored.');

            return Command::SUCCESS;
        }

        ksort($groups);
        $io->table(
            [$scoped ? 'Group (current scope)' : 'Group', 'Files', 'Size'],
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

        if ($scoped) {
            return Command::SUCCESS;
        }

        $io->text(sprintf('Distinct objects: %d', \count($paths)));
        // On rows, not on bytes: a shared row of size zero adds nothing to
        // $sharedBytes, and the sentence below counts rows. Deciding with one
        // number and reporting the other means an empty file shared twice
        // prints "no rows share an object" over a table that says otherwise.
        $files - \count($paths) === 0
            ? $io->text('No rows share an object.')
            : $io->text(sprintf(
                'Shared: %d row%s reuse an existing object, saving %s.',
                $files - \count($paths),
                $files - \count($paths) === 1 ? '' : 's',
                self::human($sharedBytes),
            ));

        return Command::SUCCESS;
    }

    /**
     * The same asymmetry `filestorage:gc --orphans` refuses on, one step
     * milder. There the physical half decides what gets deleted, so a scoped
     * read is destructive; here it only decides what gets printed, and the
     * logical half above is still exactly right for the tenant asking. So the
     * command runs and withholds the two numbers it cannot honestly produce
     * rather than printing a distinct-object count that ignores every row in
     * another scope and savings that are consequently understated.
     */
    private function scopedPhysicalNote(): string
    {
        return sprintf(
            'A %s is bound, so these counts cover the current scope only. Distinct objects and sharing savings '
            . 'are withheld: rows in other scopes may point at the same objects, and a tenant-filtered walk '
            . 'cannot see them — it would report more distinct objects than exist and less sharing than there is. '
            . 'For the physical figures, run this from a maintenance entry point that leaves %s unbound, where the '
            . 'repository sees every row.',
            FileScopeProviderInterface::class,
            FileScopeProviderInterface::class,
        );
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
