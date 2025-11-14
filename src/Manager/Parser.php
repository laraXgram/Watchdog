<?php

namespace LaraGram\Watchdog\Manager;

class Parser
{
    private ?string $message = null;
    private ?string $datetime = null;
    private ?string $level = null;
    private ?string $type = null;
    private ?string $file = null;
    private ?int $line = null;
    private ?int $userId = null;
    private array $trace = [];
    private ?string $rawLog = null;

    public function __construct(?string $logEntry = null)
    {
        if ($logEntry) {
            $this->parse($logEntry);
        }
    }

    /**
     * Parse the log entry
     */
    public function parse(string $logEntry): self
    {
        $this->rawLog = $logEntry;

        // Parse datetime and level
        if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] local\.(\w+):/', $logEntry, $matches)) {
            $this->datetime = $matches[1];
            $this->level = $matches[2];
        }

        // Parse message - get everything after "local.LEVEL: " until end or until we find exception/stacktrace markers
        if (preg_match('/local\.\w+:\s+(.+?)(?:\s+\{"|\s+\[stacktrace\]|$)/s', $logEntry, $matches)) {
            $this->message = trim($matches[1]);
        } elseif (preg_match('/local\.\w+:\s+(.+)/s', $logEntry, $matches)) {
            // Fallback: get everything after level
            $message = trim($matches[1]);

            // Remove JSON context and stacktrace if exists
            $message = preg_replace('/\s+\{".+$/s', '', $message);
            $message = preg_replace('/\s+\[stacktrace\].+$/s', '', $message);

            $this->message = trim($message);
        }

        // Parse userId from JSON context
        if (preg_match('/"userId":(\d+)/', $logEntry, $matches)) {
            $this->userId = (int)$matches[1];
        }

        // Parse exception type
        if (preg_match('/\((\w+Error)\(code:/', $logEntry, $matches)) {
            $this->type = $matches[1];
        } elseif (preg_match('/\((\w+Exception)\(code:/', $logEntry, $matches)) {
            $this->type = $matches[1];
        }

        // Parse file and line from exception
        if (preg_match('/at ([^\s]+):(\d+)\)/', $logEntry, $matches)) {
            $this->file = $matches[1];
            $this->line = (int)$matches[2];
        }

        $this->parseStackTrace($logEntry);

        return $this;
    }

    /**
     * Parse stack trace
     */
    private function parseStackTrace(string $logEntry): void
    {
        $this->trace = [];

        // Find stacktrace section
        if (!preg_match('/\[stacktrace\](.*?)(?:"\s*\}|$)/s', $logEntry, $matches)) {
            return;
        }

        $stacktrace = $matches[1];
        $lines = explode("\n", $stacktrace);

        foreach ($lines as $line) {
            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            // Pattern 1: #0 /path/file.php(123): Class->method()
            if (preg_match('/^#(\d+)\s+(.+?)\((\d+)\)(?::\s+(.+))?$/m', $line, $match)) {
                $this->trace[] = [
                    'number' => (int)$match[1],
                    'file' => $match[2],
                    'line' => (int)$match[3],
                    'call' => isset($match[4]) ? trim($match[4]) : '',
                ];
            }
            // Pattern 2: #0 /path/file.php:123 Class->method()
            elseif (preg_match('/^#(\d+)\s+(.+?):(\d+)(?:\s+(.+))?$/m', $line, $match)) {
                $this->trace[] = [
                    'number' => (int)$match[1],
                    'file' => $match[2],
                    'line' => (int)$match[3],
                    'call' => isset($match[4]) ? trim($match[4]) : '',
                ];
            }
            // Pattern 3: #0 {main}
            elseif (preg_match('/^#(\d+)\s+\{(.+)\}$/m', $line, $match)) {
                $this->trace[] = [
                    'number' => (int)$match[1],
                    'file' => '{' . $match[2] . '}',
                    'line' => 0,
                    'call' => '',
                ];
            }
        }
    }

    /**
     * Get error message
     */
    public function message(): ?string
    {
        return $this->message;
    }

    /**
     * Get datetime
     */
    public function datetime(): ?string
    {
        return $this->datetime;
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
    public function userId(): ?int
    {
        return $this->userId;
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
            'datetime' => $this->datetime(),
            'level' => $this->level(),
            'type' => $this->type(),
            'file' => $this->file(),
            'line' => $this->line(),
            'userId' => $this->userId(),
            'trace' => $this->trace(),
        ];
    }
}
