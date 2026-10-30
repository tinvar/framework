<?php

declare(strict_types=1);

namespace Tinvar\Http\Middleware;

use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\RequestAttributes;
use Tinvar\Http\Response;
use Tinvar\Http\TenantResolver;
use Tinvar\Orm\Orm;

/**
 * Runs the rest of an entry point as the tenant its #[TenantFrom] names, as
 * the registered TenantResolver reads it.
 *
 * First in an entry point's own stack, ahead of its panel's and its own
 * middleware, its model binding and its loaders: everything that reads a
 * #[TenantScoped] model has to see the tenant, and a panel's membership is
 * held in a tenant. Through Orm::actAs(), so the tenant is the request's
 * for exactly as long as the request runs, and gone -- with the identity
 * map rows read under it -- when it returns.
 *
 * What the resolver answers is held, here, to what no resolver may do: name
 * no tenant, or move a request that already runs as a tenant -- one its
 * application's middleware established from the session -- to another. "The
 * tenant resolved from a host only constrains" is the resolver's to apply;
 * that a session is never switched by a request's own say-so is the
 * framework's, so it holds with any implementation (ADR 0056). Each refusal
 * is the 404 a request no source can place gets.
 */
final readonly class EnterTenant implements Middleware
{
    public function __construct(
        private Orm $orm,
        private TenantResolver $tenants,
        private string $source,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $tenant = self::resolve($this->orm, $this->tenants, $this->source, $request);

        // Named on the request for what mints inside it -- a file URL's
        // binding (FileUrls) -- never read back as a tenant.
        $inner = $request->withAttribute(RequestAttributes::TENANT_SOURCE, $this->source);

        return $this->orm->actAs($tenant, static fn (): Response => $next($inner));
    }

    /**
     * The tenant $tenants places the request in for $source, held to the one
     * it already runs as.
     *
     * @throws HttpException 404 when there is none, or it is not the request's
     */
    public static function resolve(Orm $orm, TenantResolver $tenants, string $source, Request $request): string
    {
        $current = $orm->tenant();
        $tenant = $tenants->resolve($orm, $source, $request);

        if ($tenant === '') {
            throw new HttpException(404, "{$source} places this request in no tenant.", 'tenant_unknown');
        }
        if ($current !== '' && $tenant !== $current) {
            throw new HttpException(404, "{$source} places this request in a tenant other than the one it is running as.", 'tenant_unknown');
        }

        return $tenant;
    }
}
