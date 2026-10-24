<?php

declare(strict_types=1);

namespace Tinvar\Live;

use Tinvar\Auth\AuthContext;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\Endpoints\Limits;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Render\TokenClaims;
use Tinvar\Render\TokenError;

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

    public function __construct(
        private readonly LiveUpdates $live,
        private readonly AuthContext $auth,
        private readonly ?BuildRegistry $builds = null,
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
        $granted = [];
        $expiresAt = $now + self::GRANT_SECONDS;
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

            $refreshed[$i] = $renewed->encode($this->live->signer);
            $expiresAt = min($expiresAt, $renewed->claims->expiresAt);
            foreach (array_keys($live->topics) as $topic) {
                $granted[$topic] = true;
            }
        }

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
