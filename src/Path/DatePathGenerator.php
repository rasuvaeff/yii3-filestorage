<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Path;

use Override;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Filestorage\Id\IdGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Mime\ExtensionMap;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * `<group>/<YYYY>/<MM>/<DD>/<uuidv7>/original.<ext>`.
 *
 * Date segments make a store browsable and let an operator archive or drop a
 * whole period with one directory operation — worth having when files are
 * retained by age. Uniqueness still comes from the UUID, not from the date.
 *
 * @api
 */
final readonly class DatePathGenerator implements PathGeneratorInterface
{
    public function __construct(
        private ClockInterface $clock,
        private IdGeneratorInterface $idGenerator,
        private ExtensionMap $extensions = new ExtensionMap(),
    ) {}

    #[Override]
    public function generate(string $groupName, Upload $upload, ?string $mediaType): string
    {
        return $groupName
            . '/' . $this->clock->now()->format('Y/m/d')
            . '/' . $this->idGenerator->generate()
            . '/original.' . $this->extensions->extensionFor($mediaType);
    }
}
