<?php

declare(strict_types=1);

/**
 * QueryRegistry silently replaced a query registered under a name already
 * taken, so a route's allowlist entry started meaning whichever class
 * registered last -- with that class's checks, not the first one's.
 */

namespace Tinvar\Tests\Query\NameCollision;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Query;
use Tinvar\Query\QueryRegistry;

final class AdminArea {}

#[Query(name: 'billing.totals'), Authorize('viewAny', AdminArea::class)]
final class AdminTotals
{
    /** @return list<string> */
    public function __invoke(): array
    {
        return ['all tenants'];
    }
}

#[Query(name: 'billing.totals'), NoAuthorization(reason: 'a tenant reads its own invoices')]
final class PortalTotals
{
    /** @return list<string> */
    public function __invoke(): array
    {
        return ['my invoices'];
    }
}

/** No name given: derived from the class, as 'totals'. */
#[Query, NoAuthorization(reason: 'a tenant reads its own invoices')]
final class Totals
{
    /** @return list<string> */
    public function __invoke(): array
    {
        return ['derived'];
    }
}

#[Query(name: 'totals'), NoAuthorization(reason: 'a tenant reads its own invoices')]
final class ExplicitTotals
{
    /** @return list<string> */
    public function __invoke(): array
    {
        return ['explicit'];
    }
}

it('refuses a second query under a name already taken', function (string $first, string $second) {
    $registry = new QueryRegistry();
    $name = $registry->register($first);

    expect(fn () => $registry->register($second))
        ->toThrow(\InvalidArgumentException::class, "Two queries are named '{$name}'")
        ->and($registry->classFor($name))->toBe($first);
})->with([
    'two explicit names' => [PortalTotals::class, AdminTotals::class],
    'a derived name, then an explicit one' => [Totals::class, ExplicitTotals::class],
]);

it('refuses the same class registered twice', function () {
    $registry = new QueryRegistry();
    $registry->register(AdminTotals::class);

    expect(fn () => $registry->register(AdminTotals::class))->toThrow(\InvalidArgumentException::class);
});
