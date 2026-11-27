<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Command;

use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Filestorage\Command\CheckCommand;
use Rasuvaeff\Yii3Filestorage\Path\ContentAddressedKeyGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Path\FlatSha256KeyGenerator;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicy;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\UploadPolicy;
use Rasuvaeff\Yii3Filestorage\Reference\ReferenceAuthority;
use Rasuvaeff\Yii3Filestorage\Reference\ReferenceCheckerInterface;
use Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface;
use Rasuvaeff\Yii3Filestorage\Repository\RepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Repository\ScopedFileResolverInterface;
use Rasuvaeff\Yii3Filestorage\Store\FileSystem\FileSystemStore;
use Rasuvaeff\Yii3Filestorage\Store\FileSystem\PublicFileSystemStore;
use Rasuvaeff\Yii3Filestorage\Store\Layout\FlatSiblingLayout;
use Rasuvaeff\Yii3Filestorage\Store\StoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Test\MemoryRepository;
use Rasuvaeff\Yii3Filestorage\Test\UrlCapableInMemoryStore;
use Rasuvaeff\Yii3Filestorage\Upload;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Files\FileHelper;

use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(CheckCommand::class)]
final class CheckCommandTest
{
    private Psr17Factory $factory;
    private string $root;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->root = sys_get_temp_dir() . '/fs-check-' . bin2hex(random_bytes(8));
    }

    #[AfterTest]
    public function tearDown(): void
    {
        if (is_dir($this->root)) {
            FileHelper::removeDirectory($this->root);
        }
    }

    public function reportsAWorkingConfigurationAsSound(): void
    {
        $tester = $this->run(store: new FileSystemStore('upload', $this->root, $this->factory));

        Assert::same($tester->getStatusCode(), Command::SUCCESS);
        Assert::string($tester->getDisplay())->contains('looks sound');
    }

    /**
     * Every section has to be printed, and each one has to say which thing it
     * is describing: a report that silently dropped the repository section
     * would still exit zero.
     */
    public function printsEverySectionOfTheReport(): void
    {
        $display = $this->run(
            store: new FileSystemStore('upload', $this->root, $this->factory),
            repository: new MemoryRepository(),
        )->getDisplay();

        foreach (['Stores', 'Metadata repository', 'Tenancy', 'Groups'] as $section) {
            Assert::string($display)->contains($section);
        }

        Assert::string($display)->contains(FileSystemStore::class, 'the store class is named');
        Assert::string($display)->contains(MemoryRepository::class, 'the repository class is named');
        Assert::string($display)->contains('Capabilities');
        Assert::string($display)->contains('Max pixels');
        // The whole header row: a dropped column header is what a consumer
        // diffing two reports first stumbles over.
        Assert::string($this->flatten($display))->contains('Name Class Layout Capabilities');
    }

    /**
     * The machine object is the whole report in one shape: a dashboard parsing
     * it must get every key with its exact value, and a dropped key or a
     * flipped flag has to break this test rather than a consumer in production.
     */
    public function theMachineObjectCarriesTheWholeReport(): void
    {
        $tester = new CommandTester(new CheckCommand(
            stores: new StoreRegistry([new InMemoryStore('memory', $this->factory)]),
            repository: new MemoryRepository(),
            policies: new PolicyRegistry(),
            deliveryPolicies: new DeliveryPolicyRegistry(),
        ));
        $tester->execute(['--format' => 'json']);
        $raw = trim($tester->getDisplay());
        Assert::true((bool) preg_match('/\A\{.*\}\z/s', $raw), "stdout must be one JSON object, got: {$raw}");
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, associative: true);

        Assert::true($decoded['ok']);
        Assert::same($decoded['errors'], []);
        Assert::same($decoded['warnings'], [
            'RepositoryInterface is bound to Test\MemoryRepository: every file record is lost when '
            . 'the process ends. Install rasuvaeff/yii3-filestorage-db before using this anywhere real.',
        ]);
        Assert::same($decoded['stores'], [
            [
                'name' => 'memory',
                'class' => InMemoryStore::class,
                'layout' => \Rasuvaeff\Yii3Filestorage\Store\Layout\DirectoryLayout::class,
                'capabilities' => ['maintenance'],
                'default' => true,
            ],
        ]);
        Assert::same($decoded['groups'], [
            [
                'group' => '*',
                'accepts' => [],
                'maxBytes' => 0,
                'maxPixels' => 40_000_000,
                'directPublicUrl' => false,
            ],
        ]);
        // The log is the decorated report the text mode would have printed —
        // its content is covered by the text-mode tests; here it only has to
        // be present as an array.
        Assert::true(\is_array($decoded['log']));
    }

    /**
     * The error object carries the same refusal the text report does — the
     * full explanation, both interfaces named, the fix spelled out. A consumer
     * redisplaying it must not lose the second half of the sentence.
     */
    public function theMachineObjectCarriesTheWholeTenantError(): void
    {
        $tester = new CommandTester(new CheckCommand(
            stores: new StoreRegistry([new InMemoryStore('memory', $this->factory)]),
            repository: new MemoryRepository(),
            policies: new PolicyRegistry(),
            deliveryPolicies: new DeliveryPolicyRegistry(),
            scopes: $this->tenantScope(),
        ));
        $tester->execute(['--format' => 'json']);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(trim($tester->getDisplay()), associative: true);

        Assert::false($decoded['ok']);
        Assert::same($decoded['errors'], [sprintf(
            'A %s is bound but nothing binds %s, so a signed download has no scoped way to resolve a file '
            . '— and resolving by id alone reads any file whose id leaks. Install a repository backend that '
            . 'ships one (rasuvaeff/yii3-filestorage-db does), or unbind the scope provider if this '
            . 'installation is not multi-tenant.',
            FileScopeProviderInterface::class,
            ScopedFileResolverInterface::class,
        )]);
        Assert::same($decoded['warnings'], [
            'RepositoryInterface is bound to Test\MemoryRepository: every file record is lost when '
            . 'the process ends. Install rasuvaeff/yii3-filestorage-db before using this anywhere real.',
            'Tenancy is on, so `filestorage:gc --orphans` refuses and `filestorage:stat` withholds its '
            . 'physical figures: both compare tenant-filtered rows against unfiltered objects. Run them from a '
            . 'maintenance entry point that leaves the scope provider unbound.',
        ]);
        Assert::same($decoded['stores'][0]['name'] ?? null, 'memory');
    }

    /**
     * A scope provider that has no current tenant is still multi-tenant — the
     * report must say whose scope it looked at, and "(none)" is the answer.
     */
    public function aScopeWithoutACurrentTenantIsReportedAsNone(): void
    {
        $scopes = Understudy::for(FileScopeProviderInterface::class);
        when(static fn(): ?string => $scopes->currentScopeId())->returns(null);

        $display = $this->run(
            scopes: $scopes,
            scopedFiles: Understudy::for(ScopedFileResolverInterface::class),
        )->getDisplay();

        Assert::string($display)->contains('current scope (none).');
        Assert::same($this->run(
            scopes: $scopes,
            scopedFiles: Understudy::for(ScopedFileResolverInterface::class),
        )->getStatusCode(), Command::SUCCESS);
    }

    /**
     * The default store is marked as such. With several stores configured, the
     * one an `add()` without a name lands in is the single most useful fact in
     * the report.
     */
    public function onlyTheDefaultStoreIsMarkedAsDefault(): void
    {
        $display = $this->run(store: new FileSystemStore('upload', $this->root, $this->factory))->getDisplay();

        Assert::string($display)->contains('upload (default)');
        Assert::same(substr_count($display, '(default)'), 1);
    }

    public function listsEachStoreWithTheCapabilitiesItActuallyHas(): void
    {
        $display = $this->run(store: new FileSystemStore('upload', $this->root, $this->factory))->getDisplay();

        Assert::string($display)->contains('upload (default)');
        Assert::string($display)->contains('range');
        Assert::string($display)->contains('maintenance');
        Assert::string($display)->contains('derivatives');
        Assert::string($display)->notContains('urls');
    }

    public function aPublicStoreAdvertisesUrlSupport(): void
    {
        $store = new PublicFileSystemStore(
            new FileSystemStore('assets', $this->root, $this->factory),
            'https://cdn.example.com',
        );

        Assert::string($this->run(store: $store)->getDisplay())->contains('urls');
    }

    /**
     * A store that supports nothing optional is a legitimate configuration, and
     * the report has to say so rather than leaving the column blank. Asserting
     * a capability the store *does* have proves nothing about that branch —
     * `Test\InMemoryStore` implements maintenance, so the old assertion passed
     * either way.
     */
    public function aBaseOnlyStoreIsReportedAsSuch(): void
    {
        $bare = Understudy::for(StoreInterface::class);
        when(static fn(): string => $bare->name())->returns('bare');

        $display = $this->run(store: $bare)->getDisplay();

        Assert::string($display)->contains('base only');
        Assert::string($display)->notContains('maintenance');
    }

    /**
     * The in-memory repository is fine for a first run and catastrophic in
     * production, so the command says which one it is looking at.
     */
    public function theInMemoryRepositoryIsFlaggedAsDevelopmentOnly(): void
    {
        $tester = $this->run(repository: new MemoryRepository());

        Assert::same($tester->getStatusCode(), Command::SUCCESS, 'a warning, not an error');
        Assert::string($tester->getDisplay())->contains('lost when');
        Assert::string($tester->getDisplay())->contains('yii3-filestorage-db');
    }

    public function aRepositoryWithoutMaintenanceSupportIsFlagged(): void
    {
        $display = $this->flatten(
            $this->run(repository: Understudy::for(RepositoryInterface::class))->getDisplay(),
        );

        Assert::string($display)->contains(
            'The repository does not implement MaintenanceRepositoryInterface, so filestorage:verify, '
            . ':stat and :backfill-hash cannot run against it.',
        );
    }

    public function everyConfiguredGroupIsListedWithItsRules(): void
    {
        $display = $this->run(
            policies: new PolicyRegistry([
                'avatars' => new UploadPolicy(
                    allowedMimeTypes: ['image/png', 'image/webp'],
                    maxBytes: 5_000,
                    maxPixels: 250,
                ),
            ]),
            knownGroups: ['avatars'],
        )->getDisplay();

        Assert::string($display)->contains('avatars');
        Assert::string($display)->contains('image/png, image/webp', 'every accepted type, not just the first');
        Assert::string($display)->contains('5000 B');
        Assert::string($display)->contains('250 px');
        Assert::string($display)->contains('signed only');
    }

    /**
     * The wildcard is always reported, because it is what every group without
     * an entry of its own actually gets — and it is the one most likely to be
     * more permissive than the author remembers.
     */
    public function theWildcardGroupIsAlwaysReportedAndListedOnce(): void
    {
        $display = $this->run(knownGroups: ['avatars', 'avatars', 'documents'])->getDisplay();

        $groups = $this->groupColumn($display);

        Assert::same($groups, ['*', 'avatars', 'documents'], 'the wildcard first, no duplicates');
    }

    public function unlimitedRulesAreSpelledOutRatherThanShownAsZero(): void
    {
        $display = $this->run(
            policies: new PolicyRegistry(['*' => new UploadPolicy(maxBytes: 0, maxPixels: 0)]),
        )->getDisplay();

        Assert::string($display)->contains('unlimited');
        Assert::string($display)->contains('anything');
        // Both columns, not just one: the size and pixel cells are spelled out
        // independently, and a test that matches the word once would pass with
        // either of the two broken.
        Assert::same(substr_count($display, 'unlimited'), 2);
    }

    /**
     * An error, not a warning: a group handing out permanent public URLs while
     * accepting anything turns your own origin into a host for whatever gets
     * uploaded, and a warning in a command nobody runs twice is not a control.
     */
    public function directPublicUrlsWithAnOpenAllowListAreAnError(): void
    {
        $tester = $this->run(
            deliveryPolicies: new DeliveryPolicyRegistry([
                '*' => new DeliveryPolicy(allowDirectPublicUrl: true),
            ]),
        );

        Assert::same($tester->getStatusCode(), Command::FAILURE);
        Assert::string($this->flatten($tester->getDisplay()))->contains(
            'Group "*" allows direct public URLs while accepting any media type. '
            . 'Set an explicit allowedMimeTypes list that excludes active content, or set '
            . 'allowDirectPublicUrl to false.',
        );
    }

    /**
     * HTML, SVG and XML execute in a browser when served from your own origin —
     * the stored-XSS primitive this check exists for.
     */
    public function directPublicUrlsWithActiveContentAreAnError(): void
    {
        $tester = $this->run(
            policies: new PolicyRegistry([
                'assets' => new UploadPolicy(allowedMimeTypes: ['image/png', 'image/svg+xml']),
            ]),
            deliveryPolicies: new DeliveryPolicyRegistry([
                'assets' => new DeliveryPolicy(allowDirectPublicUrl: true),
            ]),
            knownGroups: ['assets'],
        );

        Assert::same($tester->getStatusCode(), Command::FAILURE);
        Assert::string($this->flatten($tester->getDisplay()))->contains(
            'Group "assets" allows direct public URLs and accepts image/svg+xml. '
            . 'Served from your own origin these execute as your site. Remove them from allowedMimeTypes, '
            . 'or set allowDirectPublicUrl to false so delivery goes through a route that forces a download.',
        );
    }

    /**
     * The decorated report is the whole stdout in text mode — a machine object
     * leaking into it would break every human reader and every tool that
     * scrapes the output.
     */
    public function textModeEmitsNoMachineObject(): void
    {
        $tester = $this->run();

        Assert::false((bool) preg_match('/\A\{.*\}\z/s', trim($tester->getDisplay())));
        Assert::false(str_contains($tester->getDisplay(), '"ok"'), 'no JSON keys in text mode');
    }

    /**
     * Review 1.0 C5: the list `check` used knew five types. JavaScript, XSLT
     * and feed types execute as well, and now come from the one family list.
     */
    public function directPublicUrlsWithScriptAreAnError(): void
    {
        $tester = $this->run(
            policies: new PolicyRegistry([
                'assets' => new UploadPolicy(allowedMimeTypes: ['image/png', 'text/javascript', 'application/rss+xml']),
            ]),
            deliveryPolicies: new DeliveryPolicyRegistry([
                'assets' => new DeliveryPolicy(allowDirectPublicUrl: true),
            ]),
            knownGroups: ['assets'],
        );

        Assert::same($tester->getStatusCode(), Command::FAILURE);
        Assert::string($this->flatten($tester->getDisplay()))->contains(
            'Group "assets" allows direct public URLs and accepts text/javascript, application/rss+xml.',
        );
    }

    /**
     * PDF is in the active list `-web` forces to download, but it runs in a
     * plugin, not as the origin: a public PDF is a legitimate publication and
     * not the stored XSS this gate exists for.
     */
    public function directPublicUrlsForPdfArePermitted(): void
    {
        $tester = $this->run(
            policies: new PolicyRegistry([
                'documents' => new UploadPolicy(allowedMimeTypes: ['application/pdf']),
            ]),
            deliveryPolicies: new DeliveryPolicyRegistry([
                'documents' => new DeliveryPolicy(allowDirectPublicUrl: true),
            ]),
            knownGroups: ['documents'],
        );

        Assert::same($tester->getStatusCode(), Command::SUCCESS);
    }

    /**
     * Review 1.0 B5: a store that serves objects at public URLs serves them by
     * path whatever `allowDirectPublicUrl` says — that flag only chooses which
     * URL the application prints. A permissive group next to such a store is
     * stored XSS for anyone who can compute a content-addressed path.
     */
    public function aPublicStoreMakesAPermissiveGroupAnErrorEvenWithoutDirectUrls(): void
    {
        $tester = $this->run(store: $this->publicStore());

        Assert::same($tester->getStatusCode(), Command::FAILURE);
        Assert::string($this->flatten($tester->getDisplay()))->contains(
            'Group "*" accepts any media type, and store "assets" serves every object it holds at a public URL '
            . 'whatever the delivery policy says. Set an explicit allowedMimeTypes list that excludes active '
            . 'content, or keep this group out of public stores.',
        );
    }

    public function aPublicStoreMakesAScriptableAllowListAnError(): void
    {
        $tester = $this->run(
            store: $this->publicStore(),
            policies: new PolicyRegistry([
                '*' => new UploadPolicy(allowedMimeTypes: ['image/png']),
                'assets' => new UploadPolicy(allowedMimeTypes: ['image/png', 'image/svg+xml']),
            ]),
            knownGroups: ['assets'],
        );

        Assert::same($tester->getStatusCode(), Command::FAILURE);
        Assert::string($this->flatten($tester->getDisplay()))->contains(
            'Group "assets" accepts image/svg+xml, and store "assets" serves every object it holds at a public URL',
        );
    }

    public function aPublicStoreWithInertAllowListsIsSound(): void
    {
        $tester = $this->run(
            store: $this->publicStore(),
            policies: new PolicyRegistry(['*' => new UploadPolicy(allowedMimeTypes: ['image/png', 'image/jpeg'])]),
        );

        Assert::same($tester->getStatusCode(), Command::SUCCESS);
    }

    /**
     * Answering `publicUrl()` is not being public: a Flysystem S3 store builds
     * an object URL for a private bucket too, and that URL answers 403. Only a
     * store that lives under a web root by definition counts.
     */
    public function aUrlCapableStoreIsNotTreatedAsPublicMerelyForAnsweringPublicUrl(): void
    {
        $store = new UrlCapableInMemoryStore(new InMemoryStore('memory', $this->factory), publicUrls: true);

        Assert::same($this->run(store: $store)->getStatusCode(), Command::SUCCESS);
    }

    public function directPublicUrlsWithAnInertAllowListArePermitted(): void
    {
        $tester = $this->run(
            policies: new PolicyRegistry([
                'assets' => new UploadPolicy(allowedMimeTypes: ['image/png', 'image/jpeg']),
            ]),
            deliveryPolicies: new DeliveryPolicyRegistry([
                'assets' => new DeliveryPolicy(allowDirectPublicUrl: true),
            ]),
            knownGroups: ['assets'],
        );

        Assert::same($tester->getStatusCode(), Command::SUCCESS);
        Assert::string($tester->getDisplay())->contains('direct public URL');
    }

    private function publicStore(): PublicFileSystemStore
    {
        return new PublicFileSystemStore(
            new FileSystemStore('assets', $this->root, $this->factory),
            'https://cdn.example.com',
        );
    }

    /**
     * Squeezes the wrapping and the gutter SymfonyStyle adds to a block out, so
     * a whole sentence can be asserted as one string.
     */
    private function flatten(string $display): string
    {
        return trim((string) preg_replace('/[\s!]+/u', ' ', $display));
    }

    /**
     * The first column of the Groups table, in the order it was printed.
     *
     * @return list<string>
     */
    private function groupColumn(string $display): array
    {
        $groups = [];
        $inTable = false;

        foreach (explode("\n", $display) as $line) {
            if (str_contains($line, 'Group ') && str_contains($line, 'Accepts')) {
                $inTable = true;

                continue;
            }
            if (!$inTable || !str_contains($line, 'signed only') && !str_contains($line, 'direct public URL')) {
                continue;
            }

            $cells = preg_split('/\s{2,}/', trim($line)) ?: [];
            if ($cells !== [] && $cells[0] !== '') {
                $groups[] = $cells[0];
            }
        }

        return $groups;
    }

    /**
     * §5.7's promise, which nothing enforced until now: a signed download
     * resolves through `ScopedFileResolverInterface` and *only* through it, so
     * an installation that established a tenant scope without binding one has
     * no scoped way in. `-web` requires the resolver outright, so the symptom
     * was a container error on the first download naming an interface — this
     * names the missing package instead, before anything is deployed.
     */
    public function tenantModeWithoutAScopedResolverIsAnError(): void
    {
        $tester = $this->run(scopes: $this->tenantScope());

        Assert::same($tester->getStatusCode(), Command::FAILURE);
        Assert::string((string) preg_replace('/[\s!\[\]]+/u', ' ', $tester->getDisplay()))
            ->contains('is bound but nothing binds');
        Assert::string((string) preg_replace('/[\s!\[\]]+/u', ' ', $tester->getDisplay()))
            ->contains('current scope tenant-a');
    }

    public function tenantModeWithBothSidesBoundIsSound(): void
    {
        $tester = $this->run(
            scopes: $this->tenantScope(),
            scopedFiles: Understudy::for(ScopedFileResolverInterface::class),
        );

        Assert::same($tester->getStatusCode(), Command::SUCCESS);
        Assert::string($tester->getDisplay())->contains('Multi-tenant');
    }

    /**
     * The maintenance commands behave differently under tenancy, and the report
     * that tells you your wiring is sound is the place to learn that — not the
     * refusal three weeks later.
     */
    public function tenantModeWarnsThatTheMaintenanceCommandsChange(): void
    {
        $display = (string) preg_replace('/[\s!\[\]]+/u', ' ', $this->run(
            scopes: $this->tenantScope(),
            scopedFiles: Understudy::for(ScopedFileResolverInterface::class),
        )->getDisplay());

        Assert::string($display)->contains('gc --orphans` refuses and `filestorage:stat` withholds');
    }

    /**
     * A resolver with no scope provider is the ordinary single-tenant case:
     * `-db` binds one unconditionally. Treating that as a misconfiguration
     * would fail every installation that simply is not multi-tenant.
     */
    public function aResolverWithoutAScopeProviderIsNotAnError(): void
    {
        $tester = $this->run(scopedFiles: Understudy::for(ScopedFileResolverInterface::class));

        Assert::same($tester->getStatusCode(), Command::SUCCESS);
        Assert::string($tester->getDisplay())->contains('Single-scope');
    }

    /**
     * A tenant that never changes. Binding one at all is what makes an
     * installation multi-tenant as far as this package is concerned — which is
     * the condition the tenant-wiring gate looks for.
     */
    private function tenantScope(): FileScopeProviderInterface
    {
        $scopes = Understudy::for(FileScopeProviderInterface::class);
        when(static fn(): ?string => $scopes->currentScopeId())->returns('tenant-a');

        return $scopes;
    }

    /**
     * @param list<non-empty-string> $knownGroups
     */
    public function aStoreWithoutAQuarantineIsAWarningNotAnError(): void
    {
        $tester = $this->run(store: new FileSystemStore('upload', $this->root, $this->factory));

        Assert::same($tester->getStatusCode(), Command::SUCCESS);
        Assert::string((string) preg_replace('/[\s!]+/u', ' ', $tester->getDisplay()))->contains(
            'Store "upload" has no quarantine directory, so filestorage:gc cannot delete from it.',
        );
    }

    public function aUsableQuarantineIsNotMentioned(): void
    {
        $tester = $this->run(store: new FileSystemStore(
            'upload',
            $this->root . '/root',
            $this->factory,
            quarantinePath: $this->root . '/quarantine',
        ));

        Assert::false(str_contains($tester->getDisplay(), 'quarantine'));
    }

    // ---- review 1.0 A1, C20 -------------------------------------------------

    public function aPathGeneratorTheLayoutCannotDeleteIsAnError(): void
    {
        $paths = new class implements PathGeneratorInterface {
            #[Override]
            public function generate(string $groupName, Upload $upload, ?string $mediaType): string
            {
                return $groupName . '/' . bin2hex(random_bytes(8)) . '.jpg';
            }
        };

        $tester = $this->run(paths: $paths);

        Assert::same($tester->getStatusCode(), Command::FAILURE);
        Assert::string($this->flatten($tester->getDisplay()))->contains('Store "memory": Path generator');
    }

    public function flatContentKeysOnAStoreWithTheDirectoryLayoutAreAnError(): void
    {
        $tester = $this->run(
            store: new FileSystemStore('upload', $this->root, $this->factory),
            contentKeys: new FlatSha256KeyGenerator(prefix: 'blobs'),
        );

        Assert::same($tester->getStatusCode(), Command::FAILURE);
        Assert::string($this->flatten($tester->getDisplay()))->contains('Store "upload": Content-addressed key generator');
    }

    public function flatContentKeysOnAFlatStoreAreSound(): void
    {
        $tester = $this->run(
            store: new FileSystemStore('upload', $this->root, $this->factory, layout: new FlatSiblingLayout()),
            contentKeys: new FlatSha256KeyGenerator(prefix: 'blobs'),
        );

        Assert::same($tester->getStatusCode(), Command::SUCCESS);
    }

    /**
     * Deduplication writes to one store; a second local store with the default
     * layout — private attachments next to a flat public tree — is only a
     * warning, not a failed check.
     */
    public function aContentKeyMismatchOnANonDefaultStoreIsAWarning(): void
    {
        $command = new CheckCommand(
            stores: new StoreRegistry([
                new FileSystemStore('public', $this->root . '/public', $this->factory, layout: new FlatSiblingLayout()),
                new FileSystemStore('private', $this->root . '/private', $this->factory),
            ]),
            repository: new MemoryRepository(),
            policies: new PolicyRegistry(),
            deliveryPolicies: new DeliveryPolicyRegistry(),
            contentKeys: new FlatSha256KeyGenerator(prefix: 'blobs'),
        );
        $tester = new CommandTester($command);
        $tester->execute([]);

        Assert::same($tester->getStatusCode(), Command::SUCCESS);
        Assert::string($this->flatten($tester->getDisplay()))->contains('Store "private": Content-addressed key generator');
        Assert::string($this->flatten($tester->getDisplay()))->contains('only a problem if deduplication writes to this store');
    }

    public function withoutABoundGeneratorTheDedupDefaultIsProbed(): void
    {
        Assert::same(
            $this->run(store: new FileSystemStore('upload', $this->root, $this->factory))->getStatusCode(),
            Command::SUCCESS,
        );
    }

    public function aPublicStoreOwningItsWholeRootIsAWarning(): void
    {
        $tester = $this->run(store: $this->publicStore());

        Assert::string($this->flatten($tester->getDisplay()))->contains('serves its root publicly and owns all of it');
    }

    public function aPublicStoreWhoseQuarantineCannotBeCheckedAgainstTheWebRootIsAWarning(): void
    {
        $store = new PublicFileSystemStore(
            new FileSystemStore('upload', $this->root . '/public/uploads', $this->factory, quarantinePath: $this->root . '/q'),
            'https://cdn.example.test',
        );

        Assert::string($this->flatten($this->run(store: $store)->getDisplay()))->contains('Pass webRoot to PublicFileSystemStore');
    }

    public function aPublicStoreWithAWebRootIsNotWarnedAbout(): void
    {
        $store = new PublicFileSystemStore(
            new FileSystemStore('upload', $this->root . '/public/uploads', $this->factory, quarantinePath: $this->root . '/q'),
            'https://cdn.example.test',
            webRoot: $this->root . '/public',
        );

        Assert::false(str_contains($this->flatten($this->run(store: $store)->getDisplay()), 'Pass webRoot'));
    }

    public function aPublicStoreWithOwnedPrefixesIsQuiet(): void
    {
        $tester = $this->run(store: new PublicFileSystemStore(
            new FileSystemStore('upload', $this->root, $this->factory, ownedPrefixes: ['blobs']),
            'https://cdn.example.test',
        ));

        Assert::false(str_contains($tester->getDisplay(), 'owns all of it'));
    }

    public function theShippedPathGeneratorFitsTheDefaultLayout(): void
    {
        Assert::same($this->run(paths: new RandomPathGenerator())->getStatusCode(), Command::SUCCESS);
    }

    public function everyStoreShowsItsLayout(): void
    {
        $tester = $this->run(store: new InMemoryStore('memory', $this->factory, layout: new FlatSiblingLayout()), json: true);

        $decoded = json_decode($tester->getDisplay(), associative: true, flags: \JSON_THROW_ON_ERROR);
        Assert::true(\is_array($decoded));
        Assert::same($decoded['stores'][0]['layout'] ?? null, FlatSiblingLayout::class);
        Assert::string($this->run(store: new InMemoryStore('memory', $this->factory, layout: new FlatSiblingLayout()))->getDisplay())
            ->contains('FlatSiblingLayout');
    }

    public function anApplicationOwnedGroupWithoutACheckerIsAWarning(): void
    {
        $policies = new PolicyRegistry(['avatars' => new UploadPolicy(
            allowedMimeTypes: ['image/png'],
            referencesOwnedBy: ReferenceAuthority::Application,
        )]);

        $tester = $this->run(policies: $policies, knownGroups: ['avatars']);

        Assert::same($tester->getStatusCode(), Command::SUCCESS);
        Assert::string($this->flatten($tester->getDisplay()))
            ->contains('Group "avatars" hands references to the application, but no');
    }

    public function anApplicationOwnedGroupWithACheckerIsQuiet(): void
    {
        $policies = new PolicyRegistry(['avatars' => new UploadPolicy(
            allowedMimeTypes: ['image/png'],
            referencesOwnedBy: ReferenceAuthority::Application,
        )]);

        $tester = $this->run(
            policies: $policies,
            knownGroups: ['avatars'],
            references: Understudy::for(ReferenceCheckerInterface::class),
        );

        Assert::false(str_contains($tester->getDisplay(), 'hands references'));
    }

    private function run(
        ?StoreInterface $store = null,
        ?RepositoryInterface $repository = null,
        ?PolicyRegistry $policies = null,
        ?DeliveryPolicyRegistry $deliveryPolicies = null,
        array $knownGroups = [],
        ?FileScopeProviderInterface $scopes = null,
        ?ScopedFileResolverInterface $scopedFiles = null,
        ?ContentAddressedKeyGeneratorInterface $contentKeys = null,
        ?PathGeneratorInterface $paths = null,
        ?ReferenceCheckerInterface $references = null,
        bool $json = false,
    ): CommandTester {
        $command = new CheckCommand(
            stores: new StoreRegistry([$store ?? new InMemoryStore('memory', $this->factory)]),
            repository: $repository ?? new MemoryRepository(),
            policies: $policies ?? new PolicyRegistry(),
            deliveryPolicies: $deliveryPolicies ?? new DeliveryPolicyRegistry(),
            knownGroups: $knownGroups,
            scopes: $scopes,
            scopedFiles: $scopedFiles,
            contentKeys: $contentKeys,
            paths: $paths,
            streams: $paths instanceof \Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface ? $this->factory : null,
            references: $references,
        );

        $tester = new CommandTester($command);
        $tester->execute($json ? ['--format' => 'json'] : []);

        return $tester;
    }
}
