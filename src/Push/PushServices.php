<?php

declare(strict_types=1);

namespace Tinvar\Push;

use Tinvar\Outbound\Destinations;
use Tinvar\Outbound\OutboundRefused;
use Tinvar\Outbound\Target;

/**
 * The push services a subscription's endpoint may be at.
 *
 * An endpoint comes from the browser, which is to say from whoever is
 * driving it: left open, a subscription is a way to have the server POST
 * to a URL of the subscriber's choosing whenever it pushes. OutboundHttp
 * keeps that off the application's own network whatever the list; the list
 * keeps it to the push services browsers use. KNOWN is Chrome's and
 * Edge's, Firefox's and Safari's; an application whose users' browsers use
 * another names it.
 */
final readonly class PushServices
{
    /** Hosts, and the hosts under them, that browsers' push services answer at. */
    public const array KNOWN = [
        'fcm.googleapis.com',
        'android.googleapis.com',
        'updates.push.services.mozilla.com',
        'push.services.mozilla.com',
        'push.apple.com',
        'notify.windows.com',
    ];

    /** @var list<string> */
    public array $hosts;

    /** @param list<string> $hosts */
    public function __construct(array $hosts = self::KNOWN)
    {
        foreach ($hosts as $host) {
            if (preg_match('/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+\z/', $host) !== 1) {
                throw new \InvalidArgumentException("'{$host}' is not a push service's host name.");
            }
        }
        $this->hosts = $hosts;
    }

    /**
     * The services as a push's request is held to them at every hop
     * (OutboundRequest::$destinations): each host and every host under it,
     * on 443 -- so a service that redirects is followed only to another.
     */
    public function destinations(): Destinations
    {
        return Destinations::hosts([...$this->hosts, ...array_map(static fn (string $host): string => "*.{$host}", $this->hosts)]);
    }

    /** Whether $endpoint is at one of the services. */
    public function allows(string $endpoint): bool
    {
        try {
            $target = Target::parse($endpoint);
        } catch (OutboundRefused) {
            return false;
        }
        if ($target->scheme !== 'https' || $target->literal !== null) {
            return false;
        }
        foreach ($this->hosts as $host) {
            if ($target->host === $host || str_ends_with($target->host, ".{$host}")) {
                return true;
            }
        }

        return false;
    }
}
