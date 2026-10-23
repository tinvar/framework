<?php

declare(strict_types=1);

namespace Tinvar\Auth;

/** One build-rule failure. */
final readonly class Violation
{
    public function __construct(
        public string $rule,
        public string $where,
        public string $message,
    ) {}

    public function __toString(): string
    {
        return "[{$this->rule}] {$this->where}: {$this->message}";
    }
}
