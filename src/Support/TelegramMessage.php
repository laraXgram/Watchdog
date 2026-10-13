<?php

namespace LaraGram\Watchdog\Support;

class TelegramMessage
{
    /**
     * The longest text Telegram accepts, counted in UTF-16 code units after the entities are parsed.
     */
    public const LIMIT = 4096;

    /**
     * The room kept free below the limit.
     */
    protected const MARGIN = 64;

    /**
     * The marker appended to a shortened block.
     */
    protected const ELLIPSIS = "\n…";

    /**
     * The parts of the message, in order.
     *
     * @var array<int, array{html?: string, title?: string, text?: string, tag?: string, language?: ?string, priority?: int}>
     */
    protected array $parts = [];

    /**
     * Gets the emoji of the given log level.
     */
    public static function emoji(?string $level): string
    {
        return match (strtoupper((string) $level)) {
            'DEBUG' => '⚪️',
            'INFO' => '🔵',
            'NOTICE' => '🟢',
            'WARNING' => '🟡',
            'ERROR' => '🔴',
            'CRITICAL' => '🔥',
            'ALERT' => '🚨',
            'EMERGENCY' => '🆘',
            default => '⚫️',
        };
    }

    /**
     * Escapes the given text for Telegram's HTML parse mode.
     */
    public static function escape(?string $text): string
    {
        return htmlspecialchars((string) $text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Encodes the given value as readable JSON.
     */
    public static function json(mixed $value): string
    {
        return (string) json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
    }

    /**
     * Adds a line of ready HTML.
     */
    public function line(string $html): static
    {
        $this->parts[] = ['html' => $html];

        return $this;
    }

    /**
     * Adds a labeled value, skipped when the value is empty.
     */
    public function field(string $emoji, string $label, string|int|float|null $value): static
    {
        if ($value === null || trim((string) $value) === '') {
            return $this;
        }

        return $this->line("{$emoji} <b>{$label}:</b> <code>".static::escape((string) $value).'</code>');
    }

    /**
     * Adds a titled block of plain text, skipped when the text is empty.
     *
     * Blocks are shortened when the message is too long, the lowest priority first.
     */
    public function block(string $title, ?string $text, string $tag = 'pre', ?string $language = null, int $priority = 0): static
    {
        if ($text === null || trim($text) === '') {
            return $this;
        }

        $this->parts[] = [
            'title' => $title,
            'text' => trim($text, "\n"),
            'tag' => $tag,
            'language' => $language,
            'priority' => $priority,
        ];

        return $this;
    }

    /**
     * Renders the message as HTML that fits in one Telegram message.
     */
    public function toHtml(): string
    {
        $parts = $this->parts;

        $overflow = static::length($this->render($parts)) - (static::LIMIT - static::MARGIN);

        if ($overflow > 0) {
            $parts = $this->shrink($parts, $overflow);
        }

        return $this->render($parts);
    }

    /**
     * Shortens the blocks by the given number of characters.
     *
     * @param  array<int, array<string, mixed>>  $parts
     * @return array<int, array<string, mixed>>
     */
    protected function shrink(array $parts, int $overflow): array
    {
        $blocks = collect($parts)
            ->filter(fn (array $part) => isset($part['text']))
            ->sortBy('priority')
            ->keys();

        // First keep a readable piece of every block, then cut deeper if that was not enough.
        foreach ([200, 0] as $keep) {
            foreach ($blocks as $index) {
                if ($overflow <= 0) {
                    return $parts;
                }

                $text = $parts[$index]['text'];
                $length = mb_strlen($text);
                $target = max($keep, $length - $overflow - mb_strlen(static::ELLIPSIS));

                if ($target >= $length) {
                    continue;
                }

                $shortened = $this->truncate($text, $target);
                $overflow -= $length - mb_strlen($shortened);

                $parts[$index]['text'] = $shortened;
            }
        }

        return $parts;
    }

    /**
     * Cuts the text to the given length, preferring a line break.
     */
    protected function truncate(string $text, int $length): string
    {
        if ($length <= 0) {
            return '…';
        }

        $cut = mb_substr($text, 0, $length);
        $lineBreak = mb_strrpos($cut, "\n");

        if ($lineBreak !== false && $lineBreak > $length * 0.8) {
            $cut = mb_substr($cut, 0, $lineBreak);
        }

        return $cut.static::ELLIPSIS;
    }

    /**
     * Renders the given parts.
     *
     * @param  array<int, array<string, mixed>>  $parts
     */
    protected function render(array $parts): string
    {
        return collect($parts)
            ->map(function (array $part) {
                if (isset($part['html'])) {
                    return $part['html'];
                }

                $text = static::escape($part['text']);

                $body = match ($part['tag']) {
                    'blockquote' => "<blockquote expandable>{$text}</blockquote>",
                    default => $part['language']
                        ? "<pre><code class=\"language-{$part['language']}\">{$text}</code></pre>"
                        : "<pre>{$text}</pre>",
                };

                return "\n{$part['title']}\n{$body}";
            })
            ->implode("\n");
    }

    /**
     * Gets the length Telegram counts for the given HTML.
     */
    protected static function length(string $html): int
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }
}
