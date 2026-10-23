<?php

declare(strict_types=1);

/**
 * Unique(ignoreSubject) on a save action: create when no site is named,
 * update when one is. The create path left the declared-optional `?Site
 * $site = null` out, and the rule threw a LogicException -- a 500 -- because
 * no subject was resolved, though there was simply no record being edited.
 */

namespace Tinvar\Tests\Validation\UniqueOptionalSubject;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Actions\ActionRunner;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Site;
use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Unique;
use Tinvar\Validation\ValidationException;
use Tinvar\Validation\Validator;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';

final readonly class SiteRequest extends FormRequest
{
    public function __construct(#[Unique(Site::class, 'code', ignoreSubject: true)] public string $code) {}
}

final readonly class NamedSiteRequest extends FormRequest
{
    public function __construct(#[Unique(Site::class, 'code', ignoreSubject: 'site')] public string $code) {}
}

final class SiteActions
{
    #[Action, Authorize('create', Site::class), Authorize('update', 'site')]
    public function save(SiteRequest $request, ?Site $site = null): ActionResult
    {
        return ActionResult::ok($site === null ? 'created' : 'updated');
    }

    #[Action, Authorize('create', Site::class), Authorize('update', 'site')]
    public function saveNamed(NamedSiteRequest $request, ?Site $site = null): ActionResult
    {
        return ActionResult::ok($site === null ? 'created' : 'updated');
    }

    /** Binds no site at all: ignoreSubject has nothing it could ever exempt. */
    #[Action, NoAuthorization(reason: 'fixture: validation, not access control')]
    public function misconfigured(SiteRequest $request): ActionResult
    {
        return ActionResult::ok();
    }
}

/** @return array{0: ActionRunner, 1: Fixture} */
function runner(): array
{
    $fixture = new Fixture(racks: 0, unitsPerRack: 0, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $registry = new ActionRegistry();
    $registry->register(SiteActions::class);

    return [new ActionRunner($registry, $orm, new AllowAllGate(), new Validator($orm)), $fixture];
}

it('creates with a fresh code when the optional subject is left out', function (string $action) {
    [$runner] = runner();

    expect($runner->run("SiteActions.{$action}", ['code' => 'NEW1'])->value)->toBe('created');
})->with(['save', 'saveNamed']);

it('exempts nothing on the create path, so a taken code is refused', function (string $action) {
    [$runner] = runner();

    expect(fn () => $runner->run("SiteActions.{$action}", ['code' => 'LAX0']))
        ->toThrow(ValidationException::class)
        ->and($runner->validateField("SiteActions.{$action}", ['code' => 'LAX0'], 'code'))
        ->toBe(['code is already taken.']);
})->with(['save', 'saveNamed']);

it('still exempts the site being edited', function (string $action) {
    [$runner, $fixture] = runner();

    expect($runner->run("SiteActions.{$action}", ['code' => 'LAX0', 'site' => $fixture->ids['sites'][0]])->value)
        ->toBe('updated');
})->with(['save', 'saveNamed']);

it('still refuses an action that never binds the record it would exempt', function () {
    [$runner] = runner();

    expect(fn () => $runner->run('SiteActions.misconfigured', ['code' => 'NEW1']))
        ->toThrow(\LogicException::class, 'must bind the record being edited');
});
