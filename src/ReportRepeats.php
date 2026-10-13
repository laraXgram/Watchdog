<?php

namespace LaraGram\Watchdog;

use LaraGram\Support\Facades\Cache;
use Throwable;

class ReportRepeats
{
    /**
     * The cache key of the last reported log message.
     */
    protected const KEY = 'watchdog:report:last';

    /**
     * Creates a new instance of the report repeats.
     */
    public function __construct(
        protected ?string $store = null,
    ) {
        //
    }

    /**
     * Creates a new instance from the configuration, if repeats are counted.
     */
    public static function fromConfig(): ?static
    {
        if (! config('watchdog.report.repeat.enabled', true)) {
            return null;
        }

        return new static(config('watchdog.report.repeat.store'));
    }

    /**
     * Gets the last reported log message.
     *
     * @return array{fingerprint: string, count: int, first_at: string, messages: array<int|string, int>}|null
     */
    public function last(): ?array
    {
        try {
            $last = Cache::store($this->store)->get(static::KEY);
        } catch (Throwable) {
            return null;
        }

        return is_array($last) && isset($last['fingerprint'], $last['count'], $last['messages']) ? $last : null;
    }

    /**
     * Remembers the last reported log message.
     *
     * @param  array{fingerprint: string, count: int, first_at: string, messages: array<int|string, int>}  $last
     */
    public function remember(array $last): void
    {
        try {
            Cache::store($this->store)->forever(static::KEY, $last);
        } catch (Throwable) {
            //
        }
    }
}
