<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Command;

use Override;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The `--format=json` mode of the report commands, in one place.
 *
 * In JSON mode the command's human output — notes, tables, warnings — goes to
 * a recording buffer instead of stdout, so stdout carries exactly one
 * machine-consumable object and nothing else. The recorded lines travel in the
 * object's `log` key: nothing the operator would have seen in text mode is
 * dropped, and a dashboard can show it next to the numbers.
 *
 * @internal
 */
final class Json
{
    private const string FORMAT = 'format';

    private function __construct() {}

    public static function configure(Command $command): void
    {
        if (!$command->getDefinition()->hasOption(self::FORMAT)) {
            $command->addOption(
                self::FORMAT,
                null,
                InputOption::VALUE_REQUIRED,
                'Output format: text (default) or json',
                'text',
            );
        }
    }

    public static function enabled(InputInterface $input): bool
    {
        return $input->getOption(self::FORMAT) === 'json';
    }

    /**
     * The style the command's body writes to: the real output in text mode,
     * the recording buffer in JSON mode.
     *
     * @param array<int, string> $log
     */
    public static function style(InputInterface $input, OutputInterface $output, array &$log): SymfonyStyle
    {
        if (!self::enabled($input)) {
            return new SymfonyStyle($input, $output);
        }

        $buffer = new class ($output->getFormatter(), $log) extends Output {
            /** @param array<int, string> $log */
            public function __construct(OutputFormatterInterface $formatter, private array &$log)
            {
                parent::__construct(self::VERBOSITY_NORMAL, $formatter->isDecorated(), $formatter);
            }

            #[Override]
            protected function doWrite(string $message, bool $newline): void
            {
                // Raw, including the decoration the formatter produced: the
                // log is "what text mode would have shown", not a cleaned
                // copy of it.
                $this->log[] = $message;
            }
        };

        return new SymfonyStyle($input, $buffer);
    }

    /**
     * @param array<string, mixed> $report
     */
    public static function emit(OutputInterface $output, array $report): void
    {
        // RAW: the console would otherwise interpret a `<...>` sequence in
        // a value as one of its own tags and rewrite the object.
        $output->writeln(
            json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            OutputInterface::OUTPUT_RAW,
        );
    }
}
