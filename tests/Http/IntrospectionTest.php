<?php

declare(strict_types=1);

namespace Tinvar\Tests\Introspection;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Query\QueryRegistry;
use Tinvar\Tests\Actions\Fixtures\RackActions;
use Tinvar\Tests\Auth\Fixtures\RackPolicy;
use Tinvar\Tests\Auth\Fixtures\SitePolicy;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Site;
use Tinvar\Tests\Query\Fixtures\RackSummary;
use Tinvar\Tests\Query\Fixtures\UnitLoad;

require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/models.php';
require_once __DIR__ . '/../Auth/Fixtures/policies.php';
require_once __DIR__ . '/../Actions/Fixtures/actions.php';
require_once __DIR__ . '/../Query/Fixtures/queries.php';

// What an application reads back from the framework -- for a route list, a
// debug toolbar, its own middleware -- rather than what the framework uses.

describe('a request', function () {
    it('says whether a header was sent, whatever its case', function () {
        $request = new Request('GET', '/', ['x-trace' => 'abc']);

        expect($request->hasHeader('X-Trace'))->toBeTrue()
            ->and($request->hasHeader('x-other'))->toBeFalse();
    });

    it('lists the attributes middleware attached, without changing the original', function () {
        $request = new Request('GET', '/');
        $tagged = $request->withAttribute('tenant', 'acme')->withAttribute('panel', 'admin');

        expect($tagged->attributes())->toBe(['tenant' => 'acme', 'panel' => 'admin'])
            ->and($request->attributes())->toBe([]);
    });
});

it('says whether a response streams its body', function () {
    expect(Response::stream(static function (\Closure $sink): void {})->isStreaming())->toBeTrue()
        ->and(Response::make(200, 'done')->isStreaming())->toBeFalse();
});

it('lists the registered query and action names', function () {
    $queries = new QueryRegistry();
    $queries->registerAll([RackSummary::class, UnitLoad::class]);
    $actions = new ActionRegistry();
    $compiled = $actions->register(RackActions::class);

    expect($queries->names())->toBe(['racks.summary', 'racks.unitLoad'])
        ->and($actions->names())->toBe(array_map(static fn ($a): string => $a->name, $compiled));
});

it('lists the registered policies by subject', function () {
    $policies = (new PolicyRegistry())->registerAll([Rack::class => RackPolicy::class, Site::class => SitePolicy::class]);

    expect($policies->all())->toBe([Rack::class => RackPolicy::class, Site::class => SitePolicy::class]);
});
