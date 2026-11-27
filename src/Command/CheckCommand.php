<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Command;

use Override;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Filestorage\Mime\ActiveMediaTypes;
use Rasuvaeff\Yii3Filestorage\Path\ContentAddressedKeyGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Path\Sha256KeyGenerator;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Reference\ReferenceAuthority;
use Rasuvaeff\Yii3Filestorage\Reference\ReferenceCheckerInterface;
use Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface;
use Rasuvaeff\Yii3Filestorage\Repository\MaintenanceRepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Repository\RepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Repository\ScopedFileResolverInterface;
use Rasuvaeff\Yii3Filestorage\Store\ContentAddressableStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeAwareStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\FileSystem\PublicFileSystemStore;
use Rasuvaeff\Yii3Filestorage\Store\Layout\LayoutCompatibility;
use Rasuvaeff\Yii3Filestorage\Store\MaintenanceStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\PubliclyReadableStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\RangeReadableStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Store\StoreUrlProviderInterface;
use Rasuvaeff\Yii3Filestorage\Test\MemoryRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reports what is wired, what each store can do, and what is unsafe.
 *
 * Run it after installing, and again after changing configuration. The
 * alternative first-run experience is a container error naming an interface the
 * user has never heard of, at the moment they upload their first file.
 *
 * Unsafe delivery is an *error*, not a warning: a group that hands out permanent
 * public URLs while accepting active content — HTML, SVG, XML — turns your own
 * origin into a stored-XSS host, and a warning in a command nobody runs twice is
 * not a control.
 *
 * @api
 */
#[AsCommand(name: 'filestorage:check', description: 'Verify file storage wiring, capabilities and delivery safety')]
final class CheckCommand extends Command
{
    private readonly ActiveMediaTypes $activeTypes;

    /**
     * @param list<non-empty-string> $knownGroups Groups worth reporting on;
     *        `PolicyRegistry` answers for any name, so the command cannot
     *        discover them on its own.
     * @param ContentAddressedKeyGeneratorInterface|null $contentKeys The
     *        generator deduplication writes shared keys with; null is
     *        `Sha256KeyGenerator`, the `-db` default. Probed against the
     *        layout of every content-addressable store.
     * @param PathGeneratorInterface|null $paths Probed, with `$streams`,
     *        against the layout of every store.
     * @param ReferenceCheckerInterface|null $references Whether groups that
     *        hand references to the application can be released at all.
     */
    public function __construct(
        private readonly StoreRegistry $stores,
        private readonly RepositoryInterface $repository,
        private readonly PolicyRegistry $policies,
        private readonly DeliveryPolicyRegistry $deliveryPolicies,
        private readonly array $knownGroups = [],
        private readonly ?FileScopeProviderInterface $scopes = null,
        private readonly ?ScopedFileResolverInterface $scopedFiles = null,
        private readonly ?ContentAddressedKeyGeneratorInterface $contentKeys = null,
        private readonly ?PathGeneratorInterface $paths = null,
        private readonly ?StreamFactoryInterface $streams = null,
        private readonly ?ReferenceCheckerInterface $references = null,
    ) {
        parent::__construct();
        // Formats that execute as the origin. A permanent public URL cannot be
        // combined with any of them, because nothing on that path can force a
        // download or send `X-Content-Type-Options`. The plugin tier (PDF) is
        // not refused: a public PDF is a legitimate publication.
        $this->activeTypes = ActiveMediaTypes::scriptable();
    }

    #[Override]
    protected function configure(): void
    {
        Json::configure($this);
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $log = [];
        $io = Json::style($input, $output, $log);
        $errors = [];
        $warnings = [];

        /** @var list<array{name: string, class: string, layout: string, capabilities: list<string>, default: bool}> $storeRows */
        $storeRows = [];

        /** @var list<array{group: string, accepts: list<string>, maxBytes: int, maxPixels: int, directPublicUrl: bool}> $groupRows */
        $groupRows = [];

        $io->section('Stores');
        $rows = [];
        foreach ($this->stores->names() as $name) {
            $store = $this->stores->get($name);
            $storeRows[] = [
                'name' => $name,
                'class' => $store::class,
                'layout' => $store->layout()::class,
                'capabilities' => $this->capabilities($store),
                'default' => $name === $this->stores->defaultName(),
            ];
            $rows[] = [
                $name . ($name === $this->stores->defaultName() ? ' (default)' : ''),
                $store::class,
                $this->shortName($store->layout()::class),
                implode(', ', $this->capabilities($store)) ?: 'base only',
            ];

            // An error: a key generator whose keys the layout cannot delete
            // one by one loses other files' bytes on the first deletion
            // (review 1.0 A1).
            if ($store instanceof ContentAddressableStoreInterface) {
                $problem = LayoutCompatibility::contentKeysProblem(
                    $this->contentKeys ?? new Sha256KeyGenerator(),
                    $store->layout(),
                );
                // Deduplication writes to one store — the default unless
                // `-db`'s factory is told otherwise, and it refuses a mismatch
                // itself. On the default store this is an error; on any other
                // it only matters if deduplication is pointed there.
                if ($problem !== null && $name === $this->stores->defaultName()) {
                    $errors[] = "Store \"{$name}\": {$problem}";
                } elseif ($problem !== null) {
                    $warnings[] = "Store \"{$name}\": {$problem}. It is only a problem if deduplication writes to "
                        . 'this store; rasuvaeff/yii3-filestorage-db refuses that combination when it is built.';
                }
            }
            if ($this->paths instanceof PathGeneratorInterface && $this->streams instanceof StreamFactoryInterface) {
                $problem = LayoutCompatibility::pathsProblem($this->paths, $store->layout(), $this->streams);
                if ($problem !== null) {
                    $errors[] = "Store \"{$name}\": {$problem}";
                }
            }

            // A warning, not an error: uploads and reads work without a usable
            // quarantine, only filestorage:gc cannot delete from the store.
            // A web root usually holds the application's own files too, and
            // the orphan sweep would take every one of them (review 1.0 B2).
            if (
                $store instanceof PublicFileSystemStore
                && $store->webRoot() === null
                && $store->quarantineProblem() === null
            ) {
                $warnings[] = "Store \"{$name}\": nothing checks that its quarantine is outside the web server's "
                    . 'document root, only outside the store root. Pass webRoot to PublicFileSystemStore.';
            }
            if ($store instanceof PublicFileSystemStore && $store->ownedPrefixes() === []) {
                $warnings[] = "Store \"{$name}\" serves its root publicly and owns all of it: filestorage:gc "
                    . '--orphans quarantines, then purges, every file under it that has no row. If anything else '
                    . 'lives there, pass ownedPrefixes to the FileSystemStore.';
            }

            $quarantineProblem = $store instanceof MaintenanceStoreInterface ? $store->quarantineProblem() : null;
            if ($quarantineProblem !== null) {
                $warnings[] = $quarantineProblem;
            }
        }
        $io->table(['Name', 'Class', 'Layout', 'Capabilities'], $rows);
        $publicStore = $this->firstPublicStore();

        $io->section('Metadata repository');
        $io->writeln($this->repository::class);
        if ($this->repository instanceof MemoryRepository) {
            $warnings[] = 'RepositoryInterface is bound to Test\MemoryRepository: every file record is lost when '
                . 'the process ends. Install rasuvaeff/yii3-filestorage-db before using this anywhere real.';
        }
        if (!$this->repository instanceof MaintenanceRepositoryInterface) {
            $warnings[] = 'The repository does not implement MaintenanceRepositoryInterface, so filestorage:verify, '
                . ':stat and :backfill-hash cannot run against it.';
        }

        $io->section('Tenancy');
        if (!$this->scopes instanceof \Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface) {
            $io->writeln('Single-scope: no FileScopeProviderInterface is bound, so no tenant predicate is applied.');
        } else {
            $io->writeln(sprintf(
                'Multi-tenant: %s, current scope %s.',
                $this->scopes::class,
                $this->scopes->currentScopeId() ?? '(none)',
            ));

            if (!$this->scopedFiles instanceof \Rasuvaeff\Yii3Filestorage\Repository\ScopedFileResolverInterface) {
                // The failure this exists to pre-empt is not a wrong answer, it
                // is a container error on the first signed download: `-web`
                // requires the resolver outright. A diagnostic here names the
                // missing package; the runtime error names an interface.
                $errors[] = sprintf(
                    'A %s is bound but nothing binds %s, so a signed download has no scoped way to resolve a file '
                    . '— and resolving by id alone reads any file whose id leaks. Install a repository backend that '
                    . 'ships one (rasuvaeff/yii3-filestorage-db does), or unbind the scope provider if this '
                    . 'installation is not multi-tenant.',
                    FileScopeProviderInterface::class,
                    ScopedFileResolverInterface::class,
                );
            }

            // A warning, not an error: `-db`'s global deduplication scope
            // shares across tenants on purpose, and that scope is not visible
            // from here. `-db` refuses the pair itself under the other scopes.
            $tenantProblem = LayoutCompatibility::tenantProblem($this->contentKeys ?? new Sha256KeyGenerator());
            if ($tenantProblem !== null) {
                $warnings[] = "{$tenantProblem}. A FileScopeProviderInterface is bound, so this is right only with "
                    . 'the "global" deduplication scope; rasuvaeff/yii3-filestorage-db refuses the generator under '
                    . 'the "tenant" and "tenant-group" scopes when it is built. Use a generator that keeps the scope '
                    . 'in the key.';
            }

            $warnings[] = 'Tenancy is on, so `filestorage:gc --orphans` refuses and `filestorage:stat` withholds its '
                . 'physical figures: both compare tenant-filtered rows against unfiltered objects. Run them from a '
                . 'maintenance entry point that leaves the scope provider unbound.';
        }

        $groups = array_values(array_unique([PolicyRegistry::WILDCARD, ...$this->knownGroups]));

        $io->section('Groups');
        $rows = [];
        foreach ($groups as $group) {
            $upload = $this->policies->for($group);
            $delivery = $this->deliveryPolicies->for($group);
            $allowed = $upload->allowedMimeTypes === [] ? 'anything' : implode(', ', $upload->allowedMimeTypes);

            $groupRows[] = [
                'group' => $group,
                'accepts' => $upload->allowedMimeTypes,
                'maxBytes' => $upload->maxBytes,
                'maxPixels' => $upload->maxPixels,
                'directPublicUrl' => $delivery->allowDirectPublicUrl,
            ];
            $rows[] = [
                $group,
                $allowed,
                $upload->maxBytes === 0 ? 'unlimited' : $upload->maxBytes . ' B',
                $upload->maxPixels === 0 ? 'unlimited' : $upload->maxPixels . ' px',
                $delivery->allowDirectPublicUrl ? 'direct public URL' : 'signed only',
            ];

            $unsafe = $this->unsafeDirectDelivery($group, $upload->allowedMimeTypes, $delivery->allowDirectPublicUrl)
                ?? $this->unsafePublicStore($group, $upload->allowedMimeTypes, $publicStore);
            if ($unsafe !== null) {
                $errors[] = $unsafe;
            }

            if (
                $upload->referencesOwnedBy === ReferenceAuthority::Application
                && !$this->references instanceof ReferenceCheckerInterface
            ) {
                $warnings[] = "Group \"{$group}\" hands references to the application, but no "
                    . ReferenceCheckerInterface::class . ' is bound, so filestorage:gc never releases its files.';
            }
        }
        $io->table(['Group', 'Accepts', 'Max size', 'Max pixels', 'Delivery'], $rows);

        foreach ($warnings as $warning) {
            $io->warning($warning);
        }
        foreach ($errors as $error) {
            $io->error($error);
        }

        if ($errors !== []) {
            $this->maybeJson($input, $output, $log, ok: false, errors: $errors, warnings: $warnings, storeRows: $storeRows, groupRows: $groupRows);

            return Command::FAILURE;
        }

        $io->success('File storage configuration looks sound.');
        $this->maybeJson($input, $output, $log, ok: true, errors: $errors, warnings: $warnings, storeRows: $storeRows, groupRows: $groupRows);

        return Command::SUCCESS;
    }

    /**
     * @param array<int, string> $log
     * @param list<string> $errors
     * @param list<string> $warnings
     * @param list<array{name: string, class: string, layout: string, capabilities: list<string>, default: bool}> $storeRows
     * @param list<array{group: string, accepts: list<string>, maxBytes: int, maxPixels: int, directPublicUrl: bool}> $groupRows
     */
    private function maybeJson(
        InputInterface $input,
        OutputInterface $output,
        array $log,
        bool $ok,
        array $errors,
        array $warnings,
        array $storeRows,
        array $groupRows,
    ): void {
        if (!Json::enabled($input)) {
            return;
        }

        Json::emit($output, [
            'ok' => $ok,
            'errors' => $errors,
            'warnings' => $warnings,
            'stores' => $storeRows,
            'groups' => $groupRows,
            'log' => $log,
        ]);
    }

    /**
     * @param list<non-empty-string> $allowedMimeTypes
     */
    private function unsafeDirectDelivery(string $group, array $allowedMimeTypes, bool $allowDirect): ?string
    {
        if (!$allowDirect) {
            return null;
        }

        if ($allowedMimeTypes === []) {
            return "Group \"{$group}\" allows direct public URLs while accepting any media type. "
                . 'Set an explicit allowedMimeTypes list that excludes active content, or set '
                . 'allowDirectPublicUrl to false.';
        }

        $active = $this->active($allowedMimeTypes);
        if ($active !== []) {
            return "Group \"{$group}\" allows direct public URLs and accepts " . implode(', ', $active)
                . '. Served from your own origin these execute as your site. Remove them from allowedMimeTypes, '
                . 'or set allowDirectPublicUrl to false so delivery goes through a route that forces a download.';
        }

        return null;
    }

    /**
     * A store that serves objects at public URLs serves them by path whatever
     * `allowDirectPublicUrl` says — that flag only chooses which URL the
     * application prints — and a content-addressed path is computable by
     * whoever uploaded the bytes (review 1.0 B5). Groups are not bound to
     * stores, so every group counts as reachable through such a store.
     *
     * @param list<non-empty-string> $allowedMimeTypes
     * @param non-empty-string|null $publicStore
     */
    private function unsafePublicStore(string $group, array $allowedMimeTypes, ?string $publicStore): ?string
    {
        if ($publicStore === null) {
            return null;
        }

        $reach = "store \"{$publicStore}\" serves every object it holds at a public URL whatever the delivery "
            . 'policy says.';

        if ($allowedMimeTypes === []) {
            return "Group \"{$group}\" accepts any media type, and {$reach} Set an explicit allowedMimeTypes list "
                . 'that excludes active content, or keep this group out of public stores.';
        }

        $active = $this->active($allowedMimeTypes);
        if ($active !== []) {
            return "Group \"{$group}\" accepts " . implode(', ', $active) . ", and {$reach} Served from your own "
                . 'origin these execute as your site. Remove them from allowedMimeTypes, or keep this group out of '
                . 'public stores.';
        }

        return null;
    }

    /**
     * The first registered store that serves every object it holds publicly.
     *
     * Only the store can say so ({@see PubliclyReadableStoreInterface}):
     * `StoreUrlProviderInterface::publicUrl()` answering is not evidence — a
     * Flysystem S3 adapter builds an object URL for a private bucket too, and
     * that URL answers 403.
     *
     * @return non-empty-string|null
     */
    private function firstPublicStore(): ?string
    {
        foreach ($this->stores->names() as $name) {
            if ($this->isPublic($this->stores->get($name))) {
                return $name;
            }
        }

        return null;
    }

    private function isPublic(StoreInterface $store): bool
    {
        return $store instanceof PubliclyReadableStoreInterface && $store->isPubliclyReadable();
    }

    /**
     * @param list<non-empty-string> $allowedMimeTypes
     *
     * @return list<non-empty-string>
     */
    private function active(array $allowedMimeTypes): array
    {
        return array_values(array_filter(
            $allowedMimeTypes,
            $this->activeTypes->contains(...),
        ));
    }

    private function shortName(string $class): string
    {
        $slash = strrpos($class, '\\');

        return $slash === false ? $class : substr($class, $slash + 1);
    }

    /**
     * @return list<string>
     */
    private function capabilities(StoreInterface $store): array
    {
        $capabilities = [];
        if ($store instanceof StoreUrlProviderInterface) {
            $capabilities[] = 'urls';
        }
        if ($store instanceof RangeReadableStoreInterface) {
            $capabilities[] = 'range';
        }
        if ($store instanceof ContentAddressableStoreInterface) {
            $capabilities[] = 'content-addressable';
        }
        if ($store instanceof MaintenanceStoreInterface) {
            $capabilities[] = 'maintenance';
        }
        if ($store instanceof DerivativeAwareStoreInterface) {
            $capabilities[] = 'derivatives';
        }
        if ($this->isPublic($store)) {
            $capabilities[] = 'public';
        }

        return $capabilities;
    }
}
