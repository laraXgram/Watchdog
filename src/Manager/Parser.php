<?php

namespace LaraGram\Watchdog\Manager;

class Parser
{
    private ?string $message = null;
    private ?string $datetime = null;
    private ?string $channel = null;
    private ?string $level = null;
    private ?string $type = null;
    private ?string $exceptionMessage = null;
    private ?string $file = null;
    private ?int $line = null;
    private int|string|null $userId = null;
    private array $context = [];
    private array $extra = [];
    private array $trace = [];
    private array $previous = [];
    private ?string $rawLog = null;

    public function __construct(?string $logEntry = null)
    {
        if ($logEntry) {
            $this->parse($logEntry);
        }
    }

    /**
     * Determines if the given line starts a new log entry.
     */
    public static function startsEntry(string $line): bool
    {
        return preg_match('/^\[[^\]\n]+\]\s+(?:\S+\.)?[A-Za-z]+:/', $line) === 1
            || (str_starts_with($line, '{"') && static::decodeRecord($line) !== null);
    }

    /**
     * Parse the log entry
     */
    public function parse(string $logEntry): self
    {
        foreach (get_class_vars(self::class) as $property => $default) {
            $this->{$property} = $default;
        }

        $this->rawLog = $logEntry;

        $entry = trim($logEntry);

        if (! $this->parseJson($entry) && ! $this->parseLine($entry)) {
            // Unknown format, keep the entry as it is
            $this->message = $entry;

            return $this;
        }

        $exception = $this->context['exception'] ?? null;

        if (is_string($exception)) {
            $this->parseExceptionString($exception);
        } elseif (is_array($exception)) {
            $this->parseExceptionArray($exception);
        }

        $userId = $this->context['userId'] ?? $this->context['user_id'] ?? null;

        if (is_int($userId) || is_string($userId)) {
            $this->userId = $userId;
        }

        return $this;
    }

    /**
     * Parse an entry written by the JSON formatter
     */
    private function parseJson(string $entry): bool
    {
        if (! str_starts_with($entry, '{') || ! is_array($record = static::decodeRecord($entry))) {
            return false;
        }

        $datetime = is_string($record['datetime'] ?? null) ? $record['datetime'] : null;

        $this->datetime = $datetime && ($time = date_create($datetime)) ? $time->format('Y-m-d H:i:s') : $datetime;
        $this->channel = is_string($record['channel'] ?? null) ? $record['channel'] : null;
        $this->level = is_string($record['level_name'] ?? null) ? $record['level_name'] : null;
        $this->message = is_scalar($record['message']) ? (string) $record['message'] : static::stringify($record['message']);
        $this->context = is_array($record['context'] ?? null) ? $record['context'] : [];
        $this->extra = is_array($record['extra'] ?? null) ? $record['extra'] : [];

        return true;
    }

    /**
     * Parse an entry written by the line formatter: [datetime] channel.LEVEL: message {context} {extra}
     */
    private function parseLine(string $entry): bool
    {
        if (! preg_match('/^\[(?<datetime>[^\]\n]+)\]\s+(?:(?<channel>\S+?)\.)?(?<level>[A-Za-z]+):(?<rest>.*)$/s', $entry, $matches)) {
            return false;
        }

        $this->datetime = $matches['datetime'];
        $this->channel = $matches['channel'] !== '' ? $matches['channel'] : null;
        $this->level = strtoupper($matches['level']);

        [$this->message, $this->context, $this->extra] = $this->splitContext($matches['rest']);

        return true;
    }

    /**
     * Split the message from the trailing context and extra JSON
     *
     * @return array{0: string, 1: array, 2: array}
     */
    private function splitContext(string $rest): array
    {
        $rest = rtrim($rest);

        preg_match_all('/\s[{\[]/', $rest, $matches, PREG_OFFSET_CAPTURE);

        $positions = array_column($matches[0], 1);

        // Context alone is the common case, so it is tried at every position before context with extra
        foreach ([false, true] as $withExtra) {
            foreach ($positions as $position) {
                if (($decoded = $this->decodeTail(substr($rest, $position + 1), $withExtra)) !== null) {
                    return [trim(substr($rest, 0, $position)), ...$decoded];
                }
            }
        }

        return [trim($rest), [], []];
    }

    /**
     * Decode the tail of an entry as context, or context and extra
     *
     * @return array{0: array, 1: array}|null
     */
    private function decodeTail(string $tail, bool $withExtra): ?array
    {
        if (! $withExtra) {
            return is_array($context = static::decode($tail)) ? [$context, []] : null;
        }

        $offset = 0;

        while (preg_match('/[}\]]\s+[{\[]/', $tail, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $position = $matches[0][1] + 1;

            if (is_array($context = static::decode(substr($tail, 0, $position)))
                && is_array($extra = static::decode(substr($tail, $position)))) {
                return [$context, $extra];
            }

            $offset = $position;
        }

        return null;
    }

    /**
     * Parse an exception written by the line formatter
     */
    private function parseExceptionString(string $exception): void
    {
        $chain = preg_split('/\n\s*\[previous exception\]\s+/', $exception);

        foreach ($chain as $index => $item) {
            [$head, $stacktrace] = array_pad(preg_split('/\n\s*\[stacktrace\]\s*\n/', $item, 2), 2, '');

            $head = trim($head);

            $parsed = preg_match('/^\[object\] \((?<class>[^\s(]+)\(code: (?<code>[^)]*)\): (?<message>.*) at (?<file>.*?):(?<line>\d+)\)$/s', $head, $matches)
                ? [
                    'class' => $matches['class'],
                    'message' => $matches['message'],
                    'file' => $matches['file'],
                    'line' => (int) $matches['line'],
                ]
                : ['class' => null, 'message' => $head, 'file' => null, 'line' => null];

            if ($index === 0) {
                $this->setException($parsed);
                $this->trace = $this->parseStackTrace($stacktrace);
            } else {
                $this->previous[] = $parsed;
            }
        }
    }

    /**
     * Parse an exception normalized by the JSON formatter
     */
    private function parseExceptionArray(array $exception, int $depth = 0): void
    {
        [$file, $line] = static::splitLocation($exception['file'] ?? null);

        $parsed = [
            'class' => is_string($exception['class'] ?? null) ? $exception['class'] : null,
            'message' => is_scalar($exception['message'] ?? null) ? (string) $exception['message'] : null,
            'file' => $file,
            'line' => $line,
        ];

        if ($depth === 0) {
            $this->setException($parsed);

            $this->trace = collect(is_array($exception['trace'] ?? null) ? $exception['trace'] : [])
                ->values()
                ->map(function ($frame, int $index) {
                    [$file, $line] = is_string($frame)
                        ? static::splitLocation($frame)
                        : [$frame['file'] ?? '[internal function]', $frame['line'] ?? 0];

                    return ['number' => $index, 'file' => (string) $file, 'line' => (int) $line, 'call' => ''];
                })
                ->all();
        } else {
            $this->previous[] = $parsed;
        }

        if (is_array($exception['previous'] ?? null) && $depth < 10) {
            $this->parseExceptionArray($exception['previous'], $depth + 1);
        }
    }

    /**
     * Set the main exception
     */
    private function setException(array $exception): void
    {
        $this->type = $exception['class'];
        $this->exceptionMessage = $exception['message'];
        $this->file = $exception['file'];
        $this->line = $exception['line'];
    }

    /**
     * Parse stack trace
     */
    private function parseStackTrace(string $stacktrace): array
    {
        $trace = [];

        foreach (explode("\n", $stacktrace) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            // Pattern 1: #0 /path/file.php(123): Class->method()
            if (preg_match('/^#(\d+)\s+(.+?)\((\d+)\)(?::\s+(.+))?$/', $line, $match)) {
                $trace[] = [
                    'number' => (int) $match[1],
                    'file' => $match[2],
                    'line' => (int) $match[3],
                    'call' => isset($match[4]) ? trim($match[4]) : '',
                ];
            }
            // Pattern 2: #0 /path/file.php:123 Class->method()
            elseif (preg_match('/^#(\d+)\s+([^\s\[{]+?):(\d+)(?:\s+(.+))?$/', $line, $match)) {
                $trace[] = [
                    'number' => (int) $match[1],
                    'file' => $match[2],
                    'line' => (int) $match[3],
                    'call' => isset($match[4]) ? trim($match[4]) : '',
                ];
            }
            // Pattern 3: #0 [internal function]: Class->method()
            elseif (preg_match('/^#(\d+)\s+(\[internal function\]):\s*(.*)$/', $line, $match)) {
                $trace[] = [
                    'number' => (int) $match[1],
                    'file' => $match[2],
                    'line' => 0,
                    'call' => trim($match[3]),
                ];
            }
            // Pattern 4: #0 {main} or anything else
            elseif (preg_match('/^#(\d+)\s+(.+)$/', $line, $match)) {
                $trace[] = [
                    'number' => (int) $match[1],
                    'file' => $match[2],
                    'line' => 0,
                    'call' => '',
                ];
            }
        }

        return $trace;
    }

    /**
     * Split "path:line" into its parts
     *
     * @return array{0: ?string, 1: ?int}
     */
    private static function splitLocation(mixed $location): array
    {
        if (! is_string($location) || $location === '') {
            return [null, null];
        }

        if (preg_match('/^(.*):(\d+)$/s', $location, $matches)) {
            return [$matches[1], (int) $matches[2]];
        }

        return [$location, null];
    }

    /**
     * Decode a JSON log record
     */
    private static function decodeRecord(string $line): ?array
    {
        $record = json_decode(trim($line), true);

        return is_array($record) && array_key_exists('message', $record) && (isset($record['level_name']) || isset($record['level']))
            ? $record
            : null;
    }

    /**
     * Decode JSON written with inline line breaks
     */
    private static function decode(string $json): mixed
    {
        // The line formatter turns escaped line breaks back into real ones, which JSON does not allow
        $json = str_replace(["\r", "\n"], ['\r', '\n'], trim($json));

        return json_decode($json, true);
    }

    /**
     * Encode a value for display
     */
    private static function stringify(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Get error message
     */
    public function message(): ?string
    {
        return $this->message;
    }

    /**
     * Get the exception message
     */
    public function exceptionMessage(): ?string
    {
        return $this->exceptionMessage;
    }

    /**
     * Get datetime
     */
    public function datetime(): ?string
    {
        return $this->datetime;
    }

    /**
     * Get the log channel
     */
    public function channel(): ?string
    {
        return $this->channel;
    }

    /**
     * Get log level
     */
    public function level(): ?string
    {
        return $this->level;
    }

    /**
     * Get error type
     */
    public function type(): ?string
    {
        return $this->type;
    }

    /**
     * Get file path
     */
    public function file(): ?string
    {
        return $this->file;
    }

    /**
     * Get line number
     */
    public function line(): ?int
    {
        return $this->line;
    }

    /**
     * Get user ID
     */
    public function userId(): int|string|null
    {
        return $this->userId;
    }

    /**
     * Get the context, without the exception and user ID
     */
    public function context(): array
    {
        return collect($this->context)->except(['exception', 'userId'])->all();
    }

    /**
     * Get the extra data
     */
    public function extra(): array
    {
        return $this->extra;
    }

    /**
     * Get stack trace
     */
    public function trace(): array
    {
        return $this->trace;
    }

    /**
     * Get specific trace item
     */
    public function traceItem(int $index): ?array
    {
        return $this->trace[$index] ?? null;
    }

    /**
     * Get the previous exceptions
     *
     * @return array<int, array{class: ?string, message: ?string, file: ?string, line: ?int}>
     */
    public function previous(): array
    {
        return $this->previous;
    }

    /**
     * Get raw log entry
     */
    public function raw(): ?string
    {
        return $this->rawLog;
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'message' => $this->message(),
            'exception_message' => $this->exceptionMessage(),
            'datetime' => $this->datetime(),
            'channel' => $this->channel(),
            'level' => $this->level(),
            'type' => $this->type(),
            'file' => $this->file(),
            'line' => $this->line(),
            'userId' => $this->userId(),
            'context' => $this->context(),
            'extra' => $this->extra(),
            'trace' => $this->trace(),
            'previous' => $this->previous(),
        ];
    }
}
