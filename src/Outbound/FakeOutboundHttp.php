<?php

declare(strict_types=1);

namespace Tinvar\Outbound;

use Tinvar\Attributes\OutboundPolicy;

/**
 * OutboundHttp for tests: records what was sent and answers from the test,
 * connecting to nothing.
 *
 *     $http = new FakeOutboundHttp(fn (OutboundRequest $r) => new OutboundResponse(202));
 *     // ... the code under test sends ...
 *     expect($http->sent)->toHaveCount(1);
 *
 * It refuses what PinnedHttp refuses before connecting: a URL it would not
 * accept, a literal address the policy refuses, and -- given a resolver --
 * a name resolving to one. Without a resolver a name is taken as it is,
 * since a test's hosts need not exist. A response closure may throw
 * OutboundFailed to stand for a timeout or a refused connection.
 */
final class FakeOutboundHttp implements OutboundHttp
{
    /** @var list<OutboundRequest> */
    public array $sent = [];

    /** @param (\Closure(OutboundRequest): OutboundResponse)|null $respond a 200 with no body unless given */
    public function __construct(
        private readonly ?\Closure $respond = null,
        private readonly OutboundPolicy $policy = new OutboundPolicy(),
        private readonly ?Resolver $resolver = null,
    ) {}

    public function send(OutboundRequest $request): OutboundResponse
    {
        $target = Target::parse($request->url);
        if ($target->literal !== null || $this->resolver !== null) {
            $this->policy->vet($target, $this->resolver ?? new StaticResolver([]), $request->timeout ?? PinnedHttp::DEFAULT_TIMEOUT);
        }

        $this->sent[] = $request;

        return $this->respond === null ? new OutboundResponse(200) : ($this->respond)($request);
    }
}
