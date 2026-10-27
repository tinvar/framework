<?php

declare(strict_types=1);

/** Once-shared providers (ADR 0065), beside shared.php's. */

namespace Tinvar\Tests\Data\Fixtures\Shared;

use Tinvar\Attributes\Shared;
use Tinvar\Data\DataContext;
use Tinvar\Data\SharedData;
use Tinvar\Http\Request;

require_once __DIR__ . '/shared.php';

/** Once-shared values, each call noted. */
final readonly class OnceShared implements SharedData
{
    public function __construct(private Reads $reads) {}

    #[Shared(once: true, until: '10m', tags: ['notes', ' alerts '])]
    public function motd(): GreetingData
    {
        $this->reads->note('motd');

        return new GreetingData('hello');
    }

    /** Null for nobody: the visitor, as the test names them in $reads->rack. */
    #[Shared(once: true)]
    public function pinned(): ?GreetingData
    {
        $this->reads->note('pinned');

        return $this->reads->rack === '' ? null : new GreetingData('pinned');
    }

    #[Shared(once: true)]
    public function broken(): GreetingData
    {
        $this->reads->note('broken');

        throw new \RuntimeException('no greeting today');
    }

    #[Shared]
    public function plain(): GreetingData
    {
        $this->reads->note('plain');

        return new GreetingData('plain');
    }
}

final class UntilWithoutOnceShared implements SharedData
{
    #[Shared(until: '1h')]
    public function stale(): GreetingData
    {
        return new GreetingData('x');
    }
}

final class TagsWithoutOnceShared implements SharedData
{
    #[Shared(tags: ['x'])]
    public function stale(): GreetingData
    {
        return new GreetingData('x');
    }
}

final class TooLongOnceShared implements SharedData
{
    #[Shared(once: true, until: '2d')]
    public function stale(): GreetingData
    {
        return new GreetingData('x');
    }
}

final class BadUntilOnceShared implements SharedData
{
    #[Shared(once: true, until: 'soon')]
    public function stale(): GreetingData
    {
        return new GreetingData('x');
    }
}

final class BadTagOnceShared implements SharedData
{
    #[Shared(once: true, tags: ['a,b'])]
    public function stale(): GreetingData
    {
        return new GreetingData('x');
    }
}

/** Told the page, so it cannot be kept across pages. */
final class PageOnceShared implements SharedData
{
    #[Shared(once: true)]
    public function where(DataContext $context): GreetingData
    {
        return new GreetingData($context->key);
    }
}

/** Built with the Request, so its once method could read the URL through it: refused (ADR 0074). */
final readonly class BuiltWithRequestOnceShared implements SharedData
{
    public function __construct(private Request $request) {}

    #[Shared(once: true)]
    public function here(): GreetingData
    {
        return new GreetingData($this->request->path);
    }
}

/** Could read the URL from its Request, and be kept across pages: refused (ADR 0074). */
final class RequestOnceShared implements SharedData
{
    #[Shared(once: true)]
    public function here(Request $request): GreetingData
    {
        return new GreetingData($request->path);
    }
}

/** A ProvidesData is told the page too. */
final class ProvidedOnceShared implements SharedData
{
    #[Shared(once: true)]
    public function greeting(): Greeting
    {
        return new Greeting('Ada');
    }
}
