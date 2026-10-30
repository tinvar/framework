<?php

declare(strict_types=1);

/**
 * tenant-scoped-ids-must-be-typed at registration, as ADR 0007 asks of
 * every control (ADR 0105): each registry, handed the application's
 * models, refuses an entry point taking a bare id naming a #[TenantScoped]
 * one -- and finds one the list leaves out beside a model it can see.
 */

use Tinvar\Actions\ActionException;
use Tinvar\Actions\ActionRegistry;
use Tinvar\Channels\ChannelException;
use Tinvar\Channels\ChannelRegistry;
use Tinvar\Endpoints\EndpointException;
use Tinvar\Endpoints\EndpointRegistry;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Auth\Fixtures\Registration as R;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Transfers\TransferRegistry;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/tenant.php';
require_once __DIR__ . '/Fixtures/policies.php';
require_once __DIR__ . '/Fixtures/registration.php';

it('refuses a bare id naming a tenant-scoped model in each kind of entry point, given the models', function (Closure $register, string $exception) {
    expect(fn () => $register([Script::class]))->toThrow($exception, 'tenant-scoped-ids-must-be-typed');
})->with([
    'a query' => [fn (array $models) => new QueryRegistry(null, $models)->register(R\BareIdQuery::class), InvalidArgumentException::class],
    'a page' => [fn (array $models) => new RouteRegistry($models)->register(R\BareIdPage::class), Tinvar\Routing\RoutingException::class],
    "an action's form request" => [fn (array $models) => new ActionRegistry(null, $models)->register(R\BareIdActions::class), ActionException::class],
    'an endpoint' => [fn (array $models) => new EndpointRegistry($models)->register(R\BareIdEndpoints::class), EndpointException::class],
    'a channel' => [fn (array $models) => new ChannelRegistry($models)->register(R\BareIdChannel::class), ChannelException::class],
    'an export' => [fn (array $models) => new TransferRegistry($models)->register(R\BareIdExport::class), InvalidArgumentException::class],
]);

it('registers the same entry points when no model they name is tenant-scoped', function () {
    // Rack alone is listed, and its namespace holds Script: Script is found.
    // With no model and none taken, there is nothing to name, and nothing
    // to find it beside.
    expect(new QueryRegistry()->register(R\BareIdQuery::class))->toBe('registration.bare')
        ->and(fn () => new QueryRegistry(null, [Rack::class])->register(R\BareIdQuery::class))->toThrow(InvalidArgumentException::class, 'tenant-scoped-ids-must-be-typed');
});

it('finds a tenant-scoped model beside one an entry point takes, with no models handed over', function () {
    expect(fn () => new QueryRegistry()->register(R\BareIdBesideAModel::class))
        ->toThrow(InvalidArgumentException::class, 'naming ' . Script::class);
});

it('registers an entry point whose ids are typed as the models', function () {
    expect(new QueryRegistry(null, [Script::class, Rack::class])->register(R\TypedQuery::class))->toBe('registration.typed');
});
