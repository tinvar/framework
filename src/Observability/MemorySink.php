<?php

declare(strict_types=1);

namespace Tinvar\Observability;

/** Keeps the lines, for tests to read back. */
final class MemorySink implements LogSink
{
    /** @var list<string> */
    public array $lines = [];

    public function write(string $line): void
    {
        $this->lines[] = $line;
    }

    /**
     * The lines, decoded.
     *
     * @return list<array<string, mixed>>
     */
    public function records(): array
    {
        return array_map(static function (string $line): array {
            /** @var array<string, mixed> */
            return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        }, $this->lines);
    }
}
