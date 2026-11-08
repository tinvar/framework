<?php

declare(strict_types=1);

namespace Tinvar\Auth;

/**
 * A tenant's API token, acting as itself (ADR 0193): what a call made with
 * one is signed in as, in place of a user. No person is behind it -- the
 * member who made it is recorded on the token, and acts through it no
 * more than anyone else in the tenant does -- so it is never anyone's
 * user, and its id says so: `tenant-token:<id>`, which no user's id and no
 * service's name is.
 *
 * Policies see it as the caller, as they see a Service. PolicyGate asks a
 * policy about it only where the ability's first parameter names this
 * class -- `User|TenantTokenActor|null $actor` -- and says no wherever it
 * does not: a policy written for people, answering `$user !== null`, would
 * otherwise let every token in. The application's Memberships is asked
 * about it as about anyone entering a panel; one that knows nothing of
 * tokens answers none, and the token reaches nothing in the panel.
 */
final readonly class TenantTokenActor implements Authenticatable
{
    /** What its id starts with: nothing a user's id or a service's name is. */
    public const string ID_PREFIX = 'tenant-token:';

    /**
     * @param string $id the token's id, 32 lowercase hex digits
     * @param string $tenant the tenant it belongs to, and acts in alone
     * @param string $panel the panel it was issued in; '' for none
     * @param list<string> $scopes what it may be used for, sorted, each once
     * @param string $name what the member who made it called it
     */
    public function __construct(
        public string $id,
        public string $tenant,
        public string $panel,
        public array $scopes,
        public string $name,
    ) {}

    public function authIdentifier(): string
    {
        return self::ID_PREFIX . $this->id;
    }

    /**
     * Whether it holds every scope in $required: what a policy may narrow
     * by, past what the operation's #[RequiresScope] already required.
     *
     * @param list<string> $required
     */
    public function grants(array $required): bool
    {
        return array_diff($required, $this->scopes) === [];
    }
}
