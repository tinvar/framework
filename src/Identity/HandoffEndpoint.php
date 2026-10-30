<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\Memberships;
use Tinvar\Auth\SubtreeMemberships;
use Tinvar\Auth\UserLookup;
use Tinvar\Http\DatabaseNonceStore;
use Tinvar\Http\HttpException;
use Tinvar\Http\NonceStore;
use Tinvar\Http\Request;
use Tinvar\Http\RequestAttributes;
use Tinvar\Http\Response;
use Tinvar\Orm\Orm;
use Tinvar\Render\TenantClaim;
use Tinvar\Render\TokenClaims;
use Tinvar\Render\TokenError;
use Tinvar\Support\Signer;

/**
 * Moving a session from the platform host to another realm -- a panel's own
 * host, another domain of a tenant, a tenant's custom domain -- with no
 * cookie shared between them.
 *
 * tinvar-spec.md, "Identity across domains": "Sign-in happens on the
 * platform host, then redirects back to the custom domain with PKCE, so
 * credentials are never typed on a domain a reseller controls", and "Staff
 * accounts can't sign in on custom domains." Three GETs, each a top-level
 * navigation, so SameSite=Lax cookies go with every one -- and, before a
 * code goes to a custom domain, a POST:
 *
 *   1. `/_handoff/start?return=/path`, on the target. Derives a PKCE
 *      challenge from the target session and a fresh state (Pkce), and sends
 *      the browser to the platform host with them. A visitor already signed
 *      in here goes straight on to the path.
 *   2. `/_handoff/authorize`, on the platform host (the authority). Someone
 *      not signed in there is sent to sign in, and back here after; someone
 *      who is -- held to the target's rules: no staff on a custom domain, a
 *      membership of the target's panel in its tenant or a tenant below it
 *      -- is sent to the target with a one-time code (HandoffCode) bound to
 *      all of it, the realm's own tenant exactly.
 *
 *      Unless the target is a tenant's custom domain: then the code waits on
 *      the person. The answer is a consent screen naming the domain and the
 *      account (HandoffConsent), drawn by the application or by
 *      HandoffConsentShell, kept out of frames and caches; its confirm and
 *      its cancel each POST back here with the session's CSRF token and a
 *      ticket (ConsentTicket) bound to this session, the user at their auth
 *      epoch, the destination, the challenge and five minutes. A confirm
 *      spends the ticket on a code; a cancel spends it on a return to the
 *      destination with `error=access_denied`, and no code at all. The
 *      platform's own hosts are never asked about: the platform runs them,
 *      so there is nobody there to be warned about.
 *   3. `/_handoff/callback?code=...`, on the target. The code must verify,
 *      be fresh, name this origin, tenant and panel exactly, be redeemed by
 *      the session whose challenge it carries, name a user still on the auth
 *      epoch it was minted on and still admitted here, and never have been
 *      presented here before (NonceStore): one that has ends the session
 *      its first redemption started. Then the application starts a **new**
 *      session (HandoffSessions) -- never the one the browser arrived with --
 *      and the browser goes on to the path the handoff started for. Arriving
 *      with `error` instead, from a cancel, it is a 403 `handoff_cancelled`,
 *      and nothing here changes.
 *
 * Every refusal at the callback is the same 403, `handoff_refused`, with
 * the cause in the log alone: a caller that could tell "expired" from
 * "replayed" from "another session's" would learn which codes were once
 * good, and whose.
 *
 * Mounted by the Kernel behind the application's global middleware -- which
 * starts the session and signs its user in -- CSRF, and the `handoff`
 * throttle. CSRF holds the consent's POST alone: every other step is a GET,
 * and the step that changes something there, the callback, is held to the
 * session that started it by PKCE, which is what a CSRF token would have
 * said.
 */
final class HandoffEndpoint
{
    public const string START = '/_handoff/start';

    public const string AUTHORIZE = '/_handoff/authorize';

    public const string CALLBACK = '/_handoff/callback';

    /** The NonceStore scope a redeemed code's id is claimed under. */
    public const string NONCE_SCOPE = 'handoff-code';

    /**
     * The NonceStore scope a redemption is marked under once its session is
     * made, and a replay's tombstone is claimed under: whichever comes
     * first, the other finds it taken (callback()).
     */
    public const string REVOKED_SCOPE = 'handoff-revoked';

    /**
     * How long past the code's expiry its mark is kept: longer than a
     * redemption under way when the code expired could take to make its
     * session.
     */
    public const int TOMBSTONE_SECONDS = 300;

    /** The NonceStore scope an answered consent ticket's id is claimed under. */
    public const string CONSENT_SCOPE = 'handoff-consent';

    /** What a cancel tells the destination, in OAuth's spelling (RFC 6749, 4.1.2.1). */
    public const string CANCELLED = 'access_denied';

    /** The longest path a handoff goes on to. */
    public const int MAX_RETURN = 512;

    /**
     * @param Signer $signer built for HandoffCode::PURPOSE from the application key: signs
     *        codes, derives PKCE verifiers and hashes the user's epoch
     * @param NonceStore $nonces where redeemed codes and answered tickets are claimed --
     *        shared by every worker and server, or a code is single-use per process only;
     *        and durable, or a claim an OS crash takes back is a code that works again
     * @param \Closure(Request): string $sessionId the session a request carries, '' for
     *        none: the same resolver the CSRF, route and fragment tokens are bound with
     * @param AuthContext $auth the Kernel's: who the global middleware signed in
     * @param UserLookup $users finds a code's user again, and their epoch now
     * @param HandoffSessions $sessions starts the new session a handoff arrives into
     * @param \Closure(string): Response $signIn what a visitor not signed in on the platform
     *        host is sent to, given the path and query to come back to once they are
     * @param Memberships|null $memberships held to a target's panel: given, a handoff into a
     *        panel needs a membership of it in the target's tenant
     * @param (\Closure(Authenticatable): bool)|null $staff whether a user is staff, and so never
     *        handed to a custom domain
     * @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given
     * @param (\Closure(Request, HandoffConsent): Response)|null $consent draws the consent
     *        screen a handoff to a custom domain asks with: the destination, the account, and
     *        a confirm and a cancel posting HandoffConsent::fields(). HandoffConsentShell's
     *        unless given. Whatever it answers is sent never framed and never kept.
     */
    public function __construct(
        public readonly Realms $realms,
        private readonly Signer $signer,
        private readonly NonceStore $nonces,
        private readonly \Closure $sessionId,
        private readonly AuthContext $auth,
        private readonly Orm $orm,
        private readonly UserLookup $users,
        private readonly HandoffSessions $sessions,
        private readonly \Closure $signIn,
        private readonly ?Memberships $memberships = null,
        private readonly ?\Closure $staff = null,
        private readonly ?\Closure $clock = null,
        private readonly ?\Closure $consent = null,
    ) {
        // A host that may be a custom domain is a host a reseller may
        // control. Handing a session to one with nothing to say who is
        // staff, or whether the user belongs to its tenant at all, is what
        // the spec's two rules exist to stop; refused here rather than
        // found out by the first staff member to follow a link.
        if ($realms->looksUp() && ($staff === null || $memberships === null)) {
            throw new \InvalidArgumentException(
                'These realms may include custom domains: give the handoff Memberships, to hold a user to a domain\'s '
                . 'tenant, and a staff check, so staff are never handed to one.',
            );
        }

        // A claim is all that makes a code or a ticket single-use, and
        // nothing after it can be counted on to sync it: a consent's answer
        // writes nothing more, and the application's arrival may write to
        // another database, or fail. One left to SQLite's next checkpoint
        // (ADR 0050) is lost to an OS crash or a power cut before it, and
        // what it spent is good again until it expires.
        if ($nonces instanceof DatabaseNonceStore && !$nonces->durable) {
            throw new \InvalidArgumentException(
                'Handoff codes and consent tickets are claimed once for good: give the handoff a DatabaseNonceStore that is durable.',
            );
        }
    }

    /**
     * Whether a handoff may wait on a consent screen: whenever a host may be
     * a custom domain. Its forms POST, so the Kernel mounting it must check
     * the session's CSRF token, and hand it the token to draw them with.
     */
    public function asksConsent(): bool
    {
        return $this->realms->looksUp();
    }

    /** Whether this endpoint reads $auth: the Kernel's own, or who is signed in is never known. */
    public function reads(AuthContext $auth): bool
    {
        return $this->auth === $auth;
    }

    public function matches(Request $request): bool
    {
        return \in_array($request->path, [self::START, self::AUTHORIZE, self::CALLBACK], true);
    }

    /** GET: each step is a navigation. And POST to authorize, which a consent screen's forms answer. */
    public static function assertMethod(Request $request): void
    {
        $allowed = $request->path === self::AUTHORIZE ? ['GET', 'POST'] : ['GET'];
        if (!\in_array($request->method, $allowed, true)) {
            throw new HttpException(405, 'A handoff is followed with GET, and a consent answered with POST.', 'method_not_allowed', [
                'allow' => implode(', ', $allowed),
            ]);
        }
    }

    public function handle(Request $request): Response
    {
        self::assertMethod($request);

        if ($request->method === 'POST') {
            return $this->decide($request);
        }

        return match ($request->path) {
            self::START => $this->start($request),
            self::AUTHORIZE => $this->authorize($request),
            self::CALLBACK => $this->callback($request),
            default => throw self::unknown('No handoff step answers this path.'),
        };
    }

    /** Step 1, on the target: commit to this browser's session, and ask the platform host. */
    private function start(Request $request): Response
    {
        $realm = $this->realms->forRequest($request) ?? throw self::unknown('This host is no realm.');
        if ($this->realms->isAuthority($realm)) {
            throw self::unknown('Sign-in happens on the platform host itself; nothing is handed to it.');
        }

        $returnTo = self::returnPath($request->query['return'] ?? null) ?? '/';
        if ($this->auth->check()) {
            return self::private(Response::redirect($returnTo, 303));
        }

        $session = ($this->sessionId)($request);
        if ($session === '') {
            // The verifier is the session's; without one, the code would be
            // redeemable by whoever presented it.
            throw new HttpException(400, 'A handoff starts from a session; this request carries none.', 'handoff_no_session');
        }

        $state = Signer::encode(random_bytes(16));
        $query = http_build_query([
            'origin' => $realm->origin,
            'state' => $state,
            'challenge' => Pkce::challenge(Pkce::verifier($this->signer, $session, $state)),
            'return' => $returnTo,
        ], '', '&', PHP_QUERY_RFC3986);

        // The authority's configured origin, never this request's Host.
        return self::private(Response::location($this->realms->authority->url(self::AUTHORIZE . '?' . $query), 303));
    }

    /** Step 2, on the platform host: who is signed in here, and may they go there? */
    private function authorize(Request $request): Response
    {
        $this->assertAuthority($request);

        $origin = self::param($request, 'origin');
        $target = $origin === null ? null : $this->realms->forOrigin($origin);
        if ($target === null || $this->realms->isAuthority($target)) {
            throw self::unknown('The handoff names no realm this application serves.');
        }

        $state = self::param($request, 'state');
        $challenge = self::param($request, 'challenge');
        $returnTo = self::returnPath($request->query['return'] ?? null);
        if ($state === null || preg_match(HandoffCode::STATE, $state) !== 1
            || $challenge === null || preg_match(HandoffCode::CHALLENGE, $challenge) !== 1
            || $returnTo === null) {
            throw new HttpException(400, 'The handoff\'s state, challenge or path is not one a start makes.', 'handoff_malformed');
        }

        $user = $this->auth->user();
        if ($user === null) {
            // Signed in on the platform host, then back here: credentials are
            // typed on the platform's own host, never on the target.
            return self::private(($this->signIn)(self::pathAndQuery($request)));
        }

        [$refusal] = $this->admission($user, $target);
        if ($refusal !== null) {
            throw new HttpException(403, "The handoff to {$target->origin} is refused: {$refusal}.", 'handoff_refused');
        }

        // The platform's own hosts are its own word: it runs them, so a
        // session made there reaches nobody the person has to be warned
        // about. A custom domain is a host someone else runs, who can read
        // the session made there -- that is asked about, always.
        if (!$target->isCustom()) {
            return $this->issue($user, $target, $challenge, $state, $returnTo);
        }

        return $this->ask($request, $user, $target, $challenge, $state, $returnTo);
    }

    /**
     * The consent screen: the destination and the account, and a confirm
     * and a cancel carrying a ticket for this pending handoff alone.
     */
    private function ask(Request $request, Authenticatable $user, Realm $target, string $challenge, string $state, string $returnTo): Response
    {
        $session = ($this->sessionId)($request);
        if ($session === '') {
            // The ticket is the session's; without one, a screen's answer
            // could be posted from anywhere.
            throw new HttpException(400, 'A consent is asked of a session; this request carries none.', 'handoff_no_session');
        }

        $csrf = $request->attribute(RequestAttributes::CSRF_TOKEN);
        if (!\is_string($csrf) || $csrf === '') {
            // Forms that could never post would leave the screen a dead end,
            // and a Kernel with no CSRF tokens checks none on the post: both
            // are a Kernel built wrong, which refuses to be (Kernel).
            throw new \LogicException('A consent screen needs the session\'s CSRF token; the Kernel mounting the handoff has no CsrfTokens.');
        }

        $ticket = ConsentTicket::mint(
            $this->signer,
            $session,
            $user->authIdentifier(),
            $this->auth->epoch(),
            $target,
            $challenge,
            $state,
            $returnTo,
            $this->now(),
            TenantClaim::of($this->orm, $target->tenant),
        );
        $consent = new HandoffConsent($user, $target, $ticket->encode($this->signer), $csrf, $ticket->expiresAt);
        $screen = $this->consent === null ? new HandoffConsentShell()->respond($request, $consent) : ($this->consent)($request, $consent);

        return self::unframed(self::private($screen));
    }

    /**
     * Step 2 answered, on the platform host: a consent screen's confirm, or
     * its cancel. Past CSRF already; what was agreed to is the ticket's
     * alone -- nothing else the post says is read -- and held to this
     * session, the user at the epoch they were asked at, and a destination
     * that is still the realm it named. Spent once, whichever was posted.
     */
    private function decide(Request $request): Response
    {
        $this->assertAuthority($request);

        $decision = $request->body[HandoffConsent::DECISION] ?? null;
        $raw = $request->body[HandoffConsent::TICKET] ?? null;
        if (!\in_array($decision, [HandoffConsent::CONFIRM, HandoffConsent::CANCEL], true) || !\is_string($raw)) {
            throw new HttpException(400, 'A consent is answered with its ticket and a decision.', 'handoff_malformed');
        }

        $now = $this->now();
        try {
            $ticket = ConsentTicket::decode($raw, $this->signer, $now);
        } catch (TokenError $e) {
            throw self::declined($e->getMessage());
        }

        if (!$ticket->belongsTo($this->signer, ($this->sessionId)($request))) {
            throw self::declined('it was shown to another session');
        }

        $user = $this->auth->user();
        if ($user === null || !$ticket->isFor($this->signer, $user->authIdentifier(), $this->auth->epoch())) {
            throw self::declined('who is signed in, or their auth epoch, has changed since it was shown');
        }

        // The destination the screen named, exactly, and still that realm:
        // a record moved to another tenant or panel while the screen was
        // open is not what the person agreed to.
        $target = $this->realms->forOrigin($ticket->origin);
        if ($target === null || $this->realms->isAuthority($target)
            || TenantClaim::of($this->orm, $target->tenant) !== $ticket->tenant || $target->panel !== $ticket->panel) {
            throw self::declined("its destination {$ticket->origin} is no longer the realm it named");
        }

        // Held again on a confirm: a membership taken away, or a user made
        // staff, while the screen was open.
        if ($decision === HandoffConsent::CONFIRM) {
            [$refusal] = $this->admission($user, $target);
            if ($refusal !== null) {
                throw self::declined($refusal);
            }
        }

        // Last, so a ticket is spent by the one answer that gets this far;
        // and before anything is issued, so two posts at once cannot both
        // be taken.
        if (!$this->nonces->claim(self::CONSENT_SCOPE, $ticket->id, $ticket->expiresAt, $now)) {
            throw self::declined('it has been answered before');
        }

        if ($decision === HandoffConsent::CANCEL) {
            return self::private(Response::location($target->url(self::CALLBACK . '?error=' . self::CANCELLED), 303));
        }

        return $this->issue($user, $target, $ticket->challenge, $ticket->state, $ticket->returnTo);
    }

    /** A code for $user into $target, and the browser sent to redeem it there. */
    private function issue(Authenticatable $user, Realm $target, string $challenge, string $state, string $returnTo): Response
    {
        $code = HandoffCode::mint(
            $this->signer,
            $target,
            $user->authIdentifier(),
            $this->auth->epoch(),
            $challenge,
            $state,
            $returnTo,
            $this->now(),
            TenantClaim::of($this->orm, $target->tenant),
        );

        return self::private(Response::location($target->url(self::CALLBACK . '?code=' . rawurlencode($code->encode($this->signer))), 303));
    }

    private function assertAuthority(Request $request): void
    {
        $here = $this->realms->forRequest($request);
        if ($here === null || !$this->realms->isAuthority($here)) {
            throw self::unknown('Handoffs are authorized on the platform host alone.');
        }
    }

    /** Step 3, on the target: redeem the code once, into a new session. */
    private function callback(Request $request): Response
    {
        $realm = $this->realms->forRequest($request) ?? throw self::unknown('This host is no realm.');
        $now = $this->now();

        // Declined on the platform host: no code came, and none is read even
        // if one did. Nothing here changes, so nothing needs proving -- a
        // link to this is a page saying so, and no more.
        if (\array_key_exists('error', $request->query)) {
            throw new HttpException(403, 'The handoff was cancelled on the platform host.', 'handoff_cancelled');
        }

        $raw = $request->query['code'] ?? null;
        if (!\is_string($raw)) {
            throw self::refused('the request carries no code');
        }

        try {
            $code = HandoffCode::decode($raw, $this->signer, $now);
        } catch (TokenError $e) {
            throw self::refused($e->getMessage());
        }

        // The binding, against this request and this realm -- never against
        // anything else the request says.
        if (!hash_equals($realm->origin, $code->origin) || $this->realms->isAuthority($realm)) {
            throw self::refused("it was minted for {$code->origin}, and this is {$realm->origin}");
        }
        if ($code->tenant !== TenantClaim::of($this->orm, $realm->tenant)) {
            throw self::refused('it was minted for another tenant than this realm\'s');
        }
        if ($code->panel !== $realm->panel) {
            throw self::refused('it was minted for another panel than this realm\'s');
        }

        // Claimed here, by the first presentation of a good code meant for
        // this realm, so a code shown again is known for one whatever else
        // it fails: a code redeemed once and shown a second time was copied,
        // and the session its first redemption started may be the copier's.
        // That session ends (HandoffSessions::revoke()). Before the session
        // is made, so two at once cannot both get one.
        if (!$this->nonces->claim(self::NONCE_SCOPE, $code->id, $code->expiresAt, $now)) {
            // A tombstone first, then the revocation: the first redemption
            // may have claimed the code and not yet made its session, and
            // finds the tombstone once it has (below). Whatever this claim
            // answers -- the first redemption's own mark, or another
            // replay's tombstone -- the session is ended.
            $this->nonces->claim(self::REVOKED_SCOPE, Arrival::redemptionOf($code), $code->expiresAt + self::TOMBSTONE_SECONDS, $now);
            $this->sessions->revoke(Arrival::redemptionOf($code));

            throw self::refused('it has been redeemed before; the session it started is ended');
        }

        // Given back when a check below refuses it, so a copy presented
        // first, from a session that did not start the handoff, neither
        // spends the code nor gets anything: its owner can still redeem it.
        try {
            $session = ($this->sessionId)($request);
            if (!Pkce::matches($this->signer, $session, $code->state, $code->challenge)) {
                throw self::refused('the session redeeming it is not the one that started the handoff');
            }

            $user = $this->users->find($code->user);
            if ($user === null || $user->authIdentifier() !== $code->user) {
                throw self::refused('its user is not there any more');
            }
            $epoch = $this->users->epoch($user);
            if (!hash_equals(TokenClaims::hashAuth($this->signer, $code->user, $epoch), $code->authHash)) {
                throw self::refused('its user\'s auth epoch has moved since it was minted');
            }

            // Held again: a membership taken away, or a user made staff, in
            // the minute the code was in flight. And where the session lands:
            // the realm's tenant, or the one below it the user belongs to.
            [$refusal, $tenant] = $this->admission($user, $realm);
            if ($refusal !== null) {
                throw self::refused($refusal);
            }
        } catch (\Throwable $e) {
            $this->nonces->release(self::NONCE_SCOPE, $code->id);

            throw $e;
        }

        $redemption = Arrival::redemptionOf($code);
        $fresh = $this->sessions->arrive($request, new Arrival($user, $epoch, $realm, $code->returnTo, $tenant, $redemption));

        // Checked once the session is made, never before: a replay landing
        // between the code's claim and the session found nothing to end.
        // The same claim a replay makes, so one of the two takes it and the
        // other sees it taken: a replay after this one ends the session
        // itself; one before it left a tombstone, and the session ends here.
        if (!$this->nonces->claim(self::REVOKED_SCOPE, $redemption, $code->expiresAt + self::TOMBSTONE_SECONDS, $this->now())) {
            $this->sessions->revoke($redemption);

            throw self::refused('it was replayed while it was being redeemed; the session it started is ended');
        }

        if ($fresh === '' || hash_equals($session, $fresh)) {
            // The one thing the application must not get wrong here: a
            // session the browser arrived with may be one an attacker
            // planted, and signing it in hands them the account.
            throw new \LogicException('HandoffSessions::arrive() must start a new session, under a new id; it kept the one the request carried.');
        }

        return self::private(Response::redirect($code->returnTo, 303));
    }

    /**
     * Why $user may not hold a session in $realm -- null when they may --
     * and the tenant that session runs as.
     *
     * Staff are never handed to a custom domain: a domain a reseller
     * controls is a domain whose traffic the reseller can read, and a staff
     * session read there is every tenant's data. And a realm in a panel
     * needs a membership of it in the realm's tenant subtree -- asked as
     * each tenant, as the panel's own check is -- so a domain gets sessions
     * only for the people its tenant and the tenants below it have: "A
     * session created on it can only hold memberships within that domain's
     * tenant subtree." The realm's own tenant first; then the first tenant
     * below it the application lists for the user (SubtreeMemberships).
     *
     * @return array{string|null, string}
     */
    private function admission(Authenticatable $user, Realm $realm): array
    {
        if ($realm->isCustom() && ($this->staff === null || ($this->staff)($user))) {
            return ['staff accounts are never signed in on a custom domain', ''];
        }

        if ($realm->panel === '' || $this->memberships === null) {
            return [null, $realm->tenant];
        }

        $members = new SubtreeMemberships($this->orm, $this->memberships);
        if ($members->holds($user, $realm->panel, $realm->tenant)) {
            return [null, $realm->tenant];
        }

        if ($realm->tenant !== '') {
            foreach ($members->tenants($user, $realm->panel, $realm->tenant) as $below) {
                return [null, $below];
            }
        }

        return ["the user holds no membership of panel '{$realm->panel}' in its tenant or below it", ''];
    }

    /**
     * A path on the target to go on to, or null: root-relative and
     * same-origin by Response's own rules, so the two cannot drift; and
     * never a handoff step, which would only go round again.
     */
    private static function returnPath(mixed $value): ?string
    {
        if (!\is_string($value) || $value === '' || \strlen($value) > self::MAX_RETURN
            || str_contains($value, '#') || !Response::isSameOriginTarget($value)) {
            return null;
        }

        return str_starts_with(strtolower(explode('?', $value, 2)[0]), '/_handoff/') ? null : $value;
    }

    private static function param(Request $request, string $name): ?string
    {
        $value = $request->query[$name] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /** This request's path and query, to come back to. */
    private static function pathAndQuery(Request $request): string
    {
        $query = $request->rawQuery ?? http_build_query($request->query, '', '&', PHP_QUERY_RFC3986);

        return $query === '' ? $request->path : "{$request->path}?{$query}";
    }

    /**
     * Never kept, and never told on: each response here carries a code, a
     * challenge or a session about to change, and a Referer carrying any of
     * them to the next page's third parties is a leak.
     */
    private static function private(Response $response): Response
    {
        return $response
            ->withHeader('cache-control', 'no-store')
            ->withHeader('referrer-policy', 'no-referrer');
    }

    /**
     * Never drawn inside another page: a consent screen in a frame is one a
     * page of anyone's can lay its own over, and have the confirm pressed
     * through it. Both headers, for browsers old and new; the policy added
     * beside whatever the screen's own is, as a policy of its own, which a
     * browser enforces with it.
     */
    private static function unframed(Response $response): Response
    {
        return $response
            ->withHeader('x-frame-options', 'DENY')
            ->withAddedHeader('content-security-policy', "frame-ancestors 'none'");
    }

    private static function unknown(string $why): HttpException
    {
        return new HttpException(404, $why, 'handoff_unknown');
    }

    private static function refused(string $why): HttpException
    {
        return new HttpException(403, "The handoff code is refused: {$why}.", 'handoff_refused');
    }

    /** As a code's refusals are: one 403, the cause in the log alone. */
    private static function declined(string $why): HttpException
    {
        return new HttpException(403, "The handoff consent is refused: {$why}.", 'handoff_refused');
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }
}
