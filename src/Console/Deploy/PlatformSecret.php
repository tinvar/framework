<?php

declare(strict_types=1);

namespace Tinvar\Console\Deploy;

/**
 * A secret the deployment needs in the platform's secret store: generated
 * by `deploy:init` into a file outside the project (StateFile), never into
 * a file the recipe writes, and moved into the store by a command that
 * reads the file and prints nothing.
 */
final readonly class PlatformSecret
{
    /**
     * @param string $variable what the application reads it from: `TINVAR_APP_KEY`
     * @param string $extension its state file's: `fly.key`
     * @param string $what what a person reads: `the production app key`
     */
    public function __construct(
        public string $variable,
        public string $extension,
        public string $what,
    ) {}
}
