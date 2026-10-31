<?php

declare(strict_types=1);

namespace Tinvar\Identity;

/**
 * Checks the application's custom domains against their DNS, and records
 * what it found.
 *
 * tinvar-spec.md, "Identity across domains": "Custom domains are
 * re-verified daily and at every certificate renewal, and deactivated if
 * their DNS no longer points at the platform." Daily is VerifyCustomDomains,
 * through verifyDue(); certificate renewal is TlsAskEndpoint, which Caddy
 * asks before it obtains or renews a certificate, through verify().
 *
 * What each answer the DomainVerifier gives leads to:
 *
 *   - **Points at the platform.** Recorded as a passed check
 *     (CustomDomains::passed()), which ends any run of failures.
 *   - **Resolves, and points elsewhere.** Deactivated at once. Someone else
 *     now controls where the name goes: kept active, it would stay a realm
 *     a handoff can send a session to (HandoffEndpoint), and whoever now
 *     answers for it could have a certificate issued for it and read what
 *     arrives.
 *   - **The lookup fails** -- a server failure, a timeout, no answer.
 *     That says nothing about who controls the name; DNS has bad hours,
 *     and deactivating a tenant's domain over one would take their site
 *     down for a fault that was not theirs. So it is not verified this time
 *     (CustomDomains::failed(), and TlsAskEndpoint refuses a certificate),
 *     and the domain stays as it was until it has failed continuously --
 *     every check since the first failure, with no pass between -- for
 *     longer than $grace. The first check after that deactivates it.
 *
 * The grace period is the application's, and a trade: for that long, a
 * domain whose DNS cannot be read stays a realm, including one whose new
 * owner arranges for the platform's lookups, and nobody else's, to fail.
 * Hours to a few days, not weeks.
 */
final readonly class CustomDomainVerification
{
    /** How many due domains are asked for at a time. */
    public const int BATCH = 100;

    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $clock;

    /**
     * @param int $grace seconds a domain whose DNS cannot be read stays as it was, from the
     *        first failed check of an unbroken run; with 0, the next failed check after
     *        the first deactivates it
     * @param (\Closure(): \DateTimeImmutable)|null $clock now; the system clock unless given
     */
    public function __construct(
        private CustomDomains $domains,
        private DomainVerifier $verifier,
        private int $grace,
        ?\Closure $clock = null,
    ) {
        if ($grace < 0) {
            throw new \InvalidArgumentException('A grace period is zero seconds or more.');
        }

        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function now(): \DateTimeImmutable
    {
        return ($this->clock)();
    }

    /**
     * One domain, checked now, and the outcome recorded. A domain the
     * application holds inactive is not resolved.
     */
    public function verify(CustomDomain $domain): DomainStatus
    {
        if (!$domain->active) {
            return DomainStatus::Inactive;
        }

        $check = $this->verifier->check($domain->host);
        $now = $this->now();

        if ($check->pointing === DomainPointing::Platform) {
            $this->domains->passed($domain->host, $now);

            return DomainStatus::Verified;
        }

        if ($check->pointing === DomainPointing::Elsewhere) {
            $this->domains->deactivate($domain->host, "Its DNS no longer points at the platform: {$check->reason}.", $now);

            return DomainStatus::Deactivated;
        }

        $since = $domain->failingSince ?? $now;
        if ($now->getTimestamp() - $since->getTimestamp() > $this->grace) {
            $this->domains->deactivate(
                $domain->host,
                'Its DNS could not be read at any check since ' . $since->format(\DATE_ATOM) . ": {$check->reason}.",
                $now,
            );

            return DomainStatus::Deactivated;
        }

        $this->domains->failed($domain->host, $now, $since);

        return DomainStatus::Unverified;
    }

    /**
     * Every domain due, each checked as verify() checks it, each bounded by
     * the verifier's timeout -- one slow domain costs its own time and no
     * more -- and a domain whose check or record throws is left for the
     * next run while the rest go on.
     *
     * Due is not checked since this run began, to the second: a run checks
     * every active domain once, whatever was checked when, and a record
     * that keeps its times to the second does not bring a domain checked in
     * the run's first second back. A domain due() answers again after this
     * run looked at it -- its record was not written -- is not looked at
     * twice -- each batch is wider by every domain already looked at, so it
     * still holds a batch of the rest -- and a batch of nothing else ends
     * the run.
     *
     * @return array{outcomes: array<string, DomainStatus>, failures: array<string, \Throwable>}
     */
    public function verifyDue(): array
    {
        $since = new \DateTimeImmutable('@' . $this->now()->getTimestamp());
        $outcomes = [];
        $failures = [];

        while (true) {
            // A batch wider by every domain already looked at: one whose
            // record could not be written is still due, and first in line,
            // and a batch of only those ended the run with every domain
            // behind them unchecked. However many of them come back first,
            // a batch's worth of the rest come with them -- and a batch
            // holding none of the rest means none is left.
            $fresh = array_filter(
                $this->domains->due($since, self::BATCH + \count($outcomes) + \count($failures)),
                static fn (CustomDomain $d): bool => !isset($outcomes[$d->host]) && !isset($failures[$d->host]),
            );
            if ($fresh === []) {
                break;
            }

            foreach ($fresh as $domain) {
                try {
                    $outcomes[$domain->host] = $this->verify($domain);
                } catch (\Throwable $e) {
                    $failures[$domain->host] = $e;
                }
            }
        }

        return ['outcomes' => $outcomes, 'failures' => $failures];
    }
}
