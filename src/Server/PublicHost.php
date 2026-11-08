<?php

declare(strict_types=1);

namespace Tinvar\Server;

use Tinvar\Http\HostName;

/**
 * The one public name a dev server may also be reached by: a tunnel's
 * (ADR 0190).
 *
 * Dev listens on loopback, and answers only a request naming this machine,
 * so no page that points a name of its own at 127.0.0.1 can read it (DNS
 * rebinding, Caddyfile.dev). A tunnel -- cloudflared, run here -- connects
 * from loopback too, but names the host its visitor asked for. That one
 * name is let in, exactly: a name of the tunnel's, which no page elsewhere
 * can point at this machine.
 *
 * Let in, it is still dev: Vite serves the app's source, its PHP included,
 * and every page's props. (Not its raw errors, which go to loopback's names
 * alone.) So the name answers only a visitor holding this run's share
 * token, given once in the URL serve prints and kept in a cookie from then
 * on. The token is fresh each run and dies with it.
 */
final readonly class PublicHost
{
    /** What FrankenPHP is told the name in; the dev Caddyfile reads it. */
    public const string HOST_ENV = 'TINVAR_DEV_PUBLIC_HOST';

    /** What FrankenPHP is told the share token in. */
    public const string TOKEN_ENV = 'TINVAR_DEV_SHARE_TOKEN';

    /** The query parameter that carries the token on a visitor's first request. */
    public const string QUERY = 'tinvar-share';

    private function __construct(
        public string $host,
        public string $token,
    ) {}

    /** The name given, held to being one a tunnel would serve; with a fresh token. */
    public static function of(string $given, ?string $token = null): self
    {
        $host = HostName::normalize($given);
        if ($host === null || $host !== strtolower(trim($given))) {
            throw new ServeException("--public-host takes a host name alone, such as dev.example.com; got '{$given}'.");
        }
        // Names this machine already answers to, or one a browser resolves to
        // loopback itself: nothing a tunnel would serve, and letting one in
        // with a token in front would refuse the developer at their own. Nor
        // .invalid, which no tunnel can have and the dev Caddyfile's stand-in
        // for no name is.
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.invalid') || !str_contains($host, '.')) {
            throw new ServeException("--public-host names the host a tunnel serves, such as dev.example.com; {$host} is not one.");
        }

        $token ??= bin2hex(random_bytes(16));
        if (preg_match('/^[0-9a-f]{32}\z/', $token) !== 1) {
            throw new \InvalidArgumentException('A share token is 32 lowercase hex digits.');
        }

        return new self($host, $token);
    }

    /** The address to open, or hand to someone: the token rides along once. */
    public function shareUrl(): string
    {
        return "https://{$this->host}/?" . self::QUERY . "={$this->token}";
    }

    /** @return array<string, string> what FrankenPHP is told, for the dev Caddyfile */
    public function environment(): array
    {
        return [self::HOST_ENV => $this->host, self::TOKEN_ENV => $this->token];
    }
}
