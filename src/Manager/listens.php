<?php

use LaraGram\Support\Facades\Bot;
use LaraGram\Watchdog\Manager\Controllers\LogManagerController;

Bot::controller(LogManagerController::class)
    ->name('watchdog.')
    ->group(function () {
        Bot::onCommand(config('watchdog.manager.command'), 'index');
        Bot::onCallbackQueryData('watchdog_log_load_{pointerIndex}', 'loadFiles');
        Bot::onCallbackQueryData('watchdog_log_read_{pointerIndex}', 'readFile');
        Bot::onCallbackQueryData('watchdog_log_entry_{pointerIndex}_{entryIndex}', 'readEntry');
        Bot::onCallbackQueryData('watchdog_log_delete_{pointerIndex}', 'deleteFile');
    });
