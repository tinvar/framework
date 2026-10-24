<?php

declare(strict_types=1);

namespace Tinvar\Outbound;

/**
 * A request OutboundHttp would not send: a URL it does not accept, or a
 * destination its policy refuses. Nothing was sent, and nothing will be on
 * a retry unless the URL or its DNS changes.
 *
 * `reason` is a code a tenant may be shown -- `private`, `metadata`,
 * `scheme`, `credentials` -- and the message is for the operator's log. It
 * names the destination by its origin alone, since a path or a query can
 * carry a token, and never the address a name resolved to: a tenant who
 * read it would learn what an internal name points at.
 */
final class OutboundRefused extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
