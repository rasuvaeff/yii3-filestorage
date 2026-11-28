<?php

declare(strict_types=1);

use Rasuvaeff\Yii3Filestorage\Command\BackfillHashCommand;
use Rasuvaeff\Yii3Filestorage\Command\CheckCommand;
use Rasuvaeff\Yii3Filestorage\Command\GcCommand;
use Rasuvaeff\Yii3Filestorage\Command\ImportCommand;
use Rasuvaeff\Yii3Filestorage\Command\StatCommand;
use Rasuvaeff\Yii3Filestorage\Command\VerifyCommand;

return [
    'rasuvaeff/yii3-filestorage' => [
        'defaultGroup' => 'common',
        'maxInlineBytes' => 8_388_608,
        'integrityHashMaxBytes' => 0,
        'defaultUrlTtl' => 'PT1H',
        'extensionOverrides' => [],
        // The family's one active-content list (Mime\ActiveMediaTypes): what is
        // never served inline and never allowed on a permanent public URL.
        // extra: types to add. allowInline: plugin-tier types (application/pdf)
        // a group with forceDownload => false may show inline.
        'activeMediaTypes' => [
            'extra' => [],
            'allowInline' => [],
        ],
        // Group name => ['allowedMimeTypes' => [...], 'maxBytes' => int,
        //                'maxPixels' => int, 'requireImageDimensions' => bool,
        //                'referencesOwnedBy' => 'rows' | 'application'].
        // '*' is the fallback applied to every group without its own entry.
        'policies' => [
            '*' => ['maxBytes' => 20_971_520],
        ],
        // Group name => ['allowDirectPublicUrl' => bool, 'forceDownload' => bool].
        'delivery' => [
            '*' => ['allowDirectPublicUrl' => false, 'forceDownload' => true],
        ],
        // ISO 8601 durations for filestorage:gc, all strictly positive.
        // leaseTtl: how long one collector holds a blob. gracePeriod: how long
        // a released blob waits, and the default --min-age — keep it at least
        // -db's deleteGracePeriod. retention: how long quarantined orphans and
        // released files stay restorable, the default --retention.
        'gc' => [
            'leaseTtl' => 'PT5M',
            'gracePeriod' => 'PT1H',
            'retention' => 'P7D',
        ],
    ],
    'yiisoft/yii-console' => [
        'commands' => [
            'filestorage:check' => CheckCommand::class,
            'filestorage:gc' => GcCommand::class,
            'filestorage:verify' => VerifyCommand::class,
            'filestorage:backfill-hash' => BackfillHashCommand::class,
            'filestorage:stat' => StatCommand::class,
            'filestorage:import' => ImportCommand::class,
        ],
    ],
];
