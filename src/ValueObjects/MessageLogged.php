<?php

namespace LaraGram\Watchdog\ValueObjects;

use LaraGram\Support\Tempora;
use Stringable;
use Throwable;

class MessageLogged implements Stringable
{
    /**
     * Creates a new instance of the message logged.
     *
     * @param  array{__watchdog: array{origin: array{trace: array<int, array{file: string, line: int}>|null, type: string, queue: string, job: string, command: string, method: string, pattern: string, user_id: ?string}}, exception: array{class: string, file: string}}  $context
     */
    protected function __construct(
        protected string $message,
        protected string $datetime,
        protected string $levelName,
        protected array $context,
    ) {
        //
    }

    /**
     * Creates a new instance of the message logged from a json string.
     */
    public static function fromJson(string $json): static
    {
        /** @var array{message: string, context: array{__watchdog: array{origin: array{trace: array<int, array{file: string, line: int}>|null, type: string, queue: string, job: string, command: string, method: string, pattern: string, user_id: ?string}}, exception: array{class: string, file: string}}, level_name: string, datetime: string} $array */
        $array = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $message = $array['message'] ?? '';
        $context = $array['context'] ?? [];

        $message = is_scalar($message) ? (string) $message : (string) json_encode($message, JSON_UNESCAPED_SLASHES);
        $datetime = (string) ($array['datetime'] ?? '');
        $levelName = strtoupper((string) ($array['level_name'] ?? 'DEBUG'));
        $context = is_array($context) ? $context : [];

        return new static($message, $datetime, $levelName, $context);
    }

    /**
     * Gets the log message's message.
     */
    public function message(): string
    {
        return $this->message;
    }

    /**
     * Gets the log message's date.
     */
    public function date(): string
    {
        return $this->formatDatetime('Y-m-d H:i:s');
    }

    /**
     * Gets the log message's time.
     */
    public function time(): string
    {
        return $this->formatDatetime('H:i:s');
    }

    /**
     * Formats the log message's datetime, keeping it as it is when it cannot be read.
     */
    protected function formatDatetime(string $format): string
    {
        try {
            $time = Tempora::createFromFormat('Y-m-d\TH:i:s.uP', $this->datetime);
        } catch (Throwable) {
            $time = date_create($this->datetime);
        }

        return $time ? $time->format($format) : $this->datetime;
    }

    /**
     * Gets the log message's class.
     */
    public function classOrType(): string
    {
        $class = $this->context['exception']['class'] ?? null;

        return is_string($class) ? $class : strtoupper($this->levelName);
    }

    /**
     * Gets the log message's color.
     */
    public function color(): string
    {
        return match ($this->levelName) {
            'DEBUG' => 'gray',
            'INFO' => 'blue',
            'NOTICE' => 'yellow',
            'WARNING' => 'yellow',
            'ERROR' => 'red',
            'CRITICAL' => 'red',
            'ALERT' => 'red',
            'EMERGENCY' => 'red',
            default => 'gray',
        };
    }

    /**
     * Gets the log message's level.
     */
    public function level(): string
    {
        return $this->levelName;
    }

    /**
     * Gets the log message's file, if any.
     */
    public function file(): ?string
    {
        $file = $this->context['exception']['file'] ?? null;

        return is_string($file) ? $file : null;
    }

    /**
     * Gets the exception's message, if it differs from the log message.
     */
    public function exceptionMessage(): ?string
    {
        $message = $this->context['exception']['message'] ?? null;

        return is_scalar($message) && (string) $message !== $this->message ? (string) $message : null;
    }

    /**
     * Gets the previous exceptions, if any.
     *
     * @return array<int, array{class: ?string, message: ?string, file: ?string}>
     */
    public function previous(): array
    {
        $previous = [];
        $exception = $this->context['exception'] ?? null;

        while (is_array($exception = $exception['previous'] ?? null) && count($previous) < 10) {
            $previous[] = [
                'class' => is_string($exception['class'] ?? null) ? $exception['class'] : null,
                'message' => is_scalar($exception['message'] ?? null) ? (string) $exception['message'] : null,
                'file' => is_string($exception['file'] ?? null) ? $exception['file'] : null,
            ];
        }

        return $previous;
    }

    /**
     * Gets a fingerprint that is the same for repeated occurrences of this log message.
     */
    public function fingerprint(): string
    {
        return md5(implode("\0", [
            $this->levelName,
            $this->message,
            $this->classOrType(),
            $this->file() ?? '',
        ]));
    }

    /**
     * Gets the log message's auth id.
     */
    public function userId(): ?string
    {
        $userId = $this->context['__watchdog']['origin']['user_id'] ?? null;

        return is_scalar($userId) ? (string) $userId : null;
    }

    /**
     * Gets the log message's origin.
     */
    public function origin(): Origin\Console|Origin\Bot|Origin\Queue
    {
        $origin = $this->context['__watchdog']['origin'] ?? [];

        return match ($origin['type'] ?? null) {
            'console' => Origin\Console::fromArray($origin),
            'queue' => Origin\Queue::fromArray($origin),
            default => Origin\Bot::fromArray($origin),
        };
    }

    /**
     * Gets the log message's trace, if any.
     *
     * @return array<int, array{file: string, line: int}>|null
     */
    public function trace(): ?array
    {
        return $this->context['__watchdog']['origin']['trace'] ?? null;
    }

    /**
     * Gets the log message's context.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return collect($this->context)->except([
            '__watchdog',
            'exception',
            'userId',
        ])->toArray();
    }

    /**
     * {@inheritDoc}
     */
    public function __toString(): string
    {
        return json_encode([
            'message' => $this->message,
            'datetime' => $this->datetime,
            'level_name' => $this->levelName,
            'context' => $this->context,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
