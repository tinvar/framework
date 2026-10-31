<?php

declare(strict_types=1);

namespace Tinvar\Live;

use Tinvar\Config\HubUrl;
use Tinvar\Http\Response;
use Tinvar\Support\Signer;

/**
 * A Mercure hub: FrankenPHP's built-in one, or one run as its own service.
 *
 * The hub checks an access token on every connection: a JWT, HS256, under
 * the subscriber key, naming the topics the subscriber may hear in its
 * `authorization_details` (the protocol's modern mode, which FrankenPHP 1.13
 * runs). It closes the connection by the token's `exp` -- measured a few
 * seconds before it -- so a grant is short and the client trades its live
 * tokens again, against the session as it is then, to go on listening.
 *
 * The access token travels in a cookie the hub reads, set HttpOnly for its
 * own path alone: never in a URL, where an access log would keep it, and
 * never readable by script. The protocol has no query parameter for it.
 *
 * Keys come from the application key (HubSettings), never from anything a
 * client is given: the client holds the access token in a cookie it cannot
 * read, and nothing else.
 */
final readonly class Mercure implements Hub
{
    public const string PATH = HubUrl::PATH;

    /** The cookie the hub reads the access token from; the Caddyfile's `cookie_name`. */
    public const string COOKIE = '__Secure-tinvar-live';

    /** The `iss` the hub trusts, named by its `issuer` block. */
    public const string ISSUER = 'tinvar';

    /**
     * The longest audience a grant may name: with LiveToken::MAX_TOPICS
     * topics beside it, what still fits the cookie a browser keeps.
     */
    public const int MAX_AUDIENCE_BYTES = 256;

    /** The `type` of a Mercure authorization detail. */
    public const string DETAIL = 'https://mercure.rocks/authorization-detail';

    /**
     * @param \Closure(string, string): void $send publishes one private update to one topic
     * @param HubLink|null $link a remote hub's connection, deadline and breaker; null for the built-in hub
     */
    private function __construct(
        #[\SensitiveParameter]
        private string $subscriberKey,
        private string $audience,
        private \Closure $send,
        private string $path = self::PATH,
        private string $cookie = self::COOKIE,
        private ?HubLink $link = null,
    ) {
        if (\strlen($subscriberKey) < 32) {
            throw new \InvalidArgumentException('A hub key must be at least 32 bytes.');
        }
        if (\strlen($audience) > self::MAX_AUDIENCE_BYTES) {
            throw new \InvalidArgumentException(
                'A hub audience must be at most ' . self::MAX_AUDIENCE_BYTES . ' bytes: every grant carries it in a cookie of 4 KB at most.',
            );
        }
    }

    /**
     * FrankenPHP's own hub, in the process serving this request: published
     * to with mercure_publish(), so there is no request and no publisher key.
     * Only under FrankenPHP with a `mercure` block in front of `php`.
     */
    public static function builtIn(#[\SensitiveParameter] string $subscriberKey, string $audience): self
    {
        if (!\function_exists('mercure_publish')) {
            throw new \LogicException('The built-in hub is FrankenPHP\'s: mercure_publish() is not defined here.');
        }

        return new self($subscriberKey, $audience, static function (string $topic, string $data): void {
            mercure_publish($topic, $data, true);
        });
    }

    /**
     * A hub of its own, published to over HTTP with a publisher token naming
     * exactly the topic of each update -- a token that leaks publishes to
     * that topic alone, for a minute.
     *
     * @param string $hubUrl the hub's URL as this server reaches it
     * @param string $path where the client reaches it, same-origin: a proxy
     *        in front of the application forwards it to the hub
     * Built once per worker, as the Kernel is: it holds the worker's one
     * connection to the hub, the deadline on what a request may spend
     * publishing, and the breaker that stops asking a hub that is down
     * (HubLink). An update that fails or is skipped throws, for its caller
     * to log.
     *
     * @param (\Closure(string, array<string, string>, string, float): int)|null $post
     *        sends a request within the seconds given and returns its status;
     *        curl, with one handle for the worker's life, unless given
     * @param (\Closure(): float)|null $clock seconds, as microtime(true) gives them
     */
    public static function remote(
        string $hubUrl,
        #[\SensitiveParameter]
        string $publisherKey,
        #[\SensitiveParameter]
        string $subscriberKey,
        string $audience,
        string $path = self::PATH,
        ?\Closure $post = null,
        ?\Closure $clock = null,
    ): self {
        if (\strlen($publisherKey) < 32) {
            throw new \InvalidArgumentException('A hub key must be at least 32 bytes.');
        }

        $link = new HubLink($post, $clock);

        return new self($subscriberKey, $audience, static function (string $topic, string $data) use ($hubUrl, $publisherKey, $audience, $link): void {
            $token = self::accessToken($publisherKey, $audience, 'publisher', ['publish'], [$topic], time() + 60);
            $link->send($hubUrl, [
                'authorization' => "Bearer {$token}",
                'content-type' => 'application/x-www-form-urlencoded',
            ], http_build_query(['topic' => $topic, 'data' => $data, 'private' => 'on']));
        }, $path, link: $link);
    }

    public function publish(string $topic, string $data): void
    {
        ($this->send)($topic, $data);
    }

    /**
     * The request is over: the next one may spend a whole deadline
     * publishing (HubLink). The Kernel's request scope calls it; nothing to
     * do for the built-in hub, published to in-process.
     */
    public function endRequest(): void
    {
        $this->link?->endRequest();
    }

    public function grant(Response $response, array $topics, int $expiresAt, string $subject): Response
    {
        $token = self::accessToken($this->subscriberKey, $this->audience, $subject, ['subscribe'], $topics, $expiresAt);

        return $response->withPathCookie($this->cookie, $token, $this->path, max(0, $expiresAt - time()));
    }

    public function revoke(Response $response): Response
    {
        return $response->withPathCookie($this->cookie, '', $this->path, 0);
    }

    public function subscribeUrl(array $topics): string
    {
        // `match`, one per topic: the modern protocol's topic matcher.
        return $this->path . '?' . implode('&', array_map(
            static fn (string $topic): string => 'match=' . rawurlencode($topic),
            $topics,
        ));
    }

    /**
     * A Mercure access token: `at+jwt`, HS256, with every claim the modern
     * protocol requires, allowing $actions on exactly $topics.
     *
     * @param list<string> $actions
     * @param list<string> $topics
     */
    public static function accessToken(
        #[\SensitiveParameter]
        string $key,
        string $audience,
        string $subject,
        array $actions,
        array $topics,
        int $expiresAt,
        ?int $now = null,
    ): string {
        $now ??= time();
        $header = Signer::encode(json_encode(['alg' => 'HS256', 'typ' => 'at+jwt'], JSON_THROW_ON_ERROR));
        $claims = Signer::encode(json_encode([
            'iss' => self::ISSUER,
            'aud' => $audience,
            'sub' => $subject,
            'client_id' => self::ISSUER,
            'iat' => $now,
            'exp' => $expiresAt,
            'jti' => bin2hex(random_bytes(16)),
            'authorization_details' => [[
                'type' => self::DETAIL,
                'actions' => $actions,
                'topics' => array_map(static fn (string $topic): array => ['match' => $topic], $topics),
            ]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return "{$header}.{$claims}." . Signer::encode(hash_hmac('sha256', "{$header}.{$claims}", $key, true));
    }
}
