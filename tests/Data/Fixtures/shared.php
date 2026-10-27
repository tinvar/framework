<?php

declare(strict_types=1);

namespace Tinvar\Tests\Data\Fixtures\Shared;

use Tinvar\Attributes\Panel;
use Tinvar\Attributes\Shared;
use Tinvar\Container\Dependency;
use Tinvar\Container\Lifetime;
use Tinvar\Data\Data;
use Tinvar\Data\DataContext;
use Tinvar\Data\ProvidesData;
use Tinvar\Data\SharedData;
use Tinvar\Http\Request;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Query\Fixtures\RackSummaryData;

final readonly class GreetingData extends Data
{
    public function __construct(public string $text) {}
}

/** Takes anything, so a test can hide a model in it. */
final readonly class LooseData extends Data
{
    public function __construct(public mixed $payload) {}
}

/** What the providers did, kept outside them: they are built afresh each render. */
final class Reads
{
    /** @var list<string> */
    public array $calls = [];

    public string $rack = '';

    public function note(string $call): void
    {
        $this->calls[] = $call;
    }
}

/** A value that becomes its Data told where it is going. */
final readonly class Greeting implements ProvidesData
{
    public function __construct(private string $who) {}

    public function toData(DataContext $context): GreetingData
    {
        return new GreetingData("{$this->who} at {$context->key} on {$context->page->view}");
    }
}

/** A Data class carrying a ProvidesData field, as a loader would return it. */
final readonly class PageWithGreetingData extends Data
{
    public function __construct(public Greeting $greeting) {}
}

final class SiteShared implements SharedData
{
    public function __construct(private readonly Reads $reads, private readonly Orm $orm) {}

    #[Shared]
    public function greeting(Request $request): Greeting
    {
        $this->reads->note('greeting');

        return new Greeting($request->header('x-who') ?? 'nobody');
    }

    /** Reaches a relation, so it batches with the queries of its tick. */
    #[Shared('rack')]
    public function firstRack(): RackSummaryData
    {
        $this->reads->note('rack');
        $rack = $this->orm->find(Rack::class, $this->reads->rack);
        \assert($rack instanceof Rack);

        return new RackSummaryData($rack->name, $rack->units->count());
    }

    #[Shared]
    public function nobody(): ?GreetingData
    {
        $this->reads->note('nobody');

        return null;
    }

    #[Shared]
    public function leak(): LooseData
    {
        $this->reads->note('leak');

        return new LooseData($this->orm->find(Rack::class, $this->reads->rack));
    }

    #[Shared]
    public function boom(): GreetingData
    {
        $this->reads->note('boom');

        throw new \RuntimeException('the session store is down');
    }

    #[Shared]
    public function writes(): GreetingData
    {
        $this->reads->note('writes');

        return $this->orm->transaction(static fn (): GreetingData => new GreetingData('wrote'));
    }

    #[Shared]
    public function where(DataContext $context): GreetingData
    {
        return new GreetingData("{$context->key} on {$context->page->view} with " . json_encode($context->parameters));
    }
}

/** In the admin panel: its values reach the admin panel's pages and no others. */
#[Panel('admin')]
final class StaffShared implements SharedData
{
    #[Shared]
    public function staffNote(): GreetingData
    {
        return new GreetingData('for staff');
    }
}

#[Dependency(Lifetime::Worker)]
final class WorkerLivedShared implements SharedData
{
    #[Shared]
    public function cached(): GreetingData
    {
        return new GreetingData('kept');
    }
}

final class StaticShared implements SharedData
{
    private static ?GreetingData $last = null;

    #[Shared]
    public function last(): ?GreetingData
    {
        return self::$last;
    }
}

final class UntypedShared implements SharedData
{
    #[Shared]
    public function untyped()
    {
        return new GreetingData('?');
    }
}

final class ArrayShared implements SharedData
{
    /** @return list<string> */
    #[Shared]
    public function roles(): array
    {
        return ['admin'];
    }
}

final class ServiceParameterShared implements SharedData
{
    #[Shared]
    public function tenantName(Orm $orm): GreetingData
    {
        return new GreetingData($orm->tenant());
    }
}

final class DuplicateShared implements SharedData
{
    #[Shared('greeting')]
    public function another(): GreetingData
    {
        return new GreetingData('again');
    }
}

final class NothingShared implements SharedData
{
    public function notMarked(): GreetingData
    {
        return new GreetingData('no');
    }
}

final class NotProvider
{
    #[Shared]
    public function greeting(): GreetingData
    {
        return new GreetingData('no');
    }
}

#[Panel('admin', permission: 'orders.view')]
final class NarrowingShared implements SharedData
{
    #[Shared]
    public function orders(): GreetingData
    {
        return new GreetingData('orders');
    }
}

final class BadKeyShared implements SharedData
{
    #[Shared('not-a-key')]
    public function dashed(): GreetingData
    {
        return new GreetingData('-');
    }
}

final class PrivateShared implements SharedData
{
    #[Shared]
    private function hidden(): GreetingData
    {
        return new GreetingData('hidden');
    }
}

/** Its toData() names no class of its own. */
final readonly class VagueProvider implements ProvidesData
{
    public function toData(DataContext $context): ?Data
    {
        return null;
    }
}

final class VagueShared implements SharedData
{
    #[Shared]
    public function vague(): VagueProvider
    {
        return new VagueProvider();
    }
}

/** Cached for everyone, and built on one visitor's shared data: refused by the build. */
#[\Tinvar\Attributes\Query(name: 'staff.note', cache: '5m', shared: true), \Tinvar\Attributes\NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class SharedQueryReadingSharedData
{
    public function __construct(private readonly StaffShared $shared) {}

    public function __invoke(): GreetingData
    {
        return $this->shared->staffNote();
    }
}
