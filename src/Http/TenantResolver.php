<?php

declare(strict_types=1);

namespace Tinvar\Http;

use Tinvar\Orm\Orm;

/**
 * How the tenant an entry point declares is read from a request: what
 * tinvar/tenancy registers with the Kernel, the webhook endpoint and the
 * console, so the framework can run `#[TenantFrom(...)]` without knowing
 * what a tenant source is (ADR 0056).
 *
 * Without one, an entry point declaring a tenant source is refused when it
 * is registered, and refused again if it is reached: it would otherwise
 * run with no tenant while its author believed it ran as one.
 *
 * The framework holds whatever implements it to what it may not do itself
 * (EnterTenant): an answer that names no tenant is refused, and a request
 * already running as a tenant -- the session's -- goes on as exactly that
 * tenant or not at all, so no resolver can switch a session into another.
 * Which tenant a source names, and whether the session's is below it, is
 * the resolver's to answer, asked of the Orm's checked tree.
 */
interface TenantResolver
{
    /**
     * Refuses, when an entry point is registered, a source this resolver
     * cannot read a request's tenant from.
     *
     * @param string $source the class an entry point's #[TenantFrom] names
     * @throws \InvalidArgumentException
     */
    public function check(string $source): void;

    /**
     * The tenant an entry point declaring $source runs as for $request: the
     * one the source places it in, held to the tenant $orm already runs as.
     *
     * @throws HttpException 404 when the source places the request in no
     *         tenant, or in one other than the request's and not above it
     */
    public function resolve(Orm $orm, string $source, Request $request): string;
}
