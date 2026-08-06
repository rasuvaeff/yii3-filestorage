<?php

declare(strict_types=1);

use Rasuvaeff\Yii3Filestorage\Command\CheckCommand;

return [
    'rasuvaeff/yii3-filestorage' => [
        'defaultGroup' => 'common',
        'maxInlineBytes' => 8_388_608,
        'maxSpoolBytes' => 268_435_456,
        'integrityHashMaxBytes' => 0,
        'defaultUrlTtl' => 'PT1H',
        'extensionOverrides' => [],
        // Group name => ['allowedMimeTypes' => [...], 'maxBytes' => int,
        //                'maxPixels' => int, 'requireImageDimensions' => bool].
        // '*' is the fallback applied to every group without its own entry.
        'policies' => [
            '*' => ['maxBytes' => 20_971_520],
        ],
        // Group name => ['allowDirectPublicUrl' => bool, 'forceDownload' => bool].
        'delivery' => [
            '*' => ['allowDirectPublicUrl' => false, 'forceDownload' => true],
        ],
    ],
    'yiisoft/yii-console' => [
        'commands' => [
            'filestorage:check' => CheckCommand::class,
        ],
    ],
];
