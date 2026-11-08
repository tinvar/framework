<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

use Tinvar\Attributes\OutboundPolicy;
use Tinvar\Outbound\OutboundFailed;
use Tinvar\Outbound\OutboundRefused;
use Tinvar\Outbound\Resolver;
use Tinvar\Outbound\SystemResolver;
use Tinvar\Outbound\Target;

/**
 * Whether a URL a tenant gives is one its webhooks may be sent to (ADR
 * 0197), asked when a subscription is made or its URL changed: the rules
 * OutboundHttp holds every delivery to, held as early as they can be.
 *
 * - The URL parses as OutboundHttp parses one: http or https, a host, no
 *   user name or password (Target).
 * - https alone, unless the application says otherwise: a delivery carries
 *   the tenant's data, and its signature proves where it came from but
 *   hides nothing.
 * - Its port is one the application's #[OutboundPolicy] allows.
 * - Its host -- a literal address, or every address its name resolves to
 *   now -- is public: the policy with `allow` closed (publicOnly()), as a
 *   delivery is sent, so no private range the application opened for its
 *   own services is a tenant's to name. A name that does not resolve is
 *   refused too: fail closed.
 *
 * Passing here is not trusted later: DNS can change, so each delivery is
 * vetted again where it is sent (PinnedHttp, `publicOnly`).
 */
final readonly class WebhookUrlCheck
{
    /** The longest URL kept. */
    public const int MAX_LENGTH = 2048;

    /**
     * @param OutboundPolicy $policy the application's: OutboundPolicy::of(Kernel::class)
     * @param Resolver $resolver how names are looked up; the system's unless given
     * @param bool $httpsOnly refuse plain http; true unless the application says otherwise
     * @param float $timeout seconds a lookup may take
     */
    public function __construct(
        private OutboundPolicy $policy = new OutboundPolicy(),
        private Resolver $resolver = new SystemResolver(),
        private bool $httpsOnly = true,
        private float $timeout = 5.0,
    ) {}

    /**
     * Why $url will not do, as words a tenant may be shown, or null when it
     * will. Which kind of address a name resolved to is not said: that is
     * the network's to know.
     */
    public function refusal(string $url): ?string
    {
        if ($url === '' || \strlen($url) > self::MAX_LENGTH) {
            return 'Give a URL of at most ' . self::MAX_LENGTH . ' characters.';
        }
        try {
            $target = Target::parse($url);
        } catch (OutboundRefused $e) {
            return match ($e->reason) {
                'scheme' => 'Give an https URL.',
                'credentials' => 'Leave the user name and password out of the URL.',
                default => 'That is not a URL webhooks can be sent to.',
            };
        }
        if ($this->httpsOnly && $target->scheme !== 'https') {
            return 'Give an https URL.';
        }

        try {
            $this->policy->publicOnly()->vet($target, $this->resolver, $this->timeout);
        } catch (OutboundRefused $e) {
            return $e->reason === 'port'
                ? "Webhooks are not sent to port {$target->port}."
                : 'Webhooks are not sent to that address: give one on the public internet.';
        } catch (OutboundFailed) {
            return "{$target->host} does not resolve: give a host that does.";
        }

        return null;
    }
}
