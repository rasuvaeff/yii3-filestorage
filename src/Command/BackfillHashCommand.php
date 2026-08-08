<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Command;

use Override;
use Rasuvaeff\Yii3Filestorage\Exception\InvalidConfigException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Repository\MaintenanceRepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Computes the content hash of rows that do not have one.
 *
 * `contentHash` is optional on the way in — hashing costs a second full read of
 * every upload, and consumers that never need one should not pay for it. This
 * fills it in afterwards for the ones that turn out to: an ETag that survives a
 * re-upload, an integrity check, a `filestorage:verify --deep`.
 *
 * It does **not** deduplicate anything. A hash is content identity, not
 * physical ownership, and existing rows already own their own objects — turning
 * those into shared ones is `filestorage:deduplicate`, which moves bytes.
 *
 * Resumable and idempotent: rows that already have a hash are skipped, so a
 * second run over the same range does nothing.
 *
 * @api
 */
#[AsCommand(name: 'filestorage:backfill-hash', description: 'Compute content hashes for rows that lack one')]
final class BackfillHashCommand extends Command
{
    public function __construct(
        private readonly StoreRegistry $stores,
        private readonly MaintenanceRepositoryInterface $repository,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Actually write the hashes')
            ->addOption('after', null, InputOption::VALUE_REQUIRED, 'Resume after this file id')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many rows', '1000');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $limit = max(1, (int) $input->getOption('limit'));
        $after = $this->stringOption($input, 'after');

        if (!$apply) {
            $io->note('Dry run. No hashes will be written — pass --apply to act.');
        }

        $seen = 0;
        $hashed = 0;
        $unreadable = 0;
        $last = null;

        while ($seen < $limit) {
            $page = iterator_to_array($this->repository->files($after, max(1, min(500, $limit - $seen))), false);
            if ($page === []) {
                break;
            }

            foreach ($page as $file) {
                $seen++;
                $last = $file->id;
                $after = $file->id;

                if ($file->contentHash !== null) {
                    continue;
                }

                $hash = $this->hash($file);
                if ($hash === null) {
                    $unreadable++;
                    $io->text(sprintf('  unreadable: %s (%s)', $file->id, $file->relativePath));

                    continue;
                }

                $hashed++;
                if ($apply) {
                    $this->repository->updateContentHash($file->id, $hash);
                }
            }
        }

        if ($last !== null) {
            $io->text(sprintf('Last id: %s', $last));
        }
        if ($unreadable !== 0) {
            $io->warning(sprintf(
                '%d row%s could not be read; run filestorage:verify to see why.',
                $unreadable,
                $unreadable === 1 ? '' : 's',
            ));
        }

        $io->success(sprintf(
            '%s %d of %d row%s.',
            $apply ? 'Hashed' : 'Would hash',
            $hashed,
            $seen,
            $seen === 1 ? '' : 's',
        ));

        return Command::SUCCESS;
    }

    /**
     * @return non-empty-string|null
     */
    private function hash(File $file): ?string
    {
        $stream = $this->storeFor($file->storeName)?->stream($file);
        if ($stream === null) {
            return null;
        }

        $context = hash_init('sha256');

        while (!$stream->eof()) {
            $chunk = $stream->read(262_144);
            if ($chunk === '') {
                break;
            }

            hash_update($context, $chunk);
        }

        return hash_final($context);
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

    /**
     * A row can name a store that configuration no longer has — a rename, a
     * dropped backend, a restore that brought back a database without its
     * objects. That is one of the situations this command exists to report,
     * so it must survive it rather than abort on the first such row.
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
}
