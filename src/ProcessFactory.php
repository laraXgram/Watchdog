<?php

namespace LaraGram\Watchdog;

use LaraGram\Support\Facades\Process;
use LaraGram\Support\Str;
use LaraGram\Watchdog\Printers\CliPrinter;
use LaraGram\Watchdog\Printers\TelegramPrinter;
use LaraGram\Watchdog\ValueObjects\MessageLogged;
use LaraGram\Console\Output\OutputInterface;

class ProcessFactory
{
    /**
     * Creates a new instance of the process factory.
     */
    public function run(File $file, OutputInterface $output, string $basePath, Options $options): void
    {
        $cliPrinter = new CliPrinter($output, $basePath);
        $telegramPrinter = new TelegramPrinter($basePath);

        $remainingBuffer = '';

        Process::timeout($options->timeout())
            ->tty(false)
            ->run(
                $this->command($file),
                function (string $type, string $buffer) use ($options, $cliPrinter, $telegramPrinter, &$remainingBuffer) {
                    $lines = Str::of($buffer)->explode("\n");

                    if ($remainingBuffer !== '' && isset($lines[0])) {
                        $lines[0] = $remainingBuffer.$lines[0];
                        $remainingBuffer = '';
                    }

                    if ($lines->last() === '') {
                        $lines = $lines->slice(0, -1);
                    } elseif (! str_ends_with((string) $lines->last(), "\n")) {
                        $remainingBuffer = $lines->pop();
                    }

                    $lines
                        ->filter(fn (string $line) => $line !== '')
                        ->map(fn (string $line) => MessageLogged::fromJson($line))
                        ->filter(fn (MessageLogged $messageLogged) => $options->accepts($messageLogged))
                        ->each(function (MessageLogged $messageLogged) use ($cliPrinter, $telegramPrinter) {
                            $cliPrinter->print($messageLogged);
                            $telegramPrinter->print($messageLogged);
                        });
                }
            );
    }

    /**
     * Returns the raw command.
     */
    protected function command(File $file): string
    {
        return '\\tail -F "'.$file->__toString().'"';
    }
}
