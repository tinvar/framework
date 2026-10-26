<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\ThrottleStore;
use Tinvar\Observability\ProbeAccess;
use Tinvar\Tenancy\Host;

/**
 * `/_tls/ask?domain=`: Caddy's on-demand TLS `ask` endpoint, where a custom
 * domain is re-verified "at every certificate renewal" (tinvar-spec.md).
 *
 * FrankenPHP is Caddy, and a platform serving its tenants' domains has
 * Caddy obtain their certificates on demand, asking first:
 *
 *     {
 *         on_demand_tls {
 *             ask http://localhost/_tls/ask
 *         }
 *     }
 *
 * What was confirmed, against Caddy v2.11.7 and the CertMagic it is built
 * with, v0.25.6 (`go version -m` on the caddy binary), from their source:
 *
 *   - Caddy's `ask` (modules/caddytls/ondemand.go, PermissionByHTTP) is a
 *     GET to the URL with `domain` set in its query string to the name
 *     asked about; any 2xx allows the certificate and anything else denies
 *     it. Redirects are not followed, and the request gives up after 10
 *     seconds.
 *   - CertMagic (handshake.go) asks it -- its OnDemand DecisionFunc,
 *     through checkIfCertShouldBeObtained() -- in two places: during a
 *     handshake for a name it holds no certificate for in memory, before
 *     loading one from storage or obtaining one; and in
 *     renewDynamicCertificate(), before renewing an on-demand certificate.
 *     Denied at renewal, the certificate is dropped from the cache. A
 *     renewal of a certificate not yet expired runs in the background and
 *     one already expired holds the handshake; both ask.
 *   - On-demand certificates are renewed only from handshakes: CertMagic's
 *     background maintenance (maintain.go, RenewManagedCertificates) skips
 *     every certificate managed on demand. A domain nobody visits is not
 *     renewed, and not asked about -- which the daily check
 *     (VerifyCustomDomains) covers.
 *   - Two paths renew or obtain without asking: a handshake that finds
 *     another goroutine already renewing a certificate still valid is
 *     served the current one, and a certificate found missing from storage
 *     at renewal time (cleaned up while still cached) is obtained afresh
 *     by obtainOnDemandCertificate(), which does not consult the decision.
 *
 * Caddy's documentation asks for an ask endpoint that answers in
 * milliseconds and makes no DNS query. This one makes one, bounded by the
 * DomainVerifier's timeout (4 seconds unless set), because the spec's
 * check at renewal is a DNS check and this is the only place renewal is
 * seen.
 *
 * The answer:
 *
 *   - Only to callers ProbeAccess lets in -- the internal network or the
 *     token, as /_health -- and a plain 404 to anyone else, the endpoint
 *     not there to find. Caddy's ask sends no header of the operator's, so
 *     no token: it is let in by its address, which is why `ask` names a
 *     loopback host, as above, and not the platform's public one.
 *     Answered by the Kernel ahead of everything else, as Probes are:
 *     Caddy is not a visitor, and pays for no session, no stack and no
 *     throttle.
 *   - 200 for one of the platform's own hosts (Realms: listed, or looked
 *     up as the platform's), with no lookup: the platform answers for them.
 *   - 200 for a custom domain the application holds as active that
 *     verifies now (CustomDomainVerification::verify()), the outcome
 *     recorded as any check's is: a domain pointing elsewhere is
 *     deactivated by the asking, and one whose lookup fails counts toward
 *     its grace period. Or that passed a check under RETRY_AFTER seconds
 *     ago, as below.
 *   - 404 for anything else, and for what Host::normalize() does not read
 *     as a host name.
 *
 * A name that is not in the application's records is never resolved: the
 * caller learns nothing about any name's DNS from the answer, so the
 * endpoint is no oracle for it. And a domain checked under RETRY_AFTER
 * seconds ago is answered from that check without resolving it again --
 * refused if it failed, allowed if it passed: Caddy asks at every handshake
 * naming a host it has no certificate for, and a stream of handshakes
 * would otherwise hold a worker for a full check each. Naming one whose DNS
 * times out, after a failure; and after a pass, naming one whose issuance
 * keeps failing -- which whoever controls its DNS can arrange with a CAA
 * record -- while its DNS answers just inside the check's time and is
 * cached nowhere. A minute-old pass is the renewal's check all the same:
 * a domain that moved since cannot pass the CA's challenge here, and the
 * next ask after it, or the daily check, deactivates it.
 *
 * And one check of a domain at a time, across every worker and server:
 * before resolving, an ask claims the domain in the application's
 * ThrottleStore -- the one its throttles count in, which every worker
 * shares -- and an ask that finds it claimed is refused there and then,
 * with no lookup and no wait. Waiting would hold a worker as long as the
 * check it waits on, which is what the claim is there to stop: a burst of
 * handshakes naming a domain no check has yet recorded -- none ever has,
 * or the last is over a minute old -- each ran a full one. Refused, Caddy
 * fails that one handshake, and the client's next is answered from the
 * check that was under way. Under the claim the record is read again, so
 * an ask that read it just before another's check was recorded is
 * answered from that check rather than making a second. The claim is let
 * go when the check ends, however it ends; a worker killed in the middle
 * of one leaves the domain refused until the claim's window does, at most
 * CHECK_WINDOW seconds. Windows are the store's, aligned to the clock, so
 * a check under way as one ends can be joined by one more: two at once,
 * never a stream.
 *
 * The Kernel counts it under the route `_tls_ask`, never by the domain: a
 * name chosen by whoever opens a TLS connection is not a label. The access
 * log has the path, which carries no query.
 */
final readonly class TlsAskEndpoint
{
    public const string PATH = '/_tls/ask';

    /** The route a request to it is counted under in the metrics and the log. */
    public const string ROUTE = '_tls_ask';

    /** Seconds after a check during which a domain is answered from it, without another lookup. */
    public const int RETRY_AFTER = 60;

    /** Seconds a claim to check a domain lasts at most, should the check never end. */
    public const int CHECK_WINDOW = 60;

    /** What a domain is claimed under in the ThrottleStore: its own prefix, beside the throttles' buckets. */
    public const string CLAIM = 'tls-ask:';

    /**
     * @param ThrottleStore $claims where a domain is claimed for its one check at a time:
     *        the store the application's throttles count in, shared by every worker
     *        and server that answers the ask -- never one per worker, which would
     *        claim nothing another worker can see
     */
    public function __construct(
        private Realms $realms,
        private CustomDomains $domains,
        private CustomDomainVerification $verification,
        private ThrottleStore $claims,
        private ProbeAccess $access = new ProbeAccess(),
    ) {}

    public function matches(Request $request): bool
    {
        return $request->path === self::PATH;
    }

    public function handle(Request $request): Response
    {
        if (!$this->access->allows($request)) {
            return self::plain(404, 'Not found.');
        }
        if (!\in_array($request->method, ['GET', 'HEAD'], true)) {
            return self::plain(405, 'Method not allowed.')->withHeader('allow', 'GET, HEAD');
        }

        return $this->allows($request->query['domain'] ?? null) ? self::plain(200, 'OK.') : self::plain(404, 'Not found.');
    }

    /** Whether a certificate may be obtained or renewed for the domain Caddy asks about. */
    private function allows(mixed $domain): bool
    {
        $host = \is_string($domain) ? Host::normalize($domain) : null;
        if ($host === null) {
            return false;
        }

        // The listed hosts first, then the application's lookup -- its
        // records, never DNS -- as for any request.
        $realm = $this->realms->forHost($host);
        if ($realm !== null && !$realm->isCustom()) {
            return true;
        }

        $record = $this->held($host);
        if ($record === null) {
            return false;
        }
        $recent = $this->recent($record);
        if ($recent !== null) {
            return $recent;
        }

        // One check at a time: another's under way, this one is refused
        // rather than made to wait or to look the domain up as well.
        $claim = self::CLAIM . $host;
        [$claimed] = $this->claims->increment($claim, self::CHECK_WINDOW);
        if ($claimed !== 1) {
            return false;
        }

        try {
            // Read again: a check recorded since the read above answers.
            $record = $this->held($host);
            if ($record === null) {
                return false;
            }

            return $this->recent($record) ?? $this->verification->verify($record) === DomainStatus::Verified;
        } finally {
            $this->claims->release($claim, self::CHECK_WINDOW, PHP_INT_MAX);
        }
    }

    /**
     * The application's record of a host, while it holds it active. A record
     * for another host than asked is a lookup that answered the wrong
     * question, and is refused, as Realms refuses one.
     */
    private function held(string $host): ?CustomDomain
    {
        $record = $this->domains->find($host);

        return $record === null || $record->host !== $host || !$record->active ? null : $record;
    }

    /**
     * What the domain's last check says, when it was made under RETRY_AFTER
     * seconds ago: false if it failed, true if it passed. Null when there is
     * none that recent to go by, and a check is due.
     */
    private function recent(CustomDomain $record): ?bool
    {
        if ($record->checkedAt === null) {
            return null;
        }

        $age = $this->verification->now()->getTimestamp() - $record->checkedAt->getTimestamp();
        if ($age >= self::RETRY_AFTER) {
            return null;
        }
        if ($record->failingSince !== null) {
            return false;
        }

        // A pass is trusted only from the past: one a server with its clock
        // ahead recorded "later" than now would otherwise stand for as long.
        return $age >= 0 ? true : null;
    }

    private static function plain(int $status, string $body): Response
    {
        return Response::make($status, $body, ['content-type' => 'text/plain; charset=utf-8', 'cache-control' => 'no-store']);
    }
}
