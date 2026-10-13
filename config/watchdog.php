<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CLI Log Capture
    |--------------------------------------------------------------------------
    |
    | enabled: Enable or disable capturing CLI logs.
    | levels:  List of log levels to capture.
    |          Options: DEBUG, INFO, NOTICE, WARNING, ERROR, CRITICAL, ALERT, EMERGENCY
    |
    */

    'cli' => [
        'enabled' => true,
        'levels' => ['*']
    ],


    /*
    |--------------------------------------------------------------------------
    | Automatic Log Reporting
    |--------------------------------------------------------------------------
    |
    | enabled:    Enable or disable sending logs/errors to Telegram.
    | chats:      Telegram chat IDs that should receive the logs.
    | levels:     Log levels that will be reported.
    |             Options: DEBUG, INFO, NOTICE, WARNING, ERROR, CRITICAL, ALERT, EMERGENCY
    | connection: Bot Connection for sending reports. Null uses the default connection.
    | repeat:     Counts a log message that is reported again and again: instead of
    |             a new message, the last report is edited with a counter. Another
    |             log message in between starts a new report.
    |             store: Cache store that keeps the last report and its message IDs.
    |                    Null uses the default cache store.
    |
    */

    'report' => [
        'enabled' => false,
        'chats' => [
            //
        ],
        'levels' => ['*'],
        'connection' => null,
        'repeat' => [
            'enabled' => true,
            'store' => null,
        ],
    ],


    /*
    |--------------------------------------------------------------------------
    | Log Manager Access
    |--------------------------------------------------------------------------
    |
    | enabled: Enable or disable the log manager.
    | command: The command name that triggers the Log Manager panel.
    |          Example: if set to "log", sending "/log" will open the panel.
    | admins:  List of Telegram user IDs allowed to access the Log Manager.
    |          Only these users can open and interact with the panel.
    |
    */

    'manager' => [
        'enabled' => true,
        'command' => 'log',
        'admins' => [
            //
        ]
    ],

];
