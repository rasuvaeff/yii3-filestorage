<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Command;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\Command\CheckCommand;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicy;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\UploadPolicy;
use Rasuvaeff\Yii3Filestorage\Repository\RepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Store\FileSystem\FileSystemStore;
use Rasuvaeff\Yii3Filestorage\Store\FileSystem\PublicFileSystemStore;
use Rasuvaeff\Yii3Filestorage\Store\StoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Test\MemoryRepository;
use Rasuvaeff\Yii3Filestorage\Tests\Support\FailingRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Files\FileHelper;

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
     * the report has to say so rather than leaving the column blank.
     */
    public function aBaseOnlyStoreIsReportedAsSuch(): void
    {
        Assert::string($this->run()->getDisplay())->contains('maintenance');
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
        $display = $this->run(repository: new FailingRepository())->getDisplay();

        Assert::string($display)->contains('MaintenanceRepositoryInterface');
    }

    public function everyConfiguredGroupIsListedWithItsRules(): void
    {
        $display = $this->run(
            policies: new PolicyRegistry([
                'avatars' => new UploadPolicy(allowedMimeTypes: ['image/png'], maxBytes: 5_000),
            ]),
            knownGroups: ['avatars'],
        )->getDisplay();

        Assert::string($display)->contains('avatars');
        Assert::string($display)->contains('image/png');
        Assert::string($display)->contains('5000 B');
        Assert::string($display)->contains('signed only');
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
     * @param list<non-empty-string> $knownGroups
     */
    private function run(
        ?StoreInterface $store = null,
        ?RepositoryInterface $repository = null,
        ?PolicyRegistry $policies = null,
        ?DeliveryPolicyRegistry $deliveryPolicies = null,
        array $knownGroups = [],
    ): CommandTester {
        $command = new CheckCommand(
            stores: new StoreRegistry([$store ?? new InMemoryStore('memory', $this->factory)]),
            repository: $repository ?? new MemoryRepository(),
            policies: $policies ?? new PolicyRegistry(),
            deliveryPolicies: $deliveryPolicies ?? new DeliveryPolicyRegistry(),
            knownGroups: $knownGroups,
        );

        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }
}
