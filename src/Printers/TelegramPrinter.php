<?php

namespace LaraGram\Watchdog\Printers;

use LaraGram\Console\Output\OutputInterface;
use LaraGram\Keyboard\Make;
use LaraGram\Laraquest\Mode;
use LaraGram\Request\Request;
use LaraGram\Support\Facades\Keyboard;
use LaraGram\Watchdog\Contracts\Printer;
use LaraGram\Watchdog\ReportRepeats;
use LaraGram\Watchdog\Support\TelegramMessage;
use LaraGram\Watchdog\ValueObjects\MessageLogged;
use LaraGram\Watchdog\ValueObjects\Origin\Console;
use LaraGram\Watchdog\ValueObjects\Origin\Queue;
use Throwable;

class TelegramPrinter implements Printer
{
    /**
     * {@inheritDoc}
     */
    public function print(MessageLogged $messageLogged): void
    {
        try {
            $fingerprint = $messageLogged->fingerprint();
            $last = $this->repeats?->last();

            // The same log message as the last report edits it, anything else starts a new one
            $repeated = $last !== null && $last['fingerprint'] === $fingerprint;

            $count = $repeated ? $last['count'] + 1 : 1;
            $firstAt = $repeated ? $last['first_at'] : $messageLogged->date();

            $text = $this->text($messageLogged, $count, $firstAt);
            $keyboard = $this->keyboard($messageLogged, $count);

            $messages = [];

            foreach ((array) config('watchdog.report.chats', []) as $chat) {
                $messageId = $repeated ? ($last['messages'][$chat] ?? null) : null;

                if ($messageId !== null && $this->edit($chat, $messageId, $text, $keyboard)) {
                    $messages[$chat] = $messageId;

                    continue;
                }

                if (($messageId = $this->send($chat, $text, $keyboard)) !== null) {
                    $messages[$chat] = $messageId;
                }
            }

            $this->repeats?->remember([
                'fingerprint' => $fingerprint,
                'count' => $count,
                'first_at' => $firstAt,
                'messages' => $messages,
            ]);
        } catch (Throwable $e) {
            $this->warn($e->getMessage());
        }
    }

    /**
     * Creates a new instance printer instance.
     */
    public function __construct(
        protected string $basePath,
        protected ?OutputInterface $output = null,
        protected ?ReportRepeats $repeats = null,
    ) {
        //
    }

    /**
     * Builds the message text.
     */
    protected function text(MessageLogged $messageLogged, int $count, string $firstAt): string
    {
        $level = $messageLogged->level();
        $emoji = TelegramMessage::emoji($level);

        $message = (new TelegramMessage)
            ->line("{$emoji} <b>".TelegramMessage::escape($level)."</b> {$emoji}");

        if ($count > 1) {
            $message
                ->field('🔁', 'Repeated', "{$count} times")
                ->field('🕐', 'First Seen', $firstAt);
        }

        $message
            ->block('📝 <b>Message:</b>', $messageLogged->message(), 'pre', 'php', 5)
            ->line('')
            ->field('⏰', $count > 1 ? 'Last Seen' : 'Time', $messageLogged->date())
            ->field('🏷', 'Type', $messageLogged->classOrType())
            ->field('📍', 'File', $this->file($messageLogged->file()))
            ->field('👤', 'User ID', $messageLogged->userId())
            ->field('⚙️', 'Origin', $this->origin($messageLogged))
            ->block('💥 <b>Exception Message:</b>', $messageLogged->exceptionMessage(), 'pre', 'php', 4);

        if (! empty($context = $messageLogged->context())) {
            $message->block('🧩 <b>Context:</b>', TelegramMessage::json($context), 'pre', 'json', 3);
        }

        return $message
            ->block('🔗 <b>Previous Exceptions:</b>', $this->previous($messageLogged), 'blockquote', priority: 1)
            ->block('📚 <b>Stack Trace:</b>', $this->trace($messageLogged), 'blockquote', priority: 0)
            ->toHtml();
    }

    /**
     * Builds the message keyboard.
     */
    protected function keyboard(MessageLogged $messageLogged, int $count): string
    {
        $emoji = TelegramMessage::emoji($messageLogged->level());

        return Keyboard::inlineKeyboardMarkup(
            Make::row(
                Make::callbackData($count > 1 ? "{$emoji} ×{$count}" : $emoji, 'watchdog_ignore')
            )
        )->get();
    }

    /**
     * Sends a new report, returning its message id.
     */
    protected function send(int|string $chat, string $text, string $keyboard): ?int
    {
        $response = $this->api()->sendMessage($chat, $text, 'HTML', reply_markup: $keyboard);

        if ($response->failed()) {
            $this->warn("[{$chat}] ".$response->description());

            return null;
        }

        return is_numeric($messageId = $response->message_id) ? (int) $messageId : null;
    }

    /**
     * Edits a sent report, returning whether it can still be edited.
     */
    protected function edit(int|string $chat, int $messageId, string $text, string $keyboard): bool
    {
        $response = $this->api()->editMessageText($text, $chat, $messageId, 'HTML', $keyboard);

        if ($response->isOk()) {
            return true;
        }

        // When rate limited the next repeat updates the counter, the message itself is still there
        return $response->retryAfter() !== null
            || str_contains((string) $response->description(), 'message is not modified');
    }

    /**
     * Gets the request that talks to the reporting bot.
     */
    protected function api(): Request
    {
        /** @var Request $request */
        $request = app('request');

        return $request
            ->connection((string) (config('watchdog.report.connection') ?? 'auto'))
            ->mode(Mode::CURL)
            ->silent();
    }

    /**
     * Writes a reporting failure to the console, without logging it again.
     */
    protected function warn(string $message): void
    {
        $this->output?->writeln("Watchdog could not report to Telegram: {$message}", OutputInterface::OUTPUT_RAW);
    }

    /**
     * Gets the file relative to the base path.
     */
    protected function file(?string $file): ?string
    {
        if (is_null($file)) {
            return null;
        }

        return str_replace($this->basePath.'/', '', $file);
    }

    /**
     * Gets the origin of the log message.
     */
    protected function origin(MessageLogged $messageLogged): ?string
    {
        $origin = $messageLogged->origin();

        $parts = match (true) {
            $origin instanceof Queue => [$origin->command ? "laragram {$origin->command}" : null, $origin->queue, $origin->job],
            $origin instanceof Console => [$origin->command ? "laragram {$origin->command}" : null],
            default => [],
        };

        return collect($parts)->filter()->implode(' · ') ?: null;
    }

    /**
     * Gets the previous exceptions text.
     */
    protected function previous(MessageLogged $messageLogged): string
    {
        return collect($messageLogged->previous())
            ->map(fn (array $exception, int $index) => sprintf(
                "#%d. %s\n  %s%s",
                $index + 1,
                $exception['class'] ?? 'Exception',
                $exception['message'] ?? '',
                $exception['file'] ? "\n  at ".$this->file($exception['file']) : ''
            ))
            ->implode("\n--------\n");
    }

    /**
     * Gets the trace text.
     */
    public function trace(MessageLogged $messageLogged): string
    {
        return collect($messageLogged->trace() ?? [])
            ->values()
            ->map(fn (array $frame, int $index) => sprintf(
                '#%d. %s%s',
                $index + 1,
                $this->file($frame['file'] ?? '[internal function]'),
                isset($frame['line']) ? ":{$frame['line']}" : ''
            ))
            ->implode("\n--------\n");
    }
}
