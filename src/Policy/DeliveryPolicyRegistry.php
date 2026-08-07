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

    /** Everything {@see self::fromArray()} understands. */
    private const array OPTIONS = [
        'allowDirectPublicUrl',
        'forceDownload',
    ];

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
     * `forceDownload` to true, so an incomplete policy is the restrictive one.
     * A *misspelt* option is rejected outright rather than quietly taking those
     * defaults: an operator who meant to open a group and got the key wrong
     * would otherwise never learn the setting did nothing.
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

            foreach ($policy as $option => $_value) {
                if (!\is_string($option) || !\in_array($option, self::OPTIONS, true)) {
                    throw new InvalidArgumentException(sprintf(
                        'Unknown delivery policy option "%s" for group "%s". Known options: %s',
                        \is_string($option) ? $option : (string) $option,
                        $group,
                        implode(', ', self::OPTIONS),
                    ));
                }
            }

            $policies[$group] = new DeliveryPolicy(
                allowDirectPublicUrl: self::boolOption($policy, 'allowDirectPublicUrl', false, $group),
                forceDownload: self::boolOption($policy, 'forceDownload', true, $group),
            );
        }

        return new self($policies);
    }

    /**
     * `'true'` is not `true`. Coercing it would give an operator who wrote a
     * string the opposite of what they asked for, silently.
     *
     * @param array<array-key, mixed> $policy
     * @param non-empty-string $key
     * @param non-empty-string $group
     */
    private static function boolOption(array $policy, string $key, bool $default, string $group): bool
    {
        /** @var mixed $value */
        $value = $policy[$key] ?? $default;
        if (!\is_bool($value)) {
            throw new InvalidArgumentException("{$key} for group \"{$group}\" must be a boolean");
        }

        return $value;
    }

    /**
     * @param non-empty-string $groupName
     */
    public function for(string $groupName): DeliveryPolicy
    {
        return $this->policies[$groupName] ?? $this->fallback;
    }
}
