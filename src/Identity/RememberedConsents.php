<?php

declare(strict_types=1);

namespace Tinvar\Identity;

/**
 * The custom domains a person said not to be asked about again (ADR 0125).
 *
 * A consent screen's confirm, posted with its remember box ticked, keeps a
 * row bound to four things, and a handoff skips the screen only when all
 * four still hold:
 *
 * - the **user** who confirmed;
 * - their **auth epoch** then: a sign-out (where the application moves the
 *   epoch there), a role change, impersonation, "sign out everywhere" --
 *   anything that moves it -- forgets every domain at once, without a row
 *   being touched;
 * - the domain's **tenant** and **panel**, as the realm named them: a
 *   domain whose record has moved to another tenant or panel is a
 *   different destination, and is asked about again;
 * - the domain's **origin**, exactly.
 *
 * And for MAX_AGE at most, however long the epoch stands. A row is the
 * person's to take back (forget(), forgetUser()), from a page of the
 * application's listing forUser(): revocable, as the spec's "consent" asks
 * of anything remembered on a person's behalf.
 *
 * Nothing here is a credential. A row says only "do not ask"; the handoff
 * that skips the screen still runs every check a confirm does -- staff are
 * never handed to a custom domain, the membership is held again, and the
 * code is bound to the session that started it.
 */
interface RememberedConsents
{
    /** Seconds a domain is remembered for, at most: thirty days. */
    public const int MAX_AGE = 2_592_000;

    /** Whether $user, at $epoch, said not to be asked about $origin, in $tenant and $panel. */
    public function remembers(string $user, string $epoch, string $tenant, string $panel, string $origin, int $now): bool;

    /** Remembers that $user, at $epoch, confirmed $origin in $tenant and $panel, replacing what was kept for it. */
    public function remember(string $user, string $epoch, string $tenant, string $panel, string $origin, int $now): void;

    /** Forgets $origin for $user: they are asked about it again. */
    public function forget(string $user, string $origin): void;

    /** Forgets every domain $user said not to be asked about. */
    public function forgetUser(string $user): void;

    /** @return list<RememberedConsent> the domains $user is not asked about now, at $epoch */
    public function forUser(string $user, string $epoch, int $now): array;
}
