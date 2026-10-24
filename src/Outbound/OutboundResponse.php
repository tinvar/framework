<?php

declare(strict_types=1);

namespace Tinvar\Outbound;

/**
 * What a destination answered: its status, headers and body -- the body
 * whole, or not at all when the request asked for none -- with how long
 * the request took and the address it was sent to.
 *
 * Response headers can carry what the caller should not log (a session
 * cookie a server set, a token it handed back), so a dump shows their
 * names only, and the body by its length.
 */
final readonly class OutboundResponse
{
    /** @var array<string, list<string>> */
    public array $headers;

    /**
     * @param array<string, list<string>> $headers by name; lowercased here
     * @param int $milliseconds from the first connection to the last byte, redirects included
     * @param string $address the address the last request was sent to
     */
    public function __construct(
        public int $status,
        array $headers = [],
        public string $body = '',
        public int $milliseconds = 0,
        public string $address = '',
    ) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    /** The first value of a header, or null when it was not sent. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** A redirect with somewhere to go. */
    public function redirect(): bool
    {
        return \in_array($this->status, [301, 302, 303, 307, 308], true) && $this->header('location') !== null;
    }

    /**
     * The body as JSON.
     *
     * @return array<array-key, mixed>
     * @throws \JsonException when it is not a JSON object or array
     */
    public function json(): array
    {
        $data = json_decode($this->body, true, 64, JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            throw new \JsonException('The response body is JSON, and not an object or an array.');
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'status' => $this->status,
            'headers' => array_keys($this->headers),
            'body' => \strlen($this->body) . ' bytes',
            'milliseconds' => $this->milliseconds,
            'address' => $this->address,
        ];
    }
}
