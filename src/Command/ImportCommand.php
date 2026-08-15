<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Command;

use FilesystemIterator;
use Override;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Filestorage\Exception\FilestorageException;
use Rasuvaeff\Yii3Filestorage\Exception\InvalidConfigException;
use Rasuvaeff\Yii3Filestorage\StorageInterface;
use Rasuvaeff\Yii3Filestorage\Upload;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ingests a directory tree through the ordinary write path.
 *
 * Every file goes through {@see StorageInterface::add()} — the same policy
 * check, the same MIME detection, the same store write, the same metadata row
 * as a live upload. Nothing here inserts a row or writes an object directly.
 * That is the whole design constraint: an import that took a shortcut would
 * produce rows the rest of the package does not believe in, and it would skip
 * exactly the deduplication an application enabled by overriding
 * `StorageInterface` — importing a legacy tree is when sharing pays best.
 *
 * **Re-running is safe because of the manifest, not because of the cursor.** A
 * completed import is appended to a JSON Lines file, one `{source, path, id}`
 * per line, flushed as it goes; a later run skips what is already there. A
 * cursor could not do this: a tree is not a table, one unreadable file in the
 * middle is not a reason to stop, and "resume after X" says nothing about the
 * files before X that failed. The manifest is also why a dry run writes nothing
 * at all — a dry run that recorded imports would make the `--apply` after it a
 * no-op.
 *
 * Entries are matched on `source`, the absolute path, because one manifest is
 * meant to cover several roots: importing `/srv/legacy/invoices` and then
 * `/srv/legacy/avatars` is the documented shape, and both trees holding a
 * `notes.txt` must not make the second run skip a file it never imported.
 *
 * `--limit` bounds the *work* per run, not the memory: the listing is built and
 * sorted up front, so it is proportional to the whole tree. Sorting is what
 * makes two runs comparable, and it cannot be done lazily.
 *
 * @api
 */
#[AsCommand(name: 'filestorage:import', description: 'Import a directory tree through the ordinary write path')]
final class ImportCommand extends Command
{
    /**
     * Under `build/`, which every package here already gitignores, so a manifest
     * left behind by an import does not turn up in a commit.
     */
    private const string DEFAULT_MANIFEST = 'build/filestorage-import.jsonl';

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly StreamFactoryInterface $streams,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('directory', InputArgument::REQUIRED, 'The directory tree to import')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Actually import. Without this, nothing is written')
            ->addOption('group', null, InputOption::VALUE_REQUIRED, 'Group for every file in this run')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store to write to')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many files', '1000')
            ->addOption(
                'manifest',
                null,
                InputOption::VALUE_REQUIRED,
                'Where imported paths are recorded, and read back to skip them',
                self::DEFAULT_MANIFEST,
            );
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $limit = max(1, (int) $input->getOption('limit'));
        $group = $this->stringOption($input, 'group');
        $store = $this->stringOption($input, 'store');
        $manifestPath = $this->stringOption($input, 'manifest') ?? self::DEFAULT_MANIFEST;

        $directory = $this->stringArgument($input, 'directory');
        if ($directory === null || !is_dir($directory) || !is_readable($directory)) {
            $io->error(sprintf('"%s" is not a readable directory.', $directory ?? ''));

            return Command::FAILURE;
        }

        $root = realpath($directory);
        if ($root === false) {
            $io->error(sprintf('"%s" could not be resolved.', $directory));

            return Command::FAILURE;
        }

        if (!$apply) {
            $io->note('Dry run. Nothing is imported and the manifest is untouched — pass --apply to act.');
        }

        $done = $this->readManifest($manifestPath);
        if ($done !== []) {
            $io->text(sprintf(
                'Manifest %s holds %d already-imported path%s.',
                $manifestPath,
                \count($done),
                \count($done) === 1 ? '' : 's',
            ));
        }

        $handle = null;
        if ($apply) {
            $handle = $this->openManifest($manifestPath);
            if ($handle === null) {
                $io->error(sprintf(
                    'Manifest "%s" could not be opened for appending. Create its directory, or point --manifest '
                    . 'somewhere writable — without it a second run would import everything again.',
                    $manifestPath,
                ));

                return Command::FAILURE;
            }
        }

        $imported = 0;
        $unrecorded = 0;
        $skipped = 0;
        $failed = 0;
        $seen = 0;

        foreach ($this->files($root, $manifestPath) as $relative => $absolute) {
            // Keyed by the absolute path, not the relative one. One manifest
            // legitimately covers several roots — the README's own recipe is a
            // run per subdirectory — and two trees holding a `notes.txt` each
            // would otherwise make the second run skip a file it never
            // imported, and report that as a skip.
            if (isset($done[$absolute])) {
                $skipped++;

                continue;
            }

            if ($seen >= $limit) {
                break;
            }
            $seen++;

            if (!$apply) {
                $io->text(sprintf('  + %s', $relative));

                continue;
            }

            try {
                $id = $this->import($io, $absolute, $relative, $group, $store);
            } catch (InvalidConfigException|\InvalidArgumentException $e) {
                // A mistyped --store or --group is a fact about the invocation,
                // not about each of ten thousand files. Repeating it per file
                // buries it in its own repetition, and InvalidArgumentException
                // is deliberately not a FilestorageException — so without this
                // it escaped the per-file catch entirely and ended the run with
                // a stack trace.
                if ($handle !== null) {
                    fclose($handle);
                }

                $io->error(sprintf('%s. Nothing further was imported.', rtrim($e->getMessage(), '.')));

                return Command::FAILURE;
            }
            if ($id === null) {
                $failed++;

                continue;
            }

            $imported++;
            $io->text(sprintf('  + %s → %s', $relative, $id));
            if (!$this->record($handle, $absolute, $relative, $id)) {
                // The file is stored and the row is committed; only the record
                // of it failed. Saying so is the difference between "the next
                // run will re-import this" and finding a duplicate later and
                // not knowing why.
                $unrecorded++;
                $io->text(sprintf('  ! %s imported but not recorded in the manifest', $relative));
            }
        }

        if ($handle !== null) {
            fclose($handle);
        }

        if ($unrecorded !== 0) {
            $io->warning(sprintf(
                '%d file%s imported but could not be written to the manifest, so a later run will import %s again.',
                $unrecorded,
                $unrecorded === 1 ? '' : 's',
                $unrecorded === 1 ? 'it' : 'them',
            ));
        }

        return $this->report($io, $apply, $manifestPath, $imported, $seen, $skipped, $failed);
    }

    private function report(
        SymfonyStyle $io,
        bool $apply,
        string $manifestPath,
        int $imported,
        int $seen,
        int $skipped,
        int $failed,
    ): int {
        $summary = sprintf(
            '%s %d file%s',
            $apply ? 'Imported' : 'Would import',
            $apply ? $imported : $seen,
            ($apply ? $imported : $seen) === 1 ? '' : 's',
        );
        if ($skipped !== 0) {
            $summary .= sprintf(', skipped %d already in the manifest', $skipped);
        }
        if ($apply) {
            $summary .= sprintf('. Manifest: %s', $manifestPath);
        }

        if ($failed !== 0) {
            $io->error(sprintf(
                '%s. %d file%s could not be imported — see above. They are not in the manifest, so a later run '
                . 'retries exactly those.',
                $summary,
                $failed,
                $failed === 1 ? '' : 's',
            ));

            return Command::FAILURE;
        }

        if ($seen === 0 && $skipped === 0) {
            $io->success('Nothing to import.');

            return Command::SUCCESS;
        }

        $io->success($summary . '.');

        return Command::SUCCESS;
    }

    /**
     * @param non-empty-string|null $group
     * @param non-empty-string|null $store
     *
     * @return non-empty-string|null Null when this one file failed; the run continues.
     */
    private function import(
        SymfonyStyle $io,
        string $absolute,
        string $relative,
        ?string $group,
        ?string $store,
    ): ?string {
        try {
            $file = $this->storage->add(
                upload: Upload::fromPath(
                    path: $absolute,
                    streamFactory: $this->streams,
                    originalName: basename($relative),
                ),
                groupName: $group,
                storeName: $store,
                // Recorded on the row as well as in the manifest: a manifest
                // can be lost or pointed elsewhere, and then this is the only
                // remaining answer to "where did this file come from". Both
                // forms, for the same reason the manifest matches on the
                // absolute one — two roots each holding a `notes.txt` are
                // indistinguishable by the relative path alone.
                // Forward slashes on every platform: the value is a record, and
                // a record that reads differently depending on where the import
                // ran is one nobody can match against later.
                metadata: [
                    'importSource' => $relative,
                    'importSourcePath' => str_replace('\\', '/', $absolute),
                ],
            );

            return $file->id;
        } catch (InvalidConfigException|\InvalidArgumentException $e) {
            // Not this file's fault — the invocation's. Rethrown so the caller
            // can stop the run rather than print the same line per file.
            throw $e;
        } catch (FilestorageException|\RuntimeException $e) {
            // Policy rejections, oversized bodies and store failures are all
            // per-file facts about a legacy tree, not reasons to abandon the
            // other ten thousand files. \RuntimeException is in the list
            // because PSR-17 specifies it for a file that cannot be opened, and
            // one chmod 000 in a legacy tree is the most ordinary thing there
            // is — it must not kill a run that has already imported thousands.
            $io->text(sprintf('  ! %s — %s', $relative, $e->getMessage()));

            return null;
        }
    }

    /**
     * Every regular file under the root, deepest-last, sorted, keyed by its
     * path relative to the root.
     *
     * Symbolic links are skipped rather than followed: a link in a legacy tree
     * points wherever it was pointed, and following one imports files from
     * outside the directory the operator named. Entries beginning with a dot
     * are skipped too — `.git`, `.DS_Store` and editor droppings are noise in
     * every tree this command exists for.
     *
     * So is the manifest, when it happens to sit inside the tree. The listing
     * is built *after* the manifest is opened, so a run pointed at a directory
     * containing its own manifest would otherwise import it — and then record
     * having done so, in the file it just imported.
     *
     * @return iterable<string, string>
     */
    private function files(string $root, string $manifestPath): iterable
    {
        // Normalised to forward slashes: the iterator is created with
        // FilesystemIterator::UNIX_PATHS while realpath() answers in the
        // platform's own separator, so on Windows the two never matched and
        // the manifest imported itself.
        $resolved = realpath($manifestPath);
        $manifest = $resolved === false ? null : str_replace('\\', '/', $resolved);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $root,
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS,
            ),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        $paths = [];
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile() || $item->isLink()) {
                continue;
            }

            $absolute = $item->getPathname();
            if ($manifest !== null && str_replace('\\', '/', $absolute) === $manifest) {
                continue;
            }

            $relative = substr($absolute, \strlen($root) + 1);
            if ($relative === '' || $this->hasDotSegment($relative)) {
                continue;
            }

            $paths[$relative] = $absolute;
        }

        // Sorted so two runs over an unchanged tree see the same order, and so
        // the output of one is comparable with the output of the next.
        ksort($paths);

        return $paths;
    }

    private function hasDotSegment(string $relative): bool
    {
        foreach (explode('/', $relative) as $segment) {
            if (str_starts_with($segment, '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, true>
     */
    private function readManifest(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $done = [];
        while (($line = fgets($handle)) !== false) {
            /** @var mixed $entry */
            $entry = json_decode(trim($line), associative: true);
            // A truncated final line is what a crash mid-append leaves. Skipping
            // it re-imports one file, which is the right way round: the
            // alternative is refusing to read a manifest that is 99% good.
            if (\is_array($entry) && isset($entry['source']) && \is_string($entry['source'])) {
                $done[$entry['source']] = true;
            }
        }
        fclose($handle);

        return $done;
    }

    /**
     * @return resource|null
     */
    private function openManifest(string $path)
    {
        $directory = \dirname($path);
        // Silenced deliberately: the third clause is the race check, so a
        // concurrent creator is an expected outcome rather than a problem —
        // and the warning it emits is enough to fail a test run that treats
        // warnings as errors.
        if (!is_dir($directory) && !@mkdir($directory, 0o775, recursive: true) && !is_dir($directory)) {
            return null;
        }

        $handle = fopen($path, 'ab');

        return $handle === false ? null : $handle;
    }

    /**
     * `source` is the identity the next run matches on; `path` is there so the
     * file reads without reconstructing which root each entry belonged to.
     *
     * @param resource|null $handle
     */
    private function record($handle, string $absolute, string $relative, string $id): bool
    {
        if ($handle === null) {
            return false;
        }

        $line = json_encode(
            ['source' => $absolute, 'path' => $relative, 'id' => $id],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        $written = fwrite($handle, $line . "\n");
        // Flushed per line, not per run: the point of the manifest is to
        // survive the run that did not finish.

        return $written !== false && fflush($handle);
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
     * @return non-empty-string|null
     */
    private function stringArgument(InputInterface $input, string $name): ?string
    {
        $arguments = $input->getArguments();

        return isset($arguments[$name]) && \is_string($arguments[$name]) && $arguments[$name] !== ''
            ? $arguments[$name]
            : null;
    }
}
