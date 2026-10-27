<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * A channel: a stream of messages a client may subscribe to, and PHP
 * publishes to.
 *
 *     #[Channel('orders.{order}', message: OrderUpdateData::class), Authorize('view', 'order')]
 *     final readonly class OrderUpdates
 *     {
 *         public function __construct(public Order $order) {}
 *     }
 *
 * The class is the channel's address: one instance names one topic, so
 * PHP publishes with `$channels->publish(new OrderUpdates($order), $data)`
 * and cannot name a topic without the model it is about. Its constructor's
 * parameters are the pattern's, each a model -- resolved scoped to the
 * tenant, and named by an #[Authorize] -- or a string, int, bool or backed
 * enum. A client subscribes by the channel's name and those parameters,
 * and is let in through the pipeline every entry point runs: the tenant,
 * the panel and its stack, the class's #[Middleware], the models resolved,
 * every #[Authorize] (see Tinvar\Channels and ADR 0069).
 *
 * What it carries is one Data class, sent through the encoder, which
 * refuses anything else.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Channel
{
    /**
     * @param string $pattern dot-separated: identifiers, and `{parameter}` for each
     *        constructor parameter, as in 'orders.{order}'
     * @param class-string<\Tinvar\Data\Data> $message the Data class every message is
     * @param string|null $name what a client subscribes to it by; the pattern's
     *        identifiers, dotted, otherwise -- 'orders' for 'orders.{order}'
     */
    public function __construct(
        public string $pattern,
        public string $message,
        public ?string $name = null,
    ) {}
}
