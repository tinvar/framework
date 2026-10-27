<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Auth\Authenticatable;

/**
 * Who may invite whom, as what: the application's, since roles are.
 *
 * Asked when an invite is made, when one is revoked, and again when one is
 * accepted -- of the inviter as they are then -- so an invite outlives
 * neither its inviter's right to make it nor a role the inviter no longer
 * holds. An application answers no for any role that grants something the
 * inviter does not hold themselves: an invite is how a member brings
 * someone in, never how anyone climbs above the member who asked.
 */
interface InvitePolicy
{
    public function mayInvite(Authenticatable $inviter, string $tenant, string $panel, string $role): bool;
}
