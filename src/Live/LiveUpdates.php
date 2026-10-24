<?php

declare(strict_types=1);

namespace Tinvar\Live;

use Tinvar\Http\Request;
use Tinvar\Orm\Orm;
use Tinvar\Support\Signer;

/**
 * What an application supplies for live updates: the hub, and the keys and
 * session seam the tokens need. Handed to the Kernel, which mints a live
 * token for every page it renders (Kernel::LIVE_MINTER), mounts
 * /_live/subscribe, and publishes what actions invalidate.
 *
 * tinvar-spec.md: "When an action revalidates a tag, the client re-fetches
 * any mounted fragment that read it, not the whole page." That sentence
 * covers the client that ran the action. This carries it to every other
 * client holding a page or a fragment that read the tag.
 */
final readonly class LiveUpdates
{
    /**
     * @param Signer $signer signs live tokens, under LiveToken::PURPOSE
     * @param \Closure(Request): string $sessionId the session a token is bound to, as
     *        FragmentSupport's; '' for none, and none is minted or honoured
     */
    public function __construct(
        public Signer $signer,
        public Topics $topics,
        public Hub $hub,
        public \Closure $sessionId,
    ) {}

    /** @param \Closure(Request): string $sessionId */
    public static function fromKey(#[\SensitiveParameter] string $appKey, Hub $hub, \Closure $sessionId): self
    {
        return new self(
            new Signer($appKey, LiveToken::PURPOSE),
            new Topics(new Signer($appKey, Topics::PURPOSE)),
            $hub,
            $sessionId,
        );
    }

    /** @param (\Closure(\Throwable, ?string): string)|null $report */
    public function invalidator(Orm $orm, ?\Closure $report = null): Invalidator
    {
        return new Invalidator($this->hub, $this->topics, $orm, $report);
    }
}
