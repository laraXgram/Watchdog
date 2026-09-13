<?php

use LaraGram\Keyboard\Make;
use LaraGram\Support\Facades\File;
use LaraGram\Support\Collection;
use LaraGram\Support\Facades\Keyboard;
use LaraGram\Support\Str;
use LaraGram\Watchdog\Manager\Parser;

/**
 * Create log message and keyboard for navigation
 *
 * @param string $root Root directory path
 * @param int $pointerIndex Current selected index
 * @return array [message, keyboard]
 */
function makeLogMessageAndKeyboard(string $root, int $pointerIndex): array
{
    if (!File::isDirectory($root)) {
        return [
            "📁 logs/\n\n❌ Directory not found",
            null
        ];
    }

    $files = collect(File::allFiles($root))
        ->map(fn($file) => $file->getFilename())
        ->sort()
        ->values();

    $totalFiles = $files->count();

    if ($totalFiles === 0) {
        return [
            "📁 logs/\n\n📭 No log files found",
            null
        ];
    }

    $pointerIndex = max(0, min($pointerIndex, $totalFiles - 1));

    $message = "📁 logs/\n";
    $message .= buildTree($root, '', $pointerIndex);

    $keyboard = buildNavigationKeyboard($pointerIndex, $totalFiles);

    return [$message, $keyboard];
}

/**
 * Build navigation keyboard
 *
 * @param int $pointerIndex Current index
 * @param int $totalFiles Total number of files
 * @return array Inline keyboard array
 */
function buildNavigationKeyboard(int $pointerIndex, int $totalFiles): string
{
    $upIndex = $pointerIndex > 0 ? $pointerIndex - 1 : -1;
    $downIndex = $pointerIndex < $totalFiles - 1 ? $pointerIndex + 1 : -1;

    $upDisabled = $pointerIndex === 0;
    $downDisabled = $pointerIndex === $totalFiles - 1;

    return Keyboard::inlineKeyboardMarkup(
        Make::row(
            Make::callbackData($upDisabled ? '⬆️ —' : '⬆️ Up', $upDisabled ? 'noop' : "watchdog_log_load_{$upIndex}"),
            Make::callbackData($downDisabled ? '⬇️ —' : '⬇️ Down', $downDisabled ? 'noop' : "watchdog_log_load_{$downIndex}")
        ),
        Make::row(
            Make::callbackData('📖 Read', "watchdog_log_read_{$pointerIndex}"),
            Make::callbackData('❌ Delete', "watchdog_log_delete_{$pointerIndex}")
        )
    )->get();
}

/**
 * Parse log entries from file
 *
 * @param string $filePath Path to log file
 * @return Collection Collection of log entries
 */
function parseLogEntries(string $filePath): Collection
{
    if (!File::exists($filePath)) {
        return collect();
    }

    $content = File::get($filePath);

    return collect(preg_split('/(?=\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\])/', $content))
        ->filter(fn($entry) => !empty(trim($entry)))
        ->values();
}

/**
 * Get total log entries count
 *
 * @param string $filePath Path to log file
 * @return int Total entries
 */
function getTotalLogEntries(string $filePath): int
{
    return parseLogEntries($filePath)->count();
}

/**
 * Get log entry at specific index
 *
 * @param string $filePath Path to log file
 * @param int $entryIndex Entry index
 * @return string|null Log entry content
 */
function getLogEntryAt(string $filePath, int $entryIndex): ?string
{
    return parseLogEntries($filePath)->get($entryIndex);
}

/**
 * Format parsed log for Telegram display
 *
 * @param Parser $parser Parsed log object
 * @param int $currentEntry Current entry number (1-based)
 * @param int $totalEntries Total entries count
 * @param string $fileName File name
 * @return string Formatted text
 */
function formatParsedLog(Parser $parser, int $currentEntry, int $totalEntries, string $fileName): string
{
    $levelEmoji = match (strtoupper($parser->level() ?? '')) {
        'ERROR' => '🔴',
        'WARNING' => '🟡',
        'INFO' => '🔵',
        'DEBUG' => '⚪',
        'CRITICAL' => '🔥',
        'ALERT' => '🚨',
        default => '⚫'
    };

    $parts = [];

    $parts[] = "📄 <b>{$fileName}</b>";
    $parts[] = "📋 <b>Entry #{$currentEntry} of {$totalEntries}</b>\n";

    if ($parser->level()) {
        $parts[] = "{$levelEmoji} <b>Level:</b> {$parser->level()}";
    }

    if ($parser->datetime()) {
        $parts[] = "⏰ <b>Time:</b> {$parser->datetime()}";
    }

    if ($parser->type()) {
        $parts[] = "🏷 <b>Type:</b> <code>{$parser->type()}</code>";
    }

    if ($parser->userId()) {
        $parts[] = "👤 <b>User ID:</b> <code>{$parser->userId()}</code>";
    }

    if ($parser->message()) {
        $parts[] = "\n📝 <b>Message:</b>";
        $parts[] = "<pre><code class='language-php'>" . mb_substr($parser->message(), 0, 3500) . "</code></pre>";
    }

    if ($parser->file() && $parser->line()) {
        $basePath = base_path();
        $cleanFile = str_replace($basePath . '/', '', $parser->file());

        $parts[] = "\n📍 <b>Location:</b>";
        $parts[] = "<code>" . htmlspecialchars($cleanFile, ENT_QUOTES, 'UTF-8') . ":{$parser->line()}</code>";
    }

    if (!empty($parser->trace())) {
        $parts[] = "\n📚 <b>Stack Trace:</b>";

        $traceHtml = collect($parser->trace())
            ->map(function ($item, $index) {
                $basePath = base_path();
                $cleanFile = str_replace($basePath . '/', '', $item['file']);
                $file = htmlspecialchars($cleanFile, ENT_QUOTES, 'UTF-8');

                $callInfo = '';
                if (!empty($item['call'])) {
                    $callInfo = "\n  " . htmlspecialchars($item['call'], ENT_QUOTES, 'UTF-8');
                }

                return sprintf(
                    "<b>#%d.</b>\n  %s<b>:</b>%d%s\n<b>--------</b>",
                    $index + 1,
                    $file,
                    $item['line'],
                    $callInfo
                );
            })
            ->implode("\n");

        $parts[] = "<blockquote expandable>{$traceHtml}</blockquote>";
    }

    return implode("\n", $parts);
}

/**
 * Build log entry navigation keyboard
 *
 * @param int $pointerIndex File index
 * @param int $entryIndex Current entry index
 * @param int $totalEntries Total entries
 * @return array Keyboard array
 */
function buildLogEntryKeyboard(int $pointerIndex, int $entryIndex, int $totalEntries): string
{
    $prevEntry = $entryIndex > 0 ? $entryIndex - 1 : -1;
    $nextEntry = $entryIndex < $totalEntries - 1 ? $entryIndex + 1 : -1;

    $firstDisabled = $entryIndex === 0;
    $prevDisabled = $entryIndex === 0;
    $nextDisabled = $entryIndex === $totalEntries - 1;
    $lastDisabled = $entryIndex === $totalEntries - 1;

    return Keyboard::inlineKeyboardMarkup(
        Make::row(
            Make::callbackData($firstDisabled ? '⏮ —' : '⏮ First', $firstDisabled ? 'noop' : "watchdog_log_entry_{$pointerIndex}_0"),
            Make::callbackData($prevDisabled ? '⬅️ —' : '⬅️ Prev', $prevDisabled ? 'noop' : "watchdog_log_entry_{$pointerIndex}_{$prevEntry}"),
            Make::callbackData($nextDisabled ? '➡️ —' : '➡️ Next', $nextDisabled ? 'noop' : "watchdog_log_entry_{$pointerIndex}_{$nextEntry}"),
            Make::callbackData($lastDisabled ? '⏭ —' : '⏭ Last', $lastDisabled ? 'noop' : "watchdog_log_entry_{$pointerIndex}_" . ($totalEntries - 1))
        ),
        Make::row(
            Make::callbackData('🏠 Home', "watchdog_log_load_{$pointerIndex}"),
        )
    )->get();
}

/**
 * Render log page (legacy support - now shows single entry)
 *
 * @param string $filePath Path to log file
 * @param int $entryIndex Entry index
 * @return string|null Formatted page content
 */
function renderLogPage(string $filePath, int $entryIndex): ?string
{
    if (!File::exists($filePath)) {
        return null;
    }

    $logEntries = parseLogEntries($filePath);
    $totalEntries = $logEntries->count();

    if ($totalEntries === 0) {
        return "📄 " . basename($filePath) . "\n\n📭 Empty file";
    }

    $entryIndex = max(0, min($entryIndex, $totalEntries - 1));
    $logEntry = $logEntries->get($entryIndex);

    if (!$logEntry) {
        return null;
    }

    $parser = new Parser($logEntry);
    return formatParsedLog($parser, $entryIndex + 1, $totalEntries, basename($filePath));
}

/**
 * Calculate total pages for file pagination (now returns total entries)
 *
 * @param string $filePath Path to file
 * @return int Total entries
 */
function paginateFile(string $filePath): int
{
    return getTotalLogEntries($filePath);
}

/**
 * Format file size to human readable format
 *
 * @param int $bytes File size in bytes
 * @return string Formatted size
 */
function formatSize(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    if ($bytes === 0) {
        return '0 B';
    }

    $power = floor(log($bytes, 1024));
    $power = min($power, count($units) - 1);

    return round($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
}

/**
 * Build tree structure of directory
 *
 * @param string $dir Directory path
 * @param string $prefix Prefix for tree lines
 * @param int|null $selectedIndex Selected file index
 * @param int $currentIndex Current iteration index
 * @return string Tree structure
 */
function buildTree(
    string $dir,
    string $prefix = '',
    ?int   $selectedIndex = null,
    int    &$currentIndex = 0
): string
{
    if (!File::isDirectory($dir)) {
        return '';
    }

    $output = '';
    $items = collect(File::allFiles($dir))
        ->merge(File::directories($dir))
        ->sortBy(fn($item) => [!is_dir($item), basename($item)])
        ->values();

    $items->each(function ($item, $index) use ($items, $prefix, $selectedIndex, &$currentIndex, &$output, $dir) {
        $isLast = $index === $items->count() - 1;
        $connector = $isLast ? "└── " : "├── ";
        $name = is_string($item) ? basename($item) : $item->getFilename();
        $path = is_string($item) ? $item : $item->getPathname();

        if (File::isDirectory($path)) {
            $pointer = ($selectedIndex !== null && $currentIndex === $selectedIndex) ? ' 👈' : '';
            $output .= $prefix . $connector . "📁 " . $name . "/" . $pointer . "\n";

            $newPrefix = $prefix . ($isLast ? "    " : "│   ");
            $output .= buildTree($path, $newPrefix, $selectedIndex, $currentIndex);
            $currentIndex++;
        } else {
            $pointer = ($selectedIndex !== null && $currentIndex === $selectedIndex) ? ' 👈' : '';
            $size = formatSize(File::size($path));
            $output .= $prefix . $connector . "📄 " . $name . " ({$size})" . $pointer . "\n";
            $currentIndex++;
        }
    });

    return $output;
}

/**
 * Get file at specific index
 *
 * @param string $root Root directory
 * @param int $index File index
 * @return string|null File path
 */
function getFileAtIndex(string $root, int $index): ?string
{
    $files = collect(File::allFiles($root))
        ->map(fn($file) => $file->getPathname())
        ->sort()
        ->values();

    return $files->get($index);
}

/**
 * Delete file at specific index
 *
 * @param string $root Root directory
 * @param int $index File index
 * @return bool Success status
 */
function deleteFileAtIndex(string $root, int $index): bool
{
    $filePath = getFileAtIndex($root, $index);

    if (!$filePath || !File::exists($filePath)) {
        return false;
    }

    return File::delete($filePath);
}

/**
 * Get log statistics
 *
 * @param string $root Root directory
 * @return array Statistics
 */
function getLogStats(string $root): array
{
    if (!File::isDirectory($root)) {
        return [
            'total_files' => 0,
            'total_size' => '0 B',
            'oldest_file' => null,
            'newest_file' => null,
        ];
    }

    $files = collect(File::allFiles($root));

    if ($files->isEmpty()) {
        return [
            'total_files' => 0,
            'total_size' => '0 B',
            'oldest_file' => null,
            'newest_file' => null,
        ];
    }

    return [
        'total_files' => $files->count(),
        'total_size' => formatSize($files->sum(fn($file) => $file->getSize())),
        'oldest_file' => $files->min(fn($file) => $file->getMTime()),
        'newest_file' => $files->max(fn($file) => $file->getMTime()),
    ];
}

/**
 * Search in log files
 *
 * @param string $root Root directory
 * @param string $query Search query
 * @return Collection Search results
 */
function searchInLogs(string $root, string $query): Collection
{
    if (!File::isDirectory($root)) {
        return collect();
    }

    return collect(File::allFiles($root))
        ->flatMap(function ($file) use ($query) {
            return collect(File::lines($file->getPathname()))
                ->filter(fn($line) => Str::contains($line, $query, true))
                ->map(fn($line, $lineNumber) => [
                    'file' => $file->getFilename(),
                    'line' => $lineNumber + 1,
                    'content' => trim($line),
                ]);
        })
        ->values();
}

/**
 * Get recent logs
 *
 * @param string $root Root directory
 * @param int $limit Number of recent logs
 * @return Collection Recent log files
 */
function getRecentLogs(string $root, int $limit = 10): Collection
{
    if (!File::isDirectory($root)) {
        return collect();
    }

    return collect(File::allFiles($root))
        ->sortByDesc(fn($file) => $file->getMTime())
        ->take($limit)
        ->map(fn($file) => [
            'name' => $file->getFilename(),
            'path' => $file->getPathname(),
            'size' => formatSize($file->getSize()),
            'modified' => date('Y-m-d H:i:s', $file->getMTime()),
        ])
        ->values();
}
