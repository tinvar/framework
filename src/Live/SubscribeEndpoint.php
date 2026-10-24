<?php

declare(strict_types=1);

namespace Tinvar\Live;

use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\Endpoints\Limits;
use Tinvar\Http\EntryContext;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Pipeline;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\NoTenantContext;
use Tinvar\Orm\Orm;
use Tinvar\Render\TokenClaims;
use Tinvar\Render\TokenError;
use Tinvar\Routing\LoaderRunner;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Routing\RoutingException;

/**
 * POST /_live/subscribe
 *
 * Trades the live tokens a client holds -- its page's and its fragments' --
 * for the hub's access to their topics, and hands each token back refreshed.
 *
 * The hub can check a signature and an expiry, not a session: whoever holds
 * its access token hears its topics until it runs out. So the session is
 * checked here, on every trade. A token minted for another session, or for
 * another user or auth epoch under this one -- a sign-out in another tab, a
 * role change -- is refused with a 401, which ends the session on the
 * client: "Client-held data ... is cleared, with a full page load, on ...
 * session revocation, which the server signals with a 401" (tinvar-spec.md).
 * The client's live tokens are client-held data, and so is the hub's cookie,
 * which the refusal clears.
 *
 * A token that is merely unusable -- expired past its lifetime, from a build
 * no longer answered for, forged, or from another tenant or panel than the
 * rest -- is answered with null in its place, and its topics are not
 * granted: the same answer for every reason, as for fragment tokens.
 *
 * And so is one for a page the session may no longer open. A token says
 * which page a client holds, never whether it may go on holding it -- the
 * tenant and panel in it were the request's when it was minted -- so every
 * trade runs the page's route as /_fragment does: its tenant resolved from
 * this request, its panel entered, the panel's guard, membership check and
 * stack, the route's own middleware and every #[Authorize] in its chain. A
 * user taken out of a panel, or out of a tenant, stops being granted its
 * topics at the next trade rather than for the token's whole lifetime --
 * and an application that also moves the user's auth epoch on such a
 * change (AuthContext) has the next trade refused with the 401 that purges
 * what the client holds.
 *
 * Behind CSRF and the live throttle: a trade sets a cookie, and is a POST.
 * Not behind authentication: a page a visitor with no account may see can
 * read tags too.
 */
final class SubscribeEndpoint
{
    public const string PATH = '/_live/subscribe';

    /** A page and its boundaries' tokens, not a payload. */
    public const int MAX_BODY_BYTES = 32_768;

    public const int MAX_TOKENS = 32;

    /**
     * How long one grant lets a client listen before it trades again, and
     * so how long a session ended elsewhere goes on hearing invalidations --
     * which carry a topic and a tag, never data.
     */
    public const int GRANT_SECONDS = 300;

    /**
     * The shortest grant worth giving. The built-in hub closes a connection
     * some seconds before its token's `exp` (ADR 0030), so a grant shorter
     * than this would be closed about as soon as it opened.
     */
    public const int MIN_GRANT_SECONDS = 30;

    /**
     * @param \Closure(class-string): Middleware $middlewareResolver builds a route's own middleware
     * @param list<class-string<Middleware>> $globalMiddleware already run around this endpoint,
     *        so left out of a route's when it is run again here
     */
    public function __construct(
        private readonly LiveUpdates $live,
        private readonly AuthContext $auth,
        private readonly Orm $orm,
        private readonly RouteRegistry $routes,
        private readonly LoaderRunner $loaders,
        private readonly EntryContext $entry,
        private readonly \Closure $middlewareResolver,
        private readonly ?PanelContext $panels = null,
        private readonly ?BuildRegistry $builds = null,
        private readonly array $globalMiddleware = [],
    ) {}

    public function matches(Request $request): bool
    {
        return $request->path === self::PATH;
    }

    /** POST only: the answer is per-session and sets a cookie. */
    public static function assertMethod(Request $request): void
    {
        if ($request->method !== 'POST') {
            throw new HttpException(405, 'Live subscriptions are POST only.', 'method_not_allowed', ['allow' => 'POST']);
        }
    }

    public function handle(Request $request, string $rawBody = '', ?int $now = null): Response
    {
        self::assertMethod($request);
        $now ??= time();

        $body = Limits::input($request, $rawBody, self::MAX_BODY_BYTES);
        $tokens = $body['tokens'] ?? null;
        if (!\is_array($tokens) || !array_is_list($tokens) || $tokens === [] || \count($tokens) > self::MAX_TOKENS) {
            throw new HttpException(400, 'Send the live tokens you hold, as a list.', 'bad_request');
        }

        $session = ($this->live->sessionId)($request);
        if ($session === '') {
            return $this->ended('no_session');
        }

        $auth = TokenClaims::hashAuth($this->live->signer, $this->auth->id(), $this->auth->epoch());

        $refreshed = [];
        /** @var array<string, list<array{int, LiveToken, LiveToken}>> $byPage tokens that survived, by the page they name */
        $byPage = [];
        $binding = null;

        foreach ($tokens as $i => $token) {
            $refreshed[$i] = null;
            if (!\is_string($token)) {
                continue;
            }

            try {
                $live = LiveToken::decode($token, $this->live->signer, $now);
            } catch (TokenError) {
                continue;
            }

            $claims = $live->claims;

            // Whose it is, before anything else about it: a token of another
            // session's, user's or epoch's means the session the client
            // thinks it has is not the one asking. Every user binding is
            // compared, the signed-out one included: a page rendered signed
            // out, held across a sign-in, is no longer this session's page.
            if (!$claims->belongsTo($this->live->signer, $session) || !hash_equals($claims->authHash, $auth)) {
                return $this->ended('session_changed');
            }

            if ($this->builds !== null && !$this->builds->accepts($claims->build)) {
                continue;
            }

            // One stream, one tenant and panel: the first token's. A client's
            // tokens are all its page's, and a page is in one of each.
            $binding ??= [$claims->tenant, $claims->panel];
            if ([$claims->tenant, $claims->panel] !== $binding) {
                continue;
            }

            $renewed = $live->refreshed($now);
            if ($renewed === null || $renewed->claims->expiresAt - $now < self::MIN_GRANT_SECONDS) {
                continue;
            }

            $byPage[json_encode([$claims->route, $claims->params], JSON_THROW_ON_ERROR)][] = [$i, $live, $renewed];
        }

        $granted = [];
        $expiresAt = $now + self::GRANT_SECONDS;
        foreach ($byPage as $tokensOfPage) {
            // Once per page: a page's token and its boundaries' name one
            // route, so one run of it says for all of them.
            if (!$this->admits($tokensOfPage[0][1]->claims, $request)) {
                continue;
            }

            foreach ($tokensOfPage as [$i, $live, $renewed]) {
                $refreshed[$i] = $renewed->encode($this->live->signer);
                $expiresAt = min($expiresAt, $renewed->claims->expiresAt);
                foreach (array_keys($live->topics) as $topic) {
                    $granted[$topic] = true;
                }
            }
        }
        ksort($refreshed);

        $topics = array_keys($granted);
        sort($topics);

        if ($topics === []) {
            // Nothing to listen to: whatever an earlier grant allowed goes.
            return $this->live->hub->revoke(self::json([
                'tokens' => $refreshed,
                'topics' => [],
                'url' => null,
                'expiresAt' => null,
            ]));
        }

        return $this->live->hub->grant(
            self::json([
                'tokens' => $refreshed,
                'topics' => $topics,
                'url' => $this->live->hub->subscribeUrl($topics),
                'expiresAt' => $expiresAt,
            ]),
            $topics,
            $expiresAt,
            TokenClaims::hashSession($this->live->signer, $session),
        );
    }

    /**
     * Whether this request may still open the page the token was minted
     * for, in the tenant and panel it names -- the route run as /_fragment
     * runs it (FragmentEndpoint), short of rendering anything. Fail-closed:
     * any refusal along the way, a redirect from a guard included, is no.
     */
    private function admits(TokenClaims $claims, Request $request): bool
    {
        try {
            $chain = $this->routes->chainFor($claims->route, $claims->params);
        } catch (RoutingException) {
            // Renamed or removed since: a page this server no longer has.
            return false;
        }

        // The panel the route is in, as the token says it was minted in: a
        // token naming another, or none, was not minted for this page.
        if (($this->entry->panelForChain($chain)->name ?? '') !== $claims->panel) {
            return false;
        }

        $pipeline = new Pipeline([
            // The tenant, resolved from this request, and the panel's guard,
            // membership check and stack -- what decides whether the session
            // still belongs where the token says it was.
            ...$this->entry->stackForChain($chain),
            ...array_map(
                fn (string $class): Middleware => ($this->middlewareResolver)($class),
                array_values(array_diff($chain->middleware(), $this->globalMiddleware)),
            ),
        ]);

        $run = fn (): Response => $pipeline->run($request, function (Request $inner) use ($chain, $claims): Response {
            // Checked, never applied: a token agrees with where the request
            // is, and can put it nowhere.
            if (($this->panels?->currentOrNull() ?? '') !== $claims->panel || $this->orm->tenant() !== $claims->tenant) {
                throw new HttpException(404, 'The page is in another tenant or panel than the token.', 'live_unavailable');
            }

            // Whoever the route's middleware says is signed in, as at the
            // start of the trade.
            if (!hash_equals($claims->authHash, TokenClaims::hashAuth($this->live->signer, $this->auth->id(), $this->auth->epoch()))) {
                throw new HttpException(404, 'The page was drawn for someone else.', 'live_unavailable');
            }

            // Model binding and every #[Authorize] in the chain: a page whose
            // subject the user may no longer see grants nothing about it.
            $this->loaders->bindAndAuthorize($chain, $inner);

            return Response::make(204);
        });

        try {
            if ($claims->panel === '') {
                $response = $run();
            } elseif ($this->panels === null) {
                return false;
            } else {
                // Entered as /_fragment enters it: the trade itself is in no
                // panel, and the stack holds the session to this one.
                $response = $this->panels->within($claims->panel, $run);
            }
        } catch (HttpException|RoutingException|NoTenantContext) {
            return false;
        }

        return $response->status >= 200 && $response->status < 300;
    }

    /**
     * The refusal that ends the session on the client, with whatever the
     * hub was letting it hear taken away.
     */
    private function ended(string $code): Response
    {
        return $this->live->hub->revoke(Response::make(
            401,
            json_encode(['error' => ['code' => $code]], JSON_THROW_ON_ERROR),
            ['content-type' => 'application/json', 'cache-control' => 'private, no-store', 'vary' => 'Cookie'],
        ));
    }

    /** @param array<string, mixed> $payload */
    private static function json(array $payload): Response
    {
        return Response::make(
            200,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            [
                'content-type' => 'application/json',
                // Per session: the refreshed tokens and the cookie are this
                // visitor's alone.
                'cache-control' => 'private, no-store',
                'vary' => 'Cookie',
            ],
        );
    }
}
