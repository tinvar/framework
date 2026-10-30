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
 * never a header or a body.
 *
 * The header goes only to an origin the application names as its own, in
 * `propagateTo` -- its collector, its own services -- and to nothing by
 * default. It holds two ids and whether the trace is sampled, and the trace
 * id is the request's: a third party handed it could tie the calls it
 * receives to one visitor's request. A webhook's endpoint, an image's host,
 * an OIDC provider are someone else's, and get the span on this side alone.
 * A `traceparent` the caller set is left as it is.
 */
final readonly class TracedHttp implements OutboundHttp
{
    /** @var list<string> the origins a traceparent goes to, as Target::origin() writes them */
    private array $propagateTo;

    /**
     * @param list<string> $propagateTo origins the application owns -- `https://collector.internal`,
     *        `https://api.example.com:8443` -- the only ones a call to carries its traceparent.
     *        Matched by scheme, host and port exactly; a URL's path is not part of it. None by default
     * @throws \InvalidArgumentException when one is not an origin
     */
    public function __construct(
        private OutboundHttp $inner,
        private Telemetry $telemetry,
        array $propagateTo = [],
    ) {
        $origins = [];
        foreach ($propagateTo as $origin) {
            $target = Target::parse($origin);
            if ($target->requestTarget() !== '/' && $target->requestTarget() !== '') {
                throw new \InvalidArgumentException("'{$origin}' is not an origin: name the scheme, host and port alone.");
            }
            $origins[] = $target->origin();
        }
        $this->propagateTo = $origins;
    }

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
        if (isset($request->headers['traceparent']) || !$this->owns($request)) {
            return $request;
        }
        $header = $this->telemetry->traceparent($span);
        if ($header === null) {
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

    /** Whether the request goes to an origin the application named as its own. */
    private function owns(OutboundRequest $request): bool
    {
        if ($this->propagateTo === []) {
            return false;
        }
        try {
            return \in_array(Target::parse($request->url)->origin(), $this->propagateTo, true);
        } catch (\Throwable) {
            return false;
        }
    }
}
