<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Policy;

use InvalidArgumentException;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\UploadPolicy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(PolicyRegistry::class)]
final class PolicyRegistryTest
{
    public function resolvesAGroupsOwnPolicy(): void
    {
        $avatars = new UploadPolicy(allowedMimeTypes: ['image/png']);
        $registry = new PolicyRegistry(['avatars' => $avatars]);

        Assert::same($registry->for('avatars'), $avatars);
    }

    public function anUnknownGroupGetsTheWildcard(): void
    {
        $fallback = new UploadPolicy(maxBytes: 99);
        $registry = new PolicyRegistry(['avatars' => new UploadPolicy(), '*' => $fallback]);

        Assert::same($registry->for('anything-else'), $fallback);
    }

    /**
     * A permissive default is deliberate: the mechanism has to exist from the
     * first release, because adding acceptance rules later turns uploads that
     * used to succeed into failures.
     */
    public function withoutAWildcardTheDefaultIsPermissive(): void
    {
        $policy = (new PolicyRegistry())->for('anything');

        Assert::same($policy->allowedMimeTypes, []);
        Assert::same($policy->maxBytes, 0);
    }

    public function theWildcardIsNotReachableAsAGroupName(): void
    {
        $fallback = new UploadPolicy(maxBytes: 99);
        $registry = new PolicyRegistry(['*' => $fallback]);

        Assert::same($registry->for('*'), $fallback);
    }

    public function fromArrayBuildsPoliciesFromParams(): void
    {
        $registry = PolicyRegistry::fromArray([
            'avatars' => [
                'allowedMimeTypes' => ['image/png', 'image/jpeg'],
                'maxBytes' => 5_000,
                'maxPixels' => 100,
                'requireImageDimensions' => true,
            ],
            '*' => ['maxBytes' => 1_000],
        ]);

        $avatars = $registry->for('avatars');
        Assert::same($avatars->allowedMimeTypes, ['image/png', 'image/jpeg']);
        Assert::same($avatars->maxBytes, 5_000);
        Assert::same($avatars->maxPixels, 100);
        Assert::true($avatars->requireImageDimensions);

        Assert::same($registry->for('other')->maxBytes, 1_000);
    }

    public function fromArrayFillsInDefaultsForAbsentKeys(): void
    {
        $policy = PolicyRegistry::fromArray(['g' => []])->for('g');
        $defaults = new UploadPolicy();

        Assert::same($policy->allowedMimeTypes, []);
        Assert::same($policy->maxBytes, $defaults->maxBytes);
        Assert::same($policy->maxPixels, $defaults->maxPixels);
        Assert::false($policy->requireImageDimensions);
    }

    public function fromArrayAcceptsAnEmptyConfiguration(): void
    {
        Assert::same(PolicyRegistry::fromArray([])->for('g')->maxBytes, 0);
    }

    /**
     * A typo in `params` must fail loudly at build time rather than silently
     * widening what a group accepts.
     */
    #[DataProvider('invalidConfigProvider')]
    public function fromArrayRejectsAMalformedConfiguration(string $message, mixed $config): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining($message);

        /** @var array<array-key, mixed> $config */
        PolicyRegistry::fromArray($config);
    }

    /**
     * @return iterable<string, array{string, array<array-key, mixed>}>
     */
    public static function invalidConfigProvider(): iterable
    {
        yield 'integer group name' => ['group names must be non-empty strings', [0 => []]];
        yield 'empty group name' => ['group names must be non-empty strings', ['' => []]];
        yield 'policy is not an array' => ['must be an array', ['g' => 'nope']];
        yield 'allow-list is not a list' => ['must be a list', ['g' => ['allowedMimeTypes' => 'image/png']]];
        yield 'allow-list holds a number' => ['must contain strings', ['g' => ['allowedMimeTypes' => [1]]]];
        yield 'maxBytes is a string' => ['maxBytes for group "g" must be an integer', ['g' => ['maxBytes' => '10']]];
        yield 'maxPixels is a float' => ['maxPixels for group "g" must be an integer', ['g' => ['maxPixels' => 1.5]]];
    }

    /**
     * A boolean option takes a boolean. `1` is not `true` here: coercing it
     * would let a configuration that reads as "on" behave as "on" by accident
     * rather than by contract, and the next value somebody writes — `'yes'`,
     * `'false'` — would land on whichever side PHP's truthiness picks.
     */
    public function requireImageDimensionsTakesABooleanOrNothing(): void
    {
        Assert::true(
            PolicyRegistry::fromArray(['g' => ['requireImageDimensions' => true]])->for('g')->requireImageDimensions,
        );
        Assert::false(PolicyRegistry::fromArray(['g' => []])->for('g')->requireImageDimensions);

        Expect::exception(InvalidArgumentException::class)
            ->withMessageContaining('requireImageDimensions for group "g" must be a boolean');

        PolicyRegistry::fromArray(['g' => ['requireImageDimensions' => 1]]);
    }

    /**
     * The one that fails *open*, which is why it is rejected rather than
     * ignored: `allowedMimeType` without the `s` leaves the allow-list empty,
     * and an empty allow-list is how this package spells "accept anything". A
     * one-character typo would turn a locked-down group into an open one.
     */
    public function anUnknownOptionIsRejected(): void
    {
        Expect::exception(InvalidArgumentException::class)
            ->withMessageContaining('Unknown upload policy option "allowedMimeType" for group "g"');

        PolicyRegistry::fromArray(['g' => ['allowedMimeType' => ['image/png']]]);
    }

    /**
     * The message names what is accepted, so the fix does not need the source.
     */
    public function theRejectionListsTheKnownOptions(): void
    {
        Expect::exception(InvalidArgumentException::class)
            ->withMessageContaining('allowedMimeTypes, maxBytes, maxPixels, requireImageDimensions');

        PolicyRegistry::fromArray(['g' => ['maxByte' => 10]]);
    }
}
