<?php

declare(strict_types=1);

/**
 * A redirect is answered with a 303 and no body, and there is no session
 * flash store yet. A flash or a value on a redirecting ActionResult was
 * dropped without a word. Until there is somewhere for them to go, the
 * combination is refused when the result is built.
 */

namespace Tinvar\Tests\Actions\RedirectCarriesNothing;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Actions\ActionRunner;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Http\Endpoints\ActionEndpoint;
use Tinvar\Http\Request;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Validation\Validator;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';

final class SiteActions
{
    #[Action, NoAuthorization(reason: 'fixture: results, not access control')]
    public function archive(): ActionResult
    {
        return ActionResult::redirect('/sites')->flash(['message' => 'Site archived']);
    }

    #[Action, NoAuthorization(reason: 'fixture: results, not access control')]
    public function leave(): ActionResult
    {
        return ActionResult::redirect('/sites')->withTags('sites');
    }
}

function endpoint(): ActionEndpoint
{
    $fixture = new Fixture(racks: 0, unitsPerRack: 0, sites: 0);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $registry = new ActionRegistry();
    $registry->register(SiteActions::class);

    return new ActionEndpoint(new ActionRunner($registry, $orm, new AllowAllGate(), new Validator($orm)));
}

it('refuses a flash or a value on a redirect', function (string $how) {
    $build = match ($how) {
        'flash' => fn () => ActionResult::redirect('/sites')->flash(['message' => 'Site archived']),
        'value' => fn () => ActionResult::redirect('/sites')->withValue(['id' => 1]),
        'after other builders' => fn () => ActionResult::redirect('/sites')->withTags('sites')->flash(['m' => 'x']),
    };

    expect($build)->toThrow(\LogicException::class, 'cannot also carry a flash or a value');
})->with(['flash', 'value', 'after other builders']);

it('fails the action loudly rather than answering a redirect without its flash', function () {
    expect(fn () => endpoint()->handle(Request::post('/_action/SiteActions.archive'), '{}'))
        ->toThrow(\LogicException::class, "redirecting to '/sites'");
});

it('still answers a plain redirect', function () {
    $response = endpoint()->handle(Request::post('/_action/SiteActions.leave'), '{}');

    expect($response->status)->toBe(303)
        ->and($response->header('location'))->toBe('/sites')
        ->and($response->header('x-invalidated-tags'))->toBe('sites');
});
