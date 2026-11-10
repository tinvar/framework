<?php

declare(strict_types=1);

namespace Tinvar\Config;

/**
 * The name of the header a webhook delivery's signature travels in
 * (ADR 0200), and of the one the new secret's goes in while a rotation
 * overlaps (ADR 0197): `Tinvar-Signature` and `Tinvar-Signature-Next`
 * unless TINVAR_WEBHOOK_SIGNATURE_HEADER names another --
 * `Celerium-Signature`, and `Celerium-Signature-Next` beside it -- so an
 * application's customers are not told which framework sends them.
 *
 *     TINVAR_WEBHOOK_SIGNATURE_HEADER=Celerium-Signature
 *
 * Built with no name, it is the configured one: so the queue worker's
 * DeliverWebhook and the WebhookEndpoint read the same name from the same
 * environment, with nothing to wire. An application may register one in
 * the container (and hand the endpoint one) to name it in code instead.
 *
 * Header names are case-insensitive, so the name is kept lowercased, as
 * OutboundRequest sends it and Request reads it. It is an HTTP token
 * (RFC 9110) of at most MAX_LENGTH characters and none of the headers the
 * transport writes or the delivery already carries; anything else is
 * refused when it is built, and by FrameworkConfig at boot. The name is
 * not a secret and changes nothing of what the MAC covers: only where a
 * receiver looks for it.
 *
 * Config's, not the webhooks': FrameworkConfig refuses a malformed one at
 * boot, and config stands below the webhooks that use it (ADR 0114), as
 * HubUrl stands below the live hub.
 */
final readonly class WebhookSignatureHeader
{
    public const string ENV = 'TINVAR_WEBHOOK_SIGNATURE_HEADER';

    /** The name unless the application chooses another. */
    public const string DEFAULT = 'Tinvar-Signature';

    /** What the rotation's header adds to the name. */
    public const string NEXT_SUFFIX = '-Next';

    /** The longest name: ample for any vendor's, short of a header line anyone would refuse. */
    public const int MAX_LENGTH = 64;

    /**
     * Headers a signature may not be sent in: those the transport writes
     * (OutboundRequest refuses them, Proxy-* among them, on every request),
     * and the body's type, which a delivery carries beside it.
     */
    private const array RESERVED = [
        'host', 'content-length', 'transfer-encoding', 'connection', 'keep-alive', 'upgrade', 'te', 'trailer',
        'expect', 'proxy-authorization', 'proxy-connection', 'content-type',
    ];

    /** The signature's header, lowercased. */
    public string $name;

    /** The new secret's header while a rotation overlaps, lowercased: the name and -next. */
    public string $next;

    /**
     * @param string|null $name the header's name, in any case; null for the configured one,
     *        TINVAR_WEBHOOK_SIGNATURE_HEADER, or Tinvar-Signature where that is unset or empty
     * @throws \InvalidArgumentException for a name that is not one (see problemWith())
     * @throws ConfigException where none is given and the framework's config is refused
     */
    public function __construct(?string $name = null)
    {
        $name ??= FrameworkConfig::load()->webhookSignature()->name;
        $problem = self::problemWith($name);
        if ($problem !== null) {
            // The name is the application's own and no secret, but a line
            // break in it would be a line of its own in a log: not repeated.
            throw new \InvalidArgumentException("That is not a webhook signature header: it {$problem}.");
        }

        $this->name = strtolower($name);
        $this->next = $this->name . strtolower(self::NEXT_SUFFIX);
    }

    /**
     * What is wrong with $name as a signature header's name, worded to
     * follow "it" -- never quoting the name -- or null when nothing is.
     */
    public static function problemWith(string $name): ?string
    {
        if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]{1,' . self::MAX_LENGTH . '}\z/', $name) !== 1) {
            return 'must be an HTTP token of 1 to ' . self::MAX_LENGTH . ' letters, digits and !#$%&\'*+.^_`|~-';
        }
        $lower = strtolower($name);
        if (\in_array($lower, self::RESERVED, true) || str_starts_with($lower, 'proxy-')) {
            return 'must not be a header the transport writes, or Content-Type';
        }

        return null;
    }
}
