<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Support;

/**
 * A `fs-unflushable://` stream whose writes are accepted but whose flush never
 * succeeds — what a full disk under the manifest looks like at the moment of
 * `fflush()`. The import lands in the store, the row is committed, and only
 * the manifest append fails: exactly the "imported but not recorded" state the
 * command has to own up to.
 *
 * The wrapper's root answers as a directory (so the manifest's parent check
 * passes and `mkdir()` is never attempted) and every concrete path answers as
 * nothing (so the previous run's manifest is never found).
 *
 * Register with
 * `stream_wrapper_register('fs-unflushable', UnflushableManifestStream::class)`
 * — the test helper does it once per process.
 *
 * @internal
 */
final class UnflushableManifestStream
{
    /** @var resource|null */
    public $context;

    private string $buffer = '';

    public function stream_open(): bool
    {
        return true;
    }

    /**
     * @param string $data
     *
     * @return int<0, max>
     */
    public function stream_write($data): int
    {
        $this->buffer .= $data;

        return \strlen($data);
    }

    public function stream_eof(): bool
    {
        return true;
    }

    /**
     * The whole point: the buffer accepted everything, and the flush still
     * fails — the data a real manifest would lose when the disk filled up.
     */
    public function stream_flush(): bool
    {
        return false;
    }

    public function stream_close(): bool
    {
        return true;
    }

    /**
     * @return array<int|string, mixed>|false
     */
    public function url_stat($path): array|false
    {
        // The root is a directory, so opening a manifest inside it never tries
        // mkdir(); anything else does not exist, so there is no manifest to
        // skip by. The stat array is the full 26-entry shape PHP expects —
        // index/key "mode" 040755 is what makes is_dir() answer true.
        if ($path === 'fs-unflushable://') {
            return [
                0, 0, 0o040755, 1, 0, 0, 0, 0, 0, 0, 0, 4096, 0,
                'dev' => 0,
                'ino' => 0,
                'mode' => 0o040755,
                'nlink' => 1,
                'uid' => 0,
                'gid' => 0,
                'rdev' => 0,
                'size' => 0,
                'atime' => 0,
                'mtime' => 0,
                'ctime' => 0,
                'blksize' => 4096,
                'blocks' => 0,
            ];
        }

        return false;
    }

    /**
     * @return array<int|string, mixed>|false
     */
    public function stream_stat(): array|false
    {
        return false;
    }

    /**
     * Registers the scheme once per process, whichever test needs it first.
     */
    public static function register(): void
    {
        static $registered = false;
        if (!$registered) {
            stream_wrapper_register('fs-unflushable', self::class);
            $registered = true;
        }
    }
}
