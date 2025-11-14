<?php

namespace LaraGram\Watchdog\Manager\Controllers;

use LaraGram\Request\Request;
use LaraGram\Support\Facades\File;
use LaraGram\Watchdog\Manager\Parser;

class LogManagerController
{
    /**
     * Show log file list
     */
    public function index(Request $request)
    {
        $root = storage_path("logs");

        [$text, $keyboard] = makeLogMessageAndKeyboard($root, 0);

        $escapedText = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $message = sprintf(
            "Choose a file to view.\n<pre><code class=\"language-php\">\n%s</code></pre>",
            $escapedText
        );

        $request->mode(64)->sendMessage(
            chat()->id,
            $message,
            parse_mode: "HTML",
            reply_markup: $keyboard
        );
    }

    /**
     * Load different file
     */
    public function loadFiles(Request $request, $pointerIndex)
    {
        $root = storage_path("logs");

        $pointerIndex = filter_var($pointerIndex, FILTER_VALIDATE_INT);

        if ($pointerIndex === false || $pointerIndex === -1) {
            return;
        }

        [$text, $keyboard] = makeLogMessageAndKeyboard($root, $pointerIndex);

        $escapedText = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $message = sprintf(
            "Choose a file to view.\n<pre><code class=\"language-php\">\n%s</code></pre>",
            $escapedText
        );

        $request->mode(64)->editMessageText(
            $message,
            callback_query()->message->chat->id,
            callback_query()->message->message_id,
            parse_mode: "HTML",
            reply_markup: $keyboard
        );
    }

    /**
     * Read log file (show last entry)
     */
    public function readFile(Request $request, $pointerIndex)
    {
        $root = storage_path("logs");

        $pointerIndex = filter_var($pointerIndex, FILTER_VALIDATE_INT);
        if ($pointerIndex === false) {
            return;
        }

        $filePath = getFileAtIndex($root, $pointerIndex);

        if (!$filePath || !File::isFile($filePath)) {
            $request->answerCallbackQuery(
                callback_query()->id,
                text: "⚠️ File not found",
                show_alert: true
            );
            return;
        }

        $totalEntries = getTotalLogEntries($filePath);

        if ($totalEntries === 0) {
            $request->answerCallbackQuery(
                callback_query()->id,
                text: "📭 Log file is empty",
                show_alert: true
            );
            return;
        }

        $entryIndex = $totalEntries - 1;

        $logEntry = getLogEntryAt($filePath, $entryIndex);
        if (!$logEntry) {
            return;
        }

        $parser = new Parser($logEntry);
        $text = formatParsedLog($parser, $entryIndex + 1, $totalEntries, basename($filePath));

        $keyboard = buildLogEntryKeyboard($pointerIndex, $entryIndex, $totalEntries);

        $request->mode(64)->editMessageText(
            $text,
            callback_query()->message->chat->id,
            callback_query()->message->message_id,
            parse_mode: "HTML",
            reply_markup: $keyboard
        );
    }

    /**
     * Read specific log entry
     */
    public function readEntry(Request $request, $pointerIndex, $entryIndex)
    {
        $root = storage_path("logs");

        $pointerIndex = filter_var($pointerIndex, FILTER_VALIDATE_INT);
        $entryIndex = filter_var($entryIndex, FILTER_VALIDATE_INT);

        if ($pointerIndex === false || $entryIndex === false) {
            return;
        }

        $filePath = getFileAtIndex($root, $pointerIndex);

        if (!$filePath || !File::isFile($filePath)) {
            return;
        }

        $totalEntries = getTotalLogEntries($filePath);

        if ($totalEntries === 0) {
            return;
        }

        $entryIndex = max(0, min($entryIndex, $totalEntries - 1));

        $logEntry = getLogEntryAt($filePath, $entryIndex);
        if (!$logEntry) {
            return;
        }

        $parser = new Parser($logEntry);
        $text = formatParsedLog($parser, $entryIndex + 1, $totalEntries, basename($filePath));

        $keyboard = buildLogEntryKeyboard($pointerIndex, $entryIndex, $totalEntries);

        $request->mode(64)->editMessageText(
            $text,
            callback_query()->message->chat->id,
            callback_query()->message->message_id,
            parse_mode: "HTML",
            reply_markup: $keyboard
        );
    }

    /**
     * Delete log file
     */
    public function deleteFile(Request $request, $pointerIndex)
    {
        $root = storage_path("logs");

        $pointerIndex = filter_var($pointerIndex, FILTER_VALIDATE_INT);

        if ($pointerIndex === false) {
            return;
        }

        $filePath = getFileAtIndex($root, $pointerIndex);

        if (!$filePath || !File::isFile($filePath)) {
            $request->answerCallbackQuery(
                callback_query()->id,
                text: "⚠️ File not found",
                show_alert: true
            );
            return;
        }

        $fileName = basename($filePath);

        if ($fileName === '.gitignore') {
            $request->answerCallbackQuery(
                callback_query()->id,
                text: "🚫 This file cannot be deleted",
                show_alert: true
            );
            return;
        }

        if (File::delete($filePath)) {
            $request->answerCallbackQuery(
                callback_query()->id,
                text: "🗑️ {$fileName} deleted successfully",
                show_alert: true
            );

            [$text, $keyboard] = makeLogMessageAndKeyboard($root, 0);

            $escapedText = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            $message = sprintf(
                "Choose a file to view.\n<pre><code class=\"language-php\">\n%s</code></pre>",
                $escapedText
            );

            $request->mode(64)->editMessageText(
                $message,
                callback_query()->message->chat->id,
                callback_query()->message->message_id,
                parse_mode: "HTML",
                reply_markup: $keyboard
            );

        } else {
            $request->answerCallbackQuery(
                callback_query()->id,
                text: "⚠️ Failed to delete {$fileName}",
                show_alert: true
            );
        }
    }
}
