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
        $authId = $array['user_id'] ?? null;

        return new static(
            (string) ($array['method'] ?? ''),
            (string) ($array['pattern'] ?? ''),
            is_scalar($authId) ? (string) $authId : null,
        );
    }
}
