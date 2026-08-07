<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store;

use Rasuvaeff\Yii3Filestorage\Exception\InvalidConfigException;

/**
 * The stores an application has, by name.
 *
 * This exists to satisfy a constraint of `yiisoft/config`: one key may be
 * defined by exactly one vendor package, so no backend can bind an array of
 * stores. Core builds a one-entry registry from the single `StoreInterface`
 * whichever backend is installed binds, and an application that genuinely
 * needs several physical stores overrides the registry in its own config —
 * the app layer beats the vendor layer. `add(storeName:)` therefore has a
 * stable signature from the first release without any vendor package binding
 * more than one store.
 *
 * @api
 */
final readonly class StoreRegistry
{
    /** @var non-empty-array<non-empty-string, StoreInterface> */
    private array $stores;

    /** @var non-empty-string */
    private string $defaultName;

    /**
     * @param non-empty-list<StoreInterface> $stores Indexed here by each store's own `name()`.
     * @param non-empty-string|null $defaultName Defaults to the first store's name.
     *
     * @throws InvalidConfigException
     */
    public function __construct(array $stores, ?string $defaultName = null)
    {
        $indexed = [];
        foreach ($stores as $store) {
            $name = $store->name();
            if (isset($indexed[$name])) {
                // Silently collapsing them would make one of the two
                // unreachable, and the symptom would surface much later as
                // files written to the wrong place.
                throw new InvalidConfigException("Duplicate store name \"{$name}\"");
            }
            $indexed[$name] = $store;
        }

        $this->stores = $indexed;
        $this->defaultName = $defaultName ?? $stores[0]->name();

        if (!isset($this->stores[$this->defaultName])) {
            throw new InvalidConfigException(
                "Default store \"{$this->defaultName}\" is not registered. Registered stores: "
                . implode(', ', array_keys($indexed)),
            );
        }
    }

    /**
     * @param non-empty-string|null $name
     *
     * @throws InvalidConfigException
     */
    public function get(?string $name = null): StoreInterface
    {
        $name ??= $this->defaultName;

        return $this->stores[$name] ?? throw new InvalidConfigException(
            "Store \"{$name}\" is not registered. Registered stores: " . implode(', ', $this->names()),
        );
    }

    public function has(string $name): bool
    {
        return isset($this->stores[$name]);
    }

    /**
     * @return non-empty-string
     */
    public function defaultName(): string
    {
        return $this->defaultName;
    }

    /**
     * @return non-empty-list<non-empty-string>
     */
    public function names(): array
    {
        return array_keys($this->stores);
    }
}
