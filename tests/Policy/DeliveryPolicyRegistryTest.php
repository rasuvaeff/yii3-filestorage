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
     * A value that is not a boolean is a mistake, and the mistake is reported
     * rather than absorbed. Silently reading `'true'` as false gives an
     * operator the opposite of what they wrote — and `allowDirectPublicUrl` is
     * the setting where the opposite matters most. A *missing* key still takes
     * the safe default; it is the present-but-wrong one that stops the boot.
     */
    #[DataProvider('nonBooleanProvider')]
    public function aNonBooleanValueIsRejectedRatherThanCoerced(mixed $value): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('must be a boolean');

        DeliveryPolicyRegistry::fromArray(['g' => ['allowDirectPublicUrl' => $value]]);
    }

    #[DataProvider('nonBooleanProvider')]
    public function forceDownloadIsRejectedTheSameWay(mixed $value): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('must be a boolean');

        DeliveryPolicyRegistry::fromArray(['g' => ['forceDownload' => $value]]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonBooleanProvider(): iterable
    {
        yield 'the string true' => ['true'];
        yield 'the string false' => ['false'];
        yield 'one' => [1];
        yield 'zero' => [0];
        yield 'yes' => ['yes'];
    }

    /**
     * Null is the one non-boolean that means "not set": it reaches the `??`
     * and takes the default, which is what an unset key does.
     */
    public function anExplicitNullTakesTheSafeDefault(): void
    {
        $registry = DeliveryPolicyRegistry::fromArray([
            'g' => ['allowDirectPublicUrl' => null, 'forceDownload' => null],
        ]);

        Assert::false($registry->for('g')->allowDirectPublicUrl);
        Assert::true($registry->for('g')->forceDownload);
    }

    /**
     * An option nobody implements is a setting that does nothing, and an
     * operator who typed `allowDirectPublicURL` would never find out.
     */
    public function anUnknownOptionIsRejected(): void
    {
        Expect::exception(InvalidArgumentException::class)
            ->withMessageContaining('Unknown delivery policy option "allowDirectPublicURL"');

        DeliveryPolicyRegistry::fromArray(['g' => ['allowDirectPublicURL' => true]]);
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
