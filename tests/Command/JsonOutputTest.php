<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Command;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\Command\CheckCommand;
use Rasuvaeff\Yii3Filestorage\Command\GcCommand;
use Rasuvaeff\Yii3Filestorage\Command\Json;
use Rasuvaeff\Yii3Filestorage\Command\StatCommand;
use Rasuvaeff\Yii3Filestorage\Command\VerifyCommand;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Test\MemoryRepository;
use Rasuvaeff\Yii3Filestorage\Upload;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Test\Support\Clock\StaticClock;

#[Test]
#[Covers(Json::class)]
#[Covers(StatCommand::class)]
#[Covers(VerifyCommand::class)]
#[Covers(GcCommand::class)]
final class JsonOutputTest
{
    private Psr17Factory $factory;
    private InMemoryStore $store;
    private MemoryRepository $repository;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->store = new InMemoryStore('memory', $this->factory, new StaticClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00')));
        $this->repository = new MemoryRepository();
    }

    /** stdout is exactly one JSON object — the whole point for a dashboard. */
    public function statEmitsOneJsonObject(): void
    {
        $this->storeFile('a', 'docs');
        $this->storeFile('b', 'docs');

        $decoded = $this->jsonOutput(new StatCommand($this->repository), ['--format' => 'json']);

        Assert::same($decoded['total'], ['files' => 2, 'bytes' => 10]);
        Assert::same($decoded['groups'], ['docs' => ['files' => 2, 'bytes' => 10]]);
        Assert::same($decoded['distinctObjects'], 2);
        Assert::same($decoded['sharedRows'], 0);
        Assert::false($decoded['scoped']);
        Assert::true(\is_array($decoded['log']));
    }

    public function statWithNothingStoredStillReports(): void
    {
        $decoded = $this->jsonOutput(new StatCommand($this->repository), ['--format' => 'json']);

        Assert::same($decoded['total'], ['files' => 0, 'bytes' => 0]);
        Assert::same($decoded['groups'], []);
    }

    public function verifyCountsItsProblems(): void
    {
        $this->storeFile('a', 'docs');
        // A row whose object is gone.
        $this->repository->save($this->row('ghost', 'docs'));

        $decoded = $this->jsonOutput(
            new VerifyCommand(new StoreRegistry([$this->store]), $this->repository),
            ['--format' => 'json'],
            $raw,
        );

        Assert::same($decoded['checked'], 2);
        Assert::same($decoded['missing'], 1);
        Assert::same($decoded['corrupt'], 0);
        Assert::same($decoded['problems'][0]['id'] ?? null, 'ghost');
        Assert::same($decoded['problems'][0]['problem'] ?? null, 'missing');
        // Unescaped slashes in the raw body — the encoding flags are part of
        // the output's shape.
        Assert::true((bool) str_contains($raw, 'docs/ab/cd/ghost'));
        Assert::false((bool) str_contains($raw, '\\\\/'));
    }

    public function checkReportsItsShape(): void
    {
        $this->storeFile('a', 'docs');

        $decoded = $this->jsonOutput(
            new CheckCommand(
                new StoreRegistry([$this->store]),
                $this->repository,
                new PolicyRegistry(),
                new DeliveryPolicyRegistry(),
            ),
            ['--format' => 'json'],
        );

        Assert::true($decoded['ok']);
        Assert::same($decoded['stores'][0]['name'] ?? null, 'memory');
        Assert::same($decoded['stores'][0]['class'] ?? null, InMemoryStore::class);
        Assert::true($decoded['stores'][0]['default'] ?? false);
        Assert::same($decoded['stores'][0]['capabilities'] ?? null, ['maintenance']);
        Assert::same($decoded['groups'][0]['group'] ?? null, '*');
    }

    /**
     * The enabled-checks are load-bearing in the other direction too: a
     * mutant that emits the object in text mode would break every consumer
     * parsing the human output.
     */
    public function textModeEmitsNoJson(): void
    {
        $this->storeFile('a', 'docs');
        $clock = new StaticClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        $commands = [
            new StatCommand($this->repository),
            new VerifyCommand(new StoreRegistry([$this->store]), $this->repository),
            new GcCommand(new StoreRegistry([$this->store]), $this->repository, $clock),
            new CheckCommand(new StoreRegistry([$this->store]), $this->repository, new PolicyRegistry(), new DeliveryPolicyRegistry()),
        ];

        foreach ($commands as $command) {
            $tester = new CommandTester($command);
            $tester->execute([]);
            Assert::false(
                (bool) preg_match('/\A\{.*\}\z/s', trim($tester->getDisplay())),
                $command::class . ' must not emit JSON without --format=json',
            );
        }
    }

    /** The scoped orphan-sweep refusal is an error object, not decorated text. */
    public function gcScopedRefusalIsAJsonError(): void
    {
        $decoded = $this->jsonOutput(
            new GcCommand(
                new StoreRegistry([$this->store]),
                $this->repository,
                new StaticClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00')),
                null,
                $this->tenantScope(),
            ),
            ['--format' => 'json', '--orphans' => true],
        );

        Assert::false($decoded['applied']);
        Assert::string($decoded['error'] ?? null);
        Assert::true((bool) str_contains((string) $decoded['error'], 'Refusing --orphans'));
        Assert::true(\is_array($decoded['log']));
    }

    public function gcReportsItsCounts(): void
    {
        $this->storeFile('a', 'docs');

        $decoded = $this->jsonOutput(
            new GcCommand(new StoreRegistry([$this->store]), $this->repository, new StaticClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'))),
            ['--format' => 'json'],
        );

        Assert::false($decoded['applied']);
        // No ledger and no --orphans: both halves are null ("not counted").
        Assert::null($decoded['blobsCollected']);
        Assert::null($decoded['orphanObjects']);
    }

    /**
     * The machine object is the whole stdout: a decorated note or a table on
     * the same stream would make the output unparseable. The raw display is
     * handed back too — escaping lives only in it, a decoded value cannot
     * see the difference.
     *
     * @param array<string, mixed>|null $raw
     *
     * @return array<string, mixed>
     */
    private function jsonOutput(Command $command, array $arguments, ?string &$raw = null): array
    {
        $tester = new CommandTester($command);
        $tester->execute($arguments);
        $raw = trim($tester->getDisplay());

        Assert::true((bool) preg_match('/\A\{.*\}\z/s', $raw), "stdout must be one JSON object, got: {$raw}");

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true);

        return $decoded;
    }

    private function tenantScope(): FileScopeProviderInterface
    {
        $scopes = \Rasuvaeff\Understudy\Understudy::for(FileScopeProviderInterface::class);
        \Rasuvaeff\Understudy\when(static fn(): ?string => $scopes->currentScopeId())->returns('tenant-a');

        return $scopes;
    }

    private function storeFile(string $id, string $group): File
    {
        $result = $this->store->write(
            Upload::fromStream($this->factory->createStream('hello'), 'a.txt', $this->factory),
            $group,
            new \Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator(),
        );
        $file = File::create(
            id: $id,
            storeName: 'memory',
            groupName: $group,
            relativePath: $result->relativePath,
            originalName: 'a.txt',
            size: 5,
            createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
        $this->repository->save($file);

        return $file;
    }

    private function row(string $id, string $group): File
    {
        return File::create(
            id: $id,
            storeName: 'memory',
            groupName: $group,
            relativePath: 'docs/ab/cd/ghost',
            originalName: 'g.txt',
            size: 5,
            createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
    }
}
