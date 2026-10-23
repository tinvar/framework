<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * The panel a routable entry point belongs to.
 *
 * tinvar-spec.md: "Actions and queries require an authenticated user and
 * belong to a panel, by #[Panel] or by namespace", and:
 *
 *   "A panel can only narrow what a request sees. Widening (a reseller's
 *    customers, all tenants for staff) comes from the session's membership:
 *    its tenant kind plus a panel permission, such as
 *    #[Panel('reseller', requires: TenantKind::Reseller, permission:
 *    'reseller.access')]."
 *
 * `requires` is left as a string rather than the spec's TenantKind enum,
 * because tenant kinds are an application's taxonomy and this framework has
 * no membership model yet. When one lands it should be typed; until then the
 * field is carried and checked as an opaque value rather than pretended to be
 * richer than it is.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class Panel
{
    public function __construct(
        public string $name,
        public ?string $requires = null,
        public ?string $permission = null,
    ) {
        if ($name === '') {
            throw new \InvalidArgumentException('A #[Panel] needs a name.');
        }
    }
}
