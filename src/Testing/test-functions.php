<?php

declare(strict_types=1);

/**
 * The testing layer's Pest-style functions, over the application Testing::use()
 * named. Namespaced, so they are imported where they are used:
 *
 *   use function Tinvar\Testing\{actingAs, get, query};
 *
 * Loaded by Testing::functions(), from a suite's Pest.php.
 */

namespace Tinvar\Testing;

use PHPUnit\Framework\Assert;
use Tinvar\Auth\Authenticatable;
use Tinvar\Orm\Connection;
use Tinvar\Orm\Orm;

/** The current test's client. */
function testing(): Client
{
    return Testing::client();
}

/** The current test's client, as $user. */
function actingAs(?Authenticatable $user, string $epoch = ''): Client
{
    return Testing::client()->actingAs($user, $epoch);
}

/** A document request for $url, answered without the renderer, as nobody. */
function get(string $url): PageResponse
{
    return Testing::client()->get($url);
}

/** $url through the real renderer, as nobody. */
function render(string $url): RenderedPage
{
    return Testing::client()->render($url);
}

/** Query $query called in PHP, as nobody. */
function query(string $query, mixed ...$arguments): QueryResult
{
    return Testing::client()->query($query, ...$arguments);
}

/**
 * Action $action called in PHP, as nobody.
 *
 * @param array{0: class-string, 1: string}|string $action
 */
function action(array|string $action, mixed ...$arguments): ActionResponse
{
    return Testing::client()->action($action, ...$arguments);
}

/** Moves the current test's clock: `travel(5)->minutes()`. */
function travel(int $amount): Travel
{
    return Testing::clock()->travel($amount);
}

/**
 * $work issues exactly $count statements: the batching assertion. On the
 * current application's connection unless given one.
 *
 * @template T
 * @param \Closure(): T $work
 * @return T
 */
function expectQueries(int $count, \Closure $work, ?Connection $connection = null): mixed
{
    $connection ??= Testing::client()->app->kernel()->orm->connection();
    $before = $connection->statementCount();
    $logged = \count($connection->statementLog());

    $result = $work();

    $issued = $connection->statementCount() - $before;
    $sql = \array_slice($connection->statementLog(), $logged);
    Assert::assertSame($count, $issued, "Expected {$count} statements; {$issued} were issued:\n" . implode("\n", $sql));

    return $result;
}

/**
 * No lazy relation load recorded so far ran outside a batch: each was Level 1
 * over the result set its parent was read in, or Level 2 through a render's
 * scheduler, and none was for one parent alone (LazyLoad) -- the 1+N that
 * expectQueries() counts and this names:
 *
 *   expectQueries(2, fn () => $racks->each(fn ($r) => $r->units->count()));
 *   assertNoLazyLoadsOutsideBatch();
 *
 * On the current application's Orm, whose loads Testing::use() records from
 * the start of the test, unless given an Orm that records its own
 * (Orm::recordLazyLoads()).
 */
function assertNoLazyLoadsOutsideBatch(?Orm $orm = null): void
{
    $orm ??= Testing::client()->app->kernel()->orm;

    LazyLoadAssertion::assertNoneOutsideBatch($orm->lazyLoads()?->since(), 'The test');
}
