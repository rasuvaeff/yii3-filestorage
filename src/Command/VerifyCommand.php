<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Command;

use Override;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Repository\MaintenanceRepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Checks that every row still has the bytes it claims.
 *
 * The failure this looks for is the one the whole package is arranged to
 * prevent and can still be produced from outside it: somebody empties a bucket,
 * a restore brings back a database without its objects, a migration copies rows
 * and not files. None of that is detectable at read time except as a 404 to a
 * user.
 *
 * Read-only by construction — it has no `--apply` because there is nothing safe
 * to do automatically. What to do about a row without bytes is a judgement
 * call: restore, re-upload, or delete the row.
 *
 * `--deep` also re-reads each object and compares the hash, which catches
 * corruption that a size check cannot. It costs a full read of everything.
 *
 * @api
 */
#[AsCommand(name: 'filestorage:verify', description: 'Check that every file row still has its object')]
final class VerifyCommand extends Command
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
            ->addOption('deep', null, InputOption::VALUE_NONE, 'Also re-read each object and compare its hash')
            ->addOption('after', null, InputOption::VALUE_REQUIRED, 'Resume after this file id')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many rows', '10000');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $deep = (bool) $input->getOption('deep');
        $limit = max(1, (int) $input->getOption('limit'));
        $after = $this->stringOption($input, 'after');

        $checked = 0;
        $missing = 0;
        $mismatched = 0;
        $last = null;

        while ($checked < $limit) {
            $page = iterator_to_array($this->repository->files($after, max(1, min(500, $limit - $checked))), false);
            if ($page === []) {
                break;
            }

            foreach ($page as $file) {
                $checked++;
                $last = $file->id;
                $after = $file->id;

                $problem = $this->inspect($file, $deep);
                if ($problem === null) {
                    continue;
                }

                $problem === 'missing' ? $missing++ : $mismatched++;
                $io->text(sprintf('  %s: %s (%s)', $problem, $file->id, $file->relativePath));
            }
        }

        $io->text(sprintf('Checked %d row%s.', $checked, $checked === 1 ? '' : 's'));
        // Printed even on success: it is what the next invocation resumes from.
        if ($last !== null) {
            $io->text(sprintf('Last id: %s', $last));
        }

        if ($missing === 0 && $mismatched === 0) {
            $io->success('Every row has its object.');

            return Command::SUCCESS;
        }

        $io->error(sprintf(
            '%d row%s without an object%s.',
            $missing,
            $missing === 1 ? '' : 's',
            $mismatched === 0 ? '' : sprintf(', %d with the wrong content', $mismatched),
        ));

        return Command::FAILURE;
    }

    /**
     * @return 'missing'|'corrupt'|null
     */
    private function inspect(File $file, bool $deep): ?string
    {
        $store = $this->stores->get($file->storeName);

        if (!$store->exists($file)) {
            return 'missing';
        }

        if (!$deep || $file->contentHash === null) {
            return null;
        }

        $stream = $store->stream($file);
        if ($stream === null) {
            // Existed a moment ago and does not now, or the read failed. Either
            // way the row cannot be served.
            return 'missing';
        }

        $context = hash_init('sha256');

        while (!$stream->eof()) {
            $chunk = $stream->read(262_144);
            if ($chunk === '') {
                break;
            }

            hash_update($context, $chunk);
        }

        return hash_final($context) === $file->contentHash ? null : 'corrupt';
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
