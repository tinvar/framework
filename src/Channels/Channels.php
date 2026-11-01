<?php

declare(strict_types=1);

namespace Tinvar\Channels;

use Tinvar\Data\Data;
use Tinvar\Data\Encoder;
use Tinvar\Live\Hub;
use Tinvar\Orm\Orm;
use Tinvar\Render\TenantClaim;

/**
 * Publishes messages to channels, from anywhere PHP runs: an action, a job,
 * a scheduled task.
 *
 *     $channels->publish(new OrderUpdates($order), new OrderUpdateData(...));
 *
 * **In the tenant in force, and nowhere else.** The topic is worked out
 * from the tenant claim the Orm runs as now -- never one the caller names
 * -- so a job running for one tenant publishes only to that tenant's
 * subscribers, as a subscription is let in only in the tenant its request
 * resolves to. Each model the channel names must be one this tenant reads:
 * a model of another tenant's, carried in from somewhere, is refused rather
 * than published to a topic named after it. And never inside
 * acrossTenants() or withinSubtree(), where "the tenant in force" says
 * nothing about whose rows are in hand.
 *
 * **A Data object, through the encoder**, refusing anything else -- as
 * every value leaving the server is -- and the message's declared class
 * alone, so a subscriber typed for one shape is never sent another. Checked
 * and encoded when publish() is called, so a mistake throws where it was
 * made; sent once the transaction commits (Orm::afterCommit), as an
 * invalidation is, so no subscriber hears of a write that is rolled back.
 *
 * Every update is private, as the hub's always are: delivered to a
 * subscriber whose access token names the topic, which only a trade that
 * ran the channel's checks grants. With no hub -- under php -S -- every
 * check still runs, and nothing is sent.
 */
final readonly class Channels
{
    /**
     * The largest message, encoded. An update is pushed to every subscriber
     * of its topic and held by the hub meanwhile; a channel says something
     * happened and what, and a page that needs more fetches it through its
     * own pipeline.
     */
    public const int MAX_MESSAGE_BYTES = 16_384;

    /** @param (\Closure(\Throwable, ?string): string)|null $report where a publish that failed is logged */
    public function __construct(
        private ChannelRegistry $registry,
        private ChannelTopics $topics,
        private Orm $orm,
        private ?Hub $hub,
        private Encoder $encoder = new Encoder(),
        private ?\Closure $report = null,
    ) {}

    /**
     * @throws \LogicException for a class that is no registered channel, a message of another
     *         class, a channel naming a model this tenant does not read, or a message too large
     */
    public function publish(object $channel, Data $message): void
    {
        $compiled = $this->registry->forClass($channel::class)
            ?? throw new \LogicException($channel::class . ' is not a channel the application registered.');
        if ($compiled->personal) {
            throw new \LogicException("{$compiled->where()} is personal: publish to it for one user, with publishTo().");
        }

        if (!$message instanceof $compiled->message) {
            throw new \LogicException(\sprintf(
                '%s carries %s, and was handed %s.',
                $compiled->where(),
                $compiled->message,
                $message::class,
            ));
        }

        if ($this->orm->isWidened()) {
            throw new \LogicException(
                "{$compiled->where()} was published to inside acrossTenants() or withinSubtree(). Publish in the tenant "
                . 'whose subscribers it is for, with actAs().',
            );
        }

        [$params, $models] = ChannelRunner::addressOf($compiled, $channel);

        // Read again in the tenant in force: a model this tenant does not
        // read is not this tenant's to announce.
        foreach ($models as $name => $model) {
            $found = $this->orm->find($model::class, $model->primaryKey());
            if ($found === null || $found->primaryKey() !== $model->primaryKey()) {
                throw new \LogicException("{$compiled->where()}: \${$name} is no row of the tenant in force, so it is not this tenant's to publish to.");
            }
        }

        $this->send($compiled, $this->topics->for(TenantClaim::current($this->orm), $compiled->name, $params), $message);
    }

    /**
     * Publishes $message to the personal channel named $channel for $user
     * alone (ChannelRegistry::registerPersonal()): heard only by a
     * subscription that user made, signed in, in the tenant in force. As
     * publish(): a Data object of the channel's class, after the commit,
     * never inside acrossTenants() or withinSubtree().
     *
     * @throws \LogicException for a name no personal channel has, a message of another class,
     *         a widened scope, no user, or a message too large
     */
    public function publishTo(string $user, string $channel, Data $message): void
    {
        $compiled = $this->registry->has($channel) ? $this->registry->get($channel) : null;
        if ($compiled === null || !$compiled->personal) {
            throw new \LogicException("'{$channel}' is no personal channel the application registered.");
        }
        if (!$message instanceof $compiled->message) {
            throw new \LogicException(\sprintf('%s carries %s, and was handed %s.', $compiled->where(), $compiled->message, $message::class));
        }
        if ($this->orm->isWidened()) {
            throw new \LogicException(
                "{$compiled->where()} was published to inside acrossTenants() or withinSubtree(). Publish in the tenant "
                . 'whose user it is for, with actAs().',
            );
        }

        $this->send($compiled, $this->topics->forChannel(TenantClaim::current($this->orm), $compiled, [], $user), $message);
    }

    /** Encodes $message, and sends it to $topic once the transaction commits. */
    private function send(CompiledChannel $compiled, string $topic, Data $message): void
    {
        $encoded = $this->orm->readOnly(fn (): mixed => $this->encoder->encode($message));
        $data = json_encode(
            // The topic, so the client knows which subscription this is for --
            // an update carries no topic of its own -- and the channel's name,
            // so it is never read as an invalidation.
            ['topic' => $topic, 'channel' => $compiled->name, 'message' => $encoded],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        if (\strlen($data) > self::MAX_MESSAGE_BYTES) {
            throw new \LogicException(\sprintf(
                '%s was handed a message of %d bytes, past the %d one may be. Send what happened; the page fetches the rest.',
                $compiled->where(),
                \strlen($data),
                self::MAX_MESSAGE_BYTES,
            ));
        }

        $hub = $this->hub;
        if ($hub === null) {
            return;
        }

        $this->orm->afterCommit(function () use ($hub, $topic, $data): void {
            try {
                $hub->publish($topic, $data);
            } catch (\Throwable $e) {
                // Committed, and its response owed: a message missed is the
                // cost of a hub that is down, and the log is where it is
                // noticed -- as for an invalidation (Invalidator).
                if ($this->report !== null) {
                    ($this->report)($e, null);

                    return;
                }
                error_log('[tinvar] a channel message could not be published: ' . $e->getMessage());
            }
        });
    }
}
