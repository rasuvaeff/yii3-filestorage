<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Command;

use DateInterval;
use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\Command\ImportCommand;
use Rasuvaeff\Yii3Filestorage\Id\Uuid7IdGenerator;
use Rasuvaeff\Yii3Filestorage\Mime\FinfoMimeTypeDetector;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\UploadPolicy;
use Rasuvaeff\Yii3Filestorage\Storage;
use Rasuvaeff\Yii3Filestorage\StorageInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Test\MemoryRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Files\FileHelper;
use Yiisoft\Test\Support\Clock\StaticClock;

#[Test]
#[Covers(ImportCommand::class)]
final class ImportCommandTest
{
    private Psr17Factory $factory;
    private MemoryRepository $repository;
    private InMemoryStore $store;
    private string $source;
    private string $manifest;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->repository = new MemoryRepository();
        $this->store = new InMemoryStore('memory', $this->factory);
        $this->source = sys_get_temp_dir() . '/fs-import-' . bin2hex(random_bytes(8));
        $this->manifest = $this->source . '-manifest/import.jsonl';
        mkdir($this->source, 0o775, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        foreach ([$this->source, \dirname($this->manifest)] as $directory) {
            if (is_dir($directory)) {
                FileHelper::removeDirectory($directory);
            }
        }
    }

    /**
     * The default is a report. Importing ten thousand legacy files on the run
     * that was meant to show what would happen is not recoverable by deleting
     * rows: the objects are written too.
     */
    public function importsNothingWithoutApply(): void
    {
        $this->file('a.txt', 'one');

        $tester = $this->run();

        Assert::string($tester->getDisplay())->contains('Dry run');
        Assert::string($tester->getDisplay())->contains('a.txt');
        Assert::same($this->repository->count(), 0);
        Assert::false(is_file($this->manifest), 'a dry run must not write the manifest');
    }

    public function importsEveryFileUnderApply(): void
    {
        $this->file('a.txt', 'one');
        $this->file('nested/b.txt', 'two');

        $tester = $this->run(['--apply' => true]);

        Assert::same($tester->getStatusCode(), Command::SUCCESS);
        Assert::same($this->repository->count(), 2);
        Assert::string($tester->getDisplay())->contains('Imported 2 files');
    }

    /**
     * The claim the manifest exists for. `add()` has no natural key — a second
     * run without it writes a second row and a second object for every file,
     * and nothing later can tell the copies apart.
     */
    public function aSecondRunImportsNothingTwice(): void
    {
        $this->file('a.txt', 'one');
        $this->file('b.txt', 'two');

        $this->run(['--apply' => true]);
        $second = $this->run(['--apply' => true]);

        Assert::same($this->repository->count(), 2, 'the second run added nothing');
        Assert::string($second->getDisplay())->contains('skipped 2 already in the manifest');
    }

    /**
     * A file added after the first run is imported by the second, and the ones
     * already in the manifest are not. "Skip everything once the manifest
     * exists" would be a different, useless command.
     */
    public function aSecondRunPicksUpWhatIsNew(): void
    {
        $this->file('a.txt', 'one');
        $this->run(['--apply' => true]);

        $this->file('b.txt', 'two');
        $this->run(['--apply' => true]);

        Assert::same($this->repository->count(), 2);
    }

    /**
     * The manifest is flushed per line rather than at the end, so a run killed
     * halfway leaves the files it finished recorded. Simulated by importing
     * under a limit, which is the same partial state.
     */
    public function whatOneRunFinishedSurvivesIntoTheNext(): void
    {
        $this->file('a.txt', 'one');
        $this->file('b.txt', 'two');
        $this->file('c.txt', 'three');

        $this->run(['--apply' => true, '--limit' => '2']);

        Assert::same($this->repository->count(), 2);
        Assert::same(substr_count((string) file_get_contents($this->manifest), "\n"), 2);

        $this->run(['--apply' => true]);

        Assert::same($this->repository->count(), 3, 'the third file, and only it');
    }

    /**
     * A crash mid-`fwrite` leaves a partial final line. Refusing to read a
     * manifest that is 99% good would re-import everything; skipping the one
     * broken line re-imports one file.
     */
    public function aTruncatedManifestLineCostsOneFileAndNoMore(): void
    {
        $this->file('a.txt', 'one');
        $this->file('b.txt', 'two');
        $this->run(['--apply' => true]);

        $lines = explode("\n", trim((string) file_get_contents($this->manifest)));
        file_put_contents($this->manifest, $lines[0] . "\n" . substr($lines[1], 0, 12));

        $this->run(['--apply' => true]);

        Assert::same($this->repository->count(), 3, 'one re-import, not two');
    }

    public function theLimitBoundsTheRun(): void
    {
        $this->file('a.txt', 'one');
        $this->file('b.txt', 'two');
        $this->file('c.txt', 'three');

        $this->run(['--apply' => true, '--limit' => '1']);

        Assert::same($this->repository->count(), 1);
    }

    /**
     * Every file goes through the facade, so the group's accept rules apply on
     * the way in — that is the whole reason this does not insert rows itself.
     */
    public function aFileTheGroupPolicyRejectsIsReportedAndSkipped(): void
    {
        $this->file('small.txt', 'ok');
        $this->file('big.txt', str_repeat('x', 64));

        $tester = $this->run(
            ['--apply' => true],
            policies: new PolicyRegistry(['*' => new UploadPolicy(maxBytes: 8)]),
        );

        Assert::same($tester->getStatusCode(), Command::FAILURE);
        Assert::same($this->repository->count(), 1, 'the acceptable one still landed');
        Assert::string($tester->getDisplay())->contains('big.txt');
        // Whitespace squeezed out: SymfonyStyle hard-wraps an error block, so
        // any phrase long enough to be worth asserting spans a line break.
        Assert::string($this->normalise($tester->getDisplay()))->contains('could not be imported');
    }

    /**
     * A rejected file must not enter the manifest, or the run that fixes the
     * policy would skip exactly the files the policy used to reject.
     */
    public function aRejectedFileIsRetriedByTheNextRun(): void
    {
        $this->file('big.txt', str_repeat('x', 64));

        $this->run(['--apply' => true], policies: new PolicyRegistry(['*' => new UploadPolicy(maxBytes: 8)]));
        $this->run(['--apply' => true]);

        Assert::same($this->repository->count(), 1);
    }

    /**
     * The source path lands on the row too. A manifest can be lost or pointed
     * somewhere else; the metadata is then the only surviving answer to "where
     * did this come from".
     */
    public function theSourcePathIsRecordedOnTheRow(): void
    {
        $this->file('nested/a.txt', 'one');

        $this->run(['--apply' => true]);

        $files = iterator_to_array($this->repository->files(null, 10), false);

        Assert::same($files[0]->metadata['importSource'], 'nested/a.txt');
    }

    public function theGroupOptionAppliesToEveryFileInTheRun(): void
    {
        $this->file('a.txt', 'one');

        $this->run(['--apply' => true, '--group' => 'documents']);

        $files = iterator_to_array($this->repository->files(null, 10), false);

        Assert::same($files[0]->groupName, 'documents');
    }

    /**
     * A symlink in a legacy tree points wherever it was pointed. Following one
     * imports files from outside the directory the operator named — including,
     * with a link to `/`, files nobody meant to hand to an object store.
     */
    public function symbolicLinksAreNotFollowed(): void
    {
        $outside = $this->source . '-outside';
        mkdir($outside, 0o775, true);
        file_put_contents($outside . '/secret.txt', 'not yours');
        symlink($outside . '/secret.txt', $this->source . '/link.txt');

        $tester = $this->run(['--apply' => true]);

        Assert::same($this->repository->count(), 0);
        Assert::string($tester->getDisplay())->notContains('link.txt');

        FileHelper::removeDirectory($outside);
    }

    /**
     * `.git`, `.DS_Store` and editor droppings are in every tree this command
     * exists for, and none of them is a document.
     */
    public function dotEntriesAreSkippedAtEveryDepth(): void
    {
        $this->file('.DS_Store', 'noise');
        $this->file('.git/config', 'noise');
        $this->file('visible/.hidden.txt', 'noise');
        $this->file('visible/real.txt', 'yes');

        $this->run(['--apply' => true]);

        Assert::same($this->repository->count(), 1);
    }

    /**
     * The listing is built after the manifest is opened, so a manifest sitting
     * inside the imported tree is a file the walk can see. Importing it would
     * store a record of the import inside the store, and then append to the
     * file it had just imported. Found the first time the wiring test pointed
     * `--manifest` at the tree it was importing.
     */
    public function theManifestIsNotImportedWhenItLivesInsideTheTree(): void
    {
        $this->file('a.txt', 'one');

        $tester = $this->run(['--apply' => true, '--manifest' => $this->source . '/import.jsonl']);

        Assert::same($this->repository->count(), 1);
        // The summary names the manifest, so the assertion is on the import
        // line's own prefix rather than on the file name anywhere.
        Assert::string($tester->getDisplay())->notContains('+ import.jsonl');
    }

    public function anEmptyDirectoryReportsNothingToImport(): void
    {
        $tester = $this->run(['--apply' => true]);

        Assert::same($tester->getStatusCode(), Command::SUCCESS);
        Assert::string($tester->getDisplay())->contains('Nothing to import');
    }

    public function aMissingDirectoryIsAnErrorRatherThanAnEmptyRun(): void
    {
        $missing = $this->source . '/nope';

        $tester = $this->run(['directory' => $missing, '--apply' => true]);

        Assert::same($tester->getStatusCode(), Command::FAILURE);
        $display = $this->normalise($tester->getDisplay());
        Assert::string($display)->contains('"' . $missing . '" is not a readable directory');
        // One diagnosis, not two: the run stops here rather than falling
        // through to the resolution step and reporting that as well.
        Assert::string($display)->notContains('could not be resolved');
    }

    /**
     * A path that exists but is a file. The check is "is it a *directory* and
     * readable", not "does it exist" — handing a file to a recursive directory
     * iterator throws from inside the walk instead of saying what is wrong.
     */
    public function aFileWhereADirectoryWasExpectedIsAnError(): void
    {
        $this->file('a.txt', 'one');

        $tester = $this->run(['directory' => $this->source . '/a.txt', '--apply' => true]);

        Assert::same($tester->getStatusCode(), Command::FAILURE);
        Assert::string($this->normalise($tester->getDisplay()))->contains('is not a readable directory');
    }

    /**
     * `--limit=0` is a typo, not an instruction to do nothing. Clamped to one,
     * so the run makes progress and the operator sees it did.
     */
    public function aLimitOfZeroStillImportsOneFile(): void
    {
        $this->file('a.txt', 'one');
        $this->file('b.txt', 'two');

        $this->run(['--apply' => true, '--limit' => '0']);

        Assert::same($this->repository->count(), 1);
    }

    /**
     * The dry-run branch has its own count, so it needs its own singular. The
     * two numbers in that sentence are only ever right one branch at a time.
     */
    public function theDryRunSingularIsItsOwn(): void
    {
        $this->file('a.txt', 'one');

        $display = $this->normalise($this->run()->getDisplay());

        Assert::string($display)->contains('Would import 1 file.');
        Assert::string($display)->notContains('Would import 1 files');
    }

    /**
     * Refusing up front rather than importing and forgetting: an import whose
     * manifest could not be written is an import that a second run duplicates,
     * and by then the objects exist.
     */
    public function anUnwritableManifestStopsTheRunBeforeAnythingIsImported(): void
    {
        $this->file('a.txt', 'one');
        // A file where the manifest's parent directory has to be.
        $blocked = $this->source . '-blocked';
        file_put_contents($blocked, 'not a directory');

        $tester = $this->run(['--apply' => true, '--manifest' => $blocked . '/import.jsonl']);

        Assert::same($tester->getStatusCode(), Command::FAILURE);
        Assert::same($this->repository->count(), 0);
        // Across the concatenation: the first half says something broke, the
        // second says what to do about it, and either half alone reads as a
        // complete message.
        Assert::string($this->normalise($tester->getDisplay()))
            ->contains('could not be opened for appending. Create its directory, or point --manifest somewhere '
                . 'writable');

        unlink($blocked);
    }

    /**
     * Counted once, and as itself. The tell that the two totals are not the
     * same number: with one new file beside two known ones, "imported" is 1 and
     * "skipped" is 2.
     */
    public function theSummaryCountsImportsAndSkipsSeparately(): void
    {
        $this->file('a.txt', 'one');
        $this->file('b.txt', 'two');
        $this->run(['--apply' => true]);

        $this->file('c.txt', 'three');
        $display = $this->run(['--apply' => true])->getDisplay();

        Assert::string($display)->contains('Imported 1 file, skipped 2 already in the manifest');
    }

    /**
     * Squeezes out the wrapping and the gutter SymfonyStyle adds to a block, so
     * a phrase can be asserted as one string.
     */
    private function normalise(string $display): string
    {
        return (string) preg_replace('/[\s!\[\]]+/u', ' ', $display);
    }

    /**
     * The dry-run summary counts what it *would* do and says nothing about a
     * manifest it did not touch. Two numbers travel through that sentence —
     * "seen" and "imported" — and only one of them is right on each branch.
     */
    public function theDryRunSummaryCountsWhatItWouldImport(): void
    {
        $this->file('a.txt', 'one');
        $this->file('b.txt', 'two');

        $display = $this->normalise($this->run()->getDisplay());

        Assert::string($display)->contains('Would import 2 files.');
        Assert::string($display)->notContains('Manifest:');
    }

    /**
     * Every file, not just the first. The listing loop is what a dry run is
     * for, so stopping at one would report a subset as if it were the plan.
     */
    public function theDryRunListsEveryFile(): void
    {
        $this->file('a.txt', 'one');
        $this->file('b.txt', 'two');
        $this->file('c.txt', 'three');

        $display = $this->run()->getDisplay();

        foreach (['a.txt', 'b.txt', 'c.txt'] as $name) {
            Assert::string($display)->contains('+ ' . $name);
        }
    }

    /**
     * A clean first run says nothing about skipping, and names the manifest so
     * the operator knows which file to keep.
     */
    public function theAppliedSummaryNamesTheManifestAndClaimsNoSkips(): void
    {
        $this->file('a.txt', 'one');
        $this->file('b.txt', 'two');

        $display = $this->normalise($this->run(['--apply' => true])->getDisplay());

        Assert::string($display)->contains('Imported 2 files. Manifest: ' . $this->manifest);
        Assert::string($display)->notContains('skipped');
    }

    public function oneFileIsAFileAndNotFiles(): void
    {
        $this->file('a.txt', 'one');

        $display = $this->normalise($this->run(['--apply' => true])->getDisplay());

        Assert::string($display)->contains('Imported 1 file.');
        Assert::string($display)->notContains('Imported 1 files');
    }

    /**
     * The manifest note is the operator's evidence that the skipping is real.
     * Singular and plural, because "holds 1 already-imported paths" is how a
     * reader learns not to trust the rest of the output.
     */
    public function theManifestNoteCountsAndAgreesWithItself(): void
    {
        $this->file('a.txt', 'one');
        $this->run(['--apply' => true]);

        Assert::string($this->normalise($this->run(['--apply' => true])->getDisplay()))
            ->contains('holds 1 already-imported path.');

        $this->file('b.txt', 'two');
        $this->run(['--apply' => true]);

        Assert::string($this->normalise($this->run(['--apply' => true])->getDisplay()))
            ->contains('holds 2 already-imported paths.');
    }

    /**
     * The line that tells you which source became which id. Without it the
     * import is a number, and reconciling it against the tree means reading
     * the manifest by hand.
     */
    public function eachImportedFileIsPrintedWithTheIdItBecame(): void
    {
        $this->file('a.txt', 'one');

        $display = $this->run(['--apply' => true])->getDisplay();
        $files = iterator_to_array($this->repository->files(null, 10), false);

        Assert::string($display)->contains('+ a.txt → ' . $files[0]->id);
    }

    /**
     * Sorted, so two runs over an unchanged tree list the same order and the
     * output of one is comparable with the next. Directory iteration order is
     * the filesystem's business and is not alphabetical.
     */
    public function theListingIsSorted(): void
    {
        foreach (['z.txt', 'm.txt', 'a.txt'] as $name) {
            $this->file($name, $name);
        }

        $display = $this->run()->getDisplay();

        Assert::true(
            strpos($display, '+ a.txt') < strpos($display, '+ m.txt')
            && strpos($display, '+ m.txt') < strpos($display, '+ z.txt'),
            'the listing came out unsorted',
        );
    }

    /**
     * The failure line has to carry its own count and the instruction that
     * follows from it — asserted across the concatenation, because half of it
     * reads as complete and is not.
     */
    public function theFailureLineCountsAndSaysWhatHappensNext(): void
    {
        $this->file('big-one.txt', str_repeat('x', 64));
        $this->file('big-two.txt', str_repeat('x', 64));

        $display = $this->normalise(
            $this->run(['--apply' => true], policies: new PolicyRegistry(['*' => new UploadPolicy(maxBytes: 8)]))
                ->getDisplay(),
        );

        Assert::string($display)->contains('2 files could not be imported');
        Assert::string($display)->contains('not in the manifest, so a later run retries exactly those');
    }

    public function oneFailureIsAFileAndNotFiles(): void
    {
        $this->file('big.txt', str_repeat('x', 64));

        $display = $this->normalise(
            $this->run(['--apply' => true], policies: new PolicyRegistry(['*' => new UploadPolicy(maxBytes: 8)]))
                ->getDisplay(),
        );

        Assert::string($display)->contains('1 file could not be imported');
        Assert::string($display)->notContains('1 files could not be imported');
    }

    /**
     * "Nothing to import" replaces the summary rather than joining it. Two
     * conclusions in one report is how an operator ends up believing whichever
     * they read first.
     */
    public function nothingToImportIsTheWholeReport(): void
    {
        $display = $this->normalise($this->run(['--apply' => true])->getDisplay());

        Assert::string($display)->contains('Nothing to import.');
        Assert::string($display)->notContains('Imported 0 files');
    }

    /**
     * A manifest line that parses but is not an entry — a stray array, an
     * object with no `path` — must not be treated as one. The guard is three
     * conditions because a JSON file is not a schema.
     */
    public function manifestLinesThatAreNotEntriesSkipNothing(): void
    {
        $this->file('a.txt', 'one');
        mkdir(\dirname($this->manifest), 0o775, true);
        file_put_contents(
            $this->manifest,
            "123\n" . json_encode(['id' => 'x']) . "\n" . json_encode(['path' => ['a.txt']]) . "\n",
        );

        $this->run(['--apply' => true]);

        Assert::same($this->repository->count(), 1, 'none of those three lines is an entry');
    }

    /**
     * The manifest's directory is created on demand. Requiring the operator to
     * mkdir `build/` before the first import would make the default value a
     * trap rather than a default.
     */
    public function theManifestDirectoryIsCreatedWhenItIsMissing(): void
    {
        $this->file('a.txt', 'one');
        $nested = \dirname($this->manifest) . '/deep/deeper/import.jsonl';

        $tester = $this->run(['--apply' => true, '--manifest' => $nested]);

        Assert::same($tester->getStatusCode(), Command::SUCCESS);
        Assert::true(is_file($nested));
    }

    private function file(string $relative, string $contents): void
    {
        $path = $this->source . '/' . $relative;
        $directory = \dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }

        file_put_contents($path, $contents);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function run(array $arguments = [], ?PolicyRegistry $policies = null): CommandTester
    {
        $tester = new CommandTester(new ImportCommand($this->storage($policies), $this->factory));
        $tester->execute([
            'directory' => $this->source,
            '--manifest' => $this->manifest,
            ...$arguments,
        ]);

        return $tester;
    }

    private function storage(?PolicyRegistry $policies): StorageInterface
    {
        $clock = new StaticClock(new DateTimeImmutable('2026-01-01T00:00:00.000000+00:00'));

        return new Storage(
            stores: new StoreRegistry([$this->store]),
            repository: $this->repository,
            pathGenerator: new RandomPathGenerator(),
            mimeTypeDetector: new FinfoMimeTypeDetector(),
            idGenerator: new Uuid7IdGenerator($clock),
            policies: $policies ?? new PolicyRegistry(),
            deliveryPolicies: new DeliveryPolicyRegistry(),
            clock: $clock,
            defaultUrlTtl: new DateInterval('PT1H'),
        );
    }
}
