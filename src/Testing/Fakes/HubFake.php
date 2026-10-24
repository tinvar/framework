<?php

declare(strict_types=1);

namespace Tinvar\Testing\Fakes;

use PHPUnit\Framework\Assert;
use Tinvar\Http\Response;
use Tinvar\Live\Hub;

/**
 * The live hub fake: what the application published, read by tag, and what
 * each grant allowed.
 *
 * An update goes out once its writes commit (Invalidator), so a tag invalidated
 * inside a transaction that rolls back is never here -- which is the point of
 * asserting through the hub rather than an action's response: it says what
 * every other client was told, not what the caller was.
 *
 * Topics are tenant-namespaced and keyed (Topics), so the tag each update
 * carries is what a test names.
 */
final class HubFake implements Hub
{
    /** @var list<array{topic: string, tag: string}> */
    private array $published = [];

    /** @var list<array{topics: list<string>, expiresAt: int, subject: string}> */
    public array $grants = [];

    public int $revoked = 0;

    public function publish(string $topic, string $data): void
    {
        $update = json_decode($data, true);
        $tag = \is_array($update) && \is_string($update['tag'] ?? null) ? $update['tag'] : '';
        $this->published[] = ['topic' => $topic, 'tag' => $tag];
    }

    public function grant(Response $response, array $topics, int $expiresAt, string $subject): Response
    {
        $this->grants[] = ['topics' => $topics, 'expiresAt' => $expiresAt, 'subject' => $subject];

        return $response->withHeader('x-test-hub-grant', (string) \count($topics));
    }

    public function revoke(Response $response): Response
    {
        $this->revoked++;

        return $response;
    }

    public function subscribeUrl(array $topics): string
    {
        return '/_test/hub';
    }

    /** @return list<string> every tag published, in order */
    public function tags(): array
    {
        return array_column($this->published, 'tag');
    }

    /** Every one of $tags went out to the clients listening for it. */
    public function assertInvalidated(string ...$tags): self
    {
        foreach ($tags as $tag) {
            Assert::assertContains($tag, $this->tags(), "'{$tag}' was not published to the hub. Published: " . (implode(', ', $this->tags()) ?: 'nothing') . '.');
        }

        return $this;
    }

    public function assertNotInvalidated(string $tag): self
    {
        Assert::assertNotContains($tag, $this->tags(), "'{$tag}' was published to the hub.");

        return $this;
    }

    public function assertNothingPublished(): self
    {
        Assert::assertSame([], $this->tags(), 'Updates were published to the hub: ' . implode(', ', $this->tags()) . '.');

        return $this;
    }
}
