<?php

declare(strict_types=1);

namespace Tinvar\Channels;

use Tinvar\Live\Topics;
use Tinvar\Render\SignedToken;
use Tinvar\Render\TokenClaims;
use Tinvar\Render\TokenError;
use Tinvar\Support\Signer;

/**
 * A subscription to one channel, signed, so its client may trade it for the
 * hub's access to that channel's topic.
 *
 * The binding every token carries (TokenClaims): the session and the user
 * with their auth epoch as keyed hashes, the tenant, the panel and the
 * build -- read by the framework when the subscription was let in, never
 * from the client. Its route is the channel's name and its params are what
 * the server resolved -- a model's primary key, not the string sent -- and
 * it names the one topic those make (ChannelTopics).
 *
 * Its own purpose, so no live token is ever read as one and no channel
 * token as a live token: a live token's topics are let in by running a
 * page, a channel token's by running its channel.
 *
 * Short-lived and refreshed as a live token is: each trade at
 * /_live/subscribe runs the channel again and hands the token back with a
 * new expiry, up to a day from the subscription.
 */
final readonly class ChannelToken
{
    public const string PURPOSE = 'channel-token';

    /** Five minutes: a trade runs well inside it, every grant at most CHANNEL_GRANT_SECONDS long. */
    public const int DEFAULT_TTL = 300;

    public const int MAX_LIFETIME = 86400;

    public function __construct(public TokenClaims $claims, public string $topic) {}

    public function encode(Signer $signer): string
    {
        return SignedToken::encode($signer, self::PURPOSE, [...$this->claims->toMap(), 'c' => $this->topic]);
    }

    /** @throws TokenError */
    public static function decode(string $token, Signer $signer, ?int $now = null): self
    {
        $decoded = SignedToken::decode($token, $signer, self::PURPOSE);
        $claims = TokenClaims::fromMap($decoded);
        $claims->assertFresh(self::DEFAULT_TTL, self::MAX_LIFETIME, $now);

        $topic = $decoded['c'] ?? null;
        if (!\is_string($topic) || preg_match(Topics::SHAPE, $topic) !== 1) {
            throw new TokenError("field 'c' is not a topic");
        }

        return new self($claims, $topic);
    }

    /** Null when the absolute lifetime has run out: the client subscribes again for another. */
    public function refreshed(?int $now = null): ?self
    {
        $claims = $this->claims->refreshed(self::DEFAULT_TTL, self::MAX_LIFETIME, $now);

        return $claims === null ? null : new self($claims, $this->topic);
    }
}
