<?php

declare(strict_types=1);

namespace Tinvar\Outbound;

/**
 * The one way the application fetches a URL.
 *
 * tinvar-spec.md, "Outbound requests": "Every feature that fetches a URL
 * (webhooks, web push, OIDC discovery, image sources, ISO downloads) uses
 * one `OutboundHttp` client." A URL a tenant hands the application is a
 * request the application makes from inside its own network, so every one
 * goes through the same checks: PinnedHttp in production, FakeOutboundHttp
 * in tests.
 */
interface OutboundHttp
{
    /**
     * @throws OutboundRefused when the URL or its destination is refused; nothing was sent
     * @throws OutboundFailed when the request was sent, or begun, and did not complete
     */
    public function send(OutboundRequest $request): OutboundResponse;
}
