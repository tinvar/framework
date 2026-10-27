<?php

declare(strict_types=1);

namespace Tinvar\Live;

use Tinvar\Render\TokenClaims;
use Tinvar\Support\Signer;

/**
 * Mints the live token for one render, once it has run.
 *
 * Like RouteMinter and FragmentMinter, this exists so the binding is the
 * FRAMEWORK's: route and params from the chain that was matched, session,
 * user, auth epoch, tenant, panel and build from the request being served.
 * The application hands over only what the render read, and the minter
 * turns that into topics.
 *
 * After the render, not before as the route token is: what a page read is
 * known only once its queries have run. So a document carries its token at
 * its end, and a payload in a header (see
 * RequestAttributes::LIVE_MINTER).
 */
final readonly class LiveMinter
{
    /**
     * @param array<string, string> $params
     * @param TagsRead|null $loaders what the route's loaders read, by segment;
     *        none for a fragment, whose loaders do not run
     */
    public function __construct(
        private Signer $signer,
        private Topics $topics,
        private string $route,
        private array $params,
        private string $panel,
        private string $tenant,
        private string $build,
        // Sensitive: the refusal below throws, and its trace would otherwise
        // carry the session id.
        #[\SensitiveParameter]
        private string $sessionId,
        private ?string $user = null,
        private string $authEpoch = '',
        private ?TagsRead $loaders = null,
        private int $ttl = LiveToken::DEFAULT_TTL,
    ) {
        if ($this->sessionId === '') {
            // One constant hash would make every visitor's token every
            // other visitor's.
            throw new \InvalidArgumentException('A session is required before live tokens can be minted.');
        }

        if ($ttl < 1 || $ttl > LiveToken::DEFAULT_TTL) {
            throw new \InvalidArgumentException('A live token TTL must be between 1 second and ' . LiveToken::DEFAULT_TTL . '.');
        }
    }

    /**
     * The token for a render that read these tags, or null when it read
     * none -- there is nothing to listen for -- or more than one token may
     * name. Past the cap no token at all, rather than one for some of them:
     * a page told of some changes and not others shows a mix of fresh and
     * stale with nothing to say which.
     *
     * @param list<string> $read namespaced tags, as RenderResult::tagsRead lists them
     */
    public function mint(array $read): ?string
    {
        /** @var array<string, array<string, true>> $bySegment */
        $bySegment = [];
        foreach ($this->loaders?->all() ?? [] as $tag => $segments) {
            foreach ($segments as $segment) {
                $bySegment[$tag][$segment] = true;
            }
        }
        foreach ($read as $tag) {
            $bySegment[$tag][TagsRead::WHOLE] = true;
        }

        $topics = [];
        foreach ($bySegment as $tag => $segments) {
            $topic = $this->topics->of($tag);
            $topics[$topic] = [...($topics[$topic] ?? []), ...array_keys($segments)];
        }

        if ($topics === [] || \count($topics) > LiveToken::MAX_TOPICS || !TokenClaims::carries($this->params)) {
            return null;
        }

        // Read by the render as a whole, a topic is the whole page's: no
        // partial reload of fewer segments would show what changed.
        foreach ($topics as $topic => $segments) {
            $segments = array_values(array_unique(array_map(strval(...), $segments)));
            sort($segments);
            $topics[$topic] = \in_array(TagsRead::WHOLE, $segments, true) ? [TagsRead::WHOLE] : $segments;
        }
        ksort($topics);

        $now = time();

        return new LiveToken(new TokenClaims(
            route: $this->route,
            params: $this->params,
            panel: $this->panel,
            tenant: $this->tenant,
            build: $this->build,
            sessionHash: TokenClaims::hashSession($this->signer, $this->sessionId),
            expiresAt: $now + $this->ttl,
            issuedAt: $now,
            authHash: TokenClaims::hashAuth($this->signer, $this->user, $this->authEpoch),
        ), $topics)->encode($this->signer);
    }
}
