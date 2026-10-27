<?php

declare(strict_types=1);

namespace Tinvar\Tenancy;

use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\Orm;

/**
 * Runs the rest of an entry point as the tenant its #[TenantFrom] names.
 *
 * First in an entry point's own stack, ahead of its panel's and its own
 * middleware, its model binding and its loaders: everything that reads a
 * #[TenantScoped] model has to see the tenant, and a panel's membership is
 * held in a tenant. Through Orm::actAs(), so the tenant is the request's
 * for exactly as long as the request runs, and gone -- with the identity
 * map rows read under it -- when it returns.
 *
 * Fails closed, with one answer. A request the source cannot place is a
 * 404: as far as the caller may learn, there is nothing at that address,
 * which is the truth for an unknown host. And a request already running as
 * another tenant -- one the application's own middleware established, from
 * the session -- is the same 404 rather than a switch: "The tenant resolved
 * from a host only constrains." A host that could switch a session into
 * its tenant would make the Host header a way in.
 *
 * Constrains, not names: "the session's tenant must be that tenant or a
 * descendant". So a request running as a tenant below the one a host
 * names, by the application's TenantTree (handed to the Orm), goes on as
 * the session's tenant -- the narrower of the two, and the one whose
 * membership the panel then asks for. Only a source that says it bounds
 * (SubtreeSource) is read that way; a signed link names its tenant exactly.
 * The tenant the request runs as is still one tenant, so every token minted
 * under it is bound to that one and no other.
 */
final readonly class ResolveTenant implements Middleware
{
    public function __construct(
        private Orm $orm,
        private TenantSource $source,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $tenant = self::resolve($this->orm, $this->source, $request);

        return $this->orm->actAs($tenant, static fn (): Response => $next($request));
    }

    /**
     * The tenant $source places the request in, held to the one it is
     * already in: that one, when it is the same or below a bounding
     * source's answer.
     *
     * @throws HttpException 404 when there is none, or it is not the request's
     */
    public static function resolve(Orm $orm, TenantSource $source, Request $request): string
    {
        $tenant = $source->tenantFor($request);
        $named = $source::class;
        if ($tenant === null || $tenant === '') {
            throw self::unknown("{$named} places this request in no tenant.");
        }

        $current = $orm->tenant();
        if ($current === '' || $current === $tenant) {
            return $tenant;
        }

        // Below the host's tenant: the session's, which the host bounds.
        // Walked up from the session's tenant, so a tree that loops or a
        // tenant with no record refuses rather than admits.
        if ($source instanceof SubtreeSource && $orm->tenants()?->contains($tenant, $current) === true) {
            return $current;
        }

        throw self::unknown("{$named} places this request in a tenant other than the one it is running as, and not above it.");
    }

    private static function unknown(string $why): HttpException
    {
        return new HttpException(404, $why, 'tenant_unknown');
    }
}
