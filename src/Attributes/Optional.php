<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * Marks a Lazy Data field that is resolved only when a partial reload asks
 * for it by name.
 *
 * tinvar-spec.md, "Data categories": `#[Optional]` is Inertia's optional
 * prop -- "never on full renders, only when named in `X-Only`". A field's
 * cost is in its Lazy resolver (ADR 0014), so that is what is skipped: on
 * every other render the encoder sends `{"$optional": "<dot path>"}` in its
 * place, and codegen types the field `T | Unrequested`, so a component says
 * what it shows until the field is asked for. A partial reload naming the
 * path -- `page.history` -- re-renders the field's segment (ADR 0015) with
 * the field resolved. ADR 0045.
 *
 * "`#[Optional]` fields mean lazy, not hidden": any client allowed on the
 * page can ask for one, and the request that does runs every middleware,
 * check and loader of the page first, as any partial reload does. A field
 * that needs a permission of its own belongs in a query with its own
 * `#[Authorize]`, not here.
 *
 * Only on a Lazy field: any other value is produced before encoding, so
 * there would be nothing left to skip.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Optional {}
