<?php

declare(strict_types=1);

/**
 * A Tinvar attribute naming a class that does not exist is refused where
 * the framework compiles attributes: the registries, Data and validation,
 * webhooks, commands and the build rules (KnownAttributes, ADR 0055).
 *
 * PHP matches an attribute by its class, and one naming no class matches
 * nothing, so a misspelt `#[\Tinvar\Attributes\Authorise]` on a loader, or
 * a name from before the split once no alias is made for it, used to leave
 * the entry point registered as if it had never been written.
 */

namespace Tinvar\Tests\Auth\UnknownAttributes;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Query;
use Tinvar\Auth\BuildRules;
use Tinvar\Data\DataShape;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Model;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\RouteRegistry;

#[Page('/status', view: 'status'), NoAuthorization(reason: 'a public page')]
final class StatusPage
{
    #[\Tinvar\Attributes\Authorise('view')]
    public function __invoke(): array
    {
        return [];
    }
}

final class Actions
{
    #[Action, NoAuthorization(reason: 'a public action')]
    public function ping(#[\Tinvar\Attributes\Uploadd] string $file): void {}
}

#[Query(name: 'unknownAttributes.count'), NoAuthorization(reason: 'a public count')]
#[\Tinvar\Attributes\Pannel('admin')]
final class CountQuery
{
    public function __invoke(): int
    {
        return 0;
    }
}

/**
 * A payload's shape, as DataShape reads one. Not a Data subclass: the
 * testing layer's model-leak check reads every Data class a process has
 * declared, and would meet this one in whatever shop test ran after it in
 * the same worker.
 */
final readonly class Shown
{
    public function __construct(
        #[\Tinvar\Attributes\Optionall]
        public string $name = '',
    ) {}
}

#[Table('scripts'), \Tinvar\Orm\Attributes\TenantScopd]
final class Misspelt extends Model
{
    #[Id] public private(set) string $id;
}

it('refuses an entry point carrying one, as it registers', function (\Closure $register, string $where) {
    expect($register)->toThrow(\LogicException::class, "{$where} carries #[\\Tinvar\\Attributes\\");
})->with([
    'a page' => [fn () => (new RouteRegistry())->register(StatusPage::class), StatusPage::class . '::__invoke()'],
    'an action' => [fn () => (new ActionRegistry())->register(Actions::class), Actions::class . '::ping() parameter $file'],
    'a query' => [fn () => (new QueryRegistry())->register(CountQuery::class), CountQuery::class],
]);

it('refuses a Data class carrying one, when its shape is read', function () {
    expect(fn () => DataShape::fieldsOf(Shown::class))
        ->toThrow(\LogicException::class, 'carries #[\Tinvar\Attributes\Optionall], which is no class');
});

it('fails the build for an entry point, a payload or a model carrying one', function () {
    $violations = array_map(strval(...), (new BuildRules([Misspelt::class]))->check([StatusPage::class], [Shown::class]));

    expect($violations)->toHaveCount(3)
        ->and($violations[0])->toStartWith('[attributes-must-name-a-class] ' . Misspelt::class . ': ' . Misspelt::class . ' carries #[\Tinvar\Orm\Attributes\TenantScopd]')
        ->and($violations[1])->toStartWith('[attributes-must-name-a-class] ' . StatusPage::class . ':')
        ->and($violations[2])->toStartWith('[attributes-must-name-a-class] ' . Shown::class . ':');
});
