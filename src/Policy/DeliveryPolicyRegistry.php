<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Policy;

use InvalidArgumentException;

/**
 * Resolves the {@see DeliveryPolicy} of a group, falling back to the wildcard.
 *
 * @api
 */
final readonly class DeliveryPolicyRegistry
{
    /** Key of the policy applied to any group without one of its own. */
    public const string WILDCARD = '*';

    /** @var array<non-empty-string, DeliveryPolicy> */
    private array $policies;

    private DeliveryPolicy $fallback;

    /**
     * @param array<non-empty-string, DeliveryPolicy> $policies Group name => policy.
     *        The {@see self::WILDCARD} key, if present, becomes the fallback.
     */
    public function __construct(array $policies = [])
    {
        $this->fallback = $policies[self::WILDCARD] ?? new DeliveryPolicy();
        unset($policies[self::WILDCARD]);
        $this->policies = $policies;
    }

    /**
     * Builds the registry from a `params` array. See
     * {@see PolicyRegistry::fromArray()} for why this is not in `config/di.php`.
     *
     * A missing `allowDirectPublicUrl` defaults to false and a missing
     * `forceDownload` to true: a typo in configuration must fail closed.
     *
     * @param array<array-key, mixed> $config Group name => delivery array.
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $config): self
    {
        $policies = [];
        foreach ($config as $group => $policy) {
            if (!\is_string($group) || $group === '') {
                throw new InvalidArgumentException('Delivery policy group names must be non-empty strings');
            }
            if (!\is_array($policy)) {
                throw new InvalidArgumentException("Delivery policy for group \"{$group}\" must be an array");
            }

            $policies[$group] = new DeliveryPolicy(
                allowDirectPublicUrl: ($policy['allowDirectPublicUrl'] ?? false) === true,
                forceDownload: ($policy['forceDownload'] ?? true) !== false,
            );
        }

        return new self($policies);
    }

    /**
     * @param non-empty-string $groupName
     */
    public function for(string $groupName): DeliveryPolicy
    {
        return $this->policies[$groupName] ?? $this->fallback;
    }
}
