<?php

declare(strict_types=1);

namespace Tinvar\Channels;

use Tinvar\Support\Signer;

/**
 * The hub topic a channel is published and subscribed under.
 *
 * Worked out by the server alone, from what it resolved: the tenant claim
 * the subscription or the publish ran in, the channel's name, and each of
 * its parameters as the server holds it -- a model's primary key, read off
 * the row the binder found, never the string the client sent. A client
 * names a channel and its parameters and is handed a token; it never names
 * a topic, so it has none to forge, and none to widen with a wildcard: a
 * topic is a keyed hash, and the hub is asked for exactly the ones a token
 * names (ADR 0069).
 *
 * Keyed apart from the tags' topics (Topics): its own purpose, so no tag
 * any application could write hashes to a channel's topic, or a channel's
 * to a tag's. The same shape, so the hub, the grant and the client handle
 * both alike.
 */
final readonly class ChannelTopics
{
    public const string PURPOSE = 'live-channel-topic';

    /**
     * The key a personal channel's user is worked into its topic under:
     * no parameter can be named so, since a pattern's parameters are
     * identifiers, so no channel's parameters can spell a personal one's.
     */
    public const string USER = '@user';

    public function __construct(private Signer $signer) {}

    public static function fromKey(#[\SensitiveParameter] string $appKey): self
    {
        return new self(new Signer($appKey, self::PURPOSE));
    }

    /**
     * The topic of a channel in a tenant.
     *
     * @param string $tenant the tenant claim (TenantClaim), as tokens carry it
     * @param array<string, string> $params each parameter as the server holds it,
     *        in the pattern's order
     */
    public function for(string $tenant, string $channel, array $params): string
    {
        // A list, JSON-encoded: no value can run into the next, as a
        // separator byte inside a scalar parameter could make it.
        $subject = json_encode([$tenant, $channel, array_keys($params), array_values($params)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return 'tinvar:' . Signer::encode(substr($this->signer->sign($subject), 0, 16));
    }

    /**
     * The topic of $channel's subscription or publish, as the server holds
     * it: a personal channel's with its user worked in beside the
     * parameters -- the signed-in user's when a client subscribes, the one
     * named when PHP publishes -- and any other channel's with its
     * parameters alone.
     *
     * @param array<string, string> $params
     * @throws \LogicException for a personal channel with no user to work in
     */
    public function forChannel(string $tenant, CompiledChannel $channel, array $params, ?string $user): string
    {
        if (!$channel->personal) {
            return $this->for($tenant, $channel->name, $params);
        }
        if ($user === null || $user === '') {
            throw new \LogicException("{$channel->where()} is personal, and its topic is a user's: name the user it is for.");
        }

        return $this->for($tenant, $channel->name, [...$params, self::USER => $user]);
    }
}
