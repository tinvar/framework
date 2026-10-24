<?php

declare(strict_types=1);

namespace Tinvar\Http\Middleware;

use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Identity\Realms;

/**
 * Keeps a platform-only panel -- the admin panel -- off every host the
 * platform does not own.
 *
 * tinvar-spec.md, "Panels and scope": "The admin panel is mapped only to
 * platform-owned hosts. No `Domain` record can carry it." Realms already
 * refuses a custom domain's record that names such a panel, so no handoff
 * makes a session for it there. That holds the record, not the request:
 * an admin page asked for on a reseller's domain still ran its guard and
 * its membership check there. This holds the request.
 *
 * First in the entry point's stack, ahead of the tenant and of the panel's
 * guard. A guard's answer to a visitor not signed in is a sign-in page,
 * and an admin sign-in page served on a domain whose traffic a reseller
 * can read is what the rule exists to prevent; as far as such a request
 * may learn, nothing is there. A host the application does not know is
 * refused the same way -- not known to be the platform's is not the
 * platform's.
 */
final readonly class RequirePlatformHost implements Middleware
{
    public function __construct(
        private Realms $realms,
        private string $panel,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $realm = $this->realms->forRequest($request);
        if ($realm === null || $realm->isCustom()) {
            throw new HttpException(
                404,
                "Panel '{$this->panel}' is mapped only to the platform's own hosts, and this is "
                . ($realm === null ? 'no host of the application\'s.' : "{$realm->host}, a tenant's custom domain."),
                'not_found',
            );
        }

        return $next($request);
    }
}
