<?php

declare(strict_types=1);

namespace Tinvar\Channels;

use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\Endpoints\Limits;
use Tinvar\Http\EntryContext;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Middleware\RequireAuthentication;
use Tinvar\Http\Pipeline;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Live\LiveUpdates;
use Tinvar\Orm\NoTenantContext;
use Tinvar\Orm\Orm;
use Tinvar\Render\TenantClaim;
use Tinvar\Render\TokenClaims;

/**
 * POST /_channel/{name}
 *
 * Subscribes a client to one channel: the body names its parameters, and
 * the answer is a channel token (ChannelToken) the client trades at
 * /_live/subscribe, beside its page's live tokens, for the hub's access to
 * the channel's topic.
 *
 * Let in as every entry point is (tinvar-spec.md, "Order for every action
 * call"): behind the Kernel's stack -- the global middleware, CSRF, the live
 * throttle and authentication, unless the channel is #[PublicAccess] --
 * then the channel's tenant, its panel and the panel's guard, membership
 * check and stack, its own #[Middleware], its models resolved scoped to the
 * tenant (a missing or foreign id is a 404) and every #[Authorize] (a 403).
 * The token binds what that run found: the session and the user with their
 * auth epoch, the tenant, the panel, the build, and the topic the server
 * worked out from the rows it resolved. The client never names a topic.
 *
 * And let in again on every trade (admits()), the same way, with the
 * parameters the token carries: a subscription says which channel a client
 * listens to, never whether it may go on listening, so a customer whose
 * order is no longer theirs, a user taken out of the panel, or a session
 * in another tenant is granted nothing at the next trade.
 */
final class ChannelEndpoint
{
    public const string PREFIX = '/_channel/';

    /** A channel's parameters, not a payload. */
    public const int MAX_BODY_BYTES = 4_096;

    /**
     * @param \Closure(class-string): Middleware $middlewareResolver builds a channel's own #[Middleware]
     * @param list<class-string> $globalMiddleware already run by the stack around this endpoint
     */
    public function __construct(
        public readonly ChannelRegistry $registry,
        private readonly ChannelRunner $runner,
        private readonly LiveUpdates $live,
        private readonly ChannelTopics $topics,
        private readonly AuthContext $auth,
        private readonly Orm $orm,
        private readonly EntryContext $entry,
        private readonly \Closure $middlewareResolver,
        private readonly ?PanelContext $panels = null,
        private readonly ?BuildRegistry $builds = null,
        private readonly array $globalMiddleware = [],
    ) {}

    public function matches(Request $request): bool
    {
        return str_starts_with($request->path, self::PREFIX);
    }

    /** POST only: the answer is per-session, and a subscription is not a page. */
    public static function assertMethod(Request $request): void
    {
        if ($request->method !== 'POST') {
            throw new HttpException(405, 'Channel subscriptions are POST only.', 'method_not_allowed', ['allow' => 'POST']);
        }
    }

    /**
     * The class a request subscribes to, so the stack's authentication reads
     * #[PublicAccess] off it; null for a name no channel has, which is not
     * public.
     *
     * @return array{0: class-string, 1: null}|null
     */
    public function entryPoint(Request $request): ?array
    {
        $name = substr($request->path, \strlen(self::PREFIX));
        if (!$this->registry->has($name)) {
            return null;
        }

        /** @var class-string $class */
        $class = $this->registry->get($name)->class;

        return [$class, null];
    }

    public function handle(Request $request, string $rawBody = '', ?int $now = null): Response
    {
        self::assertMethod($request);

        $name = substr($request->path, \strlen(self::PREFIX));
        if (!$this->registry->has($name)) {
            throw new HttpException(404, 'No channel by that name.', 'not_found');
        }
        $channel = $this->registry->get($name);

        $body = Limits::input($request, $rawBody, self::MAX_BODY_BYTES, 4);
        $params = $body['params'] ?? [];
        // {} decodes to [], which is a list: a channel with no parameters
        // is subscribed to with none.
        if (!\is_array($params) || ($params !== [] && array_is_list($params))) {
            throw new HttpException(400, 'A channel\'s parameters are a JSON object.', 'bad_request');
        }

        return $this->stackFor($channel)->run($request, function (Request $inner) use ($channel, $params, $now): Response {
            $session = ($this->live->sessionId)($inner);
            if ($session === '') {
                // Nothing to bind it to: one token for every visitor with no
                // session would be every such visitor's.
                throw new HttpException(401, 'A subscription is bound to a session, and there is none.', 'no_session');
            }

            $resolved = $this->runner->admit($channel, $params);
            if (!TokenClaims::carries($resolved)) {
                throw new HttpException(404, 'No channel by those parameters.', 'not_found');
            }

            $tenant = TenantClaim::current($this->orm);
            $at = $now ?? time();
            $token = new ChannelToken(new TokenClaims(
                route: $channel->name,
                params: $resolved,
                panel: $this->panels?->currentOrNull() ?? '',
                tenant: $tenant,
                build: $this->builds->current ?? 'dev',
                sessionHash: TokenClaims::hashSession($this->live->signer, $session),
                expiresAt: $at + ChannelToken::DEFAULT_TTL,
                issuedAt: $at,
                authHash: TokenClaims::hashAuth($this->live->signer, $this->auth->id(), $this->auth->epoch()),
            ), $this->topics->for($tenant, $channel->name, $resolved));

            return Response::make(
                200,
                json_encode(['token' => $token->encode($this->live->signer)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ['content-type' => 'application/json', 'cache-control' => 'private, no-store', 'vary' => 'Cookie'],
            );
        });
    }

    /**
     * Whether this request may still hear the channel a token names, in the
     * tenant and panel it names: the channel run again as a subscription
     * runs it, short of minting anything, its parameters the token's. The
     * topic it works out must be the token's, too. Fail-closed: any refusal
     * along the way is no.
     */
    public function admits(ChannelToken $token, Request $request): bool
    {
        $claims = $token->claims;
        if (!$this->registry->has($claims->route)) {
            // Renamed or removed since: a channel this server no longer has.
            return false;
        }
        $channel = $this->registry->get($claims->route);

        if (($this->entry->panelFor($channel->panels, $channel->class)->name ?? '') !== $claims->panel) {
            return false;
        }

        $run = fn (): Response => $this->stackFor($channel, authenticate: true)->run($request, function (Request $inner) use ($channel, $claims, $token): Response {
            // Checked, never applied: a token agrees with where the request
            // is, and can put it nowhere.
            $tenant = TenantClaim::current($this->orm);
            if (($this->panels?->currentOrNull() ?? '') !== $claims->panel || $tenant !== $claims->tenant) {
                throw new HttpException(404, 'The channel is in another tenant or panel than the token.', 'live_unavailable');
            }

            if (!hash_equals($claims->authHash, TokenClaims::hashAuth($this->live->signer, $this->auth->id(), $this->auth->epoch()))) {
                throw new HttpException(404, 'The subscription was made by someone else.', 'live_unavailable');
            }

            $resolved = $this->runner->admit($channel, $claims->params);
            if (!hash_equals($token->topic, $this->topics->for($tenant, $channel->name, $resolved))) {
                throw new HttpException(404, 'The token names another topic than its channel.', 'live_unavailable');
            }

            return Response::make(204);
        });

        try {
            if ($claims->panel === '') {
                $response = $run();
            } elseif ($this->panels === null) {
                return false;
            } else {
                // Entered as the subscription entered it: the trade itself is
                // in no panel, and the stack holds the session to this one.
                $response = $this->panels->within($claims->panel, $run);
            }
        } catch (HttpException|NoTenantContext) {
            return false;
        }

        return $response->status >= 200 && $response->status < 300;
    }

    /**
     * The channel's tenant, its panel and the panel's stack, then its own
     * #[Middleware]. On a trade, which no authentication stands in front
     * of, the authentication a subscription met first: a channel that is
     * not #[PublicAccess] is heard by someone signed in, or by no one.
     */
    private function stackFor(CompiledChannel $channel, bool $authenticate = false): Pipeline
    {
        return new Pipeline([
            ...($authenticate && !$channel->publicAccess ? [new RequireAuthentication($this->auth)] : []),
            ...$this->entry->stackFor($channel->tenantFrom, $this->entry->panelFor($channel->panels, $channel->class)),
            ...array_map(
                fn (string $class): Middleware => ($this->middlewareResolver)($class),
                array_values(array_diff($channel->middleware, $this->globalMiddleware)),
            ),
        ]);
    }
}
