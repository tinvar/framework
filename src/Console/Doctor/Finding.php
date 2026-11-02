<?php

declare(strict_types=1);

namespace Tinvar\Console\Doctor;

/**
 * One thing a doctor check found: what it is, and -- for a warning or a
 * problem -- the exact fix, as a command to run or a variable to set
 * (ADR 0157). Never a secret's value: a finding names variables and files.
 */
final readonly class Finding
{
    public function __construct(
        public Severity $severity,
        public string $message,
        public ?string $fix = null,
        public ?SafeFix $safeFix = null,
    ) {}

    public static function passed(string $message): self
    {
        return new self(Severity::Passed, $message);
    }

    public static function warning(string $message, string $fix, ?SafeFix $safeFix = null): self
    {
        return new self(Severity::Warning, $message, $fix, $safeFix);
    }

    public static function problem(string $message, string $fix, ?SafeFix $safeFix = null): self
    {
        return new self(Severity::Problem, $message, $fix, $safeFix);
    }

    public static function skipped(string $message): self
    {
        return new self(Severity::Skipped, $message);
    }
}
