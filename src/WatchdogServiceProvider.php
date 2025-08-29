<?php

namespace LaraGram\Watchdog;

use LaraGram\Console\Events\CommandStarting;
use LaraGram\Contracts\Foundation\Application;
use LaraGram\Log\Events\MessageLogged;
use LaraGram\Queue\Events\JobExceptionOccurred;
use LaraGram\Queue\Events\JobProcessed;
use LaraGram\Queue\Events\JobProcessing;
use LaraGram\Support\ServiceProvider;
use LaraGram\Watchdog\Console\Commands\WatchdogCommand;

class WatchdogServiceProvider extends ServiceProvider
{
    /**
     * Registers the application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            Files::class,
            fn (Application $app) => new Files($app->storagePath('watchdog'))
        );

        $this->app->singleton(Handler::class, fn (Application $app) => new Handler(
            $app,
            $app->make(Files::class),
            $app->runningInConsole(),
        ));
    }

    /**
     * Bootstraps the application services.
     */
    public function boot(): void
    {
        /** @var \LaraGram\Contracts\Events\Dispatcher $events */
        $events = $this->app->make('events');

        $events->listen(MessageLogged::class, function (MessageLogged $messageLogged) {
            /** @var Handler $handler */
            $handler = $this->app->make(Handler::class);

            $handler->log($messageLogged);
        });

        $events->listen([CommandStarting::class, JobProcessing::class, JobExceptionOccurred::class], function (CommandStarting|JobProcessing|JobExceptionOccurred $lifecycleEvent) {
            /** @var Handler $handler */
            $handler = $this->app->make(Handler::class);

            $handler->setLastLifecycleEvent($lifecycleEvent);
        });

        $events->listen([JobProcessed::class], function () {
            /** @var Handler $handler */
            $handler = $this->app->make(Handler::class);

            $handler->setLastLifecycleEvent(null);
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                WatchdogCommand::class,
            ]);
        }
    }
}
