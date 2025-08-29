<?php

namespace LaraGram\Watchdog\ValueObjects\Origin;

class Bot
{
    /**
     * Creates a new instance of the bot origin.
     */
    public function __construct(
        public string $method,
        public string $pattern,
        public ?string $userId,
    ) {
        //
    }

    /**
     * Creates a new instance of the bot origin from the given json string.
     *
     * @param  array{method: string, pattern: string, user_id: ?string}  $array
     */
    public static function fromArray(array $array): static
    {
        ['method' => $method, 'pattern' => $pattern, 'user_id' => $authId] = $array;

        return new static($method, $pattern, $authId);
    }
}
