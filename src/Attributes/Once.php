<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * Marks a Data field that is sent once and reused on later visits.
 *
 * tinvar-spec.md, "Data categories": `#[Once(until: '1d', key: 'roles')]`,
 * "skipped when listed in `X-Have-Once`" -- Inertia's once props. Data that
 * is expensive and rarely changes, a country list or an account's totals.
 *
 * Under RSC the browser holds rendered elements, not loader data (ADR 0015),
 * so what is reused is not the value but what was rendered from it: the
 * page renders the field through `<Once>`, the browser keeps that subtree
 * under the key, and a request listing the key in `X-Have-Once` gets a
 * marker in its place -- the field's Lazy is never resolved and the subtree
 * never rendered. The loader that holds the field still runs, with every
 * check and guard in it; only the field's own resolver is skipped, so a
 * guard belongs in the loader's body, never in a once field's resolver.
 * ADR 0020.
 *
 * On the field, like #[Rescue], because codegen reads classes and not
 * values: the generated type says the value may not be there.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Once
{
    /**
     * @param string|null  $key   what the browser keeps the rendered field under. It names the
     *        rendered content, so a key shared by two fields must render the same in both, and
     *        wherever it is claimed. Defaults to the Data class's short name and the field's,
     *        `OrdersPageData.summary`, scoped to the page asked for -- route, parameters and query
     *        -- so `/customers/1` and `/customers/2` are kept apart. A key may name its subject
     *        from the class's own string or int properties, in braces: `customer.badge:{id}`.
     *        A class met more than once in a payload -- a list's rows -- must, or it is refused.
     * @param string|null  $until how long the browser may go on claiming it, as a duration
     *        ('10m', '1d'); unset, until the session ends or the document is loaded again
     * @param list<string> $tags  cache tags whose invalidation by an action makes the browser stop
     *        claiming it, as `X-Invalidated-Tags` drops prefetched pages
     */
    public function __construct(
        public ?string $key = null,
        public ?string $until = null,
        public array $tags = [],
    ) {}
}
