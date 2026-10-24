<?php

declare(strict_types=1);

namespace Tinvar\Observability;

/** Where a Logger's lines go: one JSON object each, already redacted. */
interface LogSink
{
    /** Writes one line, without its newline. May throw; the Logger catches. */
    public function write(string $line): void;
}
