<?php

namespace LaraGram\Watchdog;

use LaraGram\Console\Events\CommandStarting;
use LaraGram\Contracts\Container\Container;
use LaraGram\Log\Context\Repository as ContextRepository;
use LaraGram\Log\Events\MessageLogged;
use LaraGram\Queue\Events\JobExceptionOccurred;
use LaraGram\Queue\Events\JobProcessing;
use LaraGram\Support\Collection;
use LaraGram\Support\Str;
use LaraGram\Log\LogLevel;
use Throwable;

class Handler
{
    /**
     * The last lifecycle captured event.
     */
    protected CommandStarting|JobProcessing|JobExceptionOccurred|null $lastLifecycleEvent = null;

    /**
     * The artisan command being executed, if any.
     */
    protected ?string $commanderCommand = null;

    /**
     * Creates a new instance of the handler.
     */
    public function __construct(
        protected Container $container,
        protected Files $files,
        protected bool $runningInConsole,
    ) {
        //
    }

    /**
     * Reports the given message logged.
     */
    public function log(MessageLogged $messageLogged): void
    {
        $files = $this->files->all();

        if ($files->isEmpty()) {
            return;
        }

        if (
            $messageLogged->level === LogLevel::WARNING
            && Str::contains($messageLogged->message, ['deprecated', 'Deprecated', '[\ReturnTypeWillChange]'])
        ) {
            return;
        }

        $context = $this->context($messageLogged);

        $files->each(
            fn (File $file) => $file->log(
                $messageLogged->level,
                $messageLogged->message,
                $context,
            ),
        );
    }

    /**
     * Sets the last application lifecycle event.
     */
    public function setLastLifecycleEvent(CommandStarting|JobProcessing|JobExceptionOccurred|null $event): void
    {
        if ($event instanceof CommandStarting) {
            $this->commanderCommand = $event->command;
        }

        $this->lastLifecycleEvent = $event;
    }

    /**
     * Builds the context array.
     *
     * @return array<string, mixed>
     */
    protected function context(MessageLogged $messageLogged): array
    {
        $context = ['__watchdog' => ['origin' => match (true) {
            $this->commanderCommand && $this->lastLifecycleEvent && in_array($this->lastLifecycleEvent::class, [JobProcessing::class, JobExceptionOccurred::class]) => [
                'type' => 'queue',
                'command' => $this->commanderCommand,
                'queue' => $this->lastLifecycleEvent->job->getQueue(),
                'job' => $this->lastLifecycleEvent->job->resolveName(),
            ],
            $this->runningInConsole => [
                'type' => 'console',
                'command' => $this->commanderCommand,
            ],
            default => [
                'type' => 'bot',
                'method' => app('request')->method(),
                'pattern' => app('request')->method(), // TODO: replate with pattern
                'user_id' => user()->id ?? "Unknown",
            ],
        }]];

        if (isset($messageLogged->context['exception']) && $this->lastLifecycleEvent instanceof JobExceptionOccurred) {
            if ($messageLogged->context['exception'] === $this->lastLifecycleEvent->exception) {
                $this->setLastLifecycleEvent(null);
            }
        }

        $context['__watchdog']['origin']['trace'] = isset($messageLogged->context['exception'])
        && $messageLogged->context['exception'] instanceof Throwable ? collect($messageLogged->context['exception']->getTrace())
            ->filter(fn (array $frame) => isset($frame['file']))
            ->map(fn (array $frame) => [
                'file' => $frame['file'],
                'line' => $frame['line'] ?? null,
            ])->values()
            : null;

        return collect($messageLogged->context)
            ->merge($context)
            ->when($this->container->bound(ContextRepository::class), function (Collection $context) {
                return $context->merge($this->container->make(ContextRepository::class)->all());
            })->toArray();
    }
}
