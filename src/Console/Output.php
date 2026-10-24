<?php

declare(strict_types=1);

namespace Tinvar\Console;

/** Where a command writes: lines for the person running it, and errors apart from them. */
final readonly class Output
{
    /**
     * @param \Closure(string): void $out
     * @param \Closure(string): void $err
     */
    public function __construct(private \Closure $out, private \Closure $err) {}

    public static function standard(): self
    {
        return new self(
            static function (string $line): void {
                fwrite(STDOUT, $line . "\n");
            },
            static function (string $line): void {
                fwrite(STDERR, $line . "\n");
            },
        );
    }

    public function line(string $line = ''): void
    {
        ($this->out)($line);
    }

    public function error(string $line): void
    {
        ($this->err)($line);
    }
}
