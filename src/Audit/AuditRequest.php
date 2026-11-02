<?php

declare(strict_types=1);

namespace Tinvar\Audit;

/**
 * Where a change was asked for, when a request asked for it (ADR 0153):
 * the host it was sent to, the client's address as the throttles read it
 * -- through the pinned proxies, never a header anyone else sent
 * (TrustedProxies, ADR 0134) -- and the request's id, which its log lines
 * carry too.
 */
final readonly class AuditRequest
{
    public function __construct(
        public ?string $host,
        public ?string $ip,
        public ?string $requestId,
    ) {}
}
