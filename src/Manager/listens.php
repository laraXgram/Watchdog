<?php

use LaraGram\Support\Facades\Bot;
use LaraGram\Watchdog\Manager\Controllers\LogManagerController;
use LaraGram\Watchdog\Manager\Middleware\IsManagerAdmin;

Bot::controller(LogManagerController::class)
    ->name('watchdog.')
    ->group(function () {
        Bot::onCommand(config('watchdog.manager.command'), 'index')->name('index');
        Bot::onCallbackQueryData('watchdog_log_load_{pointerIndex}', 'loadFiles')->name('load_files');
        Bot::onCallbackQueryData('watchdog_log_read_{pointerIndex}', 'readFile')->name('read_file');
        Bot::onCallbackQueryData('watchdog_log_entry_{pointerIndex}_{entryIndex}', 'readEntry')->name('read_entry');
        Bot::onCallbackQueryData('watchdog_log_delete_{pointerIndex}', 'deleteFile')->name('delete_file');
    });
