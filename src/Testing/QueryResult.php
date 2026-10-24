<?php

declare(strict_types=1);

namespace Tinvar\Testing;

use PHPUnit\Framework\Assert;
use Tinvar\Query\QueryError;

/**
 * One query called in PHP: the value it returned -- the Data object, and its
 * encoding as the renderer would be handed it -- or the error its call
 * failed with.
 */
final readonly class QueryResult
{
    /**
     * @param mixed $value the encoded value; null when the call failed
     * @param mixed $data what the body returned, before encoding
     * @param array{code: string, digest: string, message?: string}|null $error
     */
    public function __construct(
        public string $name,
        public mixed $value,
        public mixed $data,
        public ?array $error,
    ) {}

    public function assertOk(): self
    {
        Assert::assertNull($this->error, "Query '{$this->name}' failed: " . $this->describe());

        return $this;
    }

    /**
     * $check is handed the Data object the body returned, and fails the test
     * by returning false -- or by asserting inside.
     */
    public function assertData(\Closure $check): self
    {
        $this->assertOk();
        Assert::assertNotFalse($check($this->data), "Query '{$this->name}' returned data the closure marked invalid.");

        return $this;
    }

    /** The encoded value -- what the renderer is handed -- is $expected. */
    public function assertValue(mixed $expected): self
    {
        $this->assertOk();
        Assert::assertSame($expected, $this->value, "Query '{$this->name}' returned another value.");

        return $this;
    }

    /** An #[Authorize] on the query, or its panel, refused the call. */
    public function assertForbidden(): self
    {
        return $this->assertError(QueryError::FORBIDDEN);
    }

    /** A model argument named no row in scope -- missing, or another tenant's. */
    public function assertNotFound(): self
    {
        return $this->assertError(QueryError::NOT_FOUND);
    }

    public function assertError(string $code): self
    {
        Assert::assertNotNull($this->error, "Query '{$this->name}' succeeded; it should have failed with '{$code}'.");
        Assert::assertSame($code, $this->error['code'], "Query '{$this->name}' failed otherwise: " . $this->describe());

        return $this;
    }

    private function describe(): string
    {
        return $this->error === null ? 'it did not' : $this->error['code'] . (isset($this->error['message']) ? " ({$this->error['message']})" : '');
    }
}
