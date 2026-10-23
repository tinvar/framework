<?php

declare(strict_types=1);

namespace Tinvar\Data;

/**
 * A Data field whose value is produced on demand. Codegen maps `Lazy<T>` to
 * `Promise<T>` in TypeScript.
 *
 * Phase 0 resolves these during encoding. Streaming an unresolved Lazy to the
 * renderer as a real promise is Level 3 lazy loading (tinvar-spec.md, "Lazy
 * loading") and belongs to a later phase.
 */
final class Lazy
{
    private bool $resolved = false;
    private mixed $value = null;

    /** @param \Closure(): mixed $resolver */
    private function __construct(private readonly \Closure $resolver) {}

    /** @param \Closure(): mixed $resolver */
    public static function of(\Closure $resolver): self
    {
        return new self($resolver);
    }

    public static function value(mixed $value): self
    {
        $lazy = new self(static fn () => $value);
        $lazy->resolved = true;
        $lazy->value = $value;

        return $lazy;
    }

    public function resolve(): mixed
    {
        if (!$this->resolved) {
            $this->value = ($this->resolver)();
            $this->resolved = true;
        }

        return $this->value;
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }
}
