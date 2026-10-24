<?php

declare(strict_types=1);

namespace Tinvar\Testing;

use PHPUnit\Framework\Assert;
use Tinvar\Http\Response;

/**
 * One action called in PHP, answered as its endpoint answers a script: JSON
 * with its value, flash and revalidated routes, the tags it invalidated in
 * `X-Invalidated-Tags`, field errors on a 422, a 303 for a redirect -- and a
 * refusal as the status and code the client would be given, with the
 * exception behind it kept for the test's message.
 */
final readonly class ActionResponse
{
    /** @var array<array-key, mixed> */
    private array $json;

    public function __construct(
        public string $name,
        public Response $response,
        public ?\Throwable $failure = null,
    ) {
        $decoded = $response->body() === '' ? [] : json_decode($response->body(), true);
        $this->json = \is_array($decoded) ? $decoded : [];
    }

    public function status(): int
    {
        return $this->response->status;
    }

    /** @return array<array-key, mixed> the response's JSON */
    public function json(): array
    {
        return $this->json;
    }

    /** What the action handed back, encoded as the client receives it. */
    public function value(): mixed
    {
        return $this->json['value'] ?? null;
    }

    public function assertStatus(int $status): self
    {
        Assert::assertSame($status, $this->response->status, "Action '{$this->name}' was answered with {$this->response->status}, not {$status}." . $this->why());

        return $this;
    }

    /** Ran and returned, with no redirect: a 200, or a validate-only call's 204. */
    public function assertOk(): self
    {
        Assert::assertContains($this->response->status, [200, 204], "Action '{$this->name}' was answered with {$this->response->status}." . $this->why());

        return $this;
    }

    public function assertForbidden(): self
    {
        return $this->assertStatus(403);
    }

    public function assertNotFound(): self
    {
        return $this->assertStatus(404);
    }

    public function assertUnauthenticated(): self
    {
        return $this->assertStatus(401);
    }

    /**
     * Refused by validation, with an error on each of $fields -- or, for a
     * field given as a key, an error containing its value.
     *
     * @param list<string>|array<string, string> $fields
     */
    public function assertInvalid(array $fields): self
    {
        $this->assertStatus(422);
        $errors = $this->errors();
        foreach ($fields as $key => $value) {
            $field = \is_int($key) ? $value : $key;
            Assert::assertArrayHasKey($field, $errors, "Action '{$this->name}' had no error for '{$field}'. It had errors for: " . (implode(', ', array_keys($errors)) ?: 'nothing') . '.');
            if (!\is_int($key)) {
                Assert::assertStringContainsString($value, implode("\n", $errors[$field]), "Action '{$this->name}''s error for '{$field}' does not say '{$value}'.");
            }
        }

        return $this;
    }

    /** @return array<string, list<string>> the field errors of a 422 */
    public function errors(): array
    {
        $errors = $this->json['errors'] ?? [];
        if (!\is_array($errors)) {
            return [];
        }

        $out = [];
        foreach ($errors as $field => $messages) {
            $out[(string) $field] = array_values(array_map(strval(...), \is_array($messages) ? $messages : [$messages]));
        }

        return $out;
    }

    /** Every one of $tags is among those the action invalidated (`X-Invalidated-Tags`). */
    public function assertRevalidatedTags(string ...$tags): self
    {
        $invalidated = array_values(array_filter(explode(',', $this->response->header('x-invalidated-tags') ?? '')));
        foreach ($tags as $tag) {
            Assert::assertContains($tag, $invalidated, "Action '{$this->name}' did not invalidate '{$tag}'. It invalidated: " . (implode(', ', $invalidated) ?: 'nothing') . '.');
        }

        return $this;
    }

    /** Every one of $routes is among those the action said to re-render. */
    public function assertRevalidated(string ...$routes): self
    {
        $revalidated = \is_array($this->json['revalidated'] ?? null) ? $this->json['revalidated'] : [];
        foreach ($routes as $route) {
            Assert::assertContains($route, $revalidated, "Action '{$this->name}' did not revalidate '{$route}'.");
        }

        return $this;
    }

    /** The action flashed $key -- with $value, given one. */
    public function assertFlash(string $key, mixed $value = null): self
    {
        $flash = \is_array($this->json['flash'] ?? null) ? $this->json['flash'] : [];
        Assert::assertArrayHasKey($key, $flash, "Action '{$this->name}' flashed no '{$key}'." . $this->why());
        if (\func_num_args() > 1) {
            Assert::assertSame($value, $flash[$key], "Action '{$this->name}' flashed another '{$key}'.");
        }

        return $this;
    }

    /** The action redirected -- to $to, given one -- with the 303 a write is answered with. */
    public function assertRedirect(?string $to = null): self
    {
        $this->assertStatus(303);
        if ($to !== null) {
            Assert::assertSame($to, $this->response->header('location'), "Action '{$this->name}' redirected elsewhere.");
        }

        return $this;
    }

    private function why(): string
    {
        if ($this->failure !== null) {
            return "\nIt threw " . $this->failure::class . ': ' . $this->failure->getMessage();
        }

        $errors = $this->errors();

        return $errors === [] ? '' : "\nIts errors: " . json_encode($errors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
