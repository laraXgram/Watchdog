<?php

namespace LaraGram\Watchdog\Contracts;

use LaraGram\Watchdog\ValueObjects\MessageLogged;

interface Printer
{
    /**
     * Prints the given message logged.
     */
    public function print(MessageLogged $messageLogged): void;
}
