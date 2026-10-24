<?php

declare(strict_types=1);

namespace Tinvar\Live;

use Tinvar\Http\Response;

/**
 * A hub that keeps what it is asked to do, for tests: every update
 * published, and what each grant allowed.
 *
 * Not a transport. Nothing subscribes to it; a test reads `published` to see
 * what went out, and when.
 */
final class MemoryHub implements Hub
{
    /** @var list<array{topic: string, data: string}> */
    public array $published = [];

    /** @var list<array{topics: list<string>, expiresAt: int, subject: string}> */
    public array $grants = [];

    public int $revoked = 0;

    /** @param (\Closure(string, string): void)|null $onPublish called as each update goes out */
    public function __construct(private readonly ?\Closure $onPublish = null) {}

    public function publish(string $topic, string $data): void
    {
        if ($this->onPublish !== null) {
            ($this->onPublish)($topic, $data);
        }
        $this->published[] = ['topic' => $topic, 'data' => $data];
    }

    public function grant(Response $response, array $topics, int $expiresAt, string $subject): Response
    {
        $this->grants[] = ['topics' => $topics, 'expiresAt' => $expiresAt, 'subject' => $subject];

        return $response->withHeader('x-test-hub-grant', implode(',', $topics));
    }

    public function revoke(Response $response): Response
    {
        $this->revoked++;

        return $response->withHeader('x-test-hub-revoked', '1');
    }

    public function subscribeUrl(array $topics): string
    {
        return '/_test/hub?' . http_build_query(['topic' => $topics]);
    }
}
