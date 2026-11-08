<?php

declare(strict_types=1);

namespace Tinvar\Dev;

use Tinvar\Http\Request;

/**
 * Whether a request came from this machine, to this machine, by name.
 *
 * tinvar-spec.md, "Dev and test tooling": the dev helpers "bind to loopback
 * with a per-run token". `tinvar serve --dev` listens on loopback only, so a request
 * from anywhere else should be impossible; this is the check that does not
 * rely on that.
 *
 * The address alone is not enough. A page on any site can point a name of
 * its own at 127.0.0.1 once its visitor has loaded it -- DNS rebinding --
 * and its script is then same-origin with the dev server, from the
 * visitor's own loopback address. What it cannot change is the Host header
 * its requests carry, which still names its own domain; so a request counts
 * only when it names this machine as well.
 */
final class Loopback
{
    /** The addresses a loopback peer connects from, as the SAPI reports them. */
    public const array ADDRESSES = ['127.0.0.1', '::1', '::ffff:127.0.0.1'];

    /** A Host header that names this machine: an address or `localhost`, with any port. */
    private const string HOST = '/^(?:127\.0\.0\.1|localhost|\[::1\])(?::[0-9]{1,5})?\z/i';

    /** And a name under `.localhost` too, as dev serves a second host on (`admin.localhost`). */
    private const string NAME = '/^(?:127\.0\.0\.1|\[::1\]|(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*localhost)(?::[0-9]{1,5})?\z/i';

    public static function address(?string $address): bool
    {
        return $address !== null && \in_array(strtolower($address), self::ADDRESSES, true);
    }

    public static function host(?string $host): bool
    {
        return $host !== null && preg_match(self::HOST, $host) === 1;
    }

    /**
     * Whether a Host header names this machine by any of its names: an
     * address, `localhost`, or a name under it, which a browser resolves to
     * loopback itself (RFC 6761) -- so no page elsewhere can rebind one.
     */
    public static function name(?string $host): bool
    {
        return $host !== null && preg_match(self::NAME, $host) === 1;
    }

    /** From loopback, naming loopback; a request with no Host header names nothing, and is refused. */
    public static function request(Request $request): bool
    {
        return self::address($request->remoteAddress) && self::host($request->header('host'));
    }

    /**
     * From loopback, naming this machine by any of its names (name()): what
     * a dev tool answers -- the error overlay, the toolbar -- on every host
     * dev serves, `admin.localhost` among them.
     */
    public static function local(Request $request): bool
    {
        return self::address($request->remoteAddress) && self::name($request->header('host'));
    }
}
