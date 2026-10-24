<?php

declare(strict_types=1);

namespace Tinvar\Http;

use Tinvar\Http\Middleware\ResolveTenant;
use Tinvar\Orm\Orm;
use Tinvar\Tenancy\TenantSource;

/**
 * What an entry point's declarations put around it before its own
 * middleware runs: the tenant its #[TenantFrom] names.
 *
 * One object, handed to every path that runs an entry point -- a page, its
 * fragments, a re-render after an action, an action, a query over HTTP,
 * /_bootstrap -- because the alternative is one copy of this per path, and
 * this codebase has watched two copies of one check drift apart more than
 * once (TokenClaims says how). A path that ran a route without it would
 * run that route as no tenant, or as whichever the request arrived with.
 */
final readonly class EntryContext
{
    /** @param \Closure(class-string): object $resolver builds tenant sources, as it builds middleware */
    public function __construct(
        private Orm $orm,
        private \Closure $resolver,
    ) {}

    /**
     * The middleware that goes outermost in an entry point's own stack.
     *
     * @param class-string<TenantSource>|null $tenantSource what its #[TenantFrom] names
     * @return list<Middleware>
     */
    public function stackFor(?string $tenantSource): array
    {
        return $tenantSource === null ? [] : [new ResolveTenant($this->orm, $this->source($tenantSource))];
    }

    /**
     * The tenant an entry point would run as for $request, without running
     * it: for minting tokens a later request will be held to.
     *
     * @param class-string<TenantSource>|null $tenantSource
     * @throws HttpException as ResolveTenant refuses
     */
    public function tenantFor(?string $tenantSource, Request $request): string
    {
        return $tenantSource === null
            ? $this->orm->tenant()
            : ResolveTenant::resolve($this->orm, $this->source($tenantSource), $request);
    }

    /** @param class-string<TenantSource> $class */
    private function source(string $class): TenantSource
    {
        $source = ($this->resolver)($class);
        if (!$source instanceof TenantSource) {
            throw new \LogicException("The resolver built {$class} as " . get_debug_type($source) . ', not a tenant source.');
        }

        return $source;
    }
}
