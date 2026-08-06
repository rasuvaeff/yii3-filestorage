<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Policy;

use InvalidArgumentException;

/**
 * Resolves the {@see UploadPolicy} of a group, falling back to the wildcard.
 *
 * @api
 */
final readonly class PolicyRegistry
{
    /** Key of the policy applied to any group without one of its own. */
    public const string WILDCARD = '*';

    /** @var array<non-empty-string, UploadPolicy> */
    private array $policies;

    private UploadPolicy $fallback;

    /**
     * @param array<non-empty-string, UploadPolicy> $policies Group name => policy.
     *        The {@see self::WILDCARD} key, if present, becomes the fallback.
     */
    public function __construct(array $policies = [])
    {
        $this->fallback = $policies[self::WILDCARD] ?? new UploadPolicy();
        unset($policies[self::WILDCARD]);
        $this->policies = $policies;
    }

    /**
     * Builds the registry from a `params` array.
     *
     * Lives here rather than in `config/di.php` because that file is covered by
     * neither the style checker, nor psalm, nor the tests — so anything with a
     * branch in it belongs on this side of the boundary.
     *
     * @param array<array-key, mixed> $config Group name => policy array. Untrusted
     *        shape: an application writes it by hand.
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $config): self
    {
        $policies = [];
        foreach ($config as $group => $policy) {
            if (!\is_string($group) || $group === '') {
                throw new InvalidArgumentException('Upload policy group names must be non-empty strings');
            }
            if (!\is_array($policy)) {
                throw new InvalidArgumentException("Upload policy for group \"{$group}\" must be an array");
            }

            $policies[$group] = self::policyFromArray($group, $policy);
        }

        return new self($policies);
    }

    /**
     * @param non-empty-string $groupName
     */
    public function for(string $groupName): UploadPolicy
    {
        return $this->policies[$groupName] ?? $this->fallback;
    }

    /**
     * @param non-empty-string $group
     * @param array<array-key, mixed> $policy
     */
    private static function policyFromArray(string $group, array $policy): UploadPolicy
    {
        /** @var mixed $allowed */
        $allowed = $policy['allowedMimeTypes'] ?? [];
        if (!\is_array($allowed)) {
            throw new InvalidArgumentException("allowedMimeTypes for group \"{$group}\" must be a list");
        }

        $mimeTypes = [];
        foreach ($allowed as $mimeType) {
            if (!\is_string($mimeType)) {
                throw new InvalidArgumentException("allowedMimeTypes for group \"{$group}\" must contain strings");
            }
            $mimeTypes[] = $mimeType;
        }

        $defaults = new UploadPolicy();

        return new UploadPolicy(
            allowedMimeTypes: $mimeTypes,
            maxBytes: self::intOption($policy, 'maxBytes', $defaults->maxBytes, $group),
            maxPixels: self::intOption($policy, 'maxPixels', $defaults->maxPixels, $group),
            requireImageDimensions: ($policy['requireImageDimensions'] ?? false) === true,
        );
    }

    /**
     * @param array<array-key, mixed> $policy
     * @param non-empty-string $key
     * @param non-empty-string $group
     */
    private static function intOption(array $policy, string $key, int $default, string $group): int
    {
        /** @var mixed $value */
        $value = $policy[$key] ?? $default;
        if (!\is_int($value)) {
            throw new InvalidArgumentException("{$key} for group \"{$group}\" must be an integer");
        }

        return $value;
    }
}
