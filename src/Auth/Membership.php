<?php

declare(strict_types=1);

namespace Tinvar\Auth;

use Tinvar\Attributes\Panel;

/**
 * A user's membership of one panel in one tenant: the tenant's kind, which
 * #[Panel(requires:)] is held to, and the permissions it carries, which
 * #[Panel(permission:)] is.
 *
 * Handed to the rest of the request as an attribute (see
 * RequirePanelMembership::ATTRIBUTE), so a loader that shows more to a
 * member holding a permission reads the same answer the check did.
 */
final readonly class Membership
{
    /** @var list<string> */
    public array $permissions;

    /** @param list<string> $permissions */
    public function __construct(
        public \UnitEnum|string $kind = '',
        array $permissions = [],
    ) {
        $this->permissions = array_values(array_unique($permissions));
    }

    public function can(string $permission): bool
    {
        return \in_array($permission, $this->permissions, true);
    }

    public function isKind(\UnitEnum|string $kind): bool
    {
        return Panel::kindName($this->kind) === Panel::kindName($kind);
    }
}
