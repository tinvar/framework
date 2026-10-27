<?php

declare(strict_types=1);

namespace Tinvar\Tenancy;

use Tinvar\Console\Console;
use Tinvar\Http\Request;

/**
 * A command's tenant, from its `--tenant=` argument:
 * `#[TenantFrom(TenantArgument::class)]` -- "or a command argument", the
 * third of the spec's places a tenant may come from.
 *
 * Read by Console, which checks the tenant against the application's
 * TenantDirectory before anything runs. A request is never placed by a
 * command argument, so on a page or an action this source answers no
 * tenant and the request is refused: declared in the wrong place, it fails
 * closed.
 */
final readonly class TenantArgument implements TenantSource
{
    /** The option a command's tenant is named by. */
    public const string OPTION = Console::TENANT_OPTION;

    public function tenantFor(Request $request): ?string
    {
        return null;
    }
}
