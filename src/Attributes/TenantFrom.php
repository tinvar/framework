<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

use Tinvar\Tenancy\TenantSource;

/**
 * Where an entry point's tenant comes from.
 *
 * tinvar-spec.md, "Tenancy": "A query on a #[TenantScoped] model ... with no
 * active tenant throws NoTenantContext, unless it is inside acrossTenants()
 * or the route, webhook, command or job declares where its tenant comes
 * from: #[TenantFrom(Host::class)], #[TenantFrom(Signature::class)], or a
 * command argument."
 *
 * On a page, a layout (which is how a route group is written here), an
 * action or a query. The source is resolved before the entry point's own
 * middleware and loaders, and the work runs as that tenant; a request the
 * source cannot place is refused with a 404 rather than run with no tenant,
 * or with whichever one the request happened to arrive with.
 *
 * The source is a class rather than a closure or a string so that it is
 * built by the application's resolver -- Host needs the application's
 * TenantHosts, Signature its SignedUrls -- and so a typo fails when the
 * attribute is read, at registration, not on the first request.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class TenantFrom
{
    /** @param class-string<TenantSource> $source */
    public function __construct(public string $source)
    {
        if (!class_exists($source) || !is_subclass_of($source, TenantSource::class)) {
            throw new \InvalidArgumentException(
                "#[TenantFrom({$source})] names no tenant source: the class must implement " . TenantSource::class . '.',
            );
        }
    }

    /**
     * The source an entry point declares -- its class's, its ancestors' and
     * traits' (see ClassAttributes), then its method's -- or null for none.
     *
     * Every declaration must name the same source. Two that disagree are
     * not something to pick a winner between: whichever lost, the author of
     * the other believed their work ran as a tenant it does not.
     *
     * @param class-string $class
     * @return class-string<TenantSource>|null
     */
    public static function declaredOn(string $class, ?string $method = null): ?string
    {
        $reflection = new \ReflectionClass($class);
        $attributes = ClassAttributes::of($reflection, self::class);

        if ($method !== null && $reflection->hasMethod($method)) {
            $attributes = [...$attributes, ...$reflection->getMethod($method)->getAttributes(self::class)];
        }

        $source = null;
        foreach ($attributes as $attribute) {
            $declared = $attribute->newInstance()->source;
            if ($source !== null && $declared !== $source) {
                throw new \LogicException(
                    "{$class} declares its tenant from both {$source} and {$declared}. An entry point has one tenant source.",
                );
            }
            $source = $declared;
        }

        return $source;
    }
}
