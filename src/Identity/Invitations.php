<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Auth\Authenticatable;
use Tinvar\Http\HttpException;
use Tinvar\Auth\UserLookup;

/**
 * Invites: an address asked to join one panel in one tenant with one role,
 * by a member who may (ADR 0076).
 *
 * Each is an EmailLinks link of the invite purpose, a week long and
 * single-use, revocable until it is used. What it grants -- the tenant, the
 * panel, the role -- is kept in its record and read back from there: the
 * token carries none of it, and nothing the person accepting posts can
 * change it. InvitePolicy is asked when it is made and revoked, and again,
 * of the inviter as they are then, when it is accepted.
 *
 * An invite names an address, not an account, so it is bound to no auth
 * epoch: whoever holds the email holds the invite, as whoever holds the
 * email can sign in by a magic link. The application joins the address's
 * account to the tenant -- making one if there is none -- and to that
 * tenant alone; never the account the browser that opens it happens to be
 * signed in as, which would let a forwarded invite be taken by anyone.
 */
final readonly class Invitations
{
    /** The most open invites one listing shows. */
    public const int LIST_LIMIT = 200;

    /** The most addresses addressedTo() looks for invites to at once. */
    public const int ADDRESS_LIMIT = 20;

    /**
     * @param string $path the page an invite lands on, which shows it and POSTs it back
     */
    public function __construct(
        private EmailLinks $links,
        private InvitePolicy $policy,
        private UserLookup $users,
        private string $path,
    ) {}

    /**
     * Invites $email to $panel in $tenant as $role, on $origin -- the
     * tenant's canonical origin -- and returns the invitation and the URL
     * to email. Any invite still open for the same address, panel and
     * tenant, to a role the inviter may give, is revoked: one invite per
     * address is the one that counts.
     *
     * @return array{url: string, invitation: Invitation}
     * @throws HttpException 403 when the inviter may not invite to that role
     */
    public function invite(Authenticatable $inviter, string $email, string $tenant, string $panel, string $role, string $origin): array
    {
        $address = self::address($email) ?? throw new \InvalidArgumentException('An invite is to an email address.');
        if ($role === '' || \strlen($role) > 64 || \strlen($panel) > 64) {
            throw new \InvalidArgumentException('An invite names a role and a panel of up to 64 bytes.');
        }
        if (!$this->policy->mayInvite($inviter, $tenant, $panel, $role)) {
            throw new HttpException(403, 'You may not invite anyone to that role.', 'forbidden');
        }

        $issued = $this->links->issue(EmailLinks::INVITE, $origin, $this->path, $tenant, null, [
            'email' => $address,
            'panel' => $panel,
            'role' => $role,
            'inviter' => $inviter->authIdentifier(),
        ]);
        $invitation = Invitation::of($issued['link']) ?? throw new \LogicException('An invite was made without what it grants.');

        // Once the new one is made, so an invite that cannot be -- an origin
        // or a landing page refused -- takes none away. Only those the
        // inviter could have sent: re-inviting someone at a narrower role
        // never takes away an invite a wider member made.
        foreach ($this->openTo($tenant, ['panel' => $panel, 'email' => $address]) as $earlier) {
            if ($earlier->id !== $invitation->id && $earlier->panel === $panel && $earlier->email === $address
                && $this->policy->mayInvite($inviter, $tenant, $panel, $earlier->role)) {
                $this->links->revoke($earlier->id, EmailLinks::INVITE, $tenant);
            }
        }

        return ['url' => $issued['url'], 'invitation' => $invitation];
    }

    /**
     * The invites to $panel in $tenant still open, newest first.
     *
     * @return list<Invitation>
     */
    public function pending(string $tenant, string $panel): array
    {
        return array_values(array_filter(
            $this->openTo($tenant, ['panel' => $panel]),
            static fn (Invitation $invitation): bool => $invitation->panel === $panel,
        ));
    }

    /**
     * The open invites in $tenant whose record holds $data, newest first:
     * asked of the store with $data, so another panel's newer invites take
     * none of the LIST_LIMIT.
     *
     * @param array<string, string> $data
     * @return list<Invitation>
     */
    private function openTo(string $tenant, array $data): array
    {
        $open = [];
        foreach ($this->links->open(EmailLinks::INVITE, $tenant, self::LIST_LIMIT, $data) as $link) {
            $invitation = Invitation::of($link);
            if ($invitation !== null) {
                $open[] = $invitation;
            }
        }

        return $open;
    }

    /**
     * Revokes an open invite to $panel in $tenant, by id, for a member who
     * may invite to its role. False for one already used, revoked or
     * expired, or none.
     *
     * @throws HttpException 403 when $by may not invite to its role
     */
    public function revoke(Authenticatable $by, string $id, string $tenant, string $panel): bool
    {
        $link = $this->links->byId($id, EmailLinks::INVITE, $tenant);
        $invitation = $link === null ? null : Invitation::of($link);
        if ($invitation === null || $invitation->panel !== $panel) {
            return false;
        }
        if (!$this->policy->mayInvite($by, $tenant, $panel, $invitation->role)) {
            throw new HttpException(403, 'You may not revoke an invite to that role.', 'forbidden');
        }

        return $this->links->revoke($id, EmailLinks::INVITE, $tenant);
    }

    /**
     * The tenant an invite's token was made in, as its MAC vouches for it,
     * or null for one that is no invite, or has expired: which tenant to
     * open() it in on a host that serves every tenant (ADR 0182). Nothing
     * about the invite beyond that -- open() still holds the token to its
     * record there, unused, unrevoked, and its inviter still allowed.
     */
    public function tenantOf(string $token): ?string
    {
        return $this->links->tenantOf($token, EmailLinks::INVITE);
    }

    /** The invite a token is, if it could be accepted now on $origin in $tenant; null otherwise. */
    public function open(string $token, string $origin, string $tenant): ?Invitation
    {
        $link = $this->links->peek($token, EmailLinks::INVITE, $origin, $tenant);
        $invitation = $link === null ? null : Invitation::of($link);

        return $invitation !== null && $this->stillGranted($invitation) ? $invitation : null;
    }

    /**
     * Uses the invite a token is, and returns what it grants -- for the
     * application to grant, to the account of its address, in its tenant
     * alone. Null for every reason it is refused: the link's own, and an
     * inviter who may no longer invite to its role.
     *
     * Inside the caller's transaction, the grant and the use are one: a
     * grant that fails gives the invite back.
     */
    public function accept(string $token, string $origin, string $tenant): ?Invitation
    {
        if ($this->open($token, $origin, $tenant) === null) {
            return null;
        }
        $link = $this->links->consume($token, EmailLinks::INVITE, $origin, $tenant);

        return $link === null ? null : Invitation::of($link);
    }

    /**
     * The open invites to any of $emails, in every tenant, newest first, at
     * most LIST_LIMIT: what someone signed in as those addresses may accept
     * or decline without the emailed link (ADR 0201). Each one still as
     * open() would find it -- unused, unrevoked, unexpired, and its inviter
     * still allowed to send it.
     *
     * $emails are addresses the account is proved to read -- its verified
     * ones, never what a request names: whoever is listed an invite here
     * may join with it. Those that are no address are passed over.
     *
     * @param list<string> $emails
     * @return list<Invitation>
     */
    public function addressedTo(array $emails): array
    {
        $found = [];
        foreach (self::addresses($emails) as $address) {
            foreach ($this->links->openAcrossTenants(EmailLinks::INVITE, self::LIST_LIMIT, ['email' => $address]) as $link) {
                $invitation = Invitation::of($link);
                if ($invitation !== null && $invitation->email === $address && $this->stillGranted($invitation)) {
                    $found[$invitation->id] = $invitation;
                }
            }
        }
        $found = array_values($found);
        usort($found, static fn (Invitation $a, Invitation $b): int => [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id]);

        return \array_slice($found, 0, self::LIST_LIMIT);
    }

    /**
     * Uses the invite with this id in $tenant, with no token, for someone
     * signed in as one of $emails -- their verified addresses, as for
     * addressedTo() -- and returns what it grants, for the application to
     * grant as accept()'s caller does (ADR 0201). Null for every reason it
     * is refused, alike: none with that id there, one to another address,
     * used, revoked, declined or expired, an inviter who may no longer send
     * it, or the race lost to its emailed link.
     *
     * @param list<string> $emails
     */
    public function acceptFor(string $id, string $tenant, array $emails): ?Invitation
    {
        $invitation = $this->addressed($id, $tenant, $emails);
        if ($invitation === null || !$this->stillGranted($invitation)) {
            return null;
        }
        $link = $this->links->useById($id, EmailLinks::INVITE, $tenant);

        return $link === null ? null : Invitation::of($link);
    }

    /**
     * Declines the invite with this id in $tenant for someone signed in as
     * one of $emails (ADR 0201): it is closed, as a revocation closes it --
     * its emailed link opens nothing after -- and its record keeps when it
     * was declined. Returns it, declinedAt set; null for one not open to
     * them, as acceptFor() refuses.
     *
     * @param list<string> $emails
     */
    public function declineFor(string $id, string $tenant, array $emails): ?Invitation
    {
        $invitation = $this->addressed($id, $tenant, $emails);
        $at = $this->links->now();
        if ($invitation === null || !$this->links->revoke($id, EmailLinks::INVITE, $tenant, ['declined_at' => (string) $at])) {
            return null;
        }

        return new Invitation(
            $invitation->id,
            $invitation->email,
            $invitation->tenant,
            $invitation->panel,
            $invitation->role,
            $invitation->inviter,
            $invitation->createdAt,
            $invitation->expiresAt,
            $at,
        );
    }

    /**
     * The invite with this id in $tenant when it is open now and sent to
     * one of $emails; null otherwise.
     *
     * @param list<string> $emails
     */
    private function addressed(string $id, string $tenant, array $emails): ?Invitation
    {
        if ($id === '' || $tenant === '') {
            return null;
        }
        $link = $this->links->byId($id, EmailLinks::INVITE, $tenant);
        $invitation = $link === null || $link->user !== '' || !$link->isOpen($this->links->now()) ? null : Invitation::of($link);

        return $invitation !== null && \in_array($invitation->email, self::addresses($emails), true) ? $invitation : null;
    }

    /**
     * $emails as invites keep them, each once, those that are no address
     * left out, at most ADDRESS_LIMIT.
     *
     * @param list<string> $emails
     * @return list<string>
     */
    private static function addresses(array $emails): array
    {
        $addresses = [];
        foreach ($emails as $email) {
            $address = self::address($email);
            if ($address !== null) {
                $addresses[$address] = $address;
            }
        }

        return \array_slice(array_values($addresses), 0, self::ADDRESS_LIMIT);
    }

    /**
     * An address as invites keep it: trimmed, lowercased, and an address;
     * null otherwise. ASCII only, lowered as ASCII: Unicode case folding
     * would turn a Kelvin sign into a `k`, and an invite typed for one
     * address would be kept for another.
     */
    public static function address(string $email): ?string
    {
        $address = strtolower(trim($email));

        return \strlen($address) <= 254 && filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $address : null;
    }

    /** Whether its inviter is still there, and may still invite to its role. */
    private function stillGranted(Invitation $invitation): bool
    {
        $inviter = $this->users->find($invitation->inviter);

        return $inviter !== null
            && $inviter->authIdentifier() === $invitation->inviter
            && $this->policy->mayInvite($inviter, $invitation->tenant, $invitation->panel, $invitation->role);
    }
}
