<?php

declare(strict_types=1);

/**
 * Unique(ignoreSubject: true) with two resolved models of its class.
 *
 * The rule documents that it fails closed then. The subject -- the model the
 * first #[Authorize] names -- was returned whenever its class matched, without
 * counting the others, so an action taking a 'view'-checked Site before the
 * edited one exempted the wrong row: a code held by the second site passed.
 */

namespace Tinvar\Tests\Validation\UniqueSubject;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Actions\ActionRunner;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Site;
use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Unique;
use Tinvar\Validation\ValidationContext;
use Tinvar\Validation\Validator;

require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/models.php';
require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/schema.php';

final readonly class RenameSiteRequest extends FormRequest
{
    public function __construct(#[Unique(Site::class, 'code', ignoreSubject: true)] public string $code) {}
}

final class CopySiteActions
{
    /** The 'view' check on the source comes first, so it is the subject. */
    #[Action, Authorize('view', 'from'), Authorize('update', 'site')]
    public function rename(Site $from, Site $site, RenameSiteRequest $request): ActionResult
    {
        return ActionResult::ok($request->code);
    }
}

/** @return array{0: Orm, 1: Site, 2: Site} */
function twoSites(): array
{
    $fixture = new Fixture(racks: 0, unitsPerRack: 0, sites: 2);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    return [
        $orm,
        $orm->find(Site::class, $fixture->ids['sites'][0]) ?? throw new \LogicException('no site 0'),
        $orm->find(Site::class, $fixture->ids['sites'][1]) ?? throw new \LogicException('no site 1'),
    ];
}

it('fails closed when two models of the rule\'s class were resolved', function () {
    [$orm, $edited, $other] = twoSites();
    $models = ['site' => $edited, 'from' => $other];

    expect(fn () => (new Validator($orm))->validate(
        RenameSiteRequest::class,
        ['code' => $other->code],
        $other,
        $models,
        $models,
    ))->toThrow(\LogicException::class, 'from, site');
});

it('fails closed through an action whose first check names the other one', function () {
    [$orm, $edited, $other] = twoSites();
    $registry = new ActionRegistry();
    $registry->register(CopySiteActions::class);
    $runner = new ActionRunner($registry, $orm, new AllowAllGate(), new Validator($orm));

    expect(fn () => $runner->run('CopySiteActions.rename', [
        'from' => $other->id,
        'site' => $edited->id,
        'code' => $other->code,
    ]))->toThrow(\LogicException::class);
});

it('counts one model named twice as one', function () {
    [$orm, $edited] = twoSites();

    $context = new ValidationContext([], $orm, $edited, ['site' => $edited, 'again' => $edited]);

    expect($context->subjectOf(Site::class))->toBe($edited);
});

it('still exempts the single resolved model', function () {
    [$orm, $edited] = twoSites();

    $request = (new Validator($orm))->validate(
        RenameSiteRequest::class,
        ['code' => $edited->code],
        $edited,
        ['site' => $edited],
        ['site' => $edited],
    );

    expect($request->code)->toBe($edited->code);
});
