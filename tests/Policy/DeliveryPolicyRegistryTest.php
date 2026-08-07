<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Policy;

use InvalidArgumentException;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicy;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(DeliveryPolicyRegistry::class)]
final class DeliveryPolicyRegistryTest
{
    public function resolvesAGroupsOwnPolicy(): void
    {
        $public = new DeliveryPolicy(allowDirectPublicUrl: true);
        $registry = new DeliveryPolicyRegistry(['assets' => $public]);

        Assert::same($registry->for('assets'), $public);
    }

    public function anUnknownGroupGetsTheWildcard(): void
    {
        $fallback = new DeliveryPolicy(allowDirectPublicUrl: true);
        $registry = new DeliveryPolicyRegistry(['*' => $fallback]);

        Assert::same($registry->for('anything'), $fallback);
    }

    /**
     * The default that matters: a permanent public URL bypasses every response
     * header the download route enforces, so it is off unless somebody says so.
     */
    public function theDefaultRefusesDirectPublicUrlsAndForcesDownload(): void
    {
        $policy = (new DeliveryPolicyRegistry())->for('anything');

        Assert::false($policy->allowDirectPublicUrl);
        Assert::true($policy->forceDownload);
    }

    public function fromArrayBuildsPoliciesFromParams(): void
    {
        $registry = DeliveryPolicyRegistry::fromArray([
            'assets' => ['allowDirectPublicUrl' => true, 'forceDownload' => false],
            '*' => ['allowDirectPublicUrl' => false],
        ]);

        Assert::true($registry->for('assets')->allowDirectPublicUrl);
        Assert::false($registry->for('assets')->forceDownload);
        Assert::false($registry->for('other')->allowDirectPublicUrl);
        Assert::true($registry->for('other')->forceDownload, 'forceDownload defaults to on');
    }

    /**
     * A misspelled or mistyped value must leave the safe behaviour in place,
     * never the permissive one.
     */
    #[DataProvider('nonTrueProvider')]
    public function anythingButALiteralTrueLeavesDirectPublicUrlsOff(mixed $value): void
    {
        $registry = DeliveryPolicyRegistry::fromArray(['g' => ['allowDirectPublicUrl' => $value]]);

        Assert::false($registry->for('g')->allowDirectPublicUrl);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonTrueProvider(): iterable
    {
        yield 'the string true' => ['true'];
        yield 'one' => [1];
        yield 'yes' => ['yes'];
        yield 'null' => [null];
    }

    #[DataProvider('nonFalseProvider')]
    public function onlyALiteralFalseTurnsOffForcedDownload(mixed $value): void
    {
        $registry = DeliveryPolicyRegistry::fromArray(['g' => ['forceDownload' => $value]]);

        Assert::true($registry->for('g')->forceDownload);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonFalseProvider(): iterable
    {
        yield 'the string false' => ['false'];
        yield 'zero' => [0];
        yield 'null' => [null];
    }

    #[DataProvider('invalidConfigProvider')]
    public function fromArrayRejectsAMalformedConfiguration(string $message, mixed $config): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining($message);

        /** @var array<array-key, mixed> $config */
        DeliveryPolicyRegistry::fromArray($config);
    }

    /**
     * @return iterable<string, array{string, array<array-key, mixed>}>
     */
    public static function invalidConfigProvider(): iterable
    {
        yield 'integer group name' => ['group names must be non-empty strings', [0 => []]];
        yield 'empty group name' => ['group names must be non-empty strings', ['' => []]];
        yield 'policy is not an array' => ['must be an array', ['g' => true]];
    }
}
