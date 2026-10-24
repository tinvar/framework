<?php

declare(strict_types=1);

namespace Tinvar\Testing;

use PHPUnit\Framework\Assert;
use Tinvar\Data\Data;
use Tinvar\Data\DataShape;
use Tinvar\Data\Encoder;
use Tinvar\Data\Lazy;
use Tinvar\Http\Kernel;
use Tinvar\Routing\PartialReload;

/**
 * The data a page sent to React, asserted the way Inertia's AssertableInertia
 * asserts props: `has`, `where` and `missing` with dot paths, scopes, and
 * `etc()`.
 *
 * Each scope holds two views of one value. What is asserted is the encoding
 * -- what the renderer is handed, after the data boundary -- so a test reads
 * what a component reads. Beside it is the value before encoding, the Data
 * objects themselves, so that a path naming a field the page's Data class
 * does not have fails as that, not as a missing value: a renamed field
 * breaks the test that names it. `data()` hands the Data object to a typed
 * closure for assertions static analysis can follow.
 *
 * Strict scoping, as Inertia's: inside a scope opened with a closure --
 * `has('order', fn ...)`, `layout('shop', fn ...)` -- every field must be
 * asserted, or the closure calls etc(). The page's own top level is not
 * strict, so a test may assert the one field it is about.
 */
final class AssertablePage
{
    /** @var array<string, true> first path segments this scope has asserted */
    private array $interacted = [];

    private bool $etc = false;

    /**
     * @param mixed $props the encoded value at this scope
     * @param mixed $raw the same value before encoding, where known
     * @param string $path where this scope sits, for messages
     * @param PageResponse|null $response the request it came from, at a page or layout's
     *        top level only: what a follow-up request and the flash are read from
     */
    public function __construct(
        private readonly mixed $props,
        private readonly mixed $raw,
        private readonly string $path,
        private readonly ?PageResponse $response = null,
    ) {}

    /**
     * The value at $key is $expected -- the same, strictly -- or, given a
     * closure, the closure returns true for it.
     */
    public function where(string $key, mixed $expected): self
    {
        $actual = $this->value($key);

        if ($expected instanceof \Closure) {
            Assert::assertTrue($expected($actual) === true, "{$this->at($key)} was marked invalid by the closure. It is " . self::show($actual) . '.');
        } else {
            Assert::assertSame($expected, $actual, "{$this->at($key)} is not what was expected.");
        }

        return $this;
    }

    /** The value at $key is anything but $value. */
    public function whereNot(string $key, mixed $value): self
    {
        Assert::assertNotSame($value, $this->value($key), "{$this->at($key)} is the value it should not be.");

        return $this;
    }

    /**
     * $key is there; with a count, it is a list of that many; with a closure,
     * the closure asserts inside it -- inside its first item, given a count
     * as well.
     */
    public function has(string $key, int|\Closure|null $length = null, ?\Closure $scope = null): self
    {
        $value = $this->value($key);
        if ($length instanceof \Closure) {
            [$scope, $length] = [$length, null];
        }

        if ($length !== null) {
            Assert::assertIsArray($value, "{$this->at($key)} is not a list to count.");
            Assert::assertCount($length, $value, "{$this->at($key)} does not hold {$length}.");
        }

        if ($scope === null) {
            return $this;
        }

        if (\is_array($value) && $length !== null) {
            $first = array_key_first($value);
            Assert::assertNotNull($first, "{$this->at($key)} is empty, so there is no first item to scope into.");

            return $this->scope("{$key}.{$first}", $value[$first], $scope);
        }

        return $this->scope($key, $value, $scope);
    }

    /** $key is not there at all -- not null, absent. */
    public function missing(string $key): self
    {
        Assert::assertFalse(self::dig($this->props, $key)[0], "{$this->at($key)} is there, and should not be.");

        return $this;
    }

    /** Fields of this scope that are not asserted are allowed. */
    public function etc(): self
    {
        $this->etc = true;

        return $this;
    }

    /**
     * A typed look at the Data object this scope is: $check is handed it,
     * and fails the test by returning false -- or by asserting inside.
     */
    public function data(\Closure $check): self
    {
        Assert::assertNotNull($this->raw, "{$this->path} has no Data object to hand over.");
        Assert::assertNotFalse($check($this->raw), "{$this->path} was marked invalid by the closure.");

        return $this;
    }

    /** The layout keyed $key -- `shop` for LayoutProps<'shop'> -- asserted inside $scope. */
    public function layout(string $key, \Closure $scope): self
    {
        $response = $this->topLevel('layout()');
        $recorded = $response->recorded();
        Assert::assertArrayHasKey($key, $recorded->encoded['layouts'], "The page has no layout '{$key}'. It has: " . implode(', ', array_keys($recorded->encoded['layouts'])) . '.');

        $layout = new self($recorded->encoded['layouts'][$key], $recorded->loaded->layouts[$key] ?? null, "layout '{$key}'", $response);
        $scope($layout);
        $layout->assertInteracted();

        return $this;
    }

    /**
     * $key is a Lazy field: resolved only when a component reads it, and
     * streamed in behind a Suspense boundary rather than held up front.
     */
    public function deferred(string $key): self
    {
        $this->value($key);
        Assert::assertInstanceOf(Lazy::class, $this->rawAt($key, keepLazy: true), "{$this->at($key)} is not a Lazy field.");

        return $this;
    }

    /**
     * $key is an #[Optional] field this response was not asked for: sent as
     * the path a partial reload names it by, its resolver never run. Ask
     * for it with reloadOnly($key, ...) (ADR 0045).
     */
    public function unrequested(string $key): self
    {
        $value = $this->value($key);
        Assert::assertIsArray($value, "{$this->at($key)} was sent, not left for a partial reload to ask for.");
        Assert::assertSame([Encoder::OPTIONAL_KEY], array_keys($value), "{$this->at($key)} was sent, not left for a partial reload to ask for.");

        return $this;
    }

    /** $key is a Lazy field, and its value -- as it streams in -- is asserted inside $scope. */
    public function loadDeferred(string $key, \Closure $scope): self
    {
        $this->deferred($key);

        return $this->scope($key, $this->value($key), $scope);
    }

    /**
     * A partial reload of this page naming $paths in `X-Only` (ADR 0015) --
     * `page.orders`, or a layout's key -- asserted inside $scope. A path
     * whose first part names no segment of the route is read as the page's
     * field. Every loader runs again; only the segments named are sent.
     *
     * @param string|list<string> $paths
     */
    public function reloadOnly(string|array $paths, \Closure $scope): self
    {
        $response = $this->topLevel('reloadOnly()');
        $recorded = $response->recorded();
        $segments = [PartialReload::PAGE, ...array_keys($recorded->loaded->layouts)];

        $only = [];
        foreach ((array) $paths as $path) {
            $first = explode('.', $path, 2)[0];
            $only[] = \in_array($first, $segments, true) ? $path : PartialReload::PAGE . ".{$path}";
        }

        $reloaded = $response->client->page($response->url, [
            'rsc' => '1',
            'accept' => 'text/x-component',
            'x-only' => implode(',', $only),
            'x-page' => $recorded->loaded->chain->page->view,
        ]);
        $reloaded->assertOk();
        $page = $reloaded->recorded();
        Assert::assertNotNull($page->loaded->segments, 'The reload was answered in full, not as a partial reload: the application has partial reloads off, or the request was refused one.');

        // The page's data, or nothing when only layouts were named: layout()
        // reads theirs, and missing() says the page's was not sent.
        $scope(new self($page->encoded['page'], $page->loaded->page, 'page (reloaded)', $reloaded));

        return $this;
    }

    /** What an action's result flashed for this page -- ActionResult::flash() -- holds $key, and $value if given. */
    public function hasFlash(string $key, mixed $value = null): self
    {
        $flash = $this->flash();
        Assert::assertArrayHasKey($key, $flash, "The page was flashed no '{$key}'. It was flashed: " . (implode(', ', array_keys($flash)) ?: 'nothing') . '.');
        if (\func_num_args() > 1) {
            Assert::assertSame($value, $flash[$key], "The page's flashed '{$key}' is not what was expected.");
        }

        return $this;
    }

    public function missingFlash(string $key): self
    {
        Assert::assertArrayNotHasKey($key, $this->flash(), "The page was flashed '{$key}', and should not have been.");

        return $this;
    }

    /**
     * The errors a form posted without script was sent back with hold
     * $fields, for the action that failed.
     *
     * @param list<string> $fields
     */
    public function hasFormErrors(array $fields): self
    {
        $form = $this->formState();
        $errors = \is_array($form['errors'] ?? null) ? $form['errors'] : [];
        foreach ($fields as $field) {
            Assert::assertArrayHasKey($field, $errors, "The page was sent back with no error for '{$field}'.");
        }

        return $this;
    }

    /** Fails unless every field of this scope was asserted, or etc() was called. */
    public function assertInteracted(): void
    {
        if ($this->etc || !\is_array($this->props) || array_is_list($this->props)) {
            return;
        }

        $left = array_diff(array_map(strval(...), array_keys($this->props)), array_keys($this->interacted));
        Assert::assertSame([], array_values($left), "{$this->path} has fields the test did not assert: " . implode(', ', $left) . '. Assert them, or call etc().');
    }

    // ------------------------------------------------------------ helpers

    /** The encoded value at $key, failing if it is not there or names no field. */
    private function value(string $key): mixed
    {
        $this->checkField($key);
        [$found, $value] = self::dig($this->props, $key);
        Assert::assertTrue($found, "{$this->at($key)} is not in the data the page sent.");
        $this->interacted[explode('.', $key, 2)[0]] = true;

        return $value;
    }

    private function scope(string $key, mixed $value, \Closure $scope): self
    {
        $nested = new self($value, $this->rawAt($key), "{$this->path}.{$key}");
        $scope($nested);
        $nested->assertInteracted();

        return $this;
    }

    /**
     * Fails when a step of $key names a field a Data object along it does not
     * have. A step into a list, a map or an unresolved Lazy field is not
     * checked: only a Data class says what fields there are.
     */
    private function checkField(string $key): void
    {
        $this->walk($key, check: true);
    }

    /** The value before encoding at $key; null where it cannot be known without running anything. */
    private function rawAt(string $key, bool $keepLazy = false): mixed
    {
        [$value, $wrapped] = $this->walk($key, check: false);
        if ($keepLazy) {
            return $value;
        }

        // A #[Once] field is encoded inside a wrapper -- `$once`, `ttl`,
        // `tags`, `value` -- that its Data object has no fields for, so a
        // scope over the wrapper is not checked against it.
        if ($wrapped) {
            return null;
        }

        return $value instanceof Lazy ? ($value->isResolved() ? $value->resolve() : null) : $value;
    }

    /**
     * Follows an encoded path through the value before encoding.
     *
     * A Lazy field is followed only once the encoding resolved it: resolving
     * one now would run its resolver after the request, outside its tenant
     * and its read-only scope. A #[Once] field's wrapper is stepped over at
     * `value`, and the walk stops at any other of its keys.
     *
     * @return array{mixed, bool} the value reached, or null; and whether it is a once
     *         field's, whose encoding is the wrapper
     */
    private function walk(string $key, bool $check): array
    {
        $value = $this->raw;
        $wrapped = false;
        foreach (explode('.', $key) as $step) {
            if ($wrapped) {
                $wrapped = false;
                if ($step === 'value') {
                    continue;
                }

                return [null, false];
            }

            if ($value instanceof Lazy) {
                if (!$value->isResolved()) {
                    return [null, false];
                }
                $value = $value->resolve();
            }

            if ($value instanceof Data) {
                $fields = array_map(static fn (\ReflectionProperty $p): string => $p->getName(), DataShape::fieldsOf($value::class));
                if (!\in_array($step, $fields, true)) {
                    if ($check) {
                        Assert::fail(\sprintf("%s has no field '%s' (asserted as '%s' in %s). Its fields: %s.", $value::class, $step, $key, $this->path, implode(', ', $fields)));
                    }

                    return [null, false];
                }
                $property = new \ReflectionProperty($value, $step);
                if (!$property->isInitialized($value)) {
                    return [null, false];
                }
                $wrapped = isset(DataShape::once($value::class)[$step]);
                $value = $property->getValue($value);
                continue;
            }

            if (!\is_array($value) || !\array_key_exists($step, $value)) {
                return [null, false];
            }
            $value = $value[$step];
        }

        return [$value, $wrapped];
    }

    /** @return array{bool, mixed} whether $key is there in $value, and what it is */
    private static function dig(mixed $value, string $key): array
    {
        foreach (explode('.', $key) as $step) {
            if (!\is_array($value) || !\array_key_exists($step, $value)) {
                return [false, null];
            }
            $value = $value[$step];
        }

        return [true, $value];
    }

    private function topLevel(string $method): PageResponse
    {
        return $this->response ?? throw new \LogicException("{$method} is asked of a page or a layout, not of a scope inside one.");
    }

    /** @return array<array-key, mixed> */
    private function flash(): array
    {
        $state = $this->topLevel('hasFlash()')->recorded()->request->attribute(Kernel::FLASH);
        $flash = \is_array($state) ? ($state['flash'] ?? []) : [];

        return \is_array($flash) ? $flash : [];
    }

    /** @return array<array-key, mixed> */
    private function formState(): array
    {
        $state = $this->topLevel('hasFormErrors()')->recorded()->request->attribute(Kernel::FLASH);
        $form = \is_array($state) ? ($state['form'] ?? []) : [];

        return \is_array($form) ? $form : [];
    }

    private function at(string $key): string
    {
        return "'{$key}' in {$this->path}";
    }

    private static function show(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return \is_string($json) ? (\strlen($json) > 200 ? substr($json, 0, 200) . '...' : $json) : get_debug_type($value);
    }
}
