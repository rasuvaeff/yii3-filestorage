<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Policy;

use InvalidArgumentException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Mime\ActiveMediaTypes;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeDescriptor;

/**
 * Resolves the {@see DeliveryPolicy} of a group, falling back to the wildcard,
 * and carries the configured {@see ActiveMediaTypes} list: the two together
 * decide a response's disposition, so whoever builds {@see DeliveryOptions}
 * — the facade for a presigned URL, `-web` for the proxy — gets both from one
 * place ({@see self::optionsFor()}).
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

    private ActiveMediaTypes $activeMediaTypes;

    /**
     * @param array<non-empty-string, DeliveryPolicy> $policies Group name => policy.
     *        The {@see self::WILDCARD} key, if present, becomes the fallback.
     * @param ActiveMediaTypes|null $activeMediaTypes Null is the default list;
     *        core's DI passes the one built from params.
     */
    public function __construct(array $policies = [], ?ActiveMediaTypes $activeMediaTypes = null)
    {
        $this->activeMediaTypes = $activeMediaTypes ?? new ActiveMediaTypes();
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
    public static function fromArray(array $config, ?ActiveMediaTypes $activeMediaTypes = null): self
    {
        $policies = [];
        foreach ($config as $group => $policy) {
            if (!\is_string($group) || $group === '') {
                throw new InvalidArgumentException('Delivery policy group names must be non-empty strings');
            }
            if (!\is_array($policy)) {
                throw new InvalidArgumentException("Delivery policy for group \"{$group}\" must be an array");
            }

            foreach (array_keys($policy) as $option) {
                if (!\is_string($option) || !\in_array($option, self::OPTIONS, strict: true)) {
                    throw new InvalidArgumentException(sprintf(
                        'Unknown delivery policy option "%s" for group "%s". Known options: %s',
                        \is_string($option) ? $option : (string) $option,
                        $group,
                        implode(', ', self::OPTIONS),
                    ));
                }
            }

            $policies[$group] = new DeliveryPolicy(
                allowDirectPublicUrl: self::boolOption($policy, 'allowDirectPublicUrl', default: false, group: $group),
                forceDownload: self::boolOption($policy, 'forceDownload', default: true, group: $group),
            );
        }

        return new self($policies, $activeMediaTypes);
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

    public function activeMediaTypes(): ActiveMediaTypes
    {
        return $this->activeMediaTypes;
    }

    /**
     * The delivery shape of a stored file under its group's policy and the
     * configured active list.
     */
    public function optionsFor(File $file): DeliveryOptions
    {
        return DeliveryOptions::fromFile($file, $this->for($file->groupName), $this->activeMediaTypes);
    }

    /**
     * The delivery shape of one of the file's renditions.
     */
    public function optionsForVariant(File $file, DerivativeDescriptor $derivative): DeliveryOptions
    {
        return DeliveryOptions::forVariant($file, $this->for($file->groupName), $derivative, $this->activeMediaTypes);
    }
}
