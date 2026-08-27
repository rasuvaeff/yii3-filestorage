<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Command;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Filestorage\Command\CheckCommand;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicy;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\UploadPolicy;
use Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface;
use Rasuvaeff\Yii3Filestorage\Repository\RepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Repository\ScopedFileResolverInterface;
use Rasuvaeff\Yii3Filestorage\Store\FileSystem\FileSystemStore;
use Rasuvaeff\Yii3Filestorage\Store\FileSystem\PublicFileSystemStore;
use Rasuvaeff\Yii3Filestorage\Store\StoreInterface;
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

        foreach (['Stores', 'Metadata repository', 'Groups'] as $section) {
            Assert::string($display)->contains($section);
        }

        Assert::string($display)->contains(FileSystemStore::class, 'the store class is named');
        Assert::string($display)->contains(MemoryRepository::class, 'the repository class is named');
        Assert::string($display)->contains('Capabilities');
        Assert::string($display)->contains('Max pixels');
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
        $display = $this->run(repository: Understudy::for(RepositoryInterface::class))->getDisplay();

        Assert::string($display)->contains('MaintenanceRepositoryInterface');
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
        Assert::string($tester->getDisplay())->contains('any media type');
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
        Assert::string($tester->getDisplay())->contains('image/svg+xml');
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
    private function run(
        ?StoreInterface $store = null,
        ?RepositoryInterface $repository = null,
        ?PolicyRegistry $policies = null,
        ?DeliveryPolicyRegistry $deliveryPolicies = null,
        array $knownGroups = [],
        ?FileScopeProviderInterface $scopes = null,
        ?ScopedFileResolverInterface $scopedFiles = null,
    ): CommandTester {
        $command = new CheckCommand(
            stores: new StoreRegistry([$store ?? new InMemoryStore('memory', $this->factory)]),
            repository: $repository ?? new MemoryRepository(),
            policies: $policies ?? new PolicyRegistry(),
            deliveryPolicies: $deliveryPolicies ?? new DeliveryPolicyRegistry(),
            knownGroups: $knownGroups,
            scopes: $scopes,
            scopedFiles: $scopedFiles,
        );

        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }
}
