<?php

declare(strict_types=1);

namespace Tinvar\Outbound;

use Uri\WhatWg\Url;

/**
 * A URL an outbound request may be sent to, parsed once, by the WHATWG URL
 * standard (PHP's URI extension), and handed on to curl as that parser
 * serialized it.
 *
 * One parse, one spelling: `http://0x7f.1/`, `http://2130706433/` and
 * `http://127.1/` are each 127.0.0.1 here and in what curl is given, so no
 * second parser can read a different host out of the same string. A
 * name is lowercased and in punycode; a literal address is kept as such
 * and never looked up.
 *
 * http and https only, a host and a port that can be connected to, and no
 * user name or password -- credentials in a URL end up in every log that
 * prints it, so they go in a header.
 */
final readonly class Target
{
    /** A host name, as the WHATWG parser leaves one: lowercased, in punycode. */
    private const string NAME = '/^(?!-)[a-z0-9._-]+\z/';

    private function __construct(
        public Url $url,
        public string $scheme,
        /** The host as curl connects to it: a name, or an address without brackets. */
        public string $host,
        public int $port,
        /** The host, when it is an IP address rather than a name. */
        public ?string $literal,
    ) {}

    /**
     * @param Target|null $base what a relative URL -- a redirect's Location -- is resolved against
     * @throws OutboundRefused for a URL an outbound request may not be sent to
     */
    public static function parse(string $url, ?self $base = null): self
    {
        if (\strlen($url) > 8192) {
            throw new OutboundRefused('url_invalid', 'The URL is over 8 KB.');
        }

        $parsed = Url::parse($url, $base?->url);
        if ($parsed === null) {
            throw new OutboundRefused('url_invalid', 'The URL does not parse.');
        }
        $parsed = $parsed->withFragment(null);

        $scheme = $parsed->getScheme();
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new OutboundRefused('scheme', "'{$scheme}' is not a scheme outbound requests use: http or https.");
        }
        if (($parsed->getUsername() ?? '') !== '' || ($parsed->getPassword() ?? '') !== '') {
            throw new OutboundRefused('credentials', 'The URL carries a user name or password; send credentials in a header.');
        }

        $host = $parsed->getAsciiHost() ?? '';
        $literal = null;
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
            $literal = $host;
        } elseif (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $literal = $host;
        }
        if ($host === '') {
            throw new OutboundRefused('url_invalid', 'The URL has no host.');
        }

        // A name of the characters DNS names are made of, and not opening
        // with a dash. WHATWG allows more than curl does, and curl reads a
        // pin for a name opening with a dash -- `-x.example:443:<address>`
        // -- as an entry to remove: the name went unpinned, and curl asked
        // DNS for it a second time, the lookup a rebinding server answers
        // with an internal address.
        if ($literal === null && preg_match(self::NAME, $host) !== 1) {
            throw new OutboundRefused('url_invalid', 'The URL\'s host is not a name curl can be pinned to.');
        }

        $port = $parsed->getPort() ?? ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            throw new OutboundRefused('url_invalid', "Port {$port} cannot be connected to.");
        }

        return new self($parsed, $scheme, $host, $port, $literal);
    }

    /** The URL as curl is handed it. */
    public function serialized(): string
    {
        return $this->url->toAsciiString();
    }

    /** The path and query, as the request line carries them -- and as a webhook signature covers them. */
    public function requestTarget(): string
    {
        $query = $this->url->getQuery();

        return $this->url->getPath() . ($query === null || $query === '' ? '' : "?{$query}");
    }

    /**
     * Scheme, host and port: what a redirect has to keep to keep a request's
     * headers, and all a message names of a destination -- a path or a query
     * can be the secret (a chat service's webhook URL is its own token).
     */
    public function origin(): string
    {
        return "{$this->scheme}://" . ($this->literal !== null && str_contains($this->host, ':') ? "[{$this->host}]" : $this->host) . ":{$this->port}";
    }
}
