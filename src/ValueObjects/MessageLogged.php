<?php

namespace LaraGram\Watchdog\ValueObjects;

use LaraGram\Support\Tempora;
use Stringable;

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

        [
            'message' => $message,
            'datetime' => $datetime,
            'level_name' => $levelName,
            'context' => $context,
        ] = $array;

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
        $time = Tempora::createFromFormat('Y-m-d\TH:i:s.uP', $this->datetime);

        assert($time instanceof Tempora);

        return $time->format('Y-m-d H:i:s');
    }

    /**
     * Gets the log message's time.
     */
    public function time(): string
    {
        $time = Tempora::createFromFormat('Y-m-d\TH:i:s.uP', $this->datetime);

        assert($time instanceof Tempora);

        return $time->format('H:i:s');
    }

    /**
     * Gets the log message's class.
     */
    public function classOrType(): string
    {
        return $this->context['exception']['class'] ?? strtoupper($this->levelName);
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
        return $this->context['exception']['file'] ?? null;
    }

    /**
     * Gets the log message's auth id.
     */
    public function userId(): ?string
    {
        return $this->context['__watchdog']['origin']['user_id'] ?? null;
    }

    /**
     * Gets the log message's origin.
     */
    public function origin(): Origin\Console|Origin\Bot|Origin\Queue
    {
        return match ($this->context['__watchdog']['origin']['type']) {
            'console' => Origin\Console::fromArray($this->context['__watchdog']['origin']),
            'queue' => Origin\Queue::fromArray($this->context['__watchdog']['origin']),
            default => Origin\Bot::fromArray($this->context['__watchdog']['origin']),
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
