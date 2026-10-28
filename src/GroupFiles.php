<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage;

use Generator;
use Rasuvaeff\Yii3Filestorage\Repository\MaintenanceRepositoryInterface;

/**
 * The files of one group — the query `MaintenanceRepositoryInterface::files()`
 * cannot express.
 *
 * "Re-point the avatar group", "audit what the old policy accepted", "list
 * everything a tenant's gallery holds" all start from the same iteration, and
 * before this class the only one available was the maintenance walk: unfiltered,
 * positioned as an operator's tool. This is the same walk with a group (and
 * optionally a metadata key) applied.
 *
 * Honest about the cost: it pages the ledger and keeps the group's rows, so
 * it is O(all rows) — right for a backfill or an audit, wrong for a request.
 * A backend that can filter natively (`-db`) should implement the same shape
 * as a WHERE; this class is also the contract that method would satisfy.
 *
 * @api
 */
final readonly class GroupFiles
{
    private const int PAGE = 500;

    /**
     * @param non-empty-string|null $metadataKey When set, only files whose
     *        metadata carries this key are yielded — the dimensions decorator
     *        writes `width`/`height`, and "every image we know the size of"
     *        is a real backfill question.
     */
    public function __construct(
        private MaintenanceRepositoryInterface $repository,
        private string $groupName,
        private ?string $metadataKey = null,
    ) {}

    /**
     * @return Generator<int, File>
     */
    public function __invoke(): Generator
    {
        $after = null;
        while (true) {
            $page = [];
            foreach ($this->repository->files($after, self::PAGE) as $file) {
                $page[] = $file;
            }

            if ($page === []) {
                return;
            }

            foreach ($page as $file) {
                $after = $file->id;
                if ($file->groupName !== $this->groupName) {
                    continue;
                }

                if ($this->metadataKey !== null && !\array_key_exists($this->metadataKey, $file->metadata)) {
                    continue;
                }

                yield $file;
            }
        }
    }
}
