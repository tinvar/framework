<?php

declare(strict_types=1);

namespace Tinvar\Routing;

use Tinvar\Http\HostName;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Http\TrustedProxies;

/**
 * The hosts an application serves, and the one place a request's host is
 * read (ADR 0149).
 *
 * The allowlist: the primary host, where every route that declares no
 * #[Domain] is served; the host patterns its entry points declare, with
 * the configured domains they end in written out; the aliases answered as
 * the primary host; and, when the application keeps them, its tenants'
 * custom domains, looked up. A request naming any other host is refused
 * with a 421 before anything runs for it -- not routed, not given a
 * session, not shown a page of whichever host it might have been. Fails
 * closed: a host is answered because it is listed, never because nothing
 * said it was not.
 *
 * The host is the Host header, or X-Forwarded-Host only from a pinned
 * proxy (TrustedProxies::host()), normalized as HostName normalizes every
 * host -- lowercase, no trailing dot -- with its port, if it names one,
 * held to the port configured. An address is no host name, and is answered
 * only when it is listed as an alias. A request that names no host at all
 * -- which HTTP/1.1 forbids, and a server adapter or a test may still
 * build -- is the primary host's: it can reach nothing a request naming
 * the primary host could not.
 *
 * Configured domains are named, `root` first, so the code says
 * `admin.{root}` and the environment says what `root` is: `localhost` in
 * dev, `shop.example.com` in production. They are host names alone, never
 * patterns, so no environment can give a route a parameter its code does
 * not bind.
 */
final readonly class Domains
{
    /** @var array<string, string> name => host */
    public array $roots;

    public HostPattern $primary;

    /** @var list<string> hosts answered as the primary host */
    public array $aliases;

    /** @var list<HostPattern> the patterns entry points declare, most specific first */
    public array $patterns;

    public ?HostPattern $customAs;

    /**
     * @param array<string, string> $roots the configured domains, by name: `['root' => 'shop.example.com']`
     * @param string $primary the pattern of the host a route declaring no #[Domain] is served on
     * @param string $scheme how URLs to another host are written: https, or http when every
     *        host is localhost or below it, which never leaves the machine
     * @param int|null $port the one port a request's Host may name; null for the scheme's default
     * @param list<string> $aliases hosts -- names, or addresses such as 127.0.0.1 -- answered as the
     *        primary host, and never written into a URL
     * @param TrustedProxies|null $proxies the proxies whose X-Forwarded-Host is believed
     * @param (\Closure(string): ?array<string, string>)|null $custom a tenant's custom domain: the
     *        parameters a normalized host is served with, as $customAs, or null for none. Verified,
     *        active domains only (CustomDomainVerification)
     * @param string|null $customAs the pattern a custom domain is served as: `{tenant}.{root}`, say
     * @param list<HostPattern|string> $patterns the patterns entry points declare; see serving()
     */
    public function __construct(
        array $roots,
        string $primary = '{root}',
        public string $scheme = 'https',
        public ?int $port = null,
        array $aliases = [],
        public ?TrustedProxies $proxies = null,
        private ?\Closure $custom = null,
        ?string $customAs = null,
        array $patterns = [],
    ) {
        $normalized = [];
        foreach ($roots as $name => $host) {
            if (!\is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*\z/', $name) !== 1) {
                throw new \InvalidArgumentException('A configured domain is named by an identifier, as root or admin.');
            }
            if (HostName::normalize($host) !== strtolower(rtrim($host, '.')) || HostName::normalize($host) === null) {
                throw new \InvalidArgumentException(
                    "The configured domain '{$name}' is not a host name: a name, with no port, scheme or address.",
                );
            }
            $normalized[$name] = (string) HostName::normalize($host);
        }
        $this->roots = $normalized;

        if ($scheme !== 'https' && $scheme !== 'http') {
            throw new \InvalidArgumentException('Domains write URLs as https, or as http on localhost.');
        }
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new \InvalidArgumentException('A port is 1 to 65535.');
        }

        $this->primary = self::compile($primary, 'The primary host');
        if ($this->primary->parameters !== []) {
            throw new \InvalidArgumentException("The primary host '{$primary}' takes parameters; it is one host.");
        }
        $this->primary->resolved($this->roots);

        $listed = [];
        foreach ($aliases as $alias) {
            $host = self::aliasOf($alias)
                ?? throw new \InvalidArgumentException("The alias '{$alias}' is neither a host name nor an address.");
            $listed[] = $host;
        }
        $this->aliases = $listed;

        if (($custom === null) !== ($customAs === null)) {
            throw new \InvalidArgumentException('A custom-domain lookup is given with the pattern its domains are served as, and the other way round.');
        }
        $this->customAs = $customAs === null ? null : self::compile($customAs, 'The custom domains\' pattern');

        $compiled = [];
        $seen = [implode('.', $this->primary->resolved($this->roots)) => $this->primary->pattern];
        foreach ([...$patterns, ...($this->customAs === null ? [] : [$this->customAs])] as $pattern) {
            $pattern = $pattern instanceof HostPattern ? $pattern : self::compile($pattern, 'A host pattern');
            $shape = implode('.', $pattern->resolved($this->roots));
            $other = $seen[$shape] ?? null;
            if ($other !== null && $other !== $pattern->pattern) {
                throw new \InvalidArgumentException(
                    $other === $this->primary->pattern
                        ? "#[Domain('{$pattern->pattern}')] is the primary host, {$other}; a route on the primary host declares no #[Domain]."
                        : "The host patterns '{$other}' and '{$pattern->pattern}' match the same hosts; write one of them.",
                );
            }
            if ($other === null) {
                $seen[$shape] = $pattern->pattern;
                $compiled[] = $pattern;
            }
        }
        usort($compiled, fn (HostPattern $a, HostPattern $b): int => $a->beats($b, $this->roots));
        $this->patterns = $compiled;

        if ($scheme === 'http') {
            foreach ([$this->primary, ...$compiled] as $pattern) {
                $resolved = $pattern->resolved($this->roots);
                if ($resolved[0] !== 'localhost') {
                    throw new \InvalidArgumentException(
                        "Domains write http only for hosts that never leave the machine, and '{$pattern->pattern}' is "
                        . 'not localhost or below it.',
                    );
                }
            }
        }
    }

    /**
     * Domains read from an origin, as an application's public address is
     * configured: `https://shop.example.com` is the domain `root` on https,
     * `http://localhost:8080` is `localhost` on port 8080.
     *
     * @param array<string, string> $roots further configured domains, by name
     * @param list<string> $aliases
     */
    public static function fromUrl(string $url, array $roots = [], array $aliases = [], ?TrustedProxies $proxies = null): self
    {
        if (preg_match('#^(?:(https?)://)?([^/:?\#]+)(?::(\d{1,5}))?/?\z#i', trim($url), $m) !== 1) {
            throw new \InvalidArgumentException('An application\'s domain is a host, with a scheme and a port if it needs them.');
        }
        $host = strtolower($m[2]);
        $local = $host === 'localhost' || str_ends_with($host, '.localhost');
        $scheme = $m[1] !== '' ? strtolower($m[1]) : ($local ? 'http' : 'https');
        $port = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;
        if ($port === ($scheme === 'https' ? 443 : 80)) {
            $port = null;
        }

        return new self(['root' => $host, ...$roots], scheme: $scheme, port: $port, aliases: $aliases, proxies: $proxies);
    }

    /**
     * The same domains, serving the host patterns the application's entry
     * points declare -- which the Kernel collects from its registries -- each
     * checked to resolve, and no two to match the same hosts.
     *
     * @param list<HostPattern|string> $patterns
     * @throws \InvalidArgumentException naming a pattern that names no configured
     *         domain, or two that match one host
     */
    public function serving(array $patterns): self
    {
        try {
            return new self(
                $this->roots,
                $this->primary->pattern,
                $this->scheme,
                $this->port,
                $this->aliases,
                $this->proxies,
                $this->custom,
                $this->customAs?->pattern,
                [...$this->patterns, ...$patterns],
            );
        } catch (\LogicException $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
    }

    /**
     * The host $request names, normalized and held to the port configured;
     * '' when it names none, and null when what it names is not a host this
     * application could serve -- malformed, another port, an address that
     * is not an alias.
     */
    public function hostOf(Request $request): ?string
    {
        $raw = $this->proxies === null ? $request->header('host') : $this->proxies->host($request);
        if ($raw === null || trim($raw) === '') {
            return '';
        }
        $raw = strtolower(trim($raw));

        $port = null;
        if (preg_match('/^(.*):(\d{1,5})\z/', $raw, $m) === 1 && !str_ends_with($m[1], ':')) {
            [$raw, $port] = [$m[1], (int) $m[2]];
        }
        $default = $this->scheme === 'https' ? 443 : 80;
        if ($port !== null && $port !== ($this->port ?? $default)) {
            return null;
        }

        $alias = self::aliasOf($raw);
        if ($alias !== null && \in_array($alias, $this->aliases, true)) {
            return $alias;
        }

        return HostName::normalize($raw);
    }

    /** The match for a normalized host, or null for one this application does not serve. */
    public function match(string $host): ?HostMatch
    {
        if ($host === '' || \in_array($host, $this->aliases, true)) {
            return new HostMatch(null, $host);
        }
        if ($this->primary->match($host, $this->roots) !== null) {
            return new HostMatch(null, $host);
        }
        foreach ($this->patterns as $pattern) {
            $parameters = $pattern->match($host, $this->roots);
            if ($parameters !== null) {
                return new HostMatch($pattern->pattern, $host, $parameters);
            }
        }

        if ($this->custom !== null && $this->customAs !== null && filter_var($host, FILTER_VALIDATE_IP) === false) {
            $parameters = ($this->custom)($host);
            if ($parameters !== null) {
                if (array_keys($parameters) != $this->customAs->parameters) {
                    // A lookup answering for parameters the pattern does not
                    // take answered the wrong question: no host at all.
                    return null;
                }

                return new HostMatch($this->customAs->pattern, $host, array_map('strval', $parameters));
            }
        }

        return null;
    }

    /**
     * The host $request was sent to, as a match: carried on the request when
     * the Kernel has resolved it, resolved here otherwise.
     *
     * @throws HttpException 421 for a host this application does not serve
     */
    public function resolve(Request $request): HostMatch
    {
        $carried = HostMatch::of($request);
        if ($carried !== null) {
            return $carried;
        }

        $host = $this->hostOf($request);
        $match = $host === null ? null : $this->match($host);

        return $match ?? throw new HttpException(
            421,
            'The request names a host this application does not serve.',
            'misdirected_request',
        );
    }

    /**
     * The origin of the host a route declaring $pattern (null: none) is
     * served on, for these parameters.
     *
     * @param array<string, string|int> $parameters
     */
    public function origin(?string $pattern, array $parameters = []): string
    {
        $host = ($pattern === null ? $this->primary : self::compile($pattern, 'A host pattern'))->host($parameters, $this->roots);

        return $this->scheme . '://' . $host . ($this->port === null ? '' : ':' . $this->port);
    }

    /**
     * Whether $url is on one of these hosts, as the application writes its
     * URLs -- this scheme, this port, a host it serves by name -- for an
     * action to redirect to one of its own hosts as it redirects on its
     * own origin (ExternalOrigins). Nothing a browser would read another
     * way: no userinfo, no control character, no backslash.
     */
    public function owns(string $url): bool
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1
            || preg_match('#^(https?)://([^/?\#@:]+)(?::([0-9]{1,5}))?(?:[/?\#].*)?\z#is', $url, $m) !== 1) {
            return false;
        }
        $default = $this->scheme === 'https' ? 443 : 80;
        $port = ($m[3] ?? '') === '' ? $default : (int) $m[3];
        $host = HostName::normalize($m[2]);
        if (strtolower($m[1]) !== $this->scheme || $port !== ($this->port ?? $default) || $host === null || \in_array($host, $this->aliases, true)) {
            return false;
        }

        return $this->match($host) !== null;
    }

    /**
     * What the client's route() builds an absolute URL with: the scheme, the
     * port, the configured domains and the primary host's pattern. All of it
     * is in every URL the application hands out already; none is a secret.
     *
     * @return array{scheme: string, port: string, roots: array<string, string>, primary: string}
     */
    public function forClient(): array
    {
        return [
            'scheme' => $this->scheme,
            'port' => $this->port === null ? '' : (string) $this->port,
            'roots' => $this->roots,
            'primary' => $this->primary->pattern,
        ];
    }

    /**
     * Every host this application serves by name -- the primary host, each
     * pattern with its configured domain written out, `{}` for a parameter's
     * label -- for the dev server and the Caddyfile to answer, and for
     * `tinvar check` to show.
     *
     * @return list<string>
     */
    public function hosts(): array
    {
        return array_map(
            fn (HostPattern $p): string => implode('.', array_reverse($p->resolved($this->roots))),
            [$this->primary, ...$this->patterns],
        );
    }

    private static function compile(string $pattern, string $what): HostPattern
    {
        try {
            return HostPattern::compile($pattern);
        } catch (HostPatternRefused $e) {
            throw new \InvalidArgumentException("{$what}: {$e->getMessage()}", 0, $e);
        }
    }

    /** A host name, normalized, or an address, as written once; null for neither. */
    private static function aliasOf(string $alias): ?string
    {
        $alias = strtolower(trim($alias));
        if (str_starts_with($alias, '[') && str_ends_with($alias, ']')) {
            $alias = substr($alias, 1, -1);
        }
        if (filter_var($alias, FILTER_VALIDATE_IP) !== false) {
            return (string) inet_ntop((string) inet_pton($alias));
        }

        return HostName::normalize($alias);
    }
}
