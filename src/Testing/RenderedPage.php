<?php

declare(strict_types=1);

namespace Tinvar\Testing;

use PHPUnit\Framework\Assert;
use Tinvar\Http\Response;
use Tinvar\Orm\LazyLoad;
use Tinvar\Render\RenderResult;

/**
 * A page rendered through the application's real renderer: the HTML, the
 * RSC payload inside it, and what the render measured.
 */
final readonly class RenderedPage
{
    /**
     * @param string $body the bytes the render wrote: the document, or the payload alone
     * @param int $statements statements the request issued, its query bodies included
     * @param list<array{model: class-string, column: string, value: string}> $undeclared
     *        the column values no Data object declares, of every row the request read
     * @param list<LazyLoad>|null $lazyLoads the lazy relation loads the request ran, its
     *        query bodies' included, or null when its Orm was not recording them
     */
    public function __construct(
        public string $url,
        public Response $response,
        public RenderResult $result,
        public string $body,
        public int $statements,
        private array $undeclared = [],
        public ?array $lazyLoads = null,
    ) {}

    /** The RSC payload: the document's inline rows joined, or the body of a payload response. */
    public function payload(): string
    {
        return RscPayload::of($this->body, $this->response->header('content-type'));
    }

    public function assertStatus(int $status): self
    {
        Assert::assertSame($status, $this->response->status, "Rendering {$this->url} was answered with {$this->response->status}, not {$status}.");

        return $this;
    }

    public function assertOk(): self
    {
        return $this->assertStatus(200);
    }

    /** $text is on the page as a reader sees it: in the HTML's text, entities decoded. */
    public function assertSee(string $text): self
    {
        Assert::assertStringContainsString($text, $this->text(), "Rendering {$this->url} did not show '{$text}'.");

        return $this;
    }

    public function assertDontSee(string $text): self
    {
        Assert::assertStringNotContainsString($text, $this->text(), "Rendering {$this->url} showed '{$text}'.");

        return $this;
    }

    /** Elements match the CSS $selector -- exactly $count of them, given a count. */
    public function assertSelector(string $selector, ?int $count = null): self
    {
        $found = $this->document()->querySelectorAll($selector)->length;
        if ($count === null) {
            Assert::assertGreaterThan(0, $found, "Rendering {$this->url} drew nothing matching {$selector}.");
        } else {
            Assert::assertSame($count, $found, "Rendering {$this->url} drew {$found} matching {$selector}, not {$count}.");
        }

        return $this;
    }

    /**
     * The client component exported as $name is referenced by the payload:
     * an `I[...]` row naming it, so its chunk is loaded and it hydrates.
     */
    public function assertClientComponent(string $name): self
    {
        $names = RscPayload::clientComponents($this->payload());
        Assert::assertContains($name, $names, "Rendering {$this->url} referenced no client component {$name}. It referenced: " . implode(', ', $names) . '.');

        return $this;
    }

    /**
     * The renderer asked for queries in at most $max ticks: the batching the
     * whole render depends on, which one component awaiting in a loop breaks.
     */
    public function assertQueryTicks(int $max): self
    {
        Assert::assertLessThanOrEqual($max, $this->result->queryBatches, "Rendering {$this->url} took {$this->result->queryBatches} query ticks, over {$max}.");

        return $this;
    }

    /** The request issued at most $max statements, its loaders and query bodies together. */
    public function assertQueryBudget(int $max): self
    {
        Assert::assertLessThanOrEqual($max, $this->statements, "Rendering {$this->url} issued {$this->statements} statements, over its budget of {$max}.");

        return $this;
    }

    /**
     * No relation the request read lazily was loaded for one parent alone:
     * the loaders' each covered its parent's result set, and the query
     * bodies' went through the render's scheduler or did the same
     * (LazyLoad).
     */
    public function assertNoLazyLoadsOutsideBatch(): self
    {
        LazyLoadAssertion::assertNoneOutsideBatch($this->lazyLoads, "Rendering {$this->url}");

        return $this;
    }

    /**
     * No column a Data object does not declare reached the browser, from any
     * row the request read (ModelLeaks). render() asks this of every page
     * unless the client said otherwise.
     */
    public function assertNoModelLeak(): self
    {
        $leaks = ModelLeaks::find($this->undeclared, $this->body . "\n" . $this->payload());
        Assert::assertSame([], $leaks, "Rendering {$this->url} sent model columns no Data object declares: " . implode(', ', array_unique(array_map(
            static fn (array $leak): string => "{$leak['model']}::{$leak['column']}",
            $leaks,
        ))) . '.');

        return $this;
    }

    /**
     * The HTML's text, as a reader sees it: no script, so the payload rows
     * that repeat the page's data cannot pass for it. A boundary streamed in
     * later counts -- `$RC` swaps it into place before any bundle loads.
     */
    private function text(): string
    {
        $document = $this->document();
        foreach ($document->querySelectorAll('script, style, template') as $element) {
            $element->remove();
        }

        return (string) $document->body?->textContent;
    }

    private function document(): \Dom\HTMLDocument
    {
        return \Dom\HTMLDocument::createFromString($this->body, LIBXML_NOERROR);
    }
}
