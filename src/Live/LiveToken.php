<?php

declare(strict_types=1);

namespace Tinvar\Live;

use Tinvar\Render\SignedToken;
use Tinvar\Render\TokenClaims;
use Tinvar\Render\TokenError;
use Tinvar\Support\Signer;

/**
 * What a render read, signed, so its client may listen for changes to it.
 *
 * tinvar-spec.md: "The panel and effective scope are part of query cache
 * keys, fragment and route tokens, live subscription tokens and service
 * context tokens." The binding is the one every token carries (TokenClaims):
 * the session and the signed-in user with their auth epoch as keyed hashes,
 * the tenant, the panel and the build -- read when the render ran, by the
 * framework, never from anything the client or the renderer said.
 *
 * What it adds is the topics: those of the tags the render actually read --
 * its loaders' (TagsRead) and its queries' (RenderResult::tagsRead) -- and
 * nothing else, so a client can only ever listen to what its page depended
 * on. Each topic carries the segments that read it, so the client knows
 * whether a partial reload of them will do; `*` is the render as a whole.
 *
 * It is not the hub's access token. The hub cannot check a session or a
 * user, so a client trades its live tokens at /_live/subscribe, where PHP
 * checks them against the session asking, for an access token the hub
 * does check, scoped to their topics (see SubscribeEndpoint).
 *
 * Short-lived and refreshed: each trade hands every token back with a new
 * expiry, up to the same absolute lifetime a route token has.
 */
final readonly class LiveToken
{
    public const string PURPOSE = 'live-token';

    /** Long enough to outlast one hub grant, so a token is always traded before it runs out. */
    public const int DEFAULT_TTL = 600;

    public const int MAX_LIFETIME = 86400;

    /**
     * Topics one token may name, and one grant may carry. The hub's access
     * token carries every topic a client listens to, in a cookie, and a
     * browser keeps none whose name and value pass 4,096 bytes: each topic
     * costs 56 of them, and 64 came to 4,122 -- the cookie dropped, the
     * stream refused, and the client trading again for good. 56 leave room
     * for an audience of Mercure::MAX_AUDIENCE_BYTES.
     */
    public const int MAX_TOPICS = 56;

    /** A segment: a layout's key, `page`, or WHOLE. */
    private const string SEGMENT = '/^[A-Za-z0-9_.*-]{1,64}\z/';

    /** @param array<string, list<string>> $topics topic => the segments that read it */
    public function __construct(public TokenClaims $claims, public array $topics) {}

    public function encode(Signer $signer): string
    {
        return SignedToken::encode($signer, self::PURPOSE, [...$this->claims->toMap(), 't' => $this->topics]);
    }

    /** @throws TokenError */
    public static function decode(string $token, Signer $signer, ?int $now = null): self
    {
        $decoded = SignedToken::decode($token, $signer, self::PURPOSE);
        $claims = TokenClaims::fromMap($decoded);
        $claims->assertFresh(self::DEFAULT_TTL, self::MAX_LIFETIME, $now);

        return new self($claims, self::topics($decoded['t'] ?? null));
    }

    /** Null when the absolute lifetime has run out: the page must be rendered again for another. */
    public function refreshed(?int $now = null): ?self
    {
        $claims = $this->claims->refreshed(self::DEFAULT_TTL, self::MAX_LIFETIME, $now);

        return $claims === null ? null : new self($claims, $this->topics);
    }

    /**
     * Validated, as every claim is: the signature says this server wrote
     * it, and a reader that trusted its shape anyway would be one change of
     * minting away from granting whatever a bug put there.
     *
     * @return array<string, list<string>>
     * @throws TokenError
     */
    private static function topics(mixed $value): array
    {
        if (!\is_array($value) || $value === [] || array_is_list($value) || \count($value) > self::MAX_TOPICS) {
            throw new TokenError("field 't' is not a map of topics");
        }

        $topics = [];
        foreach ($value as $topic => $segments) {
            if (!\is_string($topic) || preg_match(Topics::SHAPE, $topic) !== 1) {
                throw new TokenError("field 't' names something that is not a topic");
            }
            if (!\is_array($segments) || $segments === [] || !array_is_list($segments)) {
                throw new TokenError("topic '{$topic}' names no segments");
            }
            foreach ($segments as $segment) {
                if (!\is_string($segment) || preg_match(self::SEGMENT, $segment) !== 1) {
                    throw new TokenError("topic '{$topic}' names a segment that is not one");
                }
            }
            $topics[$topic] = $segments;
        }

        return $topics;
    }
}
