<?php

declare(strict_types=1);

namespace Tinvar\Console\NewApp;

/** What `tinvar new`'s command line says: each --name=value, each switch, each --kit and the directory. */
final readonly class NewAppFlags
{
    /** The options that take a value. */
    public const array OPTIONS = ['database', 'database-host', 'database-port', 'database-name', 'database-user', 'tenancy', 'mail', 'deploy', 'from'];

    /** The switches. */
    public const array SWITCHES = ['accounts', 'no-accounts', 'no-install'];

    /**
     * @param array<string, string> $options
     * @param array<string, true> $switches
     * @param list<string> $kits
     */
    public function __construct(
        public array $options = [],
        public array $switches = [],
        public array $kits = [],
        public ?string $directory = null,
    ) {}

    /**
     * @param list<string> $arguments
     * @throws \InvalidArgumentException for anything else
     */
    public static function parse(array $arguments): self
    {
        $options = [];
        $switches = [];
        $kits = [];
        $directory = null;
        foreach ($arguments as $argument) {
            if (preg_match('/^--([a-z-]+)=(.+)\z/', $argument, $m) === 1 && \in_array($m[1], self::OPTIONS, true)) {
                $options[$m[1]] = $m[2];
            } elseif (preg_match('/^--kit=(.+)\z/', $argument, $m) === 1) {
                $kits[] = $m[1];
            } elseif (str_starts_with($argument, '--') && \in_array(substr($argument, 2), self::SWITCHES, true)) {
                $switches[substr($argument, 2)] = true;
            } elseif (!str_starts_with($argument, '-') && $directory === null) {
                $directory = $argument;
            } else {
                throw new \InvalidArgumentException("'{$argument}' is not something tinvar new takes.");
            }
        }

        return new self($options, $switches, $kits, $directory);
    }

    public function option(string $name): ?string
    {
        return $this->options[$name] ?? null;
    }

    public function has(string $switch): bool
    {
        return isset($this->switches[$switch]);
    }
}
