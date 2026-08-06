<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Command;

use Override;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Repository\MaintenanceRepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Repository\RepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Store\ContentAddressableStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeAwareStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\MaintenanceStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\RangeReadableStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Store\StoreUrlProviderInterface;
use Rasuvaeff\Yii3Filestorage\Test\MemoryRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

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
    /**
     * Formats that execute in a browser. A permanent public URL cannot be
     * combined with any of them, because nothing on that path can force a
     * download or send `X-Content-Type-Options`.
     */
    private const array ACTIVE_MEDIA_TYPES = [
        'application/xhtml+xml',
        'application/xml',
        'image/svg+xml',
        'text/html',
        'text/xml',
    ];

    /**
     * @param list<non-empty-string> $knownGroups Groups worth reporting on;
     *        `PolicyRegistry` answers for any name, so the command cannot
     *        discover them on its own.
     */
    public function __construct(
        private readonly StoreRegistry $stores,
        private readonly RepositoryInterface $repository,
        private readonly PolicyRegistry $policies,
        private readonly DeliveryPolicyRegistry $deliveryPolicies,
        private readonly array $knownGroups = [],
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $errors = [];
        $warnings = [];

        $io->section('Stores');
        $rows = [];
        foreach ($this->stores->names() as $name) {
            $store = $this->stores->get($name);
            $rows[] = [
                $name . ($name === $this->stores->defaultName() ? ' (default)' : ''),
                $store::class,
                implode(', ', $this->capabilities($store)) ?: 'base only',
            ];
        }
        $io->table(['Name', 'Class', 'Capabilities'], $rows);

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

        $groups = array_values(array_unique([PolicyRegistry::WILDCARD, ...$this->knownGroups]));

        $io->section('Groups');
        $rows = [];
        foreach ($groups as $group) {
            $upload = $this->policies->for($group);
            $delivery = $this->deliveryPolicies->for($group);
            $allowed = $upload->allowedMimeTypes === [] ? 'anything' : implode(', ', $upload->allowedMimeTypes);

            $rows[] = [
                $group,
                $allowed,
                $upload->maxBytes === 0 ? 'unlimited' : $upload->maxBytes . ' B',
                $upload->maxPixels === 0 ? 'unlimited' : $upload->maxPixels . ' px',
                $delivery->allowDirectPublicUrl ? 'direct public URL' : 'signed only',
            ];

            $unsafe = $this->unsafeDirectDelivery($group, $upload->allowedMimeTypes, $delivery->allowDirectPublicUrl);
            if ($unsafe !== null) {
                $errors[] = $unsafe;
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
            return Command::FAILURE;
        }

        $io->success('File storage configuration looks sound.');

        return Command::SUCCESS;
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

        $active = array_values(array_intersect($allowedMimeTypes, self::ACTIVE_MEDIA_TYPES));
        if ($active !== []) {
            return "Group \"{$group}\" allows direct public URLs and accepts " . implode(', ', $active)
                . '. Served from your own origin these execute as your site. Remove them from allowedMimeTypes, '
                . 'or set allowDirectPublicUrl to false so delivery goes through a route that forces a download.';
        }

        return null;
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

        return $capabilities;
    }
}
