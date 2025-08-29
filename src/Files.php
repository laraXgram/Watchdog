<?php

namespace LaraGram\Watchdog;

use LaraGram\Support\Collection;

class Files
{
    /**
     * Creates a new instance of the files.
     */
    public function __construct(
        protected string $path,
    ) {
        //
    }

    /**
     * Returns the list of files.
     *
     * @return \LaraGram\Support\Collection<int, File>
     */
    public function all(): Collection
    {
        $files = glob($this->path.'/*.watchdog') ?: [];

        return collect($files)
            ->map(fn (string $file) => new File($file));
    }
}
