<?php

declare(strict_types=1);

use Rasuvaeff\Understudy\Testo\UnderstudyPlugin;
use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\SuiteConfig;

// Command tests assert phrases of SymfonyStyle blocks, which wrap at the
// terminal width: a narrower console (the Windows runner's) split one across
// two lines. One fixed width for every run, whatever runs it.
putenv('COLUMNS=120');

return new ApplicationConfig(
    src: ['src'],
    suites: [
        new SuiteConfig(
            name: 'Unit',
            location: ['tests'],
            plugins: [new UnderstudyPlugin()],
        ),
        new SuiteConfig(
            name: 'Benchmarks',
            location: ['benchmarks'],
        ),
    ],
);
