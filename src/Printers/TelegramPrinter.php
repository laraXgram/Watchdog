<?php

namespace LaraGram\Watchdog\Printers;

use LaraGram\Keyboard\Make;
use LaraGram\Request\Request;
use LaraGram\Support\Collection;
use LaraGram\Support\Facades\Keyboard;
use LaraGram\Support\Str;
use LaraGram\Watchdog\Contracts\Printer;
use LaraGram\Watchdog\ValueObjects\MessageLogged;
use LaraGram\Watchdog\ValueObjects\Origin\Bot;
use LaraGram\Watchdog\ValueObjects\Origin\Queue;
use LaraGram\Console\Output\OutputInterface;

use function LaraGram\Console\Prompts\Convertor\render;
use function LaraGram\Console\Prompts\Convertor\renderUsing;
use function LaraGram\Console\Prompts\Convertor\terminal;

class TelegramPrinter implements Printer
{
    /**
     * {@inheritDoc}
     */
    public function print(MessageLogged $messageLogged): void
    {
        $classOrType = $messageLogged->classOrType();
        $emoji = $this->emoji($messageLogged->color());
        $message = $messageLogged->message();
        $date = $messageLogged->date();
        $level = $messageLogged->level();
        $file = $this->file($messageLogged->file());
        $traceHtml = $this->traceHtml($messageLogged);
        $userId = $messageLogged->userId();

        /** @var Request $request */
        $request = app('request');

        $keyboard = Keyboard::inlineKeyboardMarkup(
            Make::row(
                Make::callbackData($emoji, 'watchdog_ignore')
            )
        )->get();

        $parts = [];

        if ($level) {
            $parts[] = "{$emoji} <b>{$level}</b> {$emoji}\n\n";
        }

        if ($message) {
            $parts[] = sprintf(
                "📝 <b>Message:</b>\n<pre><code class='language-php'>%s</code></pre>\n\n",
                htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            );
        }

        if ($date) {
            $parts[] = "⏰ <b>Time:</b> <code>{$date}</code>\n";
        }

        if ($classOrType) {
            $parts[] = "🏷 <b>Type:</b> <code>{$classOrType}</code>\n";
        }

        if ($file) {
            $parts[] = sprintf(
                "📍 <b>File:</b> <code>%s</code>\n",
                htmlspecialchars($file, ENT_QUOTES, 'UTF-8')
            );
        }

        if ($userId) {
            $parts[] = "👤 <b>User ID:</b> <code>{$userId}</code>\n";
        }

        if (!empty(trim($traceHtml ?? ''))) {
            $parts[] = "\n📚 <b>Stack Trace:</b>\n<blockquote expandable>{$traceHtml}</blockquote>";
        }

        $request->sendMessage(
            config('watchdog.report_chat'),
            implode("", $parts),
            'html',
            reply_markup: $keyboard
        );
    }

    /**
     * Creates a new instance printer instance.
     */
    public function __construct(protected string $basePath)
    {
        //
    }

    /**
     * Gets the file html.
     */
    protected function file(?string $file): ?string
    {
        if (is_null($file)) {
            return null;
        }

        return str_replace($this->basePath . '/', '', $file);
    }

    protected function emoji($color)
    {
        return match ($color) {
            'gray' => '⚪️',
            'blue' => '🔵',
            'yellow' => '🟡',
            'red' => '🔴',
            default => '⚪️',
        };
    }

    /**
     * Gets the trace html.
     */
    public function traceHtml(MessageLogged $messageLogged): string
    {
        $trace = $messageLogged->trace();

        if (is_null($trace)) {
            return '';
        }

        return collect($trace)
            ->map(function (array $frame, int $index) {
                $number = $index + 1;

                [
                    'line' => $line,
                    'file' => $file,
                ] = $frame;

                $file = str_replace($this->basePath . '/', '', $file);

                return "<b>#$number.</b>\n  $file<b>:</b>$line\n<b>--------</b>\n";
            })->implode('');
    }
}
