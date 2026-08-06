<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Policy;

/**
 * How a group may be served.
 *
 * `allowDirectPublicUrl` is off by default and that default is load-bearing: a
 * permanent public URL bypasses every response header the download action
 * enforces — `Content-Disposition`, `X-Content-Type-Options`, the corrected
 * `Content-Type`. Turning it on is a statement that the objects sit on a
 * dedicated asset origin with no authentication cookies and that the group's
 * media-type allow-list excludes active content.
 *
 * @api
 */
final readonly class DeliveryPolicy
{
    public function __construct(
        public bool $allowDirectPublicUrl = false,
        public bool $forceDownload = true,
    ) {}
}
