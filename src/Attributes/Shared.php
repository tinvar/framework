<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * Marks a public method of a SharedData provider as one value every page
 * render may read, under `$key` or else the method's name.
 *
 * tinvar-spec.md, "Data categories": Inertia's `share()` becomes a
 * "`SharedData` provider with lazy closures". The method is the closure:
 * nothing calls it until a server component reads its key with
 * `shared('key')`, and then once for the render. A method rather than a
 * closure handed over at runtime because its return type is what codegen
 * types the key by, and a closure's is known only once something has made
 * it (ADR 0060).
 *
 * The method returns a Data object, a ProvidesData, or null if its type
 * allows. It may take the Request and a DataContext; anything else it
 * needs, its class takes in its constructor.
 *
 * `once: true` is Inertia's `shareOnce()` (ADR 0065). The value crosses as
 * a once field, which a server component renders through `<Once>`, and the
 * browser keeps what was drawn from it. While the browser holds it -- and
 * proves so with the signed hold the value came with, bound to this
 * session, user and auth epoch, tenant, panel and build -- a render reads
 * the key as held, and the method is not called. A once value names the
 * visitor, never the page, so its method may not take a DataContext nor
 * return a ProvidesData, both of which are told the page.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Shared
{
    /**
     * @param string|null  $key   what `shared()` reads it by; the method's name when null
     * @param bool         $once  kept by the browser across client navigations, and not
     *        produced again while it holds it
     * @param string|null  $until with `once`, how long the browser may go on claiming it
     *        ('10m', '1d'); a day at most, and a day when unset
     * @param list<string> $tags  with `once`, cache tags whose invalidation by an action makes
     *        the browser stop claiming it, as a #[Once] field's do
     */
    public function __construct(
        public ?string $key = null,
        public bool $once = false,
        public ?string $until = null,
        public array $tags = [],
    ) {}
}
