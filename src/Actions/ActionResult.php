<?php

declare(strict_types=1);

namespace Tinvar\Actions;

/**
 * What an action did, and what should be re-rendered because of it.
 *
 * Every constructor and builder is #[\NoDiscard]: dropping the result loses
 * the revalidation and the flash silently, and the page simply does not
 * update -- a confusing bug to chase.
 *
 * The spec says "ActionResult is marked #[\NoDiscard]". PHP 8.5 only allows
 * that attribute on functions and methods, not on a class, so it goes on
 * everything that returns one, which has the same effect at every call site.
 */
final readonly class ActionResult
{
    /**
     * @param list<string>         $revalidatedRoutes route names to re-render
     * @param list<string>         $revalidatedTags   cache tags to invalidate
     * @param array<string, mixed> $flash             a side channel, kept out of the RSC tree
     */
    private function __construct(
        public int $status = 200,
        public array $revalidatedRoutes = [],
        public array $revalidatedTags = [],
        public array $flash = [],
        public ?string $redirect = null,
        public mixed $value = null,
    ) {}

    #[\NoDiscard]
    public static function ok(mixed $value = null): self
    {
        return new self(value: $value);
    }

    /** Re-render these routes through their own full GET pipeline. */
    #[\NoDiscard]
    public static function revalidate(string ...$routes): self
    {
        return new self(revalidatedRoutes: array_values($routes));
    }

    /** Invalidate these cache tags. */
    #[\NoDiscard]
    public static function revalidateTags(string ...$tags): self
    {
        return new self(revalidatedTags: array_values($tags));
    }

    /**
     * Same-origin only; Response::redirect enforces it when this is turned
     * into a response.
     */
    #[\NoDiscard]
    public static function redirect(string $location): self
    {
        return new self(status: 303, redirect: $location);
    }

    #[\NoDiscard]
    public function withRoutes(string ...$routes): self
    {
        return new self(
            $this->status,
            [...$this->revalidatedRoutes, ...array_values($routes)],
            $this->revalidatedTags,
            $this->flash,
            $this->redirect,
            $this->value,
        );
    }

    #[\NoDiscard]
    public function withTags(string ...$tags): self
    {
        return new self(
            $this->status,
            $this->revalidatedRoutes,
            [...$this->revalidatedTags, ...array_values($tags)],
            $this->flash,
            $this->redirect,
            $this->value,
        );
    }

    /**
     * "A side channel kept out of the RSC tree and out of history."
     *
     * @param array<string, mixed> $flash
     */
    #[\NoDiscard]
    public function flash(array $flash): self
    {
        return new self(
            $this->status,
            $this->revalidatedRoutes,
            $this->revalidatedTags,
            [...$this->flash, ...$flash],
            $this->redirect,
            $this->value,
        );
    }

    #[\NoDiscard]
    public function withValue(mixed $value): self
    {
        return new self(
            $this->status,
            $this->revalidatedRoutes,
            $this->revalidatedTags,
            $this->flash,
            $this->redirect,
            $value,
        );
    }

    public function isRedirect(): bool
    {
        return $this->redirect !== null;
    }
}
