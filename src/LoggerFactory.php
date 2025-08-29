<?php

namespace LaraGram\Watchdog;

use LaraGram\Log\Logger\Formatter\JsonFormatter;
use LaraGram\Log\Logger\Handler\StreamHandler;
use LaraGram\Log\Logger\Level;
use LaraGram\Log\Logger\Logger;
use LaraGram\Log\LoggerInterface;

class LoggerFactory
{
    /**
     * Creates a new instance of the logger factory.
     */
    public function __construct(
        protected File $file,
    ) {
        //
    }

    /**
     * Creates a new instance of the logger.
     */
    public function create(): LoggerInterface
    {
        $handler = new StreamHandler($this->file->__toString(), Level::Debug);
        $handler->setFormatter(new JsonFormatter);

        return new Logger('watchdog', [$handler]);
    }
}
