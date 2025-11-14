<?php

use LaraGram\Support\Facades\Bot;
use LaraGram\Request\Request;
use LaraGram\Watchdog\Manager\Controllers\LogManagerController;

Bot::on('.', function (Request $request) {
    $request->sendMessage(chat()->id);
});

Bot::onCommand('log', [LogManagerController::class, 'index']);
Bot::onCallbackQueryData('log_load_{pointerIndex}', [LogManagerController::class, 'loadFiles']);
Bot::onCallbackQueryData('log_read_{pointerIndex}', [LogManagerController::class, 'readFile']);
Bot::onCallbackQueryData('log_entry_{pointerIndex}_{entryIndex}', [LogManagerController::class, 'readEntry']);
Bot::onCallbackQueryData('log_delete_{pointerIndex}', [LogManagerController::class, 'deleteFile']);

