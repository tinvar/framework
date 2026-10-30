<?php

declare(strict_types=1);

namespace Tinvar\Outbound;

use Tinvar\Observability\Span;
use Tinvar\Observability\Telemetry;

/**
 * An OutboundHttp whose every call is a client span of the trace in hand,
 * and carries that span to the other side as a W3C `traceparent`: so a
 * collector that hears from both ends joins the call to the request or job
 * that made it (ADR 0112).
 *
 * Around the client, not inside it: the client's checks -- the policy, the
 * pinned address, the redirect rules -- are the same with or without it,
 * and a hop to another origin drops the header with every other the caller
 * set, as PinnedHttp drops them.
 *
 * What the span says is the method, the scheme, the host and port, and the
 * status: never the path or the query, which carry ids and tokens, and
 * never a header or a body. What the header says is two ids and whether
 * the trace is sampled -- the request's id, which its own response already
 * carries as X-Request-Id -- and nothing the trace holds. A destination
 * that should not learn even that is sent through a client without this,
 * or this with `propagate` off. A `traceparent` the caller set is left as
 * it is.
 */
final readonly class TracedHttp implements OutboundHttp
{
    public function __construct(
        private OutboundHttp $inner,
        private Telemetry $telemetry,
        private bool $propagate = true,
    ) {}

    public function send(OutboundRequest $request): OutboundResponse
    {
        $attributes = ['http.request.method' => $request->method];
        try {
            $target = Target::parse($request->url);
            $attributes += ['url.scheme' => $target->scheme, 'server.address' => $target->host, 'server.port' => $target->port];
        } catch (\Throwable) {
            // The client refuses it, and says why; the span is the method's alone.
        }

        return $this->telemetry->span($request->method, $attributes, function (?Span $span) use ($request): OutboundResponse {
            $response = $this->inner->send($this->carrying($request, $span));
            $span?->set('http.response.status_code', $response->status);
            if ($response->status >= 500) {
                $span?->fail((string) $response->status);
            }

            return $response;
        }, Span::KIND_CLIENT);
    }

    /** $request with the span's `traceparent` added, where there is one to add. */
    private function carrying(OutboundRequest $request, ?Span $span): OutboundRequest
    {
        $header = $this->propagate ? $this->telemetry->traceparent($span) : null;
        if ($header === null || isset($request->headers['traceparent'])) {
            return $request;
        }

        return new OutboundRequest(
            $request->method,
            $request->url,
            [...$request->headers, 'traceparent' => $header],
            $request->body,
            $request->timeout,
            $request->maxResponseBytes,
            $request->readBody,
            $request->maxRedirects,
            $request->sink,
        );
    }
}
