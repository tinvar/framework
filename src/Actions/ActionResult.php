<?php

declare(strict_types=1);

namespace Tinvar\Actions;

use Tinvar\Data\Encoder;

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
     * @param list<string>         $revalidatedRoutes routes to re-render, each a route
     *        name ('racks.show') or the view of named pages ('racks/show')
     * @param list<string>         $revalidatedTags   cache tags to invalidate
     * @param array<string, mixed> $flash             a side channel, kept out of the RSC tree
     * @param bool                 $back              redirect to wherever the request came from; see back()
     * @param string|null          $location          redirect off this origin; see location()
     */
    private function __construct(
        public int $status = 200,
        public array $revalidatedRoutes = [],
        public array $revalidatedTags = [],
        public array $flash = [],
        public ?string $redirect = null,
        public mixed $value = null,
        public bool $back = false,
        public ?string $location = null,
    ) {
        // A redirect is answered with a 303 and no body, and there is no
        // session flash store to carry anything across it yet. A flash or a
        // value on one was dropped without a word -- the "Site archived"
        // message simply never appeared. Refused here, where every result is
        // built, until there is somewhere for it to go.
        if ($this->isRedirect() && ($flash !== [] || $value !== null)) {
            $where = $redirect !== null ? "to '{$redirect}'" : ($back ? 'back' : 'off this origin');

            throw new \LogicException(
                "An ActionResult redirecting {$where} cannot also carry a flash or a value: the "
                . 'redirect is answered with no body, and there is no session flash store to carry them '
                . 'across it. Return ok() with the flash and let the client navigate, or redirect alone.',
            );
        }
    }

    #[\NoDiscard]
    public static function ok(mixed $value = null): self
    {
        return new self(value: $value);
    }

    /**
     * Re-render these routes through their own full GET pipeline.
     *
     * Each is a route name or a view path, the form the spec writes:
     * `revalidate('racks/show')`. One that refers to no named page is an
     * error when the result is answered, not a re-render that never happens.
     */
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

    /**
     * Back to the page the action was called from: the path and query its
     * Referer names on this origin, or else the session's previous URL, or
     * else the root -- where a browser's own form post is sent back to
     * (Flash::back()). Same-origin, whatever the Referer said: "redirect(),
     * back() and the 419 redirect accept same-origin URLs only."
     */
    #[\NoDiscard]
    public static function back(): self
    {
        return new self(status: 303, back: true);
    }

    /**
     * Off this origin: "External URLs go through location() with an
     * allowlist."
     *
     * Checked when the result is answered, against the external origins the
     * application gave the Kernel (ExternalOrigins): an https URL whose
     * scheme, host and port are exactly one of them. Any other URL is a 500
     * the exception handler classifies, never a redirect -- and with no
     * allowlist given, every URL is.
     */
    #[\NoDiscard]
    public static function location(string $url): self
    {
        return new self(status: 303, location: $url);
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
            $this->back,
            $this->location,
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
            $this->back,
            $this->location,
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
            $this->back,
            $this->location,
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
            $this->back,
            $this->location,
        );
    }

    /**
     * The data boundary, applied to what an action hands back.
     *
     * "The PHP->renderer encoder accepts only Data objects, scalars, lists and
     * maps, and throws on any other object, including models, in every
     * environment." Loaders are held to that by a return-type check and
     * queries by the encoder, but an ActionResult went to the browser through
     * json_encode() with nothing in the way: ActionResult::ok($rack) sent the
     * whole model graph, and json_encode forced every lazy relation on the way
     * out -- related rows the caller never asked for, and a hidden 1+N.
     *
     * Applied in the runner, so every consumer of a result is covered rather
     * than just the HTTP endpoint.
     */
    #[\NoDiscard]
    public function encoded(Encoder $encoder): self
    {
        /** @var array<string, mixed> $flash */
        $flash = $encoder->encode($this->flash);

        return new self(
            $this->status,
            $this->revalidatedRoutes,
            $this->revalidatedTags,
            $flash,
            $this->redirect,
            $encoder->encode($this->value),
            $this->back,
            $this->location,
        );
    }

    /** Whether the action is answered with a redirect: redirect(), back() or location(). */
    public function isRedirect(): bool
    {
        return $this->redirect !== null || $this->back || $this->location !== null;
    }
}
