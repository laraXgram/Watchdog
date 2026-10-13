<?php

namespace LaraGram\Watchdog\ValueObjects\Origin;

class Queue
{
    /**
     * Creates a new instance of the console origin.
     */
    public function __construct(
        public string $queue,
        public string $job,
        public ?string $command,
    ) {
        //
    }

    /**
     * Creates a new instance of the queue origin from the given json string.
     *
     * @param  array{queue: string, job: string, command: ?string}  $array
     */
    public static function fromArray(array $array): static
    {
        return new static(
            (string) ($array['queue'] ?? ''),
            (string) ($array['job'] ?? ''),
            $array['command'] ?? null,
        );
    }
}
