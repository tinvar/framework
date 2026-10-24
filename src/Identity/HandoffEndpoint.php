<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\Membership;
use Tinvar\Auth\Memberships;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Jobs\UserLookup;
use Tinvar\Orm\Orm;
use Tinvar\Render\TokenClaims;
use Tinvar\Render\TokenError;
use Tinvar\Support\Signer;
use Tinvar\Webhooks\NonceStore;

/**
 * Moving a session from the platform host to another realm -- a panel's own
 * host, another domain of a tenant, a tenant's custom domain -- with no
 * cookie shared between them.
 *
 * tinvar-spec.md, "Identity across domains": "Sign-in happens on the
 * platform host, then redirects back to the custom domain with PKCE, so
 * credentials are never typed on a domain a reseller controls", and "Staff
 * accounts can't sign in on custom domains." Three GETs, each a top-level
 * navigation, so SameSite=Lax cookies go with every one:
 *
 *   1. `/_handoff/start?return=/path`, on the target. Derives a PKCE
 *      challenge from the target session and a fresh state (Pkce), and sends
 *      the browser to the platform host with them. A visitor already signed
 *      in here goes straight on to the path.
 *   2. `/_handoff/authorize`, on the platform host (the authority). Someone
 *      not signed in there is sent to sign in, and back here after; someone
 *      who is -- held to the target's rules: no staff on a custom domain, a
 *      membership of the target's panel in its tenant -- is sent to the
 *      target with a one-time code (HandoffCode) bound to all of it.
 *   3. `/_handoff/callback?code=...`, on the target. The code must verify,
 *      be fresh, name this origin, tenant and panel exactly, be redeemed by
 *      the session whose challenge it carries, name a user still on the auth
 *      epoch it was minted on and still admitted here, and never have been
 *      redeemed before (NonceStore). Then the application starts a **new**
 *      session (HandoffSessions) -- never the one the browser arrived with --
 *      and the browser goes on to the path the handoff started for.
 *
 * Every refusal at the callback is the same 403, `handoff_refused`, with
 * the cause in the log alone: a caller that could tell "expired" from
 * "replayed" from "another session's" would learn which codes were once
 * good, and whose.
 *
 * Mounted by the Kernel behind the application's global middleware -- which
 * starts the session and signs its user in -- and the `handoff` throttle.
 * Not behind CSRF: each step is a GET, and the step that changes something,
 * the callback, is held to the session that started it by PKCE, which is
 * what a CSRF token would have said.
 */
final class HandoffEndpoint
{
    public const string START = '/_handoff/start';

    public const string AUTHORIZE = '/_handoff/authorize';

    public const string CALLBACK = '/_handoff/callback';

    /** The NonceStore scope a redeemed code's id is claimed under. */
    public const string NONCE_SCOPE = 'handoff-code';

    /** The longest path a handoff goes on to. */
    public const int MAX_RETURN = 512;

    /**
     * @param Signer $signer built for HandoffCode::PURPOSE from the application key: signs
     *        codes, derives PKCE verifiers and hashes the user's epoch
     * @param NonceStore $nonces where redeemed codes are claimed -- shared by every worker
     *        and server, or a code is single-use per process only
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

    /** GET only: each step is a navigation. */
    public static function assertMethod(Request $request): void
    {
        if ($request->method !== 'GET') {
            throw new HttpException(405, 'A handoff is followed with GET.', 'method_not_allowed', ['allow' => 'GET']);
        }
    }

    public function handle(Request $request): Response
    {
        self::assertMethod($request);

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
        $here = $this->realms->forRequest($request);
        if ($here === null || !$this->realms->isAuthority($here)) {
            throw self::unknown('Handoffs are authorized on the platform host alone.');
        }

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

        $refusal = $this->refusal($user, $target);
        if ($refusal !== null) {
            throw new HttpException(403, "The handoff to {$target->origin} is refused: {$refusal}.", 'handoff_refused');
        }

        $code = HandoffCode::mint(
            $this->signer,
            $target,
            $user->authIdentifier(),
            $this->auth->epoch(),
            $challenge,
            $state,
            $returnTo,
            $this->now(),
        );

        return self::private(Response::location($target->url(self::CALLBACK . '?code=' . rawurlencode($code->encode($this->signer))), 303));
    }

    /** Step 3, on the target: redeem the code once, into a new session. */
    private function callback(Request $request): Response
    {
        $realm = $this->realms->forRequest($request) ?? throw self::unknown('This host is no realm.');
        $now = $this->now();

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
        if ($code->tenant !== $realm->tenant) {
            throw self::refused('it was minted for another tenant than this realm\'s');
        }
        if ($code->panel !== $realm->panel) {
            throw self::refused('it was minted for another panel than this realm\'s');
        }

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

        // Held again: a membership taken away, or a user made staff, in the
        // minute the code was in flight.
        $refusal = $this->refusal($user, $realm);
        if ($refusal !== null) {
            throw self::refused($refusal);
        }

        // Last, so a code is spent by the one redemption that gets this far;
        // and before the session, so two at once cannot both get one.
        if (!$this->nonces->claim(self::NONCE_SCOPE, $code->id, $code->expiresAt, $now)) {
            throw self::refused('it has been redeemed before');
        }

        $fresh = $this->sessions->arrive($request, new Arrival($user, $epoch, $realm, $code->returnTo));
        if ($fresh === '' || hash_equals($session, $fresh)) {
            // The one thing the application must not get wrong here: a
            // session the browser arrived with may be one an attacker
            // planted, and signing it in hands them the account.
            throw new \LogicException('HandoffSessions::arrive() must start a new session, under a new id; it kept the one the request carried.');
        }

        return self::private(Response::redirect($code->returnTo, 303));
    }

    /**
     * Why $user may not hold a session in $realm, or null when they may.
     *
     * Staff are never handed to a custom domain: a domain a reseller
     * controls is a domain whose traffic the reseller can read, and a staff
     * session read there is every tenant's data. And a realm in a panel
     * needs a membership of it in the realm's tenant -- asked as that
     * tenant, as the panel's own check is -- so a domain gets sessions only
     * for the people its tenant has.
     */
    private function refusal(Authenticatable $user, Realm $realm): ?string
    {
        if ($realm->isCustom() && ($this->staff === null || ($this->staff)($user))) {
            return 'staff accounts are never signed in on a custom domain';
        }

        if ($realm->panel === '' || $this->memberships === null) {
            return null;
        }

        $memberships = $this->memberships;
        $ask = static fn (): ?Membership => $memberships->membership($user, $realm->panel, $realm->tenant);
        $membership = $realm->tenant === '' ? $ask() : $this->orm->actAs($realm->tenant, $ask);

        return $membership === null ? "the user holds no membership of panel '{$realm->panel}' in its tenant" : null;
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

    private static function unknown(string $why): HttpException
    {
        return new HttpException(404, $why, 'handoff_unknown');
    }

    private static function refused(string $why): HttpException
    {
        return new HttpException(403, "The handoff code is refused: {$why}.", 'handoff_refused');
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }
}
