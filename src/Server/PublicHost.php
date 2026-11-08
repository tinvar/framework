<?php

declare(strict_types=1);

namespace Tinvar\Server;

use Tinvar\Http\HostName;

/**
 * The public names a dev server may also be reached by: a tunnel's (ADR
 * 0190, 0195).
 *
 * Dev listens on loopback, and answers only a request naming this machine,
 * so no page that points a name of its own at 127.0.0.1 can read it (DNS
 * rebinding, Caddyfile.dev). A tunnel -- cloudflared, run here -- connects
 * from loopback too, but names the host its visitor asked for. Those names
 * are let in, exactly: names of the tunnel's, which no page elsewhere can
 * point at this machine. An app served on several hosts -- `dash.`, `api.`
 * and `admin.` of one domain -- names each.
 *
 * Let in, it is still dev: Vite serves the app's source, its PHP included,
 * and every page's props. (Not its raw errors, which go to loopback's names
 * alone.) Shared, the names answer only a visitor holding this run's share
 * token, given once in the address serve prints and kept in a cookie from
 * then on; the token is fresh each run and dies with it. A quick tunnel's
 * names are always shared: anyone given the address would otherwise reach
 * the source. Names on the developer's own domain need not be, where they
 * keep it to whom they choose (Cloudflare Access, ADR 0195).
 */
final readonly class PublicHost
{
    /** What FrankenPHP is told the names in, space-separated; the dev Caddyfile reads it. */
    public const string HOST_ENV = 'TINVAR_DEV_PUBLIC_HOST';

    /** The names that answer only with the token, space-separated: all of them, where shared. */
    public const string SHARED_ENV = 'TINVAR_DEV_SHARED_HOST';

    /** What FrankenPHP is told the share token in. */
    public const string TOKEN_ENV = 'TINVAR_DEV_SHARE_TOKEN';

    /** The query parameter that carries the token on a visitor's first request. */
    public const string QUERY = 'tinvar-share';

    /** More names than an app has hosts for: a list this long is a mistake. */
    public const int MAX_HOSTS = 16;

    /**
     * @param non-empty-list<string> $hosts
     */
    private function __construct(
        public array $hosts,
        public ?string $token,
    ) {}

    /**
     * The names given, comma-separated, each held to being one a tunnel
     * would serve; with a fresh token where they are shared.
     */
    public static function of(string $given, bool $shared = true, ?string $token = null): self
    {
        $hosts = [];
        foreach (explode(',', $given) as $name) {
            $host = self::host($name);
            if (!\in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }
        if (\count($hosts) > self::MAX_HOSTS) {
            throw new ServeException('--public-host names at most ' . self::MAX_HOSTS . ' hosts; got ' . \count($hosts) . '.');
        }

        if (!$shared) {
            // A quick tunnel's name is anyone's who is handed the address,
            // and nothing else stands in front of the app's source: it is
            // shared, always, whoever started the tunnel.
            foreach ($hosts as $host) {
                if (str_ends_with($host, '.trycloudflare.com')) {
                    throw new ServeException("{$host} is a Cloudflare quick tunnel's, which is held to the share token: pass --share.");
                }
            }

            return new self($hosts, null);
        }
        $token ??= bin2hex(random_bytes(16));
        if (preg_match('/^[0-9a-f]{32}\z/', $token) !== 1) {
            throw new \InvalidArgumentException('A share token is 32 lowercase hex digits.');
        }

        return new self($hosts, $token);
    }

    private static function host(string $given): string
    {
        $host = HostName::normalize($given);
        if ($host === null || $host !== strtolower(trim($given))) {
            throw new ServeException("--public-host takes host names alone, comma-separated, such as dev.example.com; got '{$given}'.");
        }
        // Names this machine already answers to, or one a browser resolves to
        // loopback itself: nothing a tunnel would serve, and letting one in
        // with a token in front would refuse the developer at their own. Nor
        // .invalid, which no tunnel can have and the dev Caddyfile's stand-in
        // for no name is.
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.invalid') || !str_contains($host, '.')) {
            throw new ServeException("--public-host names the hosts a tunnel serves, such as dev.example.com; {$host} is not one.");
        }

        return $host;
    }

    /** Whether the names answer only with the share token. */
    public function shared(): bool
    {
        return $this->token !== null;
    }

    /**
     * The addresses to open, or hand to someone, one per name: where shared,
     * the token rides along once.
     *
     * @return non-empty-list<string>
     */
    public function shareUrls(): array
    {
        return array_map(
            fn (string $host): string => "https://{$host}/" . ($this->token === null ? '' : '?' . self::QUERY . "={$this->token}"),
            $this->hosts,
        );
    }

    /** @return array<string, string> what FrankenPHP is told, for the dev Caddyfile */
    public function environment(): array
    {
        $names = implode(' ', $this->hosts);

        return [
            self::HOST_ENV => $names,
            ...($this->token === null ? [] : [self::SHARED_ENV => $names, self::TOKEN_ENV => $this->token]),
        ];
    }
}
