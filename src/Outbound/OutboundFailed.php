<?php

declare(strict_types=1);

namespace Tinvar\Outbound;

/**
 * A request that was allowed and did not complete: the name did not
 * resolve, the connection or TLS failed, it ran past its timeout, the
 * response was over its limit, or the connection was not to the address
 * that was vetted.
 *
 * `reason` is one of the constants, for a tenant's view of a delivery and
 * for deciding whether to try again; `status` is the response's when one
 * had begun to arrive. The message names the method, the destination's
 * origin and the transfer error in curl's generic words -- never a path or
 * query, a header, a body or the text a server sent.
 */
final class OutboundFailed extends \RuntimeException
{
    public const string DNS = 'dns';
    public const string CONNECT = 'connect';
    public const string TLS = 'tls';
    public const string TIMEOUT = 'timeout';
    public const string TOO_LARGE = 'too_large';
    public const string TOO_MANY_REDIRECTS = 'too_many_redirects';
    public const string ADDRESS_CHANGED = 'address_changed';
    public const string FAILED = 'failed';

    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?int $status = null,
        public readonly int $milliseconds = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
