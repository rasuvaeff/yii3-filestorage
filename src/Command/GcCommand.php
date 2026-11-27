<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Command;

use DateInterval;
use DateTimeImmutable;
use Override;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Filestorage\Exception\InvalidConfigException;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Reference\ReferenceAuthority;
use Rasuvaeff\Yii3Filestorage\Reference\ReferenceCheckerInterface;
use Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface;
use Rasuvaeff\Yii3Filestorage\Repository\MaintenanceRepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3Filestorage\Store\BlobLease;
use Rasuvaeff\Yii3Filestorage\Store\BlobLedgerInterface;
use Rasuvaeff\Yii3Filestorage\Store\BlobRecord;
use Rasuvaeff\Yii3Filestorage\Store\BlobState;
use Rasuvaeff\Yii3Filestorage\Store\Layout\ObjectLayoutInterface;
use Rasuvaeff\Yii3Filestorage\Store\Layout\ObjectSet;
use Rasuvaeff\Yii3Filestorage\Store\MaintenanceStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\QuarantinedObject;
use Rasuvaeff\Yii3Filestorage\Store\StoredObject;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Store\StoreInterface;
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
 * Every deletion is two-phase: the bytes are first moved into the store's
 * quarantine, the decision is committed, and only then are they purged — or,
 * when the decision turned out wrong, restored. A run starts by sweeping the
 * quarantine, so a collector that crashed between the two phases is finished
 * by the next one.
 *
 * Three jobs that look alike and are not:
 *
 * **Application-owned references.** For groups whose policy hands references
 * to the application ({@see ReferenceAuthority::Application}), rows older than
 * `--min-age` whose paths the bound {@see ReferenceCheckerInterface} no longer
 * names are released: a shared blob through the ledger, a unique object into
 * the quarantine, kept `--retention`.
 *
 * **Shared blobs.** The only place in this package that deletes bytes another
 * request might want. It takes an exclusive, expiring lease, quarantines the
 * blob's objects, and then asks the ledger to complete the deletion — which
 * it does only while the blob is *still* unreferenced and the lease is still
 * this collector's. A collector paused past its lease is refused at that
 * point, and the bytes it moved are restored with put-if-absent semantics:
 * a writer that re-published the same content meanwhile keeps its copy. A
 * committed blob deletion is purged at once.
 *
 * **Orphans.** Objects with no metadata row at all: what a crash between
 * writing bytes and saving a row leaves behind. The sweep pages the store,
 * and for each page — after it is listed — looks up the rows in the page's
 * directories. An object is only a candidate when it is older than the moment
 * of that lookup minus `--min-age`, so an upload between publication and its
 * row, and a file committed while the walk was elsewhere, are both left
 * alone. An object whose store reports no timestamp is never a candidate.
 * Opt-in, resumable (`--store` + `--after`, `nextCursor`), and refused
 * outright when the repository is tenant-scoped, because the two halves of
 * the comparison would not be the same universe.
 *
 * Dry-run is the default. A command whose first run deletes is a command
 * somebody eventually runs on the wrong database.
 *
 * @api
 */
#[AsCommand(name: 'filestorage:gc', description: 'Collect unreferenced blobs and orphaned objects')]
final class GcCommand extends Command
{
    private const int PAGE = 500;

    /** @var array<string, true> Stores already warned about in this run. */
    private array $warned = [];

    /**
     * @param BlobLedgerInterface|null $ledger Absent when no deduplication
     *        backend is installed. Orphan sweeping still works.
     * @param FileScopeProviderInterface|null $scopes Present when the
     *        installation is multi-tenant, which makes `--orphans` unsafe. See
     *        {@see self::sweepOrphans()}.
     * @param DateInterval $gracePeriod How long a released blob waits before
     *        it may be collected, and the default `--min-age`.
     * @param ReferenceCheckerInterface|null $references The application's
     *        own references. Without it, application-owned groups are not
     *        released, and nothing else changes.
     * @param PolicyRegistry|null $policies Which groups hand references to the
     *        application.
     * @param DateInterval $retention Default `--retention`: how long orphans
     *        and released application-owned files stay restorable in the
     *        quarantine. Blob deletions the ledger committed are purged at once.
     */
    public function __construct(
        private readonly StoreRegistry $stores,
        private readonly MaintenanceRepositoryInterface $repository,
        private readonly ClockInterface $clock,
        private readonly ?BlobLedgerInterface $ledger = null,
        private readonly ?FileScopeProviderInterface $scopes = null,
        private readonly DateInterval $leaseTtl = new DateInterval('PT5M'),
        private readonly DateInterval $gracePeriod = new DateInterval('PT1H'),
        private readonly ?ReferenceCheckerInterface $references = null,
        private readonly ?PolicyRegistry $policies = null,
        private readonly DateInterval $retention = new DateInterval('P7D'),
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
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many deletions', '1000')
            ->addOption('after', null, InputOption::VALUE_REQUIRED, 'Resume the orphan sweep after this path (needs --store)')
            ->addOption('max-scan', null, InputOption::VALUE_REQUIRED, 'Stop the orphan sweep after examining this many objects', '0')
            ->addOption('min-age', null, InputOption::VALUE_REQUIRED, 'ISO 8601 duration: only objects and rows older than this (default PT1H)')
            ->addOption('retention', null, InputOption::VALUE_REQUIRED, 'ISO 8601 duration: keep quarantined orphans and released files this long (default P7D)');

        Json::configure($this);
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $log = [];
        $io = Json::style($input, $output, $log);
        $this->warned = [];
        $apply = (bool) ($input->getOptions()['apply'] ?? false);
        $limit = max(1, (int) $input->getOption('limit'));
        $maxScan = max(0, (int) $input->getOption('max-scan'));
        $orphansRequested = (bool) ($input->getOptions()['orphans'] ?? false);
        $storeName = $this->stringOption($input, 'store');
        $after = $this->stringOption($input, 'after');

        $minAge = $this->duration($input, 'min-age') ?? $this->gracePeriod;
        $retention = $this->duration($input, 'retention') ?? $this->retention;
        $error = match (true) {
            $minAge === false => 'Invalid --min-age: expected an ISO 8601 duration such as PT1H',
            $retention === false => 'Invalid --retention: expected an ISO 8601 duration such as P7D',
            $after !== null && ($storeName === null || !$orphansRequested) => '--after resumes one store\'s orphan sweep: pass it with --orphans and --store',
            $orphansRequested && $this->scopes instanceof FileScopeProviderInterface => $this->scopedSweepRefusal(),
            default => null,
        };
        if ($error !== null || $minAge === false || $retention === false) {
            $io->error($error);
            if (Json::enabled($input)) {
                Json::emit($output, ['applied' => $apply, 'error' => $error, 'log' => $log]);
            }

            return Command::FAILURE;
        }

        if (!$apply) {
            $io->note('Dry run. Nothing will be deleted — pass --apply to act.');
        }

        $quarantine = $this->sweepQuarantine($io, $apply, $retention);
        $released = $this->releaseApplicationRows($io, $apply, $limit, $minAge);
        $remaining = $limit - ($released ?? 0);

        // Split, not handed to both: --limit is documented as "stop after this
        // many deletions", and giving each half the full budget let one run
        // delete twice what the operator authorised.
        $blobBudget = $orphansRequested ? intdiv($remaining, 2) + ($remaining % 2) : $remaining;
        $blobs = $this->collectBlobs($io, $apply, $blobBudget);
        $orphans = $orphansRequested
            ? $this->sweepOrphans($io, $apply, $remaining - ($blobs ?? 0), $storeName, $after, $maxScan, $minAge)
            : null;

        if ($orphans !== null && ($orphans['tooNew'] > 0 || $orphans['undated'] > 0)) {
            $io->text(sprintf(
                'Kept %d unreferenced object%s younger than --min-age and %d with no timestamp.',
                $orphans['tooNew'],
                $orphans['tooNew'] === 1 ? '' : 's',
                $orphans['undated'],
            ));
        }
        if ($orphans !== null && $orphans['nextCursor'] !== null) {
            $io->text(sprintf(
                'Stopped early. Resume with --orphans --store=%s --after=%s',
                $orphans['nextCursor']['store'],
                $orphans['nextCursor']['after'],
            ));
        }

        $io->success(sprintf(
            '%s%s.',
            $blobs === null
                ? 'Nothing was collected'
                : sprintf('%s %d blob%s', $apply ? 'Collected' : 'Would collect', $blobs, $blobs === 1 ? '' : 's'),
            $orphans === null ? '' : sprintf(
                '%s %d orphaned object%s',
                $blobs === null ? ', found' : ' and',
                $orphans['found'],
                $orphans['found'] === 1 ? '' : 's',
            ),
        ));

        if (Json::enabled($input)) {
            Json::emit($output, [
                'applied' => $apply,
                'releasedRows' => $released,
                'blobsCollected' => $blobs,
                'orphanObjects' => $orphans['found'] ?? null,
                'orphansTooNew' => $orphans['tooNew'] ?? null,
                'orphansUndated' => $orphans['undated'] ?? null,
                'nextCursor' => $orphans['nextCursor'] ?? null,
                'quarantine' => $quarantine,
                'log' => $log,
            ]);
        }

        return Command::SUCCESS;
    }

    /**
     * Finishes what earlier runs left in the quarantines.
     *
     * An entry the ledger still knows is a blob whose deletion was never
     * committed — its collector crashed or was refused — and goes back, unless
     * another collector holds a live lease on it right now. Anything else is a
     * committed decision and is purged once it is older than the retention.
     *
     * @return array{restored: int, purged: int, kept: int}
     */
    private function sweepQuarantine(SymfonyStyle $io, bool $apply, DateInterval $retention): array
    {
        $now = $this->clock->now();
        $cutoff = $now->sub($retention);
        $counts = ['restored' => 0, 'purged' => 0, 'kept' => 0];

        foreach ($this->stores->names() as $name) {
            $store = $this->storeFor($name);
            if (!$store instanceof MaintenanceStoreInterface) {
                continue;
            }

            $after = null;

            try {
                while (($page = iterator_to_array($store->quarantined($after, self::PAGE), preserve_keys: false)) !== []) {
                    foreach ($page as $entry) {
                        $after = $entry->token;
                        $decision = $this->quarantineDecision($name, $entry, $now, $cutoff);
                        $counts[$decision]++;
                        if ($decision === 'kept') {
                            continue;
                        }

                        $io->text(sprintf(
                            '  %s quarantined %s',
                            $apply ? ($decision === 'restored' ? 'restoring' : 'purging') : 'would ' . ($decision === 'restored' ? 'restore' : 'purge'),
                            $entry->objects[0]->relativePath,
                        ));
                        if ($apply) {
                            $decision === 'restored' ? $store->restore($entry) : $store->purge($entry);
                        }
                    }
                }
            } catch (StoreException $e) {
                $this->warnOnce($io, $name, sprintf('Quarantine of store "%s" could not be swept: %s', $name, $e->getMessage()));
            }
        }

        return $counts;
    }

    /**
     * @param non-empty-string $storeName
     *
     * @return 'restored'|'purged'|'kept'
     */
    private function quarantineDecision(
        string $storeName,
        QuarantinedObject $entry,
        DateTimeImmutable $now,
        DateTimeImmutable $cutoff,
    ): string {
        foreach ($entry->objects as $object) {
            $record = $this->ledgerRecord($storeName, $object->relativePath);
            if (!$record instanceof BlobRecord) {
                continue;
            }

            // Another collector between its quarantine and its commit.
            $busy = $record->state === BlobState::Deleting
                && $record->leaseExpiresAt instanceof DateTimeImmutable
                && $record->leaseExpiresAt > $now;

            return $busy ? 'kept' : 'restored';
        }

        return $entry->quarantinedAt <= $cutoff ? 'purged' : 'kept';
    }

    /**
     * Releases rows of application-owned groups that the application no longer
     * references and that are older than `$minAge`.
     *
     * @return int|null Null when no checker or no policy registry is bound.
     */
    private function releaseApplicationRows(SymfonyStyle $io, bool $apply, int $limit, DateInterval $minAge): ?int
    {
        if (!$this->references instanceof ReferenceCheckerInterface || !$this->policies instanceof PolicyRegistry) {
            return null;
        }

        $released = 0;
        $after = null;

        while ($released < $limit) {
            $page = iterator_to_array($this->repository->files($after, self::PAGE), preserve_keys: false);
            if ($page === []) {
                break;
            }

            $cutoff = $this->clock->now()->sub($minAge);

            /** @var array<non-empty-string, list<File>> $candidates */
            $candidates = [];
            foreach ($page as $file) {
                $after = $file->id;
                if (
                    $this->policies->for($file->groupName)->referencesOwnedBy === ReferenceAuthority::Application
                    && $file->createdAt < $cutoff
                ) {
                    $candidates[$file->storeName][] = $file;
                }
            }

            foreach ($candidates as $storeName => $files) {
                $referenced = $this->referencedByApplication($storeName, array_map(
                    static fn(File $file): StoredObjectId => new StoredObjectId($file->relativePath),
                    $files,
                ));

                foreach ($files as $file) {
                    if (isset($referenced[$file->relativePath])) {
                        continue;
                    }

                    $released++;
                    $io->text(sprintf('  %s %s (%s)', $apply ? 'releasing' : 'would release', $file->id, $file->relativePath));
                    if ($apply) {
                        $this->releaseRow($io, $file);
                    }

                    if ($released >= $limit) {
                        return $released;
                    }
                }
            }
        }

        return $released;
    }

    /**
     * A shared blob is the ledger's to schedule. A unique object goes to the
     * quarantine *before* its row is removed, and comes back if removing the
     * row fails: a row must never point at bytes the retention will purge.
     */
    private function releaseRow(SymfonyStyle $io, File $file): void
    {
        $now = $this->clock->now();
        if ($this->ledger instanceof BlobLedgerInterface && $this->ledgerKnows($file->storeName, $file->relativePath)) {
            $this->ledger->releaseFile($file->id, $now->add($this->gracePeriod));

            return;
        }

        $store = $this->storeFor($file->storeName);
        if (!$store instanceof MaintenanceStoreInterface) {
            $this->warnOnce($io, $file->storeName, sprintf(
                'Store "%s" cannot quarantine objects (%s is not implemented or is not configured), so its rows are '
                . 'not released.',
                $file->storeName,
                MaintenanceStoreInterface::class,
            ));

            return;
        }

        try {
            $entry = $store->quarantine($store->layout()->deletion(new StoredObjectId($file->relativePath)), $now);
        } catch (StoreException $e) {
            $io->warning(sprintf('Could not release "%s": %s', $file->id, $e->getMessage()));

            return;
        }

        try {
            $this->ledger instanceof BlobLedgerInterface
                ? $this->ledger->releaseFile($file->id, $now->add($this->gracePeriod))
                : $this->repository->delete($file->id);
        } catch (\Throwable $e) {
            if ($entry instanceof QuarantinedObject) {
                $store->restore($entry);
            }

            throw $e;
        }
    }

    /**
     * @return int|null Null when nothing was counted, which is not the same
     *         claim as zero: a dry run cannot know how many blobs are
     *         collectable without claiming them.
     */
    private function collectBlobs(SymfonyStyle $io, bool $apply, int $limit): ?int
    {
        if (!$this->ledger instanceof BlobLedgerInterface) {
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
        $attempts = 0;

        // Bounded by attempts, not by successes. A store that is down makes
        // every delete fail, and counting only successes turned `--limit=10`
        // into a claim-and-abandon round trip for every collectable blob in
        // the ledger.
        while ($attempts < $limit) {
            $attempts++;
            $lease = $this->ledger->claimForDeletion($this->clock->now(), $this->clock->now()->add($this->leaseTtl));
            if (!$lease instanceof BlobLease) {
                break;
            }

            $store = $this->storeFor($lease->blob->storeName);
            if (!$store instanceof MaintenanceStoreInterface) {
                $this->ledger->abandonDeletion($lease, $this->clock->now()->add($this->gracePeriod));
                $this->warnOnce($io, $lease->blob->storeName, sprintf(
                    'Store "%s" cannot delete objects (%s is not implemented or is not configured), so its '
                    . 'blobs cannot be collected.',
                    $lease->blob->storeName,
                    MaintenanceStoreInterface::class,
                ));

                // Not `break`: blobs belonging to other, perfectly collectable
                // stores are still waiting, and one unusable store must not
                // stop the pass for all of them.
                continue;
            }

            // A lease with little time left is likely to be stolen before the
            // commit below; giving it back now is cheaper than quarantining and
            // restoring. Correctness no longer depends on this check.
            $now = $this->clock->now();
            if (!$this->leaseIsFresh($lease, $now)) {
                $this->ledger->abandonDeletion($lease, $now);
                $io->warning(sprintf(
                    'Skipped "%s": less than half of its lease was left before the delete, so another collector may '
                    . 'already have taken it over. It stays queued.',
                    $lease->blob->key(),
                ));

                continue;
            }

            // No row references the blob, but the application may: a URL put
            // back into a text since the row was released.
            if ($this->referencedByApplication($lease->blob->storeName, [$lease->blob->object]) !== []) {
                $this->ledger->abandonDeletion($lease, $now->add($this->gracePeriod));
                $io->warning(sprintf(
                    'Kept "%s": the application references it although no file row does. Adopt it into a row.',
                    $lease->blob->key(),
                ));

                continue;
            }

            try {
                $entry = $store->quarantine($store->layout()->deletion($lease->blob->object), $now);
            } catch (StoreException $e) {
                // Back to the queue with a delay rather than dropped: the store
                // being briefly unavailable is not a reason to leak the object.
                $this->ledger->abandonDeletion($lease, $this->clock->now()->add($this->gracePeriod));
                $io->warning(sprintf('Could not delete "%s": %s', $lease->blob->key(), $e->getMessage()));

                continue;
            }

            // The point of no return, and only if nothing referenced the blob in
            // the meantime and the lease is still ours. Refused: the bytes go
            // back, and a writer's re-publication of them is kept.
            if (!$this->ledger->completeDeletion($lease)) {
                if ($entry instanceof QuarantinedObject) {
                    $store->restore($entry);
                }

                continue;
            }

            $collected++;
            if ($entry instanceof QuarantinedObject) {
                try {
                    $store->purge($entry);
                } catch (StoreException $e) {
                    // Committed; the next run's quarantine sweep purges it.
                    $io->warning(sprintf('Could not purge "%s": %s', $lease->blob->key(), $e->getMessage()));
                }
            }
        }

        return $collected;
    }

    /**
     * Whether at least half of the lease's time to live is still ahead. The
     * half is measured against {@see self::$leaseTtl} through a reference date,
     * so a calendar interval such as `P1D` converts like a fixed one.
     */
    private function leaseIsFresh(BlobLease $lease, DateTimeImmutable $now): bool
    {
        $reference = new DateTimeImmutable('@0');
        $halfTtl = intdiv($reference->add($this->leaseTtl)->getTimestamp() - $reference->getTimestamp(), 2);

        return $lease->expiresAt->getTimestamp() - $now->getTimestamp() >= $halfTtl;
    }

    /**
     * An object is an orphan when *no* row anywhere points at it. The rows come
     * from a scoped repository and the objects from an unscoped physical
     * listing, so under tenancy the sweep compares one tenant's rows against
     * every tenant's bytes — and deletes the difference. There is no scope value
     * that fixes it: no single tenant's view can prove an object unreferenced.
     *
     * @param non-empty-string|null $storeName
     * @param non-empty-string|null $after
     *
     * @return array{found: int, tooNew: int, undated: int, nextCursor: array{store: string, after: string}|null}
     */
    private function sweepOrphans(
        SymfonyStyle $io,
        bool $apply,
        int $limit,
        ?string $storeName,
        ?string $after,
        int $maxScan,
        DateInterval $minAge,
    ): array {
        // Without --store, every configured store. Walking only the default one
        // and reporting a total would say "no orphans" about stores nobody
        // looked at.
        $names = $storeName === null ? $this->stores->names() : [$storeName];
        $result = ['found' => 0, 'tooNew' => 0, 'undated' => 0, 'scanned' => 0, 'nextCursor' => null];

        foreach ($names as $name) {
            $result = $this->sweepStore($io, $apply, $limit, $name, $after, $maxScan, $minAge, $result);
            $after = null;
            if ($result['nextCursor'] !== null) {
                break;
            }
        }

        unset($result['scanned']);

        return $result;
    }

    /**
     * @param non-empty-string $storeName
     * @param non-empty-string|null $after
     * @param array{found: int, tooNew: int, undated: int, scanned: int, nextCursor: array{store: string, after: string}|null} $result
     *
     * @return array{found: int, tooNew: int, undated: int, scanned: int, nextCursor: array{store: string, after: string}|null}
     */
    private function sweepStore(
        SymfonyStyle $io,
        bool $apply,
        int $limit,
        string $storeName,
        ?string $after,
        int $maxScan,
        DateInterval $minAge,
        array $result,
    ): array {
        $store = $this->stores->get($storeName);
        if (!$store instanceof MaintenanceStoreInterface) {
            $io->warning(sprintf('Store "%s" cannot be walked, so its orphans cannot be found.', $store->name()));

            return $result;
        }

        $io->text(sprintf('Walking store "%s".', $store->name()));
        $layout = $store->layout();

        while (true) {
            if ($result['found'] >= $limit || ($maxScan > 0 && $result['scanned'] >= $maxScan)) {
                if ($after !== null) {
                    $result['nextCursor'] = ['store' => $storeName, 'after' => $after];
                }

                return $result;
            }

            $size = $maxScan > 0 ? max(1, min(self::PAGE, $maxScan - $result['scanned'])) : self::PAGE;
            /** @var list<StoredObject> $page */
            $page = iterator_to_array($store->objects($after, $size), preserve_keys: false);
            if ($page === []) {
                return $result;
            }

            // After the listing, never before it: a row saved for an object of
            // this page is visible here unless it was saved after this moment,
            // and then the object is younger than the cutoff below.
            $checkedAt = $this->clock->now();
            $cutoff = $checkedAt->sub($minAge);
            $owners = $this->referencedOwners($storeName, $layout, $page);

            /** @var array<string, StoredObject> $candidates */
            $candidates = [];
            foreach ($page as $object) {
                if (
                    !isset($owners[$layout->ownerOf($object->id)])
                    && $object->lastModified instanceof DateTimeImmutable
                    && $object->lastModified < $cutoff
                    && !$this->ledgerKnows($storeName, $object->id->relativePath)
                ) {
                    $candidates[$object->id->relativePath] = $object;
                }
            }

            $kept = $candidates === [] ? [] : $this->referencedByApplication(
                $storeName,
                array_values(array_map(static fn(StoredObject $o): StoredObjectId => $o->id, $candidates)),
            );

            foreach ($page as $object) {
                $path = $object->id->relativePath;
                $after = $path;
                $result['scanned']++;

                if (!isset($candidates[$path]) || isset($kept[$path])) {
                    $unowned = !isset($owners[$layout->ownerOf($object->id)]) && !isset($kept[$path]);
                    if ($unowned && !$object->lastModified instanceof DateTimeImmutable) {
                        $result['undated']++;
                    } elseif ($unowned && $object->lastModified >= $cutoff) {
                        $result['tooNew']++;
                    }
                } else {
                    $result['found']++;
                    $io->text(sprintf('  %s %s', $apply ? 'deleting' : 'would delete', $path));
                    if ($apply) {
                        $this->quarantineOrphan($io, $store, $object->id, $checkedAt);
                    }
                }

                if ($result['found'] >= $limit || ($maxScan > 0 && $result['scanned'] >= $maxScan)) {
                    break;
                }
            }
        }
    }

    /**
     * @param non-empty-string $storeName
     * @param non-empty-list<StoredObject> $page
     *
     * @return array<string, true> Owner keys some row of this store has.
     */
    private function referencedOwners(string $storeName, ObjectLayoutInterface $layout, array $page): array
    {
        $directories = array_values(array_unique(array_map(
            static fn(StoredObject $object): string => $object->id->directory(),
            $page,
        )));

        $owners = [];
        foreach ($this->repository->referencedPaths($storeName, $directories) as $path) {
            $owners[$layout->ownerOf(new StoredObjectId($path))] = true;
        }

        return $owners;
    }

    private function quarantineOrphan(
        SymfonyStyle $io,
        MaintenanceStoreInterface $store,
        StoredObjectId $object,
        DateTimeImmutable $now,
    ): void {
        try {
            $store->quarantine(ObjectSet::tree($object), $now);
        } catch (StoreException $e) {
            // One permission-denied object must not end the sweep and lose the
            // counts already accumulated — the blob path takes the same care.
            $io->warning(sprintf('Could not delete "%s": %s', $object->relativePath, $e->getMessage()));
        }
    }

    /**
     * @param non-empty-string $storeName
     * @param list<StoredObjectId> $objects
     *
     * @return array<string, true> Paths the application still references.
     */
    private function referencedByApplication(string $storeName, array $objects): array
    {
        if (!$this->references instanceof ReferenceCheckerInterface || $objects === []) {
            return [];
        }

        $referenced = [];
        foreach ($this->references->referenced($storeName, $objects) as $object) {
            $referenced[$object->relativePath] = true;
        }

        return $referenced;
    }

    /**
     * A blob or a row can name a store configuration no longer has.
     *
     * @param non-empty-string $storeName
     */
    private function storeFor(string $storeName): ?StoreInterface
    {
        try {
            return $this->stores->get($storeName);
        } catch (InvalidConfigException) {
            return null;
        }
    }

    /**
     * Whether the blob ledger has anything to say about these bytes, in any
     * state. Only asked about objects that already look unreferenced, so the
     * per-object lookup costs a query for the candidates rather than for the
     * whole store.
     */
    private function ledgerKnows(string $storeName, string $relativePath): bool
    {
        return $this->ledgerRecord($storeName, $relativePath) instanceof BlobRecord;
    }

    private function ledgerRecord(string $storeName, string $relativePath): ?BlobRecord
    {
        if (!$this->ledger instanceof BlobLedgerInterface) {
            return null;
        }

        try {
            return $this->ledger->find(BlobId::create($storeName, $relativePath));
        } catch (\InvalidArgumentException) {
            // Not a content-addressed path at all, so the ledger could never
            // own it.
            return null;
        }
    }

    private function warnOnce(SymfonyStyle $io, string $storeName, string $message): void
    {
        if (!isset($this->warned[$storeName])) {
            $this->warned[$storeName] = true;
            $io->warning($message);
        }
    }

    /**
     * @return DateInterval|false|null Null when not given, false when invalid.
     */
    private function duration(InputInterface $input, string $name): DateInterval|false|null
    {
        $value = $this->stringOption($input, $name);
        if ($value === null) {
            return null;
        }

        try {
            return new DateInterval($value);
        } catch (\Exception) {
            return false;
        }
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
    private function stringOption(InputInterface $input, string $name): ?string
    {
        // Through getOptions() rather than getOption(): an array offset is
        // something psalm narrows across accesses, where a method call's
        // `mixed` needs a `@var` tag that rector removes as redundant.
        $options = $input->getOptions();

        return isset($options[$name]) && \is_string($options[$name]) && $options[$name] !== ''
            ? $options[$name]
            : null;
    }
}
