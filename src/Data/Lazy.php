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
 *
 * A Lazy made inside a scope set up by within() -- the ORM's readOnly(), for
 * a loader or query body -- resolves under that scope too. The encoder
 * resolves it after the body has returned and the read-only transaction has
 * ended, so without this its resolver could write during a GET, and during a
 * prefetch on hover.
 */
final class Lazy
{
    /**
     * Scopes a Lazy made now is resolved under, innermost last: this stack for
     * the main context, and one per fiber below. Per fiber because the render
     * scheduler interleaves loaders and query bodies, as the ORM's own
     * read-only state is.
     *
     * @var list<\Closure(\Closure(): mixed): mixed>
     */
    private static array $scopes = [];

    /** @var \WeakMap<object, list<\Closure(\Closure(): mixed): mixed>>|null keyed by Fiber */
    private static ?\WeakMap $scopesByFiber = null;

    private bool $resolved = false;
    private mixed $value = null;

    /** @param \Closure(): mixed $resolver */
    private function __construct(private readonly \Closure $resolver) {}

    /** @param \Closure(): mixed $resolver */
    public static function of(\Closure $resolver): self
    {
        $scope = self::currentScope();

        return new self($scope === null ? $resolver : static fn (): mixed => $scope($resolver));
    }

    /**
     * Runs $work so that every Lazy made inside it resolves under $scope.
     *
     * @internal the ORM's readOnly() is the caller
     * @template T
     * @param \Closure(\Closure(): mixed): mixed $scope runs deferred work under the scope
     * @param \Closure(): T $work
     * @return T
     */
    public static function within(\Closure $scope, \Closure $work): mixed
    {
        $fiber = \Fiber::getCurrent();
        $scopes = self::scopesIn($fiber);
        $scopes[] = $scope;
        self::setScopesIn($fiber, $scopes);

        try {
            return $work();
        } finally {
            array_pop($scopes);
            self::setScopesIn($fiber, $scopes);
        }
    }

    /**
     * The innermost scope in force: this fiber's own, or else the main
     * context's, which a fiber started inside a read-only block runs within.
     *
     * @return (\Closure(\Closure(): mixed): mixed)|null
     */
    private static function currentScope(): ?\Closure
    {
        $own = self::scopesIn(\Fiber::getCurrent());
        if ($own !== []) {
            return $own[array_key_last($own)];
        }

        return self::$scopes === [] ? null : self::$scopes[array_key_last(self::$scopes)];
    }

    /**
     * @param \Fiber<mixed, mixed, mixed, mixed>|null $fiber
     * @return list<\Closure(\Closure(): mixed): mixed>
     */
    private static function scopesIn(?\Fiber $fiber): array
    {
        return $fiber === null ? self::$scopes : (self::$scopesByFiber[$fiber] ?? []);
    }

    /**
     * @param \Fiber<mixed, mixed, mixed, mixed>|null      $fiber
     * @param list<\Closure(\Closure(): mixed): mixed>     $scopes
     */
    private static function setScopesIn(?\Fiber $fiber, array $scopes): void
    {
        if ($fiber === null) {
            self::$scopes = $scopes;

            return;
        }

        self::$scopesByFiber ??= new \WeakMap();
        if ($scopes === []) {
            unset(self::$scopesByFiber[$fiber]);
        } else {
            self::$scopesByFiber[$fiber] = $scopes;
        }
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
