<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * The external origins an action may send a browser to, with
 * ActionResult::location().
 *
 * tinvar-spec.md, "HTTP": "ActionResult::redirect(), back() and the 419
 * redirect accept same-origin URLs only. External URLs go through
 * location() with an allowlist." This is the allowlist, given to the Kernel
 * by the application; with none, location() goes nowhere.
 *
 * An origin is matched exactly -- scheme, host and port -- and only https:
 * an action's redirect is a person following it, and a plain-http hop hands
 * the URL and whatever it carries to the network. No wildcards and no
 * parent domains: `pay.example` does not let in `evil.pay.example`, whose
 * DNS someone else may control. Hosts are normalized as HostName
 * normalizes a Host header -- lowercase, no trailing dot -- so that
 * `https://Pay.Example.` and `https://pay.example:443` are the one origin
 * they are to a browser, and anything that is not a host name, an address
 * literal included, is never one.
 *
 * Failing closed throughout: a URL this cannot read the way a browser
 * would is refused, not guessed at.
 */
final readonly class ExternalOrigins
{
    /** @var array<string, true> allowed origins, as "host:port" */
    private array $allowed;

    /**
     * @param string ...$origins each `https://host`, or `https://host:port` for a port
     *        other than 443, as a browser serializes an origin
     * @throws \InvalidArgumentException for one that is not that, when the Kernel is built
     */
    public function __construct(string ...$origins)
    {
        $allowed = [];
        foreach ($origins as $origin) {
            $parsed = self::parse($origin);
            if ($parsed === null || !\in_array($parsed['rest'], ['', '/'], true)) {
                throw new \InvalidArgumentException(
                    'An external origin to redirect to is https:// and a host name, with a port only when it is '
                    . "not 443, and nothing after it; '{$origin}' is not one.",
                );
            }
            $allowed[$parsed['origin']] = true;
        }

        $this->allowed = $allowed;
    }

    /** Whether $url is an https URL on one of the allowed origins. */
    public function allows(string $url): bool
    {
        $parsed = self::parse($url);

        return $parsed !== null && isset($this->allowed[$parsed['origin']]);
    }

    /**
     * $url, when it is on one of the allowed origins.
     *
     * A refusal is an InvalidArgumentException, as Response::redirect()'s is
     * for an off-site target: the exception handler answers it with a 500
     * and logs it, and the client is sent nowhere. The message names the
     * origin it was refused for and not the URL, whose query may carry what
     * a log should not keep.
     *
     * @throws \InvalidArgumentException
     */
    public function checked(string $url): string
    {
        if ($this->allows($url)) {
            return $url;
        }

        $parsed = self::parse($url);

        throw new \InvalidArgumentException($parsed === null
            ? 'ActionResult::location() was given a URL that is not https:// on a host name, or that a browser '
                . 'could read another way. It was not followed.'
            : "ActionResult::location() was given a URL on https://{$parsed['origin']}, which the application "
                . 'has not allowlisted. Give the Kernel that origin in redirectOrigins, or redirect on this '
                . 'origin with redirect().');
    }

    /**
     * An https URL's origin, as "host:port", and what follows it; null for
     * anything else.
     *
     * @return array{origin: string, rest: string}|null
     */
    private static function parse(string $url): ?array
    {
        // Nothing a browser would read differently from the pattern below:
        // no control character or space, which a browser strips or splits
        // on, and no backslash, which it reads as a slash in an https URL --
        // so that `https://pay.example\@evil.example` is one host to a
        // browser and another to a parser that disagrees.
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return null;
        }

        // https://, then the host and an optional port -- no userinfo, which
        // is how `https://pay.example@evil.example` names evil.example --
        // then a path, a query, a fragment or nothing.
        if (preg_match('#^https://([^/?\#@:]+)(?::([0-9]{1,5}))?([/?\#].*)?\z#is', $url, $m) !== 1) {
            return null;
        }

        $host = HostName::normalize($m[1]);
        $port = ($m[2] ?? '') === '' ? 443 : (int) $m[2];
        if ($host === null || $port < 1 || $port > 65535) {
            return null;
        }

        return ['origin' => "{$host}:{$port}", 'rest' => $m[3] ?? ''];
    }
}
