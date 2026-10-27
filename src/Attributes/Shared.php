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
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Shared
{
    public function __construct(public ?string $key = null) {}
}
