<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Command;

use DateInterval;
use Override;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface;
use Rasuvaeff\Yii3Filestorage\Repository\MaintenanceRepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Store\BlobLedgerInterface;
use Rasuvaeff\Yii3Filestorage\Store\MaintenanceStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reclaims what nothing references any more.
 *
 * Two jobs that look alike and are not:
 *
 * **Shared blobs.** The only place in this package that deletes bytes another
 * request might want. It takes an exclusive, expiring lease, deletes the
 * object, and removes the ledger row only if the blob is *still* unreferenced —
 * so a writer that committed since the claim wins, and the blob survives to be
 * collected next time.
 *
 * **Orphans.** Objects with no metadata row at all: what a crash between
 * writing bytes and saving a row leaves behind, and what a failed compensation
 * leaves behind. Reclaiming them is a scan, so it is opt-in — and it is refused
 * outright when the repository is tenant-scoped, because the two halves of the
 * comparison would not be the same universe.
 *
 * Dry-run is the default. A command whose first run deletes is a command
 * somebody eventually runs on the wrong database.
 *
 * @api
 */
#[AsCommand(name: 'filestorage:gc', description: 'Collect unreferenced blobs and orphaned objects')]
final class GcCommand extends Command
{
    /**
     * @param BlobLedgerInterface|null $ledger Absent when no deduplication
     *        backend is installed. Orphan sweeping still works.
     * @param FileScopeProviderInterface|null $scopes Present when the
     *        installation is multi-tenant, which makes `--orphans` unsafe. See
     *        {@see self::sweepOrphans()}.
     */
    public function __construct(
        private readonly StoreRegistry $stores,
        private readonly MaintenanceRepositoryInterface $repository,
        private readonly ClockInterface $clock,
        private readonly ?BlobLedgerInterface $ledger = null,
        private readonly ?FileScopeProviderInterface $scopes = null,
        private readonly DateInterval $leaseTtl = new DateInterval('PT5M'),
        private readonly DateInterval $gracePeriod = new DateInterval('PT1H'),
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Actually delete. Without this, nothing is removed')
            ->addOption('orphans', null, InputOption::VALUE_NONE, 'Also sweep objects with no metadata row')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Limit the orphan sweep to one store')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many deletions', '1000');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $limit = max(1, (int) $input->getOption('limit'));

        if (!$apply) {
            $io->note('Dry run. Nothing will be deleted — pass --apply to act.');
        }

        $orphansRequested = (bool) $input->getOption('orphans');
        if ($orphansRequested && $this->scopes !== null) {
            $io->error($this->scopedSweepRefusal());

            return Command::FAILURE;
        }

        $blobs = $this->collectBlobs($io, $apply, $limit);
        $orphans = $orphansRequested
            ? $this->sweepOrphans($io, $apply, $limit, $this->storeNameOption($input))
            : null;

        $io->success(sprintf(
            '%s%s.',
            $blobs === null
                ? 'Nothing was collected'
                : sprintf('%s %d blob%s', $apply ? 'Collected' : 'Would collect', $blobs, $blobs === 1 ? '' : 's'),
            $orphans === null ? '' : sprintf(
                '%s %d orphaned object%s',
                $blobs === null ? ', found' : ' and',
                $orphans,
                $orphans === 1 ? '' : 's',
            ),
        ));

        return Command::SUCCESS;
    }

    /**
     * @return int|null Null when nothing was counted, which is not the same
     *         claim as zero: a dry run cannot know how many blobs are
     *         collectable without claiming them.
     */
    private function collectBlobs(SymfonyStyle $io, bool $apply, int $limit): ?int
    {
        if ($this->ledger === null) {
            $io->text('No blob ledger is bound, so there are no shared blobs to collect.');

            return null;
        }

        if (!$apply) {
            // Nothing here runs under a dry run, sweeping included. The sweep
            // writes: it deletes reservation rows and schedules what they held
            // with a fresh grace period, so a dry run would postpone by an hour
            // exactly the blobs the operator is about to collect for real.
            // Claiming writes too, and would take a lease nobody releases.
            $io->text('Dry run: blobs are neither swept nor claimed, so none can be counted here.');

            return null;
        }

        $now = $this->clock->now();

        // Abandoned writer claims first: a blob still holding one is invisible
        // to the collection pass, so skipping this leaks every crashed upload.
        $swept = $this->ledger->expireReservations($now, $now->add($this->gracePeriod));
        if ($swept !== 0) {
            $io->text(sprintf('Swept %d expired reservation%s.', $swept, $swept === 1 ? '' : 's'));
        }

        $collected = 0;

        while ($collected < $limit) {
            $lease = $this->ledger->claimForDeletion($this->clock->now(), $this->clock->now()->add($this->leaseTtl));
            if ($lease === null) {
                break;
            }

            $store = $this->stores->get($lease->blob->storeName);
            if (!$store instanceof MaintenanceStoreInterface) {
                $this->ledger->abandonDeletion($lease, $this->clock->now()->add($this->gracePeriod));
                $io->warning(sprintf(
                    'Store "%s" cannot delete objects (%s is not implemented), so its blobs cannot be collected.',
                    $lease->blob->storeName,
                    MaintenanceStoreInterface::class,
                ));

                break;
            }

            try {
                $store->deleteObject($lease->blob->object);
            } catch (StoreException $e) {
                // Back to the queue with a delay rather than dropped: the store
                // being briefly unavailable is not a reason to leak the object.
                $this->ledger->abandonDeletion($lease, $this->clock->now()->add($this->gracePeriod));
                $io->warning(sprintf('Could not delete "%s": %s', $lease->blob->key(), $e->getMessage()));

                continue;
            }

            // Only now, and only if nothing referenced it in the meantime.
            if ($this->ledger->completeDeletion($lease)) {
                $collected++;
            }
        }

        return $collected;
    }

    /**
     * An object is an orphan when *no* row anywhere points at it. The rows come
     * from a scoped repository and the objects from an unscoped physical
     * listing, so under tenancy the sweep compares one tenant's rows against
     * every tenant's bytes — and deletes the difference. There is no scope value
     * that fixes it: no single tenant's view can prove an object unreferenced.
     *
     * @param non-empty-string|null $storeName
     */
    private function sweepOrphans(SymfonyStyle $io, bool $apply, int $limit, ?string $storeName): int
    {
        // Without --store, every configured store. Walking only the default one
        // and reporting a total would say "no orphans" about stores nobody
        // looked at.
        $names = $storeName === null ? $this->stores->names() : [$storeName];

        // Every *directory* any row points at, read once — not every path.
        // A row's relativePath is `<…>/<key>/original.<ext>`, while
        // writeDerivative() puts previews beside it as `<key>/thumb.webp`, and
        // the store walk yields those too. Comparing paths therefore matched no
        // row against any derivative and swept every preview in the store as an
        // orphan. This is the same reason StoreInterface::delete() removes the
        // directory rather than the object.
        //
        // Keyed by store as well: two stores can hold the same relative path,
        // and a row in one of them must not make an object in the other look
        // referenced.
        $referenced = [];
        foreach ($this->files() as $file) {
            $referenced[$file->storeName . ':' . $file->directory()] = true;
        }

        $found = 0;
        foreach ($names as $name) {
            $found += $this->sweepStore($io, $apply, $limit - $found, $name, $referenced);
            if ($found >= $limit) {
                break;
            }
        }

        return $found;
    }

    /**
     * @param non-empty-string $storeName
     * @param array<string, true> $referenced
     */
    private function sweepStore(SymfonyStyle $io, bool $apply, int $limit, string $storeName, array $referenced): int
    {
        $store = $this->stores->get($storeName);
        if (!$store instanceof MaintenanceStoreInterface) {
            $io->warning(sprintf('Store "%s" cannot be walked, so its orphans cannot be found.', $store->name()));

            return 0;
        }

        $io->text(sprintf('Walking store "%s".', $store->name()));

        $found = 0;
        $after = null;

        while ($found < $limit) {
            $page = iterator_to_array($store->objects($after, 500), false);
            if ($page === []) {
                break;
            }

            foreach ($page as $object) {
                $after = $object->relativePath;
                if (isset($referenced[$storeName . ':' . self::directoryOf($object->relativePath)])) {
                    continue;
                }

                $found++;
                $io->text(sprintf('  %s %s', $apply ? 'deleting' : 'would delete', $object->relativePath));
                if ($apply) {
                    $store->deleteObject($object);
                }

                if ($found >= $limit) {
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * @return iterable<int, \Rasuvaeff\Yii3Filestorage\File>
     */
    private function files(): iterable
    {
        $after = null;

        while (true) {
            $page = iterator_to_array($this->repository->files($after, 500), false);
            if ($page === []) {
                return;
            }

            yield from $page;

            $last = $page[\count($page) - 1];
            $after = $last->id;
        }
    }

    /**
     * The parent directory of an object, matching {@see File::directory()} so
     * the two sides of the comparison are the same shape. An object sitting at
     * the store root has none, and answers itself — which can only be an
     * orphan, because every path this package generates has a key directory.
     */
    private static function directoryOf(string $relativePath): string
    {
        $slash = strrpos($relativePath, '/');

        return $slash === false || $slash === 0 ? $relativePath : substr($relativePath, 0, $slash);
    }

    private function scopedSweepRefusal(): string
    {
        return sprintf(
            'Refusing --orphans: a %s is bound, so this installation is multi-tenant. The referenced-set comes '
            . 'from the repository, which filters by the current tenant, while the object listing is physical and '
            . 'filters by nothing — so the sweep would classify every other tenant\'s objects as orphans and '
            . '--apply would delete them. There is no tenant to run it "as": no single tenant\'s rows can prove an '
            . 'object unreferenced. Run the sweep from a maintenance entry point that leaves %s unbound, where the '
            . 'repository sees every row. Blob collection is unaffected — drop --orphans and this command still '
            . 'runs.',
            FileScopeProviderInterface::class,
            FileScopeProviderInterface::class,
        );
    }

    /**
     * @return non-empty-string|null
     */
    private function storeNameOption(InputInterface $input): ?string
    {
        // Through getOptions() rather than getOption(): an array offset is
        // something psalm narrows across accesses, where a method call's
        // `mixed` needs a `@var` tag that rector removes as redundant.
        $options = $input->getOptions();

        return isset($options['store']) && \is_string($options['store']) && $options['store'] !== ''
            ? $options['store']
            : null;
    }
}
