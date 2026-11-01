<?php

declare(strict_types=1);

namespace Tinvar\Console\Kits;

/** The application a kit installs into (AppKit). */
final readonly class KitTarget
{
    /**
     * @param string $root the application: its composer.json, app/ and resources/
     * @param string $namespace its root namespace, `App`
     * @param string $console how a step names its CLI, `bin/tinvar`
     * @param string $migrations where its migrations are
     */
    public function __construct(
        public string $root,
        public string $namespace,
        public string $console,
        public string $migrations,
    ) {}
}
