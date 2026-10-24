<?php

declare(strict_types=1);

namespace Tinvar\Live;

use Tinvar\Http\Response;

/**
 * Where live invalidations go out, and how a client is let in to hear them.
 *
 * The transport behind live updates, kept to this so it can be replaced:
 * FrankenPHP's built-in Mercure hub by default (Mercure::builtIn), a Mercure
 * hub run as a service of its own when one machine's connections are not
 * enough or there is more than one app server (Mercure::remote) -- see ADR
 * 0030 -- and MemoryHub in tests.
 *
 * Every update is private: delivered only to a subscriber whose access
 * token names its topic. A public one would reach anyone subscribed to the
 * topic, token or not, and the topics a page may hear would be whatever its
 * client asked for.
 */
interface Hub
{
    /** Publishes one update to one topic. */
    public function publish(string $topic, string $data): void;

    /**
     * The response, carrying what lets its client subscribe to these topics
     * -- and only these -- until $expiresAt.
     *
     * @param list<string> $topics
     * @param string $subject who it is for, opaquely: a session hash
     */
    public function grant(Response $response, array $topics, int $expiresAt, string $subject): Response;

    /** The response, taking away whatever grant() gave. */
    public function revoke(Response $response): Response;

    /**
     * The same-origin URL the client opens its stream on, for these topics.
     *
     * @param list<string> $topics
     */
    public function subscribeUrl(array $topics): string;
}
