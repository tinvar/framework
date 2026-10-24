<?php

declare(strict_types=1);

namespace Tinvar\Console;

/**
 * A command's arguments: positional ones in order, `--name=value` options
 * and bare `--name` flags.
 */
final readonly class Input
{
    /**
     * @param list<string>           $arguments
     * @param array<string, string>  $options
     * @param array<string, true>    $flags
     */
    private function __construct(
        public array $arguments,
        public array $options,
        public array $flags,
    ) {}

    /** @param list<string> $argv everything after the command's name */
    public static function parse(array $argv): self
    {
        $arguments = [];
        $options = [];
        $flags = [];
        $positionalOnly = false;
        foreach ($argv as $item) {
            if ($positionalOnly || !str_starts_with($item, '--')) {
                $arguments[] = $item;

                continue;
            }
            if ($item === '--') {
                $positionalOnly = true;

                continue;
            }
            if (preg_match('/^--([a-z][a-z0-9-]*)(?:=(.*))?\z/s', $item, $m) !== 1) {
                throw new \InvalidArgumentException("'{$item}' is not an option: write --name or --name=value.");
            }
            if (isset($m[2])) {
                $options[$m[1]] = $m[2];
            } else {
                $flags[$m[1]] = true;
            }
        }

        return new self($arguments, $options, $flags);
    }

    public function argument(int $position): ?string
    {
        return $this->arguments[$position] ?? null;
    }

    public function option(string $name): ?string
    {
        return $this->options[$name] ?? null;
    }

    public function flag(string $name): bool
    {
        return isset($this->flags[$name]);
    }

    /** The same input without one option, once the console has read it. */
    public function without(string $option): self
    {
        $options = $this->options;
        unset($options[$option]);

        return new self($this->arguments, $options, $this->flags);
    }
}
