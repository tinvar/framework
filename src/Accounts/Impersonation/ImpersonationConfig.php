<?php

declare(strict_types=1);

namespace Tinvar\Accounts\Impersonation;

/**
 * How an application lets its staff act as its users (ADR 0194), handed to
 * AccountsKit as `impersonation:`.
 *
 *     impersonation: new ImpersonationConfig(
 *         policy: new RankedImpersonationPolicy(...),
 *         panel: 'admin',
 *         minutes: 30,
 *     ),
 */
final readonly class ImpersonationConfig
{
    /** The longest an impersonation may be let run for: a working day. */
    public const int MAX_MINUTES = 480;

    /**
     * @param ImpersonationPolicy $policy who may act as whom
     * @param string $panel the panel an impersonation is started from: an action anywhere else
     *        that starts one is refused, so the panel's guard, membership check and MFA have
     *        held the impersonator to it first
     * @param int $minutes how long an impersonation runs before it ends by itself
     * @param string $arrival the page, on each of the app's other hosts, an impersonation
     *        started on one host arrives at (ArriveImpersonating): unused by an app with one
     */
    public function __construct(
        public ImpersonationPolicy $policy,
        public string $panel,
        public int $minutes = 30,
        public string $arrival = '/impersonate',
    ) {
        if ($panel === '') {
            throw new \InvalidArgumentException('An impersonation is started from a panel: name it, as `admin`.');
        }
        if ($minutes < 1 || $minutes > self::MAX_MINUTES) {
            throw new \InvalidArgumentException('An impersonation runs for 1 to ' . self::MAX_MINUTES . " minutes; {$minutes} is not that.");
        }
        if (preg_match('~\A/[A-Za-z0-9/_.-]*\z~', $arrival) !== 1 || str_starts_with($arrival, '//') || str_starts_with($arrival, '/_')) {
            throw new \InvalidArgumentException("The arrival page is a page's path on this site, as `/impersonate`; '{$arrival}' is not one.");
        }
    }
}
