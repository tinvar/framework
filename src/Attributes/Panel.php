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
 * On a page, a layout (a route group), an action or a query. The panel's
 * stack -- its guard, the membership check, its MFA, network allowlist and
 * email verification -- runs before the entry point's own middleware, and
 * `requires` and `permission` are held against the user's membership of
 * the panel in the request's tenant (see the memberships). Both only add:
 * a page saying `permission: 'orders.refund'` in a panel whose definition
 * requires `orders.view` needs both.
 *
 * `requires` is a tenant kind: the application's enum, as the spec writes
 * it, or a string. An application's taxonomy, so the framework compares it
 * by name and never interprets it.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class Panel
{
    public function __construct(
        public string $name,
        public \UnitEnum|string|null $requires = null,
        public ?string $permission = null,
    ) {
        if ($name === '') {
            throw new \InvalidArgumentException('A #[Panel] needs a name.');
        }
        if ($requires === '' || $permission === '') {
            throw new \InvalidArgumentException("#[Panel('{$name}')] requires nothing by an empty name; leave it out.");
        }
    }

    /**
     * A tenant kind as one string to compare: a backed enum's value, a pure
     * enum's case name, or the string itself.
     */
    public static function kindName(\UnitEnum|string $kind): string
    {
        return match (true) {
            $kind instanceof \BackedEnum => (string) $kind->value,
            $kind instanceof \UnitEnum => $kind->name,
            default => $kind,
        };
    }

    /**
     * Every #[Panel] an entry point declares -- its class's, its ancestors'
     * and traits' (see ClassAttributes), then its method's.
     *
     * All of them, not the nearest: each may narrow, and dropping an outer
     * one's permission because an inner one was written would widen.
     *
     * @param class-string $class
     * @return list<self>
     */
    public static function declaredOn(string $class, ?string $method = null): array
    {
        $reflection = new \ReflectionClass($class);
        $attributes = ClassAttributes::of($reflection, self::class);

        if ($method !== null && $reflection->hasMethod($method)) {
            $attributes = [...$attributes, ...$reflection->getMethod($method)->getAttributes(self::class)];
        }

        return array_map(static fn (\ReflectionAttribute $a): self => $a->newInstance(), $attributes);
    }
}
